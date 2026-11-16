<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Services;

use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Services\ReservationRequest;
use EvReg\Services\ReservationService;
use WP_UnitTestCase;

final class RoomOccupancyReservationTest extends WP_UnitTestCase {

	private ReservationService $service;

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
		$this->service    = new ReservationService( $this->repository, new EventConfigRepository() );
		$this->event_id   = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );

		( new EventConfigRepository() )->save(
			$this->event_id,
			array(
				'types'         => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 100.0 ) ),
				'accommodation' => array(
					'packages'          => array( array( 'key' => 'n18', 'label' => 'Noc 18/19' ) ),
					'rooms'             => array(
						array( 'key' => 'single', 'label' => '1-os.', 'occupancy' => 1 ),
						array( 'key' => 'double', 'label' => '2-os.', 'occupancy' => 2 ),
					),
					'inventory'         => array(
						array( 'package' => 'n18', 'room' => 'single', 'capacity' => 2, 'price' => 100.0 ),
						array( 'package' => 'n18', 'room' => 'double', 'capacity' => 3, 'price' => 180.0 ),
					),
					'allow_none'        => true,
					'companion_enabled' => true,
				),
				'settings'      => array( 'global_cap' => null, 'waitlist_enabled' => true ),
			)
		);
	}

	private function request( string $email, string $room, bool $companion ): ReservationRequest {
		return new ReservationRequest(
			$email,
			'Jan',
			'uczestnik',
			array( 'email' => $email ),
			new AccommodationSelection( 'n18', $room ),
			'',
			$companion,
			$companion ? 'Anna Kowalska' : ''
		);
	}

	public function test_double_room_books_two_seats_and_double_price(): void {
		$result = $this->service->reserve( $this->event_id, $this->request( 'a@example.com', 'double', true ) );

		$this->assertSame( 'reserved', $result->code );

		$booking = $this->repository->findAccommodationBooking( (int) $result->registrationId );
		$row     = $this->repository->findById( (int) $result->registrationId );

		$this->assertSame( 2, (int) $booking['seats'] );
		$this->assertSame( '360.00', $booking['price'] );
		$this->assertSame( '460.00', $row['price_total'] ); // 100 typ + 2 × 180
	}

	public function test_double_room_exhausts_its_slot_after_one_booking(): void {
		// Slot ma 3 miejsca, pokój dwuosobowy zdejmuje 2 — zostaje 1, a drugi wniosek potrzebuje 2.
		// Przy błędnym seats=1 zostałyby 2 wolne i drugi wniosek by przeszedł.
		$this->service->reserve( $this->event_id, $this->request( 'a@example.com', 'double', true ) );

		$result = $this->service->reserve( $this->event_id, $this->request( 'b@example.com', 'double', true ) );

		$this->assertSame( 'accommodation_full', $result->code );
		$this->assertNull( $result->registrationId );
	}

	public function test_single_room_books_one_seat_even_with_companion(): void {
		$result = $this->service->reserve( $this->event_id, $this->request( 'a@example.com', 'single', true ) );

		$booking = $this->repository->findAccommodationBooking( (int) $result->registrationId );

		$this->assertSame( 1, (int) $booking['seats'] );
		$this->assertSame( '100.00', $booking['price'] );
	}

	public function test_edit_answers_recomputes_seats_from_new_room(): void {
		$first = $this->service->reserve( $this->event_id, $this->request( 'a@example.com', 'single', false ) );
		$id    = (int) $first->registrationId;

		$this->assertSame( 1, (int) $this->repository->findAccommodationBooking( $id )['seats'] );

		$result = $this->service->editAnswers( $id, $this->request( 'a@example.com', 'double', true ) );

		$this->assertSame( 'edited', $result->code );

		$booking = $this->repository->findAccommodationBooking( $id );
		$row     = $this->repository->findById( $id );

		$this->assertSame( 2, (int) $booking['seats'] );
		$this->assertSame( '360.00', $booking['price'] );
		$this->assertSame( '460.00', $row['price_total'] ); // 100 typ + 2 × 180
	}
}
