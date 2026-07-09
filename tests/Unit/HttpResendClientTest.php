<?php
/**
 * HttpResendClient marshals Messages/domain calls into the Resend REST shape and
 * normalises every failure into a ResendException. The only class that talks to
 * the live API, exercised here against a stubbed wp_remote_request.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CartQuill\Deliverability\DomainStatus;
use CartQuill\Deliverability\HttpResendClient;
use CartQuill\Deliverability\ResendException;
use CartQuill\Model\Message;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class HttpResendClientTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array{url: string, args: array<string, mixed>}|null The last captured request. */
	private ?array $last_request = null;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->last_request = null;

		Functions\when( 'wp_json_encode' )->alias( static fn ( $value ) => json_encode( $value ) );
		// An HTTP error is the object with get_error_message(); a success is an array.
		Functions\when( 'is_wp_error' )->alias(
			static fn ( $response ) => is_object( $response ) && method_exists( $response, 'get_error_message' )
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn ( $response ) => $response['code'] ?? 0
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn ( $response ) => $response['body'] ?? ''
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Make wp_remote_request capture the request and return $response.
	 *
	 * @param array<string, mixed>|object $response A fake HTTP response or WP_Error-like object.
	 */
	private function stub_http( $response ): void {
		Functions\when( 'wp_remote_request' )->alias(
			function ( string $url, array $args ) use ( $response ) {
				$this->last_request = array(
					'url'  => $url,
					'args' => $args,
				);
				return $response;
			}
		);
	}

	/**
	 * @param array<string, mixed> $json Decoded JSON body Resend "returns".
	 */
	private function ok( array $json, int $code = 200 ): array {
		return array(
			'code' => $code,
			'body' => (string) json_encode( $json ),
		);
	}

	private function wp_error( string $message ): object {
		return new class( $message ) {
			public function __construct( private string $message ) {}
			public function get_error_message(): string {
				return $this->message;
			}
		};
	}

	/**
	 * @return array<string, mixed> The decoded JSON body of the captured request.
	 */
	private function sent_body(): array {
		$decoded = json_decode( (string) ( $this->last_request['args']['body'] ?? '' ), true );
		return is_array( $decoded ) ? $decoded : array();
	}

	private function client(): HttpResendClient {
		return new HttpResendClient( 're_test_key' );
	}

	private function message(
		?string $unsubscribe = 'https://shop.test/u/abc',
		string $from_name = 'Acme',
		string $from_email = 'hello@acme.test'
	): Message {
		return new Message(
			to: 'buyer@example.com',
			subject: 'Your order',
			body: '<p>Thanks</p>',
			from_name: $from_name,
			from_email: $from_email,
			unsubscribe: $unsubscribe,
		);
	}

	public function test_send_posts_the_message_and_returns_the_id(): void {
		$this->stub_http( $this->ok( array( 'id' => 'resend-42' ) ) );

		$id = $this->client()->send( $this->message() );

		$this->assertSame( 'resend-42', $id );
		$this->assertSame( 'https://api.resend.com/emails', $this->last_request['url'] );
		$this->assertSame( 'POST', $this->last_request['args']['method'] );
		$this->assertSame( 'Bearer re_test_key', $this->last_request['args']['headers']['Authorization'] );
		$this->assertSame( 'application/json', $this->last_request['args']['headers']['Content-Type'] );

		$body = $this->sent_body();
		$this->assertSame( 'Acme <hello@acme.test>', $body['from'] );
		$this->assertSame( array( 'buyer@example.com' ), $body['to'], 'to is always a single-element array' );
		$this->assertSame( 'Your order', $body['subject'] );
		$this->assertSame( '<p>Thanks</p>', $body['html'] );
	}

	public function test_send_carries_the_one_click_unsubscribe_headers_for_an_http_target(): void {
		$this->stub_http( $this->ok( array( 'id' => 'resend-1' ) ) );

		$this->client()->send( $this->message( 'https://shop.test/u/abc' ) );

		$headers = $this->sent_body()['headers'];
		$this->assertSame( '<https://shop.test/u/abc>', $headers['List-Unsubscribe'] );
		$this->assertSame( 'List-Unsubscribe=One-Click', $headers['List-Unsubscribe-Post'], 'RFC 8058 one-click for HTTP targets' );
	}

	public function test_send_omits_one_click_post_for_a_mailto_unsubscribe(): void {
		$this->stub_http( $this->ok( array( 'id' => 'resend-1' ) ) );

		$this->client()->send( $this->message( 'mailto:hello@acme.test?subject=unsubscribe' ) );

		$headers = $this->sent_body()['headers'];
		$this->assertSame( '<mailto:hello@acme.test?subject=unsubscribe>', $headers['List-Unsubscribe'] );
		$this->assertArrayNotHasKey( 'List-Unsubscribe-Post', $headers, 'a mailto is not one-click' );
	}

	public function test_send_merges_caller_supplied_message_headers_into_the_payload(): void {
		$this->stub_http( $this->ok( array( 'id' => 'resend-1' ) ) );

		$message = new Message(
			to: 'buyer@example.com',
			subject: 'Your order',
			body: '<p>Thanks</p>',
			from_name: 'Acme',
			from_email: 'hello@acme.test',
			headers: array( 'X-Entity-Ref-ID' => 'ref-123' ),
			unsubscribe: 'https://shop.test/u/abc',
		);

		$this->client()->send( $message );

		$headers = $this->sent_body()['headers'];
		$this->assertSame( 'ref-123', $headers['X-Entity-Ref-ID'], 'custom Message headers reach Resend' );
		$this->assertSame( '<https://shop.test/u/abc>', $headers['List-Unsubscribe'], 'alongside the compliance headers' );
	}

	public function test_send_uses_a_bare_email_from_when_there_is_no_display_name(): void {
		$this->stub_http( $this->ok( array( 'id' => 'resend-1' ) ) );

		$this->client()->send( $this->message( null, '', 'hello@acme.test' ) );

		$body = $this->sent_body();
		$this->assertSame( 'hello@acme.test', $body['from'] );
		$this->assertArrayNotHasKey( 'headers', $body, 'no unsubscribe target → no extra headers' );
	}

	public function test_send_throws_when_the_response_has_no_id(): void {
		$this->stub_http( $this->ok( array() ) ); // accepted, but no id

		$this->expectException( ResendException::class );
		$this->expectExceptionMessage( 'no message id' );
		$this->client()->send( $this->message() );
	}

	public function test_send_throws_when_the_response_id_is_an_empty_string(): void {
		$this->stub_http( $this->ok( array( 'id' => '' ) ) ); // present, but blank

		$this->expectException( ResendException::class );
		$this->expectExceptionMessage( 'no message id' );
		$this->client()->send( $this->message() );
	}

	public function test_send_treats_a_201_created_as_success(): void {
		// Resend returns 201 Created for POST /emails — the whole 2xx range is success.
		$this->stub_http( $this->ok( array( 'id' => 'resend-201' ), 201 ) );

		$this->assertSame( 'resend-201', $this->client()->send( $this->message() ) );
	}

	public function test_maps_a_3xx_response_to_an_error(): void {
		// 300 sits just past the 2xx success window and must be treated as a failure.
		$this->stub_http( $this->ok( array( 'message' => 'Multiple choices' ), 300 ) );

		$this->expectException( ResendException::class );
		$this->expectExceptionMessage( 'Resend error: Multiple choices' );
		$this->client()->send( $this->message() );
	}

	public function test_send_maps_a_non_2xx_to_the_resend_error_message(): void {
		$this->stub_http( $this->ok( array( 'message' => 'The domain is not verified.' ), 422 ) );

		$this->expectException( ResendException::class );
		$this->expectExceptionMessage( 'Resend error: The domain is not verified.' );
		$this->client()->send( $this->message() );
	}

	public function test_send_falls_back_to_the_status_code_without_an_error_message(): void {
		$this->stub_http( array( 'code' => 500, 'body' => 'not json' ) );

		$this->expectException( ResendException::class );
		$this->expectExceptionMessage( 'Resend error: HTTP 500' );
		$this->client()->send( $this->message() );
	}

	public function test_request_wraps_a_wp_http_transport_error(): void {
		$this->stub_http( $this->wp_error( 'cURL error 28: timed out' ) );

		$this->expectException( ResendException::class );
		$this->expectExceptionMessage( 'Resend request failed: cURL error 28: timed out' );
		$this->client()->send( $this->message() );
	}

	public function test_create_domain_posts_the_name_and_maps_the_status(): void {
		$this->stub_http(
			$this->ok(
				array(
					'id'      => 'dom_abc',
					'name'    => 'example.com',
					'status'  => 'pending',
					'records' => array(
						array( 'record' => 'SPF', 'type' => 'MX', 'name' => 'send', 'value' => 'feedback-smtp.resend.com', 'status' => 'pending' ),
					),
				)
			)
		);

		$status = $this->client()->create_domain( 'example.com' );

		$this->assertSame( 'https://api.resend.com/domains', $this->last_request['url'] );
		$this->assertSame( 'POST', $this->last_request['args']['method'] );
		$this->assertSame( array( 'name' => 'example.com' ), $this->sent_body() );

		$this->assertInstanceOf( DomainStatus::class, $status );
		$this->assertSame( 'example.com', $status->domain );
		$this->assertSame( 'dom_abc', $status->id );
		$this->assertFalse( $status->verified );
		$this->assertSame( 'pending', $status->state );
		$this->assertCount( 1, $status->records );
		$this->assertSame( 'MX', $status->records[0]->type );
	}

	public function test_verify_domain_posts_to_the_verify_endpoint_without_a_body(): void {
		$this->stub_http( $this->ok( array() ) );

		$this->client()->verify_domain( 'dom_abc' );

		$this->assertSame( 'https://api.resend.com/domains/dom_abc/verify', $this->last_request['url'] );
		$this->assertSame( 'POST', $this->last_request['args']['method'] );
		$this->assertArrayNotHasKey( 'body', $this->last_request['args'], 'verify sends no request body' );
	}

	public function test_domain_status_gets_and_maps_a_verified_domain(): void {
		$this->stub_http(
			$this->ok( array( 'id' => 'dom_abc', 'name' => 'example.com', 'status' => 'verified' ) )
		);

		$status = $this->client()->domain_status( 'dom_abc' );

		$this->assertSame( 'https://api.resend.com/domains/dom_abc', $this->last_request['url'] );
		$this->assertSame( 'GET', $this->last_request['args']['method'] );
		$this->assertTrue( $status->verified );
		$this->assertSame( 'verified', $status->state );
	}

	public function test_find_domain_id_returns_the_matching_domains_id(): void {
		$this->stub_http(
			$this->ok(
				array(
					'data' => array(
						array( 'id' => 'd1', 'name' => 'other.test' ),
						array( 'id' => 'd2', 'name' => 'example.com' ),
					),
				)
			)
		);

		$id = $this->client()->find_domain_id( 'example.com' );

		$this->assertSame( 'https://api.resend.com/domains', $this->last_request['url'] );
		$this->assertSame( 'GET', $this->last_request['args']['method'] );
		$this->assertSame( 'd2', $id );
	}

	public function test_find_domain_id_returns_null_when_the_domain_is_absent(): void {
		$this->stub_http(
			$this->ok( array( 'data' => array( array( 'id' => 'd1', 'name' => 'other.test' ) ) ) )
		);

		$this->assertNull( $this->client()->find_domain_id( 'example.com' ) );
	}

	public function test_find_domain_id_returns_null_when_the_payload_has_no_data(): void {
		$this->stub_http( $this->ok( array() ) );

		$this->assertNull( $this->client()->find_domain_id( 'example.com' ) );
	}
}
