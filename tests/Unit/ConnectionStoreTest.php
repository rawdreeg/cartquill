<?php
/**
 * ConnectionStore semantics: upsert by service, status, credentials, delete.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use CartQuill\Persistence\ConnectionRecord;
use CartQuill\Persistence\InMemoryConnectionStore;
use PHPUnit\Framework\TestCase;

final class ConnectionStoreTest extends TestCase {

	public function test_saves_and_finds_a_connection_by_service(): void {
		$store = new InMemoryConnectionStore();

		$saved = $store->save(
			new ConnectionRecord( null, 'slack', ConnectionRecord::STATUS_CONNECTED, array( 'webhook_url' => 'https://hooks.slack.com/x' ) )
		);

		$this->assertNotNull( $saved->id );
		$found = $store->find( 'slack' );
		$this->assertNotNull( $found );
		$this->assertSame( 'https://hooks.slack.com/x', $found->credential( 'webhook_url' ) );
		$this->assertTrue( $found->is_configured() );
		$this->assertNull( $store->find( 'sheets' ), 'a service with no connection is null' );
	}

	public function test_saving_the_same_service_upserts_rather_than_duplicating(): void {
		$store = new InMemoryConnectionStore();

		$first = $store->save( new ConnectionRecord( null, 'slack', ConnectionRecord::STATUS_CONNECTED, array( 'webhook_url' => 'a' ) ) );
		$store->save( new ConnectionRecord( null, 'slack', ConnectionRecord::STATUS_CONNECTED, array( 'webhook_url' => 'b' ) ) );

		$this->assertCount( 1, $store->all(), 'one row per service' );
		$this->assertSame( 'b', $store->find( 'slack' )->credential( 'webhook_url' ) );
		$this->assertSame( $first->id, $store->find( 'slack' )->id, 'the row keeps its id across updates' );
	}

	public function test_status_transitions_are_persisted(): void {
		$store = new InMemoryConnectionStore();
		$store->save( new ConnectionRecord( null, 'slack', ConnectionRecord::STATUS_CONNECTED, array( 'webhook_url' => 'x' ) ) );

		$store->save( $store->find( 'slack' )->with_status( ConnectionRecord::STATUS_ERROR ) );

		$this->assertSame( ConnectionRecord::STATUS_ERROR, $store->find( 'slack' )->status );
		$this->assertSame( 'x', $store->find( 'slack' )->credential( 'webhook_url' ), 'credentials survive a status change' );
	}

	public function test_delete_removes_the_connection(): void {
		$store = new InMemoryConnectionStore();
		$store->save( new ConnectionRecord( null, 'slack', ConnectionRecord::STATUS_CONNECTED, array( 'webhook_url' => 'x' ) ) );

		$store->delete( 'slack' );

		$this->assertNull( $store->find( 'slack' ) );
		$this->assertCount( 0, $store->all() );
	}

	public function test_an_empty_connection_is_not_configured(): void {
		$record = new ConnectionRecord( null, 'slack' );
		$this->assertFalse( $record->is_configured() );
		$this->assertSame( ConnectionRecord::STATUS_UNCONFIGURED, $record->status );
	}
}
