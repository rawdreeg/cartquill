<?php
/**
 * HTTP MailchimpClient: member upsert + tag via the WP HTTP API.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Automations;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Upserts a member (PUT /lists/{id}/members/{hash}) into the store's own
 * Mailchimp audience, then tags them (POST .../tags), authenticating with the
 * store's API key. Any transport error, missing credential, or non-2xx response
 * becomes a failed {@see MailchimpResult} the engine can retry and dead-letter.
 */
final class HttpMailchimpClient implements MailchimpClient {

	public function sync( array $credentials, string $email, string $tag ): MailchimpResult {
		$api_key       = (string) ( $credentials['api_key'] ?? '' );
		$server_prefix = (string) ( $credentials['server_prefix'] ?? '' );
		$audience_id   = (string) ( $credentials['audience_id'] ?? '' );
		if ( '' === $api_key || '' === $server_prefix || '' === $audience_id ) {
			return MailchimpResult::failed( 'Mailchimp connection is incomplete.' );
		}

		// Mailchimp addresses a member by the MD5 hash of the lowercased email —
		// its documented subscriber-hash convention, not a security use of md5.
		$hash = md5( strtolower( trim( $email ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_md5
		$base = sprintf( 'https://%s.api.mailchimp.com/3.0/lists/%s/members/%s', $server_prefix, rawurlencode( $audience_id ), $hash );
		$auth = 'Basic ' . base64_encode( 'anystring:' . $api_key );

		$upsert = \wp_remote_request(
			$base,
			array(
				'method'  => 'PUT',
				'headers' => array(
					'Authorization' => $auth,
					'Content-Type'  => 'application/json',
				),
				'body'    => (string) \wp_json_encode(
					array(
						'email_address' => $email,
						'status_if_new' => 'subscribed',
					)
				),
				'timeout' => 15,
			)
		);

		if ( \is_wp_error( $upsert ) ) {
			return MailchimpResult::failed( $upsert->get_error_message() );
		}
		$code = (int) \wp_remote_retrieve_response_code( $upsert );
		if ( $code < 200 || $code >= 300 ) {
			return MailchimpResult::failed( sprintf( 'Mailchimp upsert responded %d.', $code ) );
		}

		if ( '' !== $tag ) {
			$tags = \wp_remote_post(
				$base . '/tags',
				array(
					'headers' => array(
						'Authorization' => $auth,
						'Content-Type'  => 'application/json',
					),
					'body'    => (string) \wp_json_encode( array( 'tags' => array( array( 'name' => $tag, 'status' => 'active' ) ) ) ),
					'timeout' => 15,
				)
			);
			if ( \is_wp_error( $tags ) ) {
				return MailchimpResult::failed( $tags->get_error_message() );
			}
			$tag_code = (int) \wp_remote_retrieve_response_code( $tags );
			if ( $tag_code < 200 || $tag_code >= 300 ) {
				return MailchimpResult::failed( sprintf( 'Mailchimp tag responded %d.', $tag_code ) );
			}
		}

		$body = json_decode( (string) \wp_remote_retrieve_body( $upsert ), true );
		$id   = is_array( $body ) ? (string) ( $body['id'] ?? $hash ) : $hash;

		return MailchimpResult::ok( $id );
	}
}
