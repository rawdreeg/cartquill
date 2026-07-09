<?php
/**
 * The From-address ↔ verified-domain guard: Resend only accepts sends whose
 * From-domain it has verified, so this decides when Resend may be the sender.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use CartQuill\Deliverability\FromDomainGuard;
use PHPUnit\Framework\TestCase;

final class FromDomainGuardTest extends TestCase {

	public function test_allows_an_exact_domain_match(): void {
		$this->assertTrue( FromDomainGuard::allows( 'hello@example.com', 'example.com' ) );
	}

	public function test_allows_a_subdomain_of_the_verified_domain(): void {
		// Resend verifies example.com → sends from mail.example.com are covered.
		$this->assertTrue( FromDomainGuard::allows( 'hello@mail.example.com', 'example.com' ) );
	}

	public function test_rejects_an_unrelated_domain(): void {
		// The exact 554-bounce case: verify a domain, but From stays on gmail.
		$this->assertFalse( FromDomainGuard::allows( 'owner@gmail.com', 'example.com' ) );
	}

	public function test_rejects_a_suffix_collision_that_is_not_a_real_subdomain(): void {
		// "notexample.com" ends with "example.com" but is a different registrable domain.
		$this->assertFalse( FromDomainGuard::allows( 'hello@notexample.com', 'example.com' ) );
	}

	public function test_rejects_the_parent_of_a_verified_subdomain(): void {
		// Verifying mail.example.com does not authorize example.com.
		$this->assertFalse( FromDomainGuard::allows( 'hello@example.com', 'mail.example.com' ) );
	}

	public function test_is_case_insensitive_and_trims(): void {
		$this->assertTrue( FromDomainGuard::allows( 'Hello@Mail.Example.COM', '  example.com  ' ) );
	}

	public function test_ignores_a_trailing_dot_on_either_side(): void {
		$this->assertTrue( FromDomainGuard::allows( 'hello@example.com.', 'example.com' ) );
		$this->assertTrue( FromDomainGuard::allows( 'hello@example.com', 'example.com.' ) );
	}

	public function test_rejects_an_empty_from_address(): void {
		$this->assertFalse( FromDomainGuard::allows( '', 'example.com' ) );
	}

	public function test_rejects_a_from_address_without_an_at_sign(): void {
		$this->assertFalse( FromDomainGuard::allows( 'not-an-email', 'example.com' ) );
	}

	public function test_rejects_when_no_sending_domain_is_verified(): void {
		$this->assertFalse( FromDomainGuard::allows( 'hello@example.com', '' ) );
	}
}
