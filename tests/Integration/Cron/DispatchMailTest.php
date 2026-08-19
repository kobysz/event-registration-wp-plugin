<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Cron;

use EvReg\Cron\DispatchMail;
use EvReg\Mail\MailQueue;
use EvReg\Persistence\MailQueueRepository;
use EvReg\Persistence\Migrations;
use WP_UnitTestCase;

final class DispatchMailTest extends WP_UnitTestCase {

	private MailQueueRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( 'mail_queue' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->repository = new MailQueueRepository();

		wp_clear_scheduled_hook( DispatchMail::HOOK );
		wp_clear_scheduled_hook( DispatchMail::IMMEDIATE_HOOK );
	}

	protected function tearDown(): void {
		remove_all_filters( 'pre_wp_mail' );
		wp_clear_scheduled_hook( DispatchMail::HOOK );
		wp_clear_scheduled_hook( DispatchMail::IMMEDIATE_HOOK );
		parent::tearDown();
	}

	public function test_registers_one_minute_interval(): void {
		DispatchMail::register();

		$schedules = apply_filters( 'cron_schedules', array() );

		$this->assertArrayHasKey( DispatchMail::INTERVAL, $schedules );
		$this->assertSame( 60, $schedules[ DispatchMail::INTERVAL ]['interval'] );
	}

	public function test_schedule_registers_recurring_event(): void {
		DispatchMail::register();
		DispatchMail::schedule();

		$this->assertSame( DispatchMail::INTERVAL, wp_get_schedule( DispatchMail::HOOK ) );
	}

	public function test_schedule_is_not_blocked_by_pending_immediate_event(): void {
		DispatchMail::register();
		wp_schedule_single_event( time() + 5, DispatchMail::IMMEDIATE_HOOK );

		DispatchMail::schedule();

		$this->assertSame( DispatchMail::INTERVAL, wp_get_schedule( DispatchMail::HOOK ) );
	}

	public function test_unschedule_clears_both_hooks(): void {
		DispatchMail::register();
		DispatchMail::schedule();
		wp_schedule_single_event( time() + 5, DispatchMail::IMMEDIATE_HOOK );

		DispatchMail::unschedule();

		$this->assertFalse( wp_next_scheduled( DispatchMail::HOOK ) );
		$this->assertFalse( wp_next_scheduled( DispatchMail::IMMEDIATE_HOOK ) );
	}

	public function test_run_sends_due_mail(): void {
		add_filter( 'pre_wp_mail', static fn (): bool => true );

		$this->repository->insert(
			array(
				'registration_id' => null,
				'event_id'        => 1,
				'template_key'    => 'optin',
				'recipient'       => 'jan@example.com',
				'subject'         => 'Temat',
				'body'            => 'Treść',
				'headers'         => '',
				'scheduled_at'    => '2020-01-01 00:00:00',
			)
		);

		DispatchMail::run();

		$this->assertSame( array(), $this->repository->due( '2099-01-01 00:00:00', 10 ) );
	}

	public function test_immediate_hook_shares_handler(): void {
		DispatchMail::register();

		$this->assertNotFalse( has_action( DispatchMail::IMMEDIATE_HOOK, array( DispatchMail::class, 'run' ) ) );
		$this->assertSame( MailQueue::DISPATCH_HOOK, DispatchMail::IMMEDIATE_HOOK );
	}
}
