<?php
/**
 * HTTP SheetsClient: service-account auth + values.append via the WP HTTP API.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Automations;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Appends a row to a Google Sheet using the store's own service account: signs a
 * short-lived RS256 JWT with the account's private key, exchanges it for an
 * access token, then calls `spreadsheets.values.append`. Any transport error,
 * missing credential, or non-2xx response becomes a failed {@see SheetsResult}
 * the engine can retry and dead-letter.
 *
 * OAuth "Sign in with Google" is deferred; for now the store pastes a
 * service-account JSON and shares the target sheet with the account's email.
 */
final class HttpSheetsClient implements SheetsClient {

	private const SCOPE          = 'https://www.googleapis.com/auth/spreadsheets';
	private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

	public function append( array $service_account, string $spreadsheet_id, string $range, array $row ): SheetsResult {
		$email       = (string) ( $service_account['client_email'] ?? '' );
		$private_key = (string) ( $service_account['private_key'] ?? '' );
		if ( '' === $email || '' === $private_key ) {
			return SheetsResult::failed( 'Service account is missing client_email or private_key.' );
		}

		$token = $this->access_token( $email, $private_key );
		if ( null === $token ) {
			return SheetsResult::failed( 'Could not obtain a Google access token.' );
		}

		$endpoint = sprintf(
			'https://sheets.googleapis.com/v4/spreadsheets/%s/values/%s:append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS',
			rawurlencode( $spreadsheet_id ),
			rawurlencode( $range )
		);

		$response = \wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => (string) \wp_json_encode( array( 'values' => array( array_values( $row ) ) ) ),
				'timeout' => 15,
			)
		);

		if ( \is_wp_error( $response ) ) {
			return SheetsResult::failed( $response->get_error_message() );
		}

		$code = (int) \wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return SheetsResult::failed( sprintf( 'Sheets API responded %d.', $code ) );
		}

		$body          = json_decode( (string) \wp_remote_retrieve_body( $response ), true );
		$updated_range = is_array( $body ) ? (string) ( $body['updates']['updatedRange'] ?? '' ) : '';

		return SheetsResult::ok( '' !== $updated_range ? $updated_range : null );
	}

	/**
	 * Exchange a signed JWT assertion for a short-lived OAuth access token.
	 */
	private function access_token( string $email, string $private_key ): ?string {
		$assertion = $this->signed_jwt( $email, $private_key );
		if ( null === $assertion ) {
			return null;
		}

		$response = \wp_remote_post(
			self::TOKEN_ENDPOINT,
			array(
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $assertion,
				),
				'timeout' => 15,
			)
		);

		if ( \is_wp_error( $response ) ) {
			return null;
		}

		$body  = json_decode( (string) \wp_remote_retrieve_body( $response ), true );
		$token = is_array( $body ) ? (string) ( $body['access_token'] ?? '' ) : '';

		return '' !== $token ? $token : null;
	}

	/**
	 * Build and RS256-sign the service-account JWT assertion.
	 */
	private function signed_jwt( string $email, string $private_key ): ?string {
		if ( ! function_exists( 'openssl_sign' ) ) {
			return null;
		}

		$now    = time();
		$header = $this->base64url( (string) \wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
		$claims = $this->base64url(
			(string) \wp_json_encode(
				array(
					'iss'   => $email,
					'scope' => self::SCOPE,
					'aud'   => self::TOKEN_ENDPOINT,
					'iat'   => $now,
					'exp'   => $now + 3600,
				)
			)
		);

		$signing_input = $header . '.' . $claims;
		$signature     = '';
		if ( ! openssl_sign( $signing_input, $signature, $private_key, OPENSSL_ALGO_SHA256 ) ) {
			return null;
		}

		return $signing_input . '.' . $this->base64url( $signature );
	}

	private function base64url( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}
}
