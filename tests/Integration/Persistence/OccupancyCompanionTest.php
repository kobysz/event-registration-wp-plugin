<?php
declare( strict_types=1 );
namespace EvReg\Tests\Integration\Persistence;
use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use WP_UnitTestCase;

final class OccupancyCompanionTest extends WP_UnitTestCase {
	private RegistrationRepository $repo;
	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB
		}
		$this->repo = new RegistrationRepository();
		$this->event_id = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
	}

	public function test_slot_occupancy_sums_seats_and_companions_counted(): void {
		$id = $this->repo->insertRegistration( array(
			'event_id' => $this->event_id, 'type_key' => 'u', 'status' => 'pending',
			'email' => 'a@e.com', 'name' => 'A', 'token' => str_repeat( 'a', 32 ),
			'data' => '{}', 'price_total' => 0.0, 'expires_at' => '2099-01-01 00:00:00',
			'companion' => 1, 'companion_name' => 'Towarzysz T.',
		) );
		$this->repo->insertAccommodationBooking( $id, new AccommodationSelection( 'n12', 'double' ), 360.0, 2 );

		$snap = $this->repo->occupancy( $this->event_id );
		$this->assertSame( 2, $snap->forSlot( 'n12|double' ) );
		$this->assertSame( 1, $snap->companions() );

		$excl = $this->repo->occupancyExcluding( $this->event_id, $id );
		$this->assertSame( 0, $excl->forSlot( 'n12|double' ) );
		$this->assertSame( 0, $excl->companions() );
	}
}
