<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Cron;

use EvReg\Cron\ExpirePending;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use WP_UnitTestCase;

final class ExpirePendingTest extends WP_UnitTestCase {

	private RegistrationRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'locks' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$this->repository = new RegistrationRepository();
	}

	/**
	 * @param array<string,mixed> $overrides
	 */
	private function insert( array $overrides ): string {
		$token = bin2hex( random_bytes( 16 ) );
		$this->repository->insertRegistration(
			array_merge(
				array(
					'event_id'    => 1,
					'type_key'    => 'uczestnik',
					'status'      => 'pending',
					'email'       => 'jan@example.com',
					'name'        => 'Jan',
					'token'       => $token,
					'data'        => '{}',
					'price_total' => 0.0,
					'expires_at'  => '2099-01-01 00:00:00',
				),
				$overrides
			)
		);
		return $token;
	}

	public function test_run_cancels_past_due_pending(): void {
		$due    = $this->insert( array( 'expires_at' => '2000-01-01 00:00:00', 'email' => 'due@example.com' ) );
		$future = $this->insert( array( 'expires_at' => '2099-01-01 00:00:00', 'email' => 'future@example.com' ) );

		ExpirePending::run();

		$this->assertSame( 'cancelled', $this->repository->findByToken( $due )['status'] );
		$this->assertSame( 'pending', $this->repository->findByToken( $future )['status'] );
	}

	public function test_interval_is_registered(): void {
		ExpirePending::register();

		$schedules = apply_filters( 'cron_schedules', array() );

		$this->assertArrayHasKey( ExpirePending::INTERVAL, $schedules );
		$this->assertSame( 900, $schedules[ ExpirePending::INTERVAL ]['interval'] );
	}

	public function test_schedule_registers_the_event(): void {
		ExpirePending::register();
		ExpirePending::schedule();

		$this->assertNotFalse( wp_next_scheduled( ExpirePending::HOOK ) );
	}
}
