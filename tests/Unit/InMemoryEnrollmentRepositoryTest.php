<?php
/**
 * The in-memory enrollment repository behaves like an auto-increment table.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use CartQuill\Persistence\EnrollmentRecord;
use CartQuill\Persistence\InMemoryEnrollmentRepository;
use PHPUnit\Framework\TestCase;

final class InMemoryEnrollmentRepositoryTest extends TestCase {

	public function test_save_assigns_sequential_ids_and_is_retrievable(): void {
		$repo = new InMemoryEnrollmentRepository();

		$first  = $repo->save( new EnrollmentRecord( null, 0, 'a@example.com' ) );
		$second = $repo->save( new EnrollmentRecord( null, 0, 'b@example.com' ) );

		$this->assertSame( 1, $first->id );
		$this->assertSame( 2, $second->id );
		$this->assertSame( EnrollmentRecord::STATUS_ACTIVE, $first->status );
		$this->assertSame( 'a@example.com', $repo->find( 1 )->customer_email );
		$this->assertCount( 2, $repo->all() );
	}

	public function test_find_returns_null_for_unknown_id(): void {
		$this->assertNull( ( new InMemoryEnrollmentRepository() )->find( 42 ) );
	}

	public function test_create_rejects_a_second_active_enrollment(): void {
		$repo = new InMemoryEnrollmentRepository();

		$first  = $repo->create( new EnrollmentRecord( null, 5, 'buyer@example.com' ) );
		$second = $repo->create( new EnrollmentRecord( null, 5, 'buyer@example.com' ) );

		$this->assertNotNull( $first );
		$this->assertNull( $second, 'concurrent creates cannot both make an active enrollment' );
		$this->assertCount( 1, $repo->all() );
	}

	public function test_create_allows_re_enrollment_once_the_prior_run_is_non_active(): void {
		$repo = new InMemoryEnrollmentRepository();

		$first = $repo->create( new EnrollmentRecord( null, 5, 'buyer@example.com' ) );
		$repo->save( $first->with_status( EnrollmentRecord::STATUS_COMPLETED ) );

		$second = $repo->create( new EnrollmentRecord( null, 5, 'buyer@example.com' ) );

		$this->assertNotNull( $second, 'a completed prior run does not block re-enrollment' );
		$this->assertCount( 2, $repo->all() );
	}
}
