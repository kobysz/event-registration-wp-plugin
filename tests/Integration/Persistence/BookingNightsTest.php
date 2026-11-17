<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Persistence;

use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use WP_UnitTestCase;

final class BookingNightsTest extends WP_UnitTestCase {

	private const OCCUPYING = array( 'pending', 'confirmed' );

	private RegistrationRepository $repository;

	private int $event_id = 77;

	private int $seq = 0;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'booking_nights', 'locks' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$this->repository = new RegistrationRepository();
	}

	private function register( string $status = 'confirmed' ): int {
		++$this->seq;
		return $this->repository->insertRegistration(
			array(
				'event_id'    => $this->event_id,
				'type_key'    => 'uczestnik',
				'status'      => $status,
				'email'       => 'p' . $this->seq . '@example.com',
				'name'        => 'P' . $this->seq,
				'token'       => str_pad( (string) $this->seq, 32, '0', STR_PAD_LEFT ),
				'data'        => '{}',
				'price_total' => 0.0,
				'expires_at'  => '2099-01-01 00:00:00',
			)
		);
	}

	/**
	 * @return array{key:string,label:string,seats:int,price:float}
	 */
	private function night( string $key, int $seats = 1, float $price = 100.0 ): array {
		return array(
			'key'   => $key,
			'label' => 'Doba ' . $key,
			'seats' => $seats,
			'price' => $price,
		);
	}

	private function rowCount( int $registration_id ): int {
		global $wpdb;
		$table = Migrations::table( 'booking_nights' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE registration_id = %d", $registration_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	public function test_booking_nights_table_exists_after_install(): void {
		global $wpdb;
		$table = Migrations::table( 'booking_nights' );

		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
		$this->assertSame( 7, Migrations::DB_VERSION );
		$this->assertSame(
			array( 'id', 'registration_id', 'night_key', 'night_label', 'seats', 'price' ),
			$wpdb->get_col( 'DESC ' . $table, 0 ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		);
	}

	public function test_night_occupancy_counts_seats_and_pairs(): void {
		$pair = $this->register();
		$a    = $this->register();
		$b    = $this->register();
		$this->repository->replaceBookingNights( $pair, array( $this->night( 'n1', 2 ) ) );
		$this->repository->replaceBookingNights( $a, array( $this->night( 'n1' ), $this->night( 'n2' ) ) );
		$this->repository->replaceBookingNights( $b, array( $this->night( 'n1' ) ) );

		$occupancy = $this->repository->nightOccupancy( $this->event_id, self::OCCUPYING );

		$this->assertSame( 4, $occupancy->seatsFor( 'n1' ) );
		$this->assertSame( 1, $occupancy->pairsFor( 'n1' ) );
		$this->assertSame( 1, $occupancy->seatsFor( 'n2' ) );
		$this->assertSame( 0, $occupancy->pairsFor( 'n2' ) );
		$this->assertSame( 0, $occupancy->seatsFor( 'n9' ) );
	}

	public function test_cancelled_registration_drops_out_of_the_night_occupancy(): void {
		$live      = $this->register( 'confirmed' );
		$cancelled = $this->register( 'cancelled' );
		// Wiersze zostają (nikt ich nie skasował): liczyć ma JOIN na status, nie samo kasowanie.
		$this->repository->replaceBookingNights( $live, array( $this->night( 'n1' ) ) );
		$this->repository->replaceBookingNights( $cancelled, array( $this->night( 'n1', 2 ) ) );

		$occupancy = $this->repository->nightOccupancy( $this->event_id, self::OCCUPYING );

		$this->assertSame( 1, $occupancy->seatsFor( 'n1' ) );
		$this->assertSame( 0, $occupancy->pairsFor( 'n1' ) );
	}

	public function test_exclude_id_drops_the_edited_registration(): void {
		$edited = $this->register();
		$other  = $this->register();
		$this->repository->replaceBookingNights( $edited, array( $this->night( 'n1', 2 ) ) );
		$this->repository->replaceBookingNights( $other, array( $this->night( 'n1' ) ) );

		$occupancy = $this->repository->nightOccupancy( $this->event_id, self::OCCUPYING, $edited );

		$this->assertSame( 1, $occupancy->seatsFor( 'n1' ) );
		$this->assertSame( 0, $occupancy->pairsFor( 'n1' ) );
	}

	public function test_replace_is_idempotent_on_the_same_set(): void {
		$id     = $this->register();
		$nights = array( $this->night( 'n1' ), $this->night( 'n2' ) );

		$this->repository->replaceBookingNights( $id, $nights );
		$this->repository->replaceBookingNights( $id, $nights );

		$this->assertSame( 2, $this->rowCount( $id ) );
		$this->assertSame( 1, $this->repository->nightOccupancy( $this->event_id, self::OCCUPYING )->seatsFor( 'n1' ) );
	}

	public function test_replace_removes_nights_that_left_the_set(): void {
		$id = $this->register();
		$this->repository->replaceBookingNights( $id, array( $this->night( 'n1' ), $this->night( 'n2' ), $this->night( 'n3' ) ) );

		$this->repository->replaceBookingNights( $id, array( $this->night( 'n2', 2, 250.0 ) ) );

		$this->assertSame( 1, $this->rowCount( $id ) );
		$map = $this->repository->bookingNightsFor( array( $id ) );
		$this->assertSame(
			array(
				array(
					'key'   => 'n2',
					'label' => 'Doba n2',
					'price' => 250.0,
					'seats' => 2,
				),
			),
			$map[ $id ]
		);

		$this->repository->deleteBookingNights( $id );
		$this->assertSame( 0, $this->rowCount( $id ) );
		$this->assertSame( array(), $this->repository->bookingNightsFor( array( $id ) ) );
	}

	public function test_event_has_accommodation_sees_both_kinds_of_rows(): void {
		$this->assertFalse( $this->repository->eventHasAccommodation( $this->event_id ) );

		$classic = $this->register();
		$this->repository->insertAccommodationBooking( $classic, new AccommodationSelection( 'n12', 'double', '' ), 180.0 );
		$this->assertTrue( $this->repository->eventHasAccommodation( $this->event_id ) );

		$this->repository->deleteAccommodationBooking( $classic );
		$this->assertFalse( $this->repository->eventHasAccommodation( $this->event_id ) );

		$nights = $this->register();
		$this->repository->replaceBookingNights( $nights, array( $this->night( 'n1' ) ) );
		$this->assertTrue( $this->repository->eventHasAccommodation( $this->event_id ) );

		$this->assertFalse( $this->repository->eventHasAccommodation( $this->event_id + 1 ) );
	}

	public function test_event_with_only_cancelled_registrations_is_not_locked(): void {
		$id = $this->register( 'confirmed' );
		$this->repository->replaceBookingNights( $id, array( $this->night( 'n1' ) ) );
		$this->assertTrue( $this->repository->eventHasAccommodation( $this->event_id ) );

		$this->repository->markCancelled( $id );
		$this->repository->deleteBookingNights( $id );

		$this->assertFalse( $this->repository->eventHasAccommodation( $this->event_id ) );
	}
}
