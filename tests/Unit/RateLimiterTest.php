<?php
/**
 * The transient-backed AI rate limiter: fixed window, enforced limit.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CartQuill\Ai\TransientRateLimiter;
use CartQuill\Support\FixedClock;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase {

	/** @var array<string, mixed> */
	private array $store = array();

	protected function setUp(): void {
		Monkey\setUp();
		$this->store = array();
		Functions\when( 'get_transient' )->alias( fn ( $k ) => $this->store[ $k ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( $k, $v ) {
				$this->store[ $k ] = $v;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
	}

	public function test_enforces_the_limit_within_a_fixed_window(): void {
		$clock = new FixedClock( 1000 );
		$rl    = new TransientRateLimiter( 3, 3600, $clock );

		$this->assertTrue( $rl->try_consume() );
		$this->assertTrue( $rl->try_consume() );
		$this->assertTrue( $rl->try_consume() );
		$this->assertFalse( $rl->try_consume(), 'the 4th consume in the window is rejected' );
		$this->assertSame( 0, $rl->remaining() );
		$this->assertSame( 1000 + 3600, $rl->reset_at(), 'the window is anchored at first use' );
	}

	public function test_window_anchor_does_not_slide_as_more_is_consumed(): void {
		$clock = new FixedClock( 1000 );
		$rl    = new TransientRateLimiter( 5, 3600, $clock );

		$rl->try_consume();
		$anchor = $rl->reset_at();

		$clock->advance( 100 ); // time passes within the window
		$rl->try_consume();

		$this->assertSame( $anchor, $rl->reset_at(), 'consuming later never pushes the reset forward' );
	}

	public function test_reset_time_is_hidden_until_something_is_consumed(): void {
		$rl = new TransientRateLimiter( 3, 3600, new FixedClock( 1000 ) );
		$this->assertSame( 0, $rl->reset_at(), 'no open window until the first consume' );
	}

	public function test_the_window_is_shared_across_limiter_instances(): void {
		$clock = new FixedClock( 1000 );
		$a     = new TransientRateLimiter( 2, 3600, $clock );
		$b     = new TransientRateLimiter( 2, 3600, $clock );

		$this->assertTrue( $a->try_consume() );
		$this->assertTrue( $b->try_consume() ); // shares the same stored count
		$this->assertFalse( $a->try_consume(), 'the limit is enforced across instances via the shared store' );
	}

	public function test_allowance_resets_after_the_window_elapses(): void {
		$clock = new FixedClock( 1000 );
		$rl    = new TransientRateLimiter( 1, 3600, $clock );

		$this->assertTrue( $rl->try_consume() );
		$this->assertFalse( $rl->try_consume() );

		$clock->advance( 3601 ); // the window has elapsed
		$this->assertTrue( $rl->try_consume(), 'a fresh window restores the allowance' );
	}
}
