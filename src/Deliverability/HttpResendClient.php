<?php
/**
 * HTTP-backed ResendClient (runtime): the Resend REST API.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Deliverability;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use FlowForge\Model\Message;

/**
 * Talks to https://api.resend.com with the customer's own API key. Marshals a
 * Message into Resend's `POST /emails` shape and reads `GET /domains/{name}` for
 * the wizard, normalising every failure into {@see ResendException} so the
 * sender can return a failed SendResult without leaking transport details.
 */
final class HttpResendClient implements ResendClient {

	private const BASE = 'https://api.resend.com';

	public function __construct(
		private readonly string $api_key,
		private readonly int $timeout = 15,
	) {}

	public function send( Message $message ): string {
		$from = '' !== $message->from_name
			? sprintf( '%s <%s>', $message->from_name, $message->from_email )
			: $message->from_email;

		$payload = array(
			'from'    => $from,
			'to'      => array( $message->to ),
			'subject' => $message->subject,
			'html'    => $message->body,
		);

		$headers = array();
		if ( null !== $message->unsubscribe && '' !== $message->unsubscribe ) {
			$headers['List-Unsubscribe'] = '<' . $message->unsubscribe . '>';
			if ( 1 === preg_match( '/^https?:\/\//i', $message->unsubscribe ) ) {
				$headers['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
			}
		}
		$headers = array_merge( $headers, $message->headers );
		if ( array() !== $headers ) {
			$payload['headers'] = $headers;
		}

		$data = $this->request( 'POST', '/emails', $payload );

		if ( ! isset( $data['id'] ) || '' === (string) $data['id'] ) {
			throw new ResendException( 'Resend accepted the request but returned no message id.' );
		}

		return (string) $data['id'];
	}

	public function create_domain( string $domain ): DomainStatus {
		$data = $this->request( 'POST', '/domains', array( 'name' => $domain ) );

		return DomainStatus::from_resend( $data + array( 'name' => $domain ) );
	}

	public function verify_domain( string $domain_id ): void {
		$this->request( 'POST', '/domains/' . rawurlencode( $domain_id ) . '/verify', null );
	}

	public function domain_status( string $domain_id ): DomainStatus {
		$data = $this->request( 'GET', '/domains/' . rawurlencode( $domain_id ), null );

		return DomainStatus::from_resend( $data );
	}

	public function find_domain_id( string $domain ): ?string {
		$data    = $this->request( 'GET', '/domains', null );
		$domains = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : array();

		foreach ( $domains as $entry ) {
			$entry = (array) $entry;
			if ( isset( $entry['id'], $entry['name'] ) && (string) $entry['name'] === $domain ) {
				return (string) $entry['id'];
			}
		}

		return null;
	}

	/**
	 * @param array<string, mixed>|null $body
	 *
	 * @return array<string, mixed>
	 */
	private function request( string $method, string $path, ?array $body ): array {
		$args = array(
			'method'  => $method,
			'timeout' => $this->timeout,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = (string) \wp_json_encode( $body );
		}

		$response = \wp_remote_request( self::BASE . $path, $args );

		if ( \is_wp_error( $response ) ) {
			throw new ResendException( 'Resend request failed: ' . $response->get_error_message() );
		}

		$code = (int) \wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) \wp_remote_retrieve_body( $response ), true );
		$data = is_array( $data ) ? $data : array();

		if ( $code < 200 || $code >= 300 ) {
			$detail = isset( $data['message'] ) ? (string) $data['message'] : "HTTP {$code}";
			throw new ResendException( 'Resend error: ' . $detail );
		}

		return $data;
	}
}
