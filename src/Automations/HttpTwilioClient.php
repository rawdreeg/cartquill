<?php
/**
 * HTTP TwilioClient: sends SMS via the Twilio Messages API.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Automations;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * POSTs to the Twilio Messages endpoint with the store's account SID + auth
 * token (HTTP Basic). Any transport error, missing credential, or non-2xx
 * response becomes a failed {@see SmsResult} the engine can retry and
 * dead-letter; a success carries Twilio's message SID.
 */
final class HttpTwilioClient implements TwilioClient {

	public function send( array $credentials, string $to, string $body ): SmsResult {
		$sid   = (string) ( $credentials['account_sid'] ?? '' );
		$token = (string) ( $credentials['auth_token'] ?? '' );
		$from  = (string) ( $credentials['from_number'] ?? '' );
		if ( '' === $sid || '' === $token || '' === $from ) {
			return SmsResult::failed( 'Twilio connection is incomplete.' );
		}

		$endpoint = sprintf( 'https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json', rawurlencode( $sid ) );

		$response = \wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $sid . ':' . $token ),
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => array(
					'To'   => $to,
					'From' => $from,
					'Body' => $body,
				),
				'timeout' => 15,
			)
		);

		if ( \is_wp_error( $response ) ) {
			return SmsResult::failed( $response->get_error_message() );
		}

		$code = (int) \wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return SmsResult::failed( sprintf( 'Twilio responded %d.', $code ) );
		}

		$payload = json_decode( (string) \wp_remote_retrieve_body( $response ), true );
		$sid_out = is_array( $payload ) ? (string) ( $payload['sid'] ?? '' ) : '';

		return SmsResult::ok( '' !== $sid_out ? $sid_out : null );
	}
}
