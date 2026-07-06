<?php
/**
 * A row in the `connections` table: one external service's status + credentials.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Persistence;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Mirrors the `connections` table. One row per external service (slack, sheets,
 * mailchimp, …). Credentials are an opaque map (webhook URL, API key, service
 * account JSON, …) held decrypted in memory here; the store encrypts them at
 * rest. `status` records the last known connection health.
 */
final class ConnectionRecord {

	public const STATUS_UNCONFIGURED = 'unconfigured';
	public const STATUS_CONNECTED    = 'connected';
	public const STATUS_ERROR        = 'error';

	/**
	 * @param int|null             $id          Row id (null until persisted).
	 * @param string               $service     Service key (e.g. "slack").
	 * @param string               $status      One of the STATUS_* constants.
	 * @param array<string, mixed> $credentials Decrypted credential map.
	 * @param string|null          $updated_at  MySQL datetime last saved.
	 */
	public function __construct(
		public readonly ?int $id,
		public readonly string $service,
		public readonly string $status = self::STATUS_UNCONFIGURED,
		public readonly array $credentials = array(),
		public readonly ?string $updated_at = null,
	) {}

	/**
	 * A single credential value (webhook URL, API key, …), or $default.
	 */
	public function credential( string $key, mixed $default = null ): mixed {
		return $this->credentials[ $key ] ?? $default;
	}

	/**
	 * Whether any credential is stored — the minimum for an action to attempt use.
	 */
	public function is_configured(): bool {
		return array() !== $this->credentials;
	}

	public function with_id( int $id ): self {
		return new self( $id, $this->service, $this->status, $this->credentials, $this->updated_at );
	}

	public function with_status( string $status ): self {
		return new self( $this->id, $this->service, $status, $this->credentials, $this->updated_at );
	}
}
