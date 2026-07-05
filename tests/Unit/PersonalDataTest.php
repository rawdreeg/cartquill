<?php
/**
 * GDPR export/erase round-trip over CartQuill's stored personal data.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use CartQuill\Compliance\ArraySuppressionList;
use CartQuill\Compliance\PersonalData;
use CartQuill\Persistence\AttributionRecord;
use CartQuill\Persistence\EnrollmentRecord;
use CartQuill\Persistence\InMemoryAttributionRepository;
use CartQuill\Persistence\InMemoryCartCaptureStore;
use CartQuill\Persistence\InMemoryEnrollmentRepository;
use CartQuill\Persistence\InMemoryMessageRepository;
use CartQuill\Persistence\MessageRecord;
use PHPUnit\Framework\TestCase;

final class PersonalDataTest extends TestCase {

	private InMemoryEnrollmentRepository $enrollments;
	private InMemoryMessageRepository $messages;
	private InMemoryCartCaptureStore $captures;
	private ArraySuppressionList $suppression;
	private InMemoryAttributionRepository $attributions;
	private PersonalData $data;

	protected function setUp(): void {
		$this->enrollments  = new InMemoryEnrollmentRepository();
		$this->messages     = new InMemoryMessageRepository();
		$this->captures     = new InMemoryCartCaptureStore();
		$this->suppression  = new ArraySuppressionList();
		$this->attributions = new InMemoryAttributionRepository();
		$this->data         = new PersonalData( $this->enrollments, $this->messages, $this->captures, $this->suppression, $this->attributions );

		$this->enrollments->save(
			new EnrollmentRecord( null, 1, 'buyer@example.com', EnrollmentRecord::STATUS_ACTIVE, 0, null, null, 'newsletter' )
		);
		$message = $this->messages->claim(
			new MessageRecord( null, 1, 1, 0, 'buyer@example.com', 'wp_mail', MessageRecord::STATUS_SENT )
		);
		$this->attributions->record(
			new AttributionRecord( null, 900, 1, (int) $message->id, 49.99, '2023-01-02 00:00:00' )
		);
		$this->captures->capture( 'buyer@example.com', '2023-01-01 00:00:00' );
		$this->suppression->suppress( 'buyer@example.com', 'unsubscribe' );
	}

	public function test_erase_anonymizes_attributions_keeping_revenue_without_the_personal_link(): void {
		$this->data->erase( 'buyer@example.com' );

		$rows = $this->attributions->all();
		$this->assertCount( 1, $rows, 'the revenue record is retained' );
		$this->assertNull( $rows[0]->message_id, 'its personal link (message_id) is severed' );
		$this->assertSame( 49.99, $rows[0]->revenue );
	}

	public function test_export_returns_all_data_points_including_consent_source(): void {
		$items  = $this->data->export( 'buyer@example.com' );
		$groups = array_column( $items, 'group' );

		$this->assertContains( 'cartquill_enrollments', $groups );
		$this->assertContains( 'cartquill_messages', $groups );
		$this->assertContains( 'cartquill_cart', $groups );
		$this->assertContains( 'cartquill_suppression', $groups );

		$enrollment_item = $items[ array_search( 'cartquill_enrollments', $groups, true ) ];
		$this->assertStringContainsString( 'newsletter', $enrollment_item['value'], 'consent source is exported' );
	}

	public function test_export_for_unknown_email_is_empty(): void {
		$this->assertSame( array(), $this->data->export( 'nobody@example.com' ) );
	}

	public function test_erase_removes_data_but_retains_the_opt_out(): void {
		$removed = $this->data->erase( 'buyer@example.com' );

		$this->assertGreaterThan( 0, $removed );
		$this->assertCount( 0, $this->enrollments->for_customer( 'buyer@example.com' ) );
		$this->assertCount( 0, $this->messages->for_recipient( 'buyer@example.com' ) );
		$this->assertNull( $this->captures->find( 'buyer@example.com' ) );

		// Suppression is deliberately retained so a re-added address stays opted out.
		$this->assertTrue( $this->suppression->is_suppressed( 'buyer@example.com' ) );
		$this->assertTrue( $this->data->is_suppressed( 'buyer@example.com' ) );
	}
}
