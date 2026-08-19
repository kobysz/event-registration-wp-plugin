<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Persistence;

use EvReg\Persistence\MailQueueRepository;
use EvReg\Persistence\Migrations;
use WP_UnitTestCase;

final class MailQueueRepositoryTest extends WP_UnitTestCase {

	private MailQueueRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( 'mail_queue' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->repository = new MailQueueRepository();
	}

	/**
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function row( array $overrides = array() ): array {
		return array_merge(
			array(
				'registration_id' => 7,
				'event_id'        => 1,
				'template_key'    => 'optin',
				'recipient'       => 'jan@example.com',
				'subject'         => 'Potwierdź zgłoszenie',
				'body'            => 'Treść',
				'headers'         => '',
				'scheduled_at'    => '2026-08-19 10:00:00',
			),
			$overrides
		);
	}

	/**
	 * Zwraca id ostatnio wstawionego wiersza kolejki.
	 */
	private function last_id(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . Migrations::table( 'mail_queue' ) );
	}

	public function test_insert_creates_queued_row(): void {
		$this->assertTrue( $this->repository->insert( $this->row() ) );

		$row = $this->repository->find( $this->last_id() );

		$this->assertNotNull( $row );
		$this->assertSame( MailQueueRepository::STATUS_QUEUED, $row['status'] );
		$this->assertSame( '0', (string) $row['attempts'] );
		$this->assertSame( 'jan@example.com', $row['recipient'] );
		$this->assertSame( '7', (string) $row['registration_id'] );
	}

	public function test_insert_is_ignored_for_duplicate_registration_and_template(): void {
		$this->assertTrue( $this->repository->insert( $this->row() ) );
		$this->assertFalse( $this->repository->insert( $this->row( array( 'subject' => 'Inny temat' ) ) ) );

		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Migrations::table( 'mail_queue' ) );

		$this->assertSame( 1, $count );
	}

	public function test_insert_allows_same_registration_with_different_template_key(): void {
		$this->assertTrue( $this->repository->insert( $this->row() ) );
		$this->assertTrue( $this->repository->insert( $this->row( array( 'template_key' => 'admin_new:abc' ) ) ) );
		$this->assertTrue( $this->repository->insert( $this->row( array( 'template_key' => 'admin_new:def' ) ) ) );
	}

	public function test_insert_allows_many_rows_without_registration_id(): void {
		$this->assertTrue( $this->repository->insert( $this->row( array( 'registration_id' => null ) ) ) );
		$this->assertTrue( $this->repository->insert( $this->row( array( 'registration_id' => null ) ) ) );

		$row = $this->repository->find( $this->last_id() );

		$this->assertNotNull( $row );
		$this->assertNull( $row['registration_id'] );
	}

	public function test_due_returns_only_queued_rows_scheduled_up_to_now_ordered(): void {
		$this->repository->insert( $this->row( array( 'template_key' => 'a', 'scheduled_at' => '2026-08-19 09:00:00' ) ) );
		$this->repository->insert( $this->row( array( 'template_key' => 'b', 'scheduled_at' => '2026-08-19 08:00:00' ) ) );
		$this->repository->insert( $this->row( array( 'template_key' => 'c', 'scheduled_at' => '2026-08-19 23:00:00' ) ) );

		$due = $this->repository->due( '2026-08-19 10:00:00', 10 );

		$this->assertCount( 2, $due );
		$this->assertSame( array( 'b', 'a' ), array( $due[0]['template_key'], $due[1]['template_key'] ) );
	}

	public function test_due_respects_limit(): void {
		$this->repository->insert( $this->row( array( 'template_key' => 'a' ) ) );
		$this->repository->insert( $this->row( array( 'template_key' => 'b' ) ) );

		$this->assertCount( 1, $this->repository->due( '2026-08-19 10:00:00', 1 ) );
	}

	public function test_claim_marks_row_sending_and_increments_attempts(): void {
		$this->repository->insert( $this->row() );
		$id = $this->last_id();

		$this->assertTrue( $this->repository->claim( $id, '2026-08-19 10:05:00' ) );

		$row = $this->repository->find( $id );

		$this->assertNotNull( $row );
		$this->assertSame( MailQueueRepository::STATUS_SENDING, $row['status'] );
		$this->assertSame( '1', (string) $row['attempts'] );
		$this->assertSame( '2026-08-19 10:05:00', $row['scheduled_at'] );
	}

	public function test_second_claim_of_the_same_row_fails(): void {
		$this->repository->insert( $this->row() );
		$id = $this->last_id();

		$this->assertTrue( $this->repository->claim( $id, '2026-08-19 10:05:00' ) );
		$this->assertFalse( $this->repository->claim( $id, '2026-08-19 10:05:01' ) );
	}

	public function test_mark_sent_records_timestamp(): void {
		$this->repository->insert( $this->row() );
		$id = $this->last_id();
		$this->repository->claim( $id, '2026-08-19 10:05:00' );

		$this->repository->markSent( $id, '2026-08-19 10:05:02' );

		$row = $this->repository->find( $id );

		$this->assertNotNull( $row );
		$this->assertSame( MailQueueRepository::STATUS_SENT, $row['status'] );
		$this->assertSame( '2026-08-19 10:05:02', $row['sent_at'] );
	}

	public function test_reschedule_returns_row_to_queue_with_error(): void {
		$this->repository->insert( $this->row() );
		$id = $this->last_id();
		$this->repository->claim( $id, '2026-08-19 10:05:00' );

		$this->repository->reschedule( $id, '2026-08-19 10:06:00', 'SMTP timeout' );

		$row = $this->repository->find( $id );

		$this->assertNotNull( $row );
		$this->assertSame( MailQueueRepository::STATUS_QUEUED, $row['status'] );
		$this->assertSame( '2026-08-19 10:06:00', $row['scheduled_at'] );
		$this->assertSame( 'SMTP timeout', $row['last_error'] );
		$this->assertSame( '1', (string) $row['attempts'] );
	}

	public function test_mark_failed_keeps_error(): void {
		$this->repository->insert( $this->row() );
		$id = $this->last_id();

		$this->repository->markFailed( $id, 'Nadawca odrzucony' );

		$row = $this->repository->find( $id );

		$this->assertNotNull( $row );
		$this->assertSame( MailQueueRepository::STATUS_FAILED, $row['status'] );
		$this->assertSame( 'Nadawca odrzucony', $row['last_error'] );
	}

	public function test_recover_stale_returns_orphaned_sending_rows_to_queue(): void {
		$this->repository->insert( $this->row( array( 'template_key' => 'stale' ) ) );
		$stale = $this->last_id();
		$this->repository->claim( $stale, '2026-08-19 10:00:00' );

		$this->repository->insert( $this->row( array( 'template_key' => 'fresh' ) ) );
		$fresh = $this->last_id();
		$this->repository->claim( $fresh, '2026-08-19 10:09:00' );

		$recovered = $this->repository->recoverStale( '2026-08-19 10:05:00' );

		$this->assertSame( 1, $recovered );
		$this->assertSame( MailQueueRepository::STATUS_QUEUED, $this->repository->find( $stale )['status'] );
		$this->assertSame( MailQueueRepository::STATUS_SENDING, $this->repository->find( $fresh )['status'] );
	}

	public function test_purge_sent_deletes_only_old_sent_rows(): void {
		$this->repository->insert( $this->row( array( 'template_key' => 'old' ) ) );
		$old = $this->last_id();
		$this->repository->markSent( $old, '2026-06-01 10:00:00' );

		$this->repository->insert( $this->row( array( 'template_key' => 'recent' ) ) );
		$recent = $this->last_id();
		$this->repository->markSent( $recent, '2026-08-18 10:00:00' );

		$this->repository->insert( $this->row( array( 'template_key' => 'broken' ) ) );
		$broken = $this->last_id();
		$this->repository->markFailed( $broken, 'boom' );

		$deleted = $this->repository->purgeSent( '2026-07-20 00:00:00' );

		$this->assertSame( 1, $deleted );
		$this->assertNull( $this->repository->find( $old ) );
		$this->assertNotNull( $this->repository->find( $recent ) );
		$this->assertNotNull( $this->repository->find( $broken ) );
	}

	public function test_find_returns_null_for_unknown_id(): void {
		$this->assertNull( $this->repository->find( 987654 ) );
	}
}
