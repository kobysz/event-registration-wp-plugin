<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Persistence;

use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use WP_UnitTestCase;

final class BookingLabelColumnsTest extends WP_UnitTestCase {

	private RegistrationRepository $repo;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$this->repo     = new RegistrationRepository();
		$this->event_id = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
	}

	public function test_bookings_have_label_snapshot_columns(): void {
		global $wpdb;
		$cols = $wpdb->get_col( 'DESC ' . Migrations::table( 'accommodation_bookings' ), 0 ); // phpcs:ignore WordPress.DB

		$this->assertContains( 'package_label', $cols );
		$this->assertContains( 'room_label', $cols );
	}

	public function test_db_version_is_six(): void {
		$this->assertSame( 6, Migrations::DB_VERSION );
	}

	public function test_insert_stores_label_snapshot(): void {
		$id = $this->repo->insertRegistration(
			array(
				'event_id'    => $this->event_id,
				'type_key'    => 'uczestnik',
				'status'      => 'confirmed',
				'email'       => 'jan@example.com',
				'name'        => 'Jan',
				'token'       => str_repeat( 'd', 32 ),
				'data'        => '{}',
				'price_total' => 0.0,
				'expires_at'  => null,
			)
		);

		$this->repo->insertAccommodationBooking(
			$id,
			new AccommodationSelection( 'pkg_1', 'room_1' ),
			180.0,
			1,
			'Nocleg 18-19 grudnia',
			'Pokój 2-osobowy'
		);

		$booking = $this->repo->findAccommodationBooking( $id );

		$this->assertSame( 'Nocleg 18-19 grudnia', $booking['package_label'] );
		$this->assertSame( 'Pokój 2-osobowy', $booking['room_label'] );
	}

	public function test_labels_default_to_empty_for_legacy_rows(): void {
		$id = $this->repo->insertRegistration(
			array(
				'event_id'    => $this->event_id,
				'type_key'    => 'uczestnik',
				'status'      => 'confirmed',
				'email'       => 'anna@example.com',
				'name'        => 'Anna',
				'token'       => str_repeat( 'e', 32 ),
				'data'        => '{}',
				'price_total' => 0.0,
				'expires_at'  => null,
			)
		);

		// Wywołanie bez etykiet (jak stary kod) nie może wysypać zapisu.
		$this->repo->insertAccommodationBooking( $id, new AccommodationSelection( 'pkg_1', 'room_1' ), 180.0 );

		$booking = $this->repo->findAccommodationBooking( $id );

		$this->assertSame( '', $booking['package_label'] );
		$this->assertSame( '', $booking['room_label'] );
	}
}
