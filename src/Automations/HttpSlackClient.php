<?php
/**
 * HTTP SlackClient: posts to a Slack incoming webhook via the WP HTTP API.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Automations;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Posts JSON to the store's own Slack incoming-webhook URL. Slack acknowledges a
 * successful post with HTTP 200 and the body "ok" (no message timestamp), so the
 * result carries no ref. Any transport error or non-ok response becomes a failed
 * {@see SlackResult} the engine can retry and dead-letter.
 */
final class HttpSlackClient implements SlackClient {

	public function post( string $webhook_url, string $channel, string $text ): SlackResult {
		$payload = array( 'text' => $text );
		if ( '' !== $channel ) {
			$payload['channel'] = $channel;
		}

		$response = \wp_remote_post(
			$webhook_url,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => (string) \wp_json_encode( $payload ),
				'timeout' => 10,
			)
		);

		if ( \is_wp_error( $response ) ) {
			return SlackResult::failed( $response->get_error_message() );
		}

		$code = (int) \wp_remote_retrieve_response_code( $response );
		$body = strtolower( trim( (string) \wp_remote_retrieve_body( $response ) ) );

		if ( $code < 200 || $code >= 300 || 'ok' !== $body ) {
			return SlackResult::failed( sprintf( 'Slack responded %d: %s', $code, $body ) );
		}

		return SlackResult::ok();
	}
}
