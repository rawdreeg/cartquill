<?php
/**
 * Registry of available senders and the active selection.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Sender;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * The extension point for the locked `register_sender()` design: core registers
 * WpMailSender; paid add-ons register additional senders (ResendSender, …) —
 * gated by license — during the `cartquill_register_senders` hook. The store's
 * chosen sender becomes active; the engine always sends through active().
 */
final class SenderRegistry {

	/** @var array<string, SenderInterface> keyed by sender key */
	private array $senders = array();

	public function __construct( private string $active_key = 'wp_mail' ) {}

	public function register( SenderInterface $sender ): void {
		$this->senders[ $sender->key() ] = $sender;
	}

	public function get( string $key ): ?SenderInterface {
		return $this->senders[ $key ] ?? null;
	}

	/**
	 * Select the active sender by key. Ignored if that sender is not registered
	 * (e.g. an add-on that is not installed/licensed), so the engine never falls
	 * off a cliff to a missing sender.
	 */
	public function set_active( string $key ): void {
		if ( isset( $this->senders[ $key ] ) ) {
			$this->active_key = $key;
		}
	}

	public function active_key(): string {
		return isset( $this->senders[ $this->active_key ] ) ? $this->active_key : 'wp_mail';
	}

	/**
	 * The sender the engine should use. Falls back to WpMailSender so core
	 * always has a working transport.
	 */
	public function active(): SenderInterface {
		return $this->senders[ $this->active_key ] ?? $this->senders['wp_mail'] ?? new WpMailSender();
	}

	/**
	 * @return list<string>
	 */
	public function keys(): array {
		return array_keys( $this->senders );
	}
}
