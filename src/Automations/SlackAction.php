<?php
/**
 * The Slack action: post a store event to a Slack channel.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Automations;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Action\ActionContext;
use CartQuill\Action\ActionInterface;
use CartQuill\Builder\ConfigFields;
use CartQuill\Builder\DescribesConfig;
use CartQuill\Flow\Renderer;
use CartQuill\Model\SendResult;
use CartQuill\Persistence\ConnectionStore;

/**
 * An internal (non-customer-facing) action that posts to the store's own Slack
 * incoming webhook. It reaches no customer inbox, so suppression is never
 * consulted. The channel + message template come from the step's config; the
 * message is rendered against the enrollment's trigger context (customer email,
 * order total, …). A missing connection or a failed post returns a failed
 * result, which the engine retries and then dead-letters.
 */
final class SlackAction implements ActionInterface, DescribesConfig {

	use ConfigFields;

	public const TYPE    = 'slack_post';
	public const SERVICE = 'slack';

	public function __construct(
		private readonly ConnectionStore $connections,
		private readonly SlackClient $client,
		private readonly Renderer $renderer,
	) {}

	/**
	 * The channel + message template this action reads from the step config.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function config_fields(): array {
		return array(
			self::config_field( 'channel', 'Channel', 'text', array( 'help' => 'e.g. #orders' ) ),
			self::config_field( 'text', 'Message', 'textarea', array( 'default' => 'New order from {{ customer_email }}', 'merge' => true ) ),
		);
	}

	public function type(): string {
		return self::TYPE;
	}

	public function sender_key(): string {
		return self::SERVICE;
	}

	public function is_customer_facing(): bool {
		return false;
	}

	public function target( ActionContext $context ): ?string {
		return null; // Internal: reaches no customer destination.
	}

	public function execute( ActionContext $context ): SendResult {
		$connection = $this->connections->find( self::SERVICE );
		$webhook    = null !== $connection ? (string) $connection->credential( 'webhook_url', '' ) : '';
		if ( '' === $webhook ) {
			return SendResult::failed( 'Slack connection is not configured.' );
		}

		$channel = (string) $context->config( 'channel', '' );
		$text    = $this->message_text( $context );

		$result = $this->client->post( $webhook, $channel, $text );

		return $result->ok
			? SendResult::accepted( $result->ref )
			: SendResult::failed( $result->error ?? 'Slack post failed.' );
	}

	private function message_text( ActionContext $context ): string {
		$template = (string) $context->config( 'text', 'New order from {{ customer_email }}' );
		$data     = array_merge(
			$context->context,
			array( 'customer_email' => $context->customer_email )
		);
		return $this->renderer->render_text( $template, $data );
	}
}
