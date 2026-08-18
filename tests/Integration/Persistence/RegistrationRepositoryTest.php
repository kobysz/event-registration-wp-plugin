<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Persistence;

use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use WP_UnitTestCase;

final class RegistrationRepositoryTest extends WP_UnitTestCase {

	private RegistrationRepository $repository;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'locks' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$this->repository = new RegistrationRepository();
		$this->event_id  = 42;
	}

	/**
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function row( array $overrides = array() ): array {
		return array_merge(
			array(
				'event_id'    => $this->event_id,
				'type_key'    => 'uczestnik',
				'status'      => 'pending',
				'email'       => 'jan@example.com',
				'name'        => 'Jan',
				'token'       => str_repeat( 'a', 32 ),
				'data'        => '{}',
				'price_total' => 450.0,
				'expires_at'  => '2099-01-01 00:00:00',
			),
			$overrides
		);
	}

	public function test_insert_returns_id_and_row_is_findable_by_token(): void {
		$id = $this->repository->insertRegistration( $this->row( array( 'token' => 'tok123' . str_repeat( '0', 26 ) ) ) );

		$this->assertGreaterThan( 0, $id );

		$found = $this->repository->findByToken( 'tok123' . str_repeat( '0', 26 ) );
		$this->assertNotNull( $found );
		$this->assertSame( 'jan@example.com', $found['email'] );
	}

	public function test_occupancy_counts_only_pending_and_confirmed(): void {
		$this->repository->insertRegistration( $this->row( array( 'status' => 'pending', 'token' => str_repeat( 'b', 32 ) ) ) );
		$this->repository->insertRegistration( $this->row( array( 'status' => 'confirmed', 'token' => str_repeat( 'c', 32 ) ) ) );
		$this->repository->insertRegistration( $this->row( array( 'status' => 'waitlist', 'token' => str_repeat( 'd', 32 ) ) ) );
		$this->repository->insertRegistration( $this->row( array( 'status' => 'cancelled', 'token' => str_repeat( 'e', 32 ) ) ) );

		$occupancy = $this->repository->occupancy( $this->event_id );

		$this->assertSame( 2, $occupancy->global() );
		$this->assertSame( 2, $occupancy->forType( 'uczestnik' ) );
	}

	public function test_occupancy_counts_accommodation_slots(): void {
		$id = $this->repository->insertRegistration( $this->row( array( 'token' => str_repeat( 'f', 32 ) ) ) );
		$this->repository->insertAccommodationBooking( $id, new AccommodationSelection( 'n12', 'double', 'Anna' ), 180.0 );

		$occupancy = $this->repository->occupancy( $this->event_id );

		$this->assertSame( 1, $occupancy->forSlot( 'n12|double' ) );
	}

	public function test_active_registration_exists_for_duplicate_email(): void {
		$this->repository->insertRegistration( $this->row( array( 'status' => 'pending', 'token' => str_repeat( 'g', 32 ) ) ) );

		$this->assertTrue( $this->repository->activeRegistrationExists( $this->event_id, 'jan@example.com' ) );
		$this->assertFalse( $this->repository->activeRegistrationExists( $this->event_id, 'inny@example.com' ) );
	}

	public function test_cancelled_registration_does_not_count_as_active(): void {
		$this->repository->insertRegistration( $this->row( array( 'status' => 'cancelled', 'token' => str_repeat( 'h', 32 ) ) ) );

		$this->assertFalse( $this->repository->activeRegistrationExists( $this->event_id, 'jan@example.com' ) );
	}

	public function test_mark_confirmed_sets_status_and_timestamp(): void {
		$id = $this->repository->insertRegistration( $this->row( array( 'token' => str_repeat( 'i', 32 ) ) ) );

		$this->repository->markConfirmed( $id );

		$found = $this->repository->findByToken( str_repeat( 'i', 32 ) );
		$this->assertSame( 'confirmed', $found['status'] );
		$this->assertNotNull( $found['confirmed_at'] );
	}

	public function test_expire_pending_cancels_past_due_and_returns_count(): void {
		$this->repository->insertRegistration( $this->row( array( 'status' => 'pending', 'expires_at' => '2000-01-01 00:00:00', 'token' => str_repeat( 'j', 32 ) ) ) );
		$this->repository->insertRegistration( $this->row( array( 'status' => 'pending', 'expires_at' => '2099-01-01 00:00:00', 'token' => str_repeat( 'k', 32 ) ) ) );

		$count = $this->repository->expirePending( '2020-01-01 00:00:00' );

		$this->assertSame( 1, $count );
		$this->assertSame( 'cancelled', $this->repository->findByToken( str_repeat( 'j', 32 ) )['status'] );
		$this->assertSame( 'pending', $this->repository->findByToken( str_repeat( 'k', 32 ) )['status'] );
	}

	public function test_lock_event_runs_without_error_inside_transaction(): void {
		global $wpdb;

		$wpdb->query( 'START TRANSACTION' );
		$this->repository->lockEvent( $this->event_id );
		$wpdb->query( 'COMMIT' );

		// Wiersz-zamek został utworzony.
		$exists = $wpdb->get_var(
			$wpdb->prepare( 'SELECT event_id FROM ' . Migrations::table( 'locks' ) . ' WHERE event_id = %d', $this->event_id )
		);
		$this->assertSame( (string) $this->event_id, (string) $exists );
	}
}
