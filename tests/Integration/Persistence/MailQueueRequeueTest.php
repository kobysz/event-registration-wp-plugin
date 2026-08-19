<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Persistence;

use EvReg\Persistence\MailQueueRepository;
use EvReg\Persistence\Migrations;
use WP_UnitTestCase;

final class MailQueueRequeueTest extends WP_UnitTestCase {

	private MailQueueRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( 'mail_queue' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->repository = new MailQueueRepository();
	}

	/**
	 * Wstawia wiersz o zadanym statusie/atrybutach i zwraca jego id.
	 *
	 * @param string $status  Status docelowy.
	 * @param string $error   last_error.
	 * @param int    $attempts Liczba prób.
	 */
	private function seed( string $status, string $error = 'SMTP down', int $attempts = 3 ): int {
		global $wpdb;

		$this->repository->insert(
			array(
				'registration_id' => null,
				'event_id'        => 1,
				'template_key'    => 'optin',
				'recipient'       => 'jan@example.com',
				'subject'         => 'Temat',
				'body'            => 'Treść',
				'headers'         => '',
				'scheduled_at'    => '2026-08-19 10:00:00',
			)
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$id = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . Migrations::table( 'mail_queue' ) );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Migrations::table( 'mail_queue' ),
			array( 'status' => $status, 'last_error' => $error, 'attempts' => $attempts, 'sent_at' => '2026-08-19 11:00:00' ),
			array( 'id' => $id ),
			array( '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);

		return $id;
	}

	public function test_requeue_resets_failed_row(): void {
		$id = $this->seed( MailQueueRepository::STATUS_FAILED );

		$this->assertTrue( $this->repository->requeueFailed( $id ) );

		$row = $this->repository->find( $id );
		$this->assertSame( MailQueueRepository::STATUS_QUEUED, $row['status'] );
		$this->assertSame( '0', (string) $row['attempts'] );
		$this->assertNull( $row['last_error'] );
		$this->assertNull( $row['sent_at'] );
		$this->assertNotSame( '2026-08-19 10:00:00', $row['scheduled_at'] );
	}

	public function test_requeue_ignores_sent_row(): void {
		$id = $this->seed( MailQueueRepository::STATUS_SENT );

		$this->assertFalse( $this->repository->requeueFailed( $id ) );
		$this->assertSame( MailQueueRepository::STATUS_SENT, $this->repository->find( $id )['status'] );
	}

	public function test_requeue_ignores_queued_and_sending_rows(): void {
		$queued  = $this->seed( MailQueueRepository::STATUS_QUEUED );
		$sending = $this->seed( MailQueueRepository::STATUS_SENDING );

		$this->assertFalse( $this->repository->requeueFailed( $queued ) );
		$this->assertFalse( $this->repository->requeueFailed( $sending ) );
	}

	public function test_requeue_returns_false_for_unknown_id(): void {
		$this->assertFalse( $this->repository->requeueFailed( 987654 ) );
	}
}
