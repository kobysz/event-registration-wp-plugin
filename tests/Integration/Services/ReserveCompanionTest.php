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
}
