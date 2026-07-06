<?php
/**
 * Registry of available step actions, keyed by type.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Action;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * The extension point mirroring {@see \CartQuill\Sender\SenderRegistry}: core
 * registers the `email` action; paid add-ons register additional actions
 * (slack_post, sheets_append, mailchimp_sync, sms_send) — gated by license +
 * connection status — during the `cartquill_register_actions` hook.
 *
 * The step runner resolves a step's action with {@see self::get()}. An action
 * that is not registered (add-on absent, unlicensed, or its connection is down)
 * resolves to null, which the runner dead-letters rather than stalling on.
 */
final class ActionRegistry {

	/** @var array<string, ActionInterface> keyed by action type */
	private array $actions = array();

	public function register( ActionInterface $action ): void {
		$this->actions[ $action->type() ] = $action;
	}

	public function get( string $type ): ?ActionInterface {
		return $this->actions[ $type ] ?? null;
	}

	public function has( string $type ): bool {
		return isset( $this->actions[ $type ] );
	}

	/**
	 * @return list<string>
	 */
	public function types(): array {
		return array_keys( $this->actions );
	}
}
