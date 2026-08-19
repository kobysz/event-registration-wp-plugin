<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Cron;

use EvReg\Cron\PurgeMailQueue;
use EvReg\Persistence\MailQueueRepository;
use EvReg\Persistence\Migrations;
use WP_UnitTestCase;

final class PurgeMailQueueTest extends WP_UnitTestCase {

	private MailQueueRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( 'mail_queue' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->repository = new MailQueueRepository();
		wp_clear_scheduled_hook( PurgeMailQueue::HOOK );
	}

	protected function tearDown(): void {
		wp_clear_scheduled_hook( PurgeMailQueue::HOOK );
		parent::tearDown();
	}

	/**
	 * Wstawia wiersz i zwraca jego id.
	 *
	 * @param string $template_key Klucz szablonu.
	 */
	private function insert( string $template_key ): int {
		global $wpdb;

		$this->repository->insert(
			array(
				'registration_id' => null,
				'event_id'        => 1,
				'template_key'    => $template_key,
				'recipient'       => 'jan@example.com',
				'subject'         => 'Temat',
				'body'            => 'Treść',
				'headers'         => '',
				'scheduled_at'    => '2020-01-01 00:00:00',
			)
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . Migrations::table( 'mail_queue' ) );
	}

	public function test_schedule_registers_daily_event(): void {
		PurgeMailQueue::register();
		PurgeMailQueue::schedule();

		$this->assertSame( 'daily', wp_get_schedule( PurgeMailQueue::HOOK ) );
	}

	public function test_run_deletes_sent_rows_past_retention(): void {
		$old = $this->insert( 'old' );
		$this->repository->markSent( $old, gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) );

		$fresh = $this->insert( 'fresh' );
		$this->repository->markSent( $fresh, gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );

		$failed = $this->insert( 'failed' );
		$this->repository->markFailed( $failed, 'boom' );

		PurgeMailQueue::run();

		$this->assertNull( $this->repository->find( $old ) );
		$this->assertNotNull( $this->repository->find( $fresh ) );
		$this->assertNotNull( $this->repository->find( $failed ) );
	}

	public function test_retention_is_thirty_days(): void {
		$this->assertSame( 30, PurgeMailQueue::RETENTION_DAYS );
	}
}
