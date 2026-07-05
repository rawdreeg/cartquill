<?php
/**
 * Array-backed Settings for tests.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

final class ArraySettings implements Settings {

	public function __construct(
		private string $from_name = 'Test Store',
		private string $from_email = 'store@example.com',
	) {}

	public function from_name(): string {
		return $this->from_name;
	}

	public function from_email(): string {
		return $this->from_email;
	}
}
