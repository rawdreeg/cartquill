<?php
/**
 * GDPR export/erase round-trip over FlowForge's stored personal data.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Tests\Unit;

use FlowForge\Compliance\ArraySuppressionList;
use FlowForge\Compliance\PersonalData;
use FlowForge\Persistence\EnrollmentRecord;
use FlowForge\Persistence\InMemoryCartCaptureStore;
use FlowForge\Persistence\InMemoryEnrollmentRepository;
use FlowForge\Persistence\InMemoryMessageRepository;
use FlowForge\Persistence\MessageRecord;
use PHPUnit\Framework\TestCase;

final class PersonalDataTest extends TestCase {

	private InMemoryEnrollmentRepository $enrollments;
	private InMemoryMessageRepository $messages;
	private InMemoryCartCaptureStore $captures;
	private ArraySuppressionList $suppression;
	private PersonalData $data;

	protected function setUp(): void {
		$this->enrollments = new InMemoryEnrollmentRepository();
		$this->messages    = new InMemoryMessageRepository();
		$this->captures    = new InMemoryCartCaptureStore();
		$this->suppression = new ArraySuppressionList();
		$this->data        = new PersonalData( $this->enrollments, $this->messages, $this->captures, $this->suppression );

		$this->enrollments->save(
			new EnrollmentRecord( null, 1, 'buyer@example.com', EnrollmentRecord::STATUS_ACTIVE, 0, null, null, 'newsletter' )
		);
		$this->messages->claim(
			new MessageRecord( null, 1, 1, 0, 'buyer@example.com', 'wp_mail', MessageRecord::STATUS_SENT )
		);
		$this->captures->capture( 'buyer@example.com', '2023-01-01 00:00:00' );
		$this->suppression->suppress( 'buyer@example.com', 'unsubscribe' );
	}

	public function test_export_returns_all_data_points_including_consent_source(): void {
		$items  = $this->data->export( 'buyer@example.com' );
		$groups = array_column( $items, 'group' );

		$this->assertContains( 'flowforge_enrollments', $groups );
		$this->assertContains( 'flowforge_messages', $groups );
		$this->assertContains( 'flowforge_cart', $groups );
		$this->assertContains( 'flowforge_suppression', $groups );

		$enrollment_item = $items[ array_search( 'flowforge_enrollments', $groups, true ) ];
		$this->assertStringContainsString( 'newsletter', $enrollment_item['value'], 'consent source is exported' );
	}

	public function test_export_for_unknown_email_is_empty(): void {
		$this->assertSame( array(), $this->data->export( 'nobody@example.com' ) );
	}

	public function test_erase_removes_everything_for_the_email(): void {
		$removed = $this->data->erase( 'buyer@example.com' );

		$this->assertGreaterThan( 0, $removed );
		$this->assertCount( 0, $this->enrollments->for_customer( 'buyer@example.com' ) );
		$this->assertCount( 0, $this->messages->for_recipient( 'buyer@example.com' ) );
		$this->assertNull( $this->captures->find( 'buyer@example.com' ) );
		$this->assertFalse( $this->suppression->is_suppressed( 'buyer@example.com' ) );
		$this->assertSame( array(), $this->data->export( 'buyer@example.com' ) );
	}
}
