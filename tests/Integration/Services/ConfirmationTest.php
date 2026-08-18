<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Services;

use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Services\ReservationService;
use WP_UnitTestCase;

final class ConfirmationTest extends WP_UnitTestCase {

	private ReservationService $service;

	private RegistrationRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'locks' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$this->repository = new RegistrationRepository();
		$this->service    = new ReservationService( $this->repository, new EventConfigRepository() );
	}

	/**
	 * @param array<string,mixed> $overrides
	 */
	private function insert( array $overrides = array() ): string {
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

	public function test_pending_token_confirms(): void {
		$token = $this->insert();

		$result = $this->service->confirm( $token );

		$this->assertSame( 'confirmed', $result->code );
		$this->assertSame( 'confirmed', $this->repository->findByToken( $token )['status'] );
	}

	public function test_already_confirmed_token_reports_already(): void {
		$token = $this->insert( array( 'status' => 'confirmed' ) );

		$this->assertSame( 'already_confirmed', $this->service->confirm( $token )->code );
	}

	public function test_cancelled_token_reports_expired(): void {
		$token = $this->insert( array( 'status' => 'cancelled' ) );

		$this->assertSame( 'expired', $this->service->confirm( $token )->code );
	}

	public function test_waitlist_token_reports_waitlist(): void {
		$token = $this->insert( array( 'status' => 'waitlist' ) );

		$this->assertSame( 'waitlist', $this->service->confirm( $token )->code );
	}

	public function test_unknown_token_reports_not_found(): void {
		$this->assertSame( 'not_found', $this->service->confirm( 'nieistnieje' )->code );
	}
}
