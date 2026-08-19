<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Services;

use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Mail\Subscriber;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Services\ReservationService;
use WP_UnitTestCase;

final class AdminActionsTest extends WP_UnitTestCase {

	private ReservationService $service;

	private RegistrationRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'locks', 'mail_queue' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$this->repository = new RegistrationRepository();
		$this->service    = new ReservationService( $this->repository, new EventConfigRepository() );
	}

	protected function tearDown(): void {
		foreach ( array( 'evreg_registration_reserved', 'evreg_registration_waitlisted', 'evreg_registration_confirmed', 'evreg_registration_expired' ) as $hook ) {
			remove_all_actions( $hook );
		}
		parent::tearDown();
	}

	private function seed( string $status = 'pending' ): int {
		return $this->repository->insertRegistration(
			array(
				'event_id'    => 1,
				'type_key'    => 'uczestnik',
				'status'      => $status,
				'email'       => 'jan@example.com',
				'name'        => 'Jan',
				'token'       => str_repeat( 'a', 32 ),
				'data'        => '{}',
				'price_total' => 0.0,
				'expires_at'  => '2099-01-01 00:00:00',
			)
		);
	}

	/**
	 * Liczy wiersze kolejki maili danego typu.
	 */
	private function mail_count( string $template_key ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::table( 'mail_queue' ) . ' WHERE template_key = %s', $template_key ) );
	}

	public function test_confirm_manually_sets_confirmed_and_sends_no_mail(): void {
		Subscriber::register();
		$id = $this->seed( 'pending' );

		$result = $this->service->confirmManually( $id );

		$this->assertSame( 'confirmed', $result->code );
		$row = $this->repository->findById( $id );
		$this->assertSame( 'confirmed', $row['status'] );
		$this->assertNotNull( $row['confirmed_at'] );
		$this->assertSame( 0, $this->mail_count( 'confirmed' ) );
	}

	public function test_confirm_manually_rejects_non_pending(): void {
		$id = $this->seed( 'waitlist' );

		$result = $this->service->confirmManually( $id );

		$this->assertSame( 'invalid_status', $result->code );
		$this->assertSame( 'waitlist', $this->repository->findById( $id )['status'] );
	}

	public function test_confirm_manually_not_found(): void {
		$this->assertSame( 'not_found', $this->service->confirmManually( 987654 )->code );
	}

	public function test_cancel_releases_seat_and_deletes_booking(): void {
		$id = $this->seed( 'confirmed' );
		$this->repository->insertAccommodationBooking( $id, new AccommodationSelection( 'n12', 'double' ), 100.0 );

		$result = $this->service->cancel( $id );

		$this->assertSame( 'cancelled', $result->code );
		$this->assertSame( 'cancelled', $this->repository->findById( $id )['status'] );
		$this->assertNull( $this->repository->findAccommodationBooking( $id ) );
	}

	public function test_cancel_works_from_waitlist(): void {
		$id = $this->seed( 'waitlist' );

		$this->assertSame( 'cancelled', $this->service->cancel( $id )->code );
	}

	public function test_cancel_rejects_already_cancelled(): void {
		$id = $this->seed( 'cancelled' );

		$this->assertSame( 'invalid_status', $this->service->cancel( $id )->code );
	}
}
