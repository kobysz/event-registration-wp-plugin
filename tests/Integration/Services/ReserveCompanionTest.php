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

final class ReserveCompanionTest extends WP_UnitTestCase {

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
	}

	/**
	 * @param array<string,mixed> $overrides
	 */
	private function configure( array $overrides = array() ): void {
		( new EventConfigRepository() )->save(
			$this->event_id,
			array_merge(
				array(
					'types'         => array(
						array(
							'key'   => 'uczestnik',
							'label' => 'Uczestnik',
							'price' => 100.0,
						),
					),
					'accommodation' => array(
						'packages'               => array(
							array(
								'key'   => 'n12',
								'label' => 'Noc 1–2',
							),
						),
						'rooms'                  => array(
							array(
								'key'   => 'double',
								'label' => '2-os.',
							),
						),
						'inventory'              => array(
							array(
								'package'  => 'n12',
								'room'     => 'double',
								'capacity' => 5,
								'price'    => 180.0,
							),
						),
						'companion_enabled'      => true,
						'companion_counts_event' => false,
					),
					'settings'      => array( 'waitlist_enabled' => true ),
				),
				$overrides
			)
		);
	}

	private function request( string $email, bool $companion, string $companion_name = '', ?AccommodationSelection $sel = null ): ReservationRequest {
		return new ReservationRequest( $email, 'Jan', 'uczestnik', array( 'email' => $email ), $sel, '', $companion, $companion_name );
	}

	/**
	 * Seeds a registration row directly (bypassing reserve()) so it can start on the waitlist —
	 * reserve() never waitlists with a booking already attached, but editAnswers() can leave one
	 * behind on a waitlisted row (spec: waitlist edits skip the capacity gate but still persist
	 * the accommodation selection), which is the scenario promoteFromWaitlist must handle.
	 */
	private function seed_waitlisted( string $email, bool $companion, string $companion_name = '' ): int {
		return $this->repository->insertRegistration(
			array(
				'event_id'       => $this->event_id,
				'type_key'       => 'uczestnik',
				'status'         => 'waitlist',
				'email'          => $email,
				'name'           => 'Jan',
				'token'          => bin2hex( random_bytes( 16 ) ),
				'data'           => '{}',
				'price_total'    => 0.0,
				'expires_at'     => null,
				'companion'      => $companion ? 1 : 0,
				'companion_name' => $companion ? $companion_name : '',
			)
		);
	}

	public function test_reserve_with_companion_stores_flag_seats_and_price(): void {
		$this->configure();

		$result = $this->service->reserve(
			$this->event_id,
			$this->request( 'a@example.com', true, 'Jan T.', new AccommodationSelection( 'n12', 'double' ) )
		);

		$this->assertSame( 'reserved', $result->code );
		$this->assertTrue( $result->accommodationGranted );

		$row = $this->repository->findById( $result->registrationId );
		$this->assertSame( '1', (string) $row['companion'] );
		$this->assertSame( 'Jan T.', $row['companion_name'] );
		$this->assertSame( '460.00', $row['price_total'] ); // 100 typ + 2×180 nocleg

		$booking = $this->repository->findAccommodationBooking( $result->registrationId );
		$this->assertSame( 2, (int) $booking['seats'] );
		$this->assertSame( '360.00', $booking['price'] );

		$occupancy = $this->repository->occupancy( $this->event_id );
		$this->assertSame( 2, $occupancy->forSlot( 'n12|double' ) );
	}

	public function test_reserve_companion_without_room_free_for_two_skips_booking(): void {
		$this->configure(
			array(
				'accommodation' => array(
					'packages'               => array(
						array(
							'key'   => 'n12',
							'label' => 'Noc 1–2',
						),
					),
					'rooms'                  => array(
						array(
							'key'   => 'double',
							'label' => '2-os.',
						),
					),
					'inventory'              => array(
						array(
							'package'  => 'n12',
							'room'     => 'double',
							'capacity' => 1,
							'price'    => 180.0,
						),
					),
					'companion_enabled'      => true,
					'companion_counts_event' => false,
				),
			)
		);

		$result = $this->service->reserve(
			$this->event_id,
			$this->request( 'a@example.com', true, 'Jan T.', new AccommodationSelection( 'n12', 'double' ) )
		);

		$this->assertSame( 'reserved', $result->code );
		$this->assertFalse( $result->accommodationGranted );
		$this->assertSame( 'accommodation_full', $result->reason );

		$row = $this->repository->findById( $result->registrationId );
		$this->assertSame( '1', (string) $row['companion'] );
		$this->assertNull( $this->repository->findAccommodationBooking( $result->registrationId ) );
	}

	public function test_reserve_companion_counts_toward_event_limit_when_enabled(): void {
		$this->configure(
			array(
				'accommodation' => array(
					'packages'               => array(
						array(
							'key'   => 'n12',
							'label' => 'Noc 1–2',
						),
					),
					'rooms'                  => array(
						array(
							'key'   => 'double',
							'label' => '2-os.',
						),
					),
					'inventory'              => array(
						array(
							'package'  => 'n12',
							'room'     => 'double',
							'capacity' => 5,
							'price'    => 180.0,
						),
					),
					'companion_enabled'      => true,
					'companion_counts_event' => true,
				),
				'settings'      => array(
					'global_cap'       => 2,
					'waitlist_enabled' => true,
				),
			)
		);
		$this->service->reserve( $this->event_id, $this->request( 'a@example.com', false ) );

		// a@example.com takes 1 seat; b@example.com brings a companion = 2 more seats -> 1+2=3 > 2.
		$result = $this->service->reserve( $this->event_id, $this->request( 'b@example.com', true, 'Ktoś' ) );

		$this->assertSame( 'waitlisted', $result->code );
		$this->assertSame( 'event_full', $result->reason );
	}

	public function test_reserve_companion_ignored_for_event_limit_when_disabled(): void {
		$this->configure(
			array(
				'accommodation' => array(
					'packages'               => array(
						array(
							'key'   => 'n12',
							'label' => 'Noc 1–2',
						),
					),
					'rooms'                  => array(
						array(
							'key'   => 'double',
							'label' => '2-os.',
						),
					),
					'inventory'              => array(
						array(
							'package'  => 'n12',
							'room'     => 'double',
							'capacity' => 5,
							'price'    => 180.0,
						),
					),
					'companion_enabled'      => true,
					'companion_counts_event' => false,
				),
				'settings'      => array(
					'global_cap'       => 2,
					'waitlist_enabled' => true,
				),
			)
		);
		$this->service->reserve( $this->event_id, $this->request( 'a@example.com', false ) );

		$result = $this->service->reserve( $this->event_id, $this->request( 'b@example.com', true, 'Ktoś' ) );

		$this->assertSame( 'reserved', $result->code );
	}

	public function test_edit_answers_preserves_and_updates_companion_with_seats(): void {
		$this->configure();

		$reserved = $this->service->reserve(
			$this->event_id,
			$this->request( 'a@example.com', true, 'Jan T.', new AccommodationSelection( 'n12', 'double' ) )
		);
		$this->assertSame( 'reserved', $reserved->code );

		// Edit keeps companion=true but changes the companion name; seats/price must stay ×2.
		$result = $this->service->editAnswers(
			$reserved->registrationId,
			$this->request( 'a@example.com', true, 'Janina T.', new AccommodationSelection( 'n12', 'double' ) )
		);

		$this->assertSame( 'edited', $result->code );
		$row = $this->repository->findById( $reserved->registrationId );
		$this->assertSame( '1', (string) $row['companion'] );
		$this->assertSame( 'Janina T.', $row['companion_name'] );
		$this->assertSame( '460.00', $row['price_total'] );

		$booking = $this->repository->findAccommodationBooking( $reserved->registrationId );
		$this->assertSame( 2, (int) $booking['seats'] );

		// Now edit again, dropping the companion — must NOT silently keep companion=1.
		$result2 = $this->service->editAnswers(
			$reserved->registrationId,
			$this->request( 'a@example.com', false )
		);

		$this->assertSame( 'edited', $result2->code );
		$row2 = $this->repository->findById( $reserved->registrationId );
		$this->assertSame( '0', (string) $row2['companion'] );
		$this->assertSame( '', $row2['companion_name'] );
		$this->assertSame( '100.00', $row2['price_total'] );
		$this->assertNull( $this->repository->findAccommodationBooking( $reserved->registrationId ) );
	}

	public function test_promote_from_waitlist_keeps_companion_waitlisted_when_only_one_event_seat_free(): void {
		$this->configure(
			array(
				'accommodation' => array(
					'packages'               => array(
						array(
							'key'   => 'n12',
							'label' => 'Noc 1–2',
						),
					),
					'rooms'                  => array(
						array(
							'key'   => 'double',
							'label' => '2-os.',
						),
					),
					'inventory'              => array(
						array(
							'package'  => 'n12',
							'room'     => 'double',
							'capacity' => 5,
							'price'    => 180.0,
						),
					),
					'companion_enabled'      => true,
					'companion_counts_event' => true,
				),
				'settings'      => array(
					'global_cap'       => 2,
					'waitlist_enabled' => true,
				),
			)
		);

		$this->service->reserve( $this->event_id, $this->request( 'a@example.com', false ) ); // occupies 1/2 event seats.
		$waitlisted = $this->seed_waitlisted( 'b@example.com', true, 'Ktoś' );

		// Promoting the companion registration needs 2 event seats (self + companion);
		// only 1 is free (2 - 1 taken), so it must stay on the waitlist, NOT be promoted
		// as if it needed just 1 seat.
		$result = $this->service->promoteFromWaitlist( $waitlisted );

		$this->assertSame( 'rejected', $result->code );
		$this->assertSame( 'waitlist', $this->repository->findById( $waitlisted )['status'] );
	}

	public function test_promote_from_waitlist_grants_companion_when_two_event_seats_free_and_rebuilds_booking_seats(): void {
		$this->configure(
			array(
				'accommodation' => array(
					'packages'               => array(
						array(
							'key'   => 'n12',
							'label' => 'Noc 1–2',
						),
					),
					'rooms'                  => array(
						array(
							'key'   => 'double',
							'label' => '2-os.',
						),
					),
					'inventory'              => array(
						array(
							'package'  => 'n12',
							'room'     => 'double',
							'capacity' => 5,
							'price'    => 180.0,
						),
					),
					'companion_enabled'      => true,
					'companion_counts_event' => true,
				),
				'settings'      => array(
					'global_cap'       => 3,
					'waitlist_enabled' => true,
				),
			)
		);

		$this->service->reserve( $this->event_id, $this->request( 'a@example.com', false ) ); // occupies 1/3 event seats.
		$waitlisted = $this->seed_waitlisted( 'b@example.com', true, 'Ktoś' );
		// Simulate a booking left over from an earlier waitlist edit, stored with stale
		// seats=1/price=180 (as if companion had been toggled on after the booking was
		// first written) — promotion must rebuild it companion-aware, not trust it as-is.
		$this->repository->insertAccommodationBooking( $waitlisted, new AccommodationSelection( 'n12', 'double' ), 180.0, 1 );

		// 1 (a) + 2 (b + companion) = 3 <= global_cap 3 -> promotable.
		$result = $this->service->promoteFromWaitlist( $waitlisted );

		$this->assertSame( 'promoted', $result->code );
		$row = $this->repository->findById( $waitlisted );
		$this->assertSame( 'pending', $row['status'] );

		$booking = $this->repository->findAccommodationBooking( $waitlisted );
		$this->assertSame( 2, (int) $booking['seats'] );
		$this->assertSame( '360.00', $booking['price'] );
		// price_total synced on promotion: 100 (type) + 360 (2×180 accommodation, granted).
		$this->assertSame( '460.00', $row['price_total'] );
	}

	public function test_promote_from_waitlist_deletes_stale_booking_and_syncs_price_when_room_not_granted(): void {
		$this->configure(
			array(
				'types'         => array(
					array(
						'key'   => 'uczestnik',
						'label' => 'Uczestnik',
						'price' => 100.0,
					),
				),
				'accommodation' => array(
					'packages'               => array(
						array(
							'key'   => 'n12',
							'label' => 'Noc 1–2',
						),
					),
					'rooms'                  => array(
						array(
							'key'   => 'double',
							'label' => '2-os.',
						),
					),
					'inventory'              => array(
						array(
							'package'  => 'n12',
							'room'     => 'double',
							'capacity' => 2,
							'price'    => 180.0,
						),
					),
					'companion_enabled'      => true,
					// Isolate from the global-cap gate: only the slot itself is scarce here.
					'companion_counts_event' => false,
				),
				'settings'      => array( 'waitlist_enabled' => true ),
			)
		);

		// Takes 1 of the 2 seats in n12|double, leaving only 1 free.
		$this->service->reserve(
			$this->event_id,
			$this->request( 'x@example.com', false, '', new AccommodationSelection( 'n12', 'double' ) )
		);

		$waitlisted = $this->seed_waitlisted( 'b@example.com', true, 'Ktoś' );
		// Stale booking left over from an earlier waitlist-stage edit, claiming both seats —
		// as if it had been written back when the room still had 2 free. Only 1 is free now.
		$this->repository->insertAccommodationBooking( $waitlisted, new AccommodationSelection( 'n12', 'double' ), 360.0, 2 );

		$result = $this->service->promoteFromWaitlist( $waitlisted );

		// Only the slot is full (type/global are fine) -> Outcome::Accepted, accommodationGranted=false.
		$this->assertSame( 'promoted', $result->code );
		$row = $this->repository->findById( $waitlisted );
		$this->assertSame( 'pending', $row['status'] );

		// The stale 2-seat booking must be gone — otherwise the slot would be oversold
		// (1 already occupied + 2 phantom = 3 seats claimed in a 2-seat room).
		$this->assertNull( $this->repository->findAccommodationBooking( $waitlisted ) );

		$occupancy = $this->repository->occupancy( $this->event_id );
		$this->assertSame( 1, $occupancy->forSlot( 'n12|double' ) ); // only x@example.com's seat — no oversell.

		// price_total synced to reflect no accommodation granted: 100 (type) + 0.
		$this->assertSame( '100.00', $row['price_total'] );
	}
}
