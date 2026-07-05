<?php
/**
 * The WooCommerce dependency gate.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use CartQuill\Support\Requirements;
use PHPUnit\Framework\TestCase;

final class RequirementsTest extends TestCase {

	public function test_satisfied_when_active_and_version_high_enough(): void {
		$this->assertTrue( Requirements::woocommerce_satisfied( true, '8.0' ) );
		$this->assertTrue( Requirements::woocommerce_satisfied( true, '9.4.1' ) );
	}

	public function test_not_satisfied_when_inactive(): void {
		$this->assertFalse( Requirements::woocommerce_satisfied( false, '9.0' ) );
	}

	public function test_not_satisfied_when_version_too_low(): void {
		$this->assertFalse( Requirements::woocommerce_satisfied( true, '7.9' ) );
	}

	public function test_not_satisfied_when_version_unknown(): void {
		$this->assertFalse( Requirements::woocommerce_satisfied( true, null ) );
		$this->assertFalse( Requirements::woocommerce_satisfied( true, '' ) );
	}
}
