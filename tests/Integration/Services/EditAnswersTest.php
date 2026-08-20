<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Services;

use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Mail\Subscriber;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Services\ReservationRequest;
use EvReg\Services\ReservationService;
use WP_UnitTestCase;

final class EditAnswersTest extends WP_UnitTestCase {

	private ReservationService $service;

	private RegistrationRepository $repository;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'locks', 'mail_queue' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$config           = new EventConfigRepository();
		$this->repository = new RegistrationRepository();
		$this->service    = new ReservationService( $this->repository, $config );
		$this->event_id   = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );

		$config->save(
			$this->event_id,
			array(
				'types'         => array(
					array( 'key' => 'std', 'label' => 'Standard', 'price' => 100.0, 'capacity' => 10 ),
					array( 'key' => 'vip', 'label' => 'VIP', 'price' => 500.0, 'capacity' => 1 ),
				),
				'accommodation' => array(
					'packages'  => array( array( 'key' => 'n1', 'label' => 'Noc 1' ) ),
					'rooms'     => array( array( 'key' => 'std', 'label' => 'Standard' ) ),
					'inventory' => array(
						array( 'package' => 'n1', 'room' => 'std', 'capacity' => 1, 'price' => 50.0 ),
					),
				),
				// waitlist_enabled=false: przy pełnym typie CapacityCalculator zwraca
				// Outcome::Rejected (nie Waitlisted) — dopiero to twardo blokuje edycję.
				'settings'      => array( 'waitlist_enabled' => false ),
			)
		);
	}

	protected function tearDown(): void {
		foreach ( array( 'evreg_registration_reserved', 'evreg_registration_waitlisted', 'evreg_registration_confirmed', 'evreg_registration_expired' ) as $hook ) {
			remove_all_actions( $hook );
		}
		parent::tearDown();
	}

	private function seed( string $status, string $type_key = 'std', string $token = '' ): int {
		static $counter = 0;
		++$counter;
		$token = '' === $token ? str_pad( (string) $counter, 32, 'a', STR_PAD_LEFT ) : $token;

		return $this->repository->insertRegistration(
			array(
				'event_id'    => $this->event_id,
				'type_key'    => $type_key,
				'status'      => $status,
				'email'       => 'user' . $counter . '@example.com',
				'name'        => 'Jan',
				'token'       => $token,
				'data'        => '{}',
				'price_total' => 0.0,
				'expires_at'  => 'waitlist' === $status ? null : '2099-01-01 00:00:00',
			)
		);
	}

	private function request( string $type_key = 'std', ?AccommodationSelection $sel = null ): ReservationRequest {
		return new ReservationRequest( 'edited@example.com', 'Edited Name', $type_key, array( 'email' => 'edited@example.com' ), $sel );
	}

	public function test_self_exclusion_allows_edit_when_type_full_including_self(): void {
		$edited = $this->seed( 'confirmed', 'std' );
		for ( $i = 0; $i < 9; $i++ ) {
			$this->seed( 'confirmed', 'std' );
		}
		// std now has 10 occupying registrations, including $edited itself.

		$result = $this->service->editAnswers( $edited, $this->request( 'std' ) );

		$this->assertSame( 'edited', $result->code );
	}

	public function test_hard_block_on_full_type(): void {
		$this->seed( 'confirmed', 'vip' ); // fills vip 1/1
		$edited = $this->seed( 'pending', 'std' );

		$result = $this->service->editAnswers( $edited, $this->request( 'vip' ) );

		$this->assertSame( 'capacity_full', $result->code );
		$this->assertSame( 'std', $this->repository->findById( $edited )['type_key'] );
	}

	public function test_hard_block_on_full_type_with_waitlist_enabled_default(): void {
		// waitlist_enabled=true (the production default): CapacityCalculator::decide()
		// returns Outcome::Waitlisted (not Rejected) for a full type. editAnswers must
		// still hard-block on any non-Accepted outcome, mirroring promoteFromWaitlist().
		$config = new EventConfigRepository();
		$config->save(
			$this->event_id,
			array(
				'types'         => array(
					array( 'key' => 'std', 'label' => 'Standard', 'price' => 100.0, 'capacity' => 10 ),
					array( 'key' => 'vip', 'label' => 'VIP', 'price' => 500.0, 'capacity' => 1 ),
				),
				'accommodation' => array(
					'packages'  => array( array( 'key' => 'n1', 'label' => 'Noc 1' ) ),
					'rooms'     => array( array( 'key' => 'std', 'label' => 'Standard' ) ),
					'inventory' => array(
						array( 'package' => 'n1', 'room' => 'std', 'capacity' => 1, 'price' => 50.0 ),
					),
				),
				'settings'      => array( 'waitlist_enabled' => true ),
			)
		);

		$this->seed( 'confirmed', 'vip' ); // fills vip 1/1
		$edited = $this->seed( 'pending', 'std' );

		$result = $this->service->editAnswers( $edited, $this->request( 'vip' ) );

		$this->assertSame( 'capacity_full', $result->code );
		$row = $this->repository->findById( $edited );
		$this->assertSame( 'std', $row['type_key'] );
		$this->assertSame( 'pending', $row['status'] );
	}

	public function test_unknown_type_key_is_invalid(): void {
		$edited = $this->seed( 'pending', 'std' );

		$result = $this->service->editAnswers( $edited, $this->request( 'nope' ) );

		$this->assertSame( 'invalid_status', $result->code );
		$row = $this->repository->findById( $edited );
		$this->assertSame( 'std', $row['type_key'] );
		$this->assertSame( 'pending', $row['status'] );
	}

	public function test_hard_block_on_full_accommodation(): void {
		$other = $this->seed( 'confirmed', 'std' );
		$this->repository->insertAccommodationBooking( $other, new AccommodationSelection( 'n1', 'std' ), 50.0 );
		$edited = $this->seed( 'pending', 'std' );

		$result = $this->service->editAnswers( $edited, $this->request( 'std', new AccommodationSelection( 'n1', 'std' ) ) );

		$this->assertSame( 'accommodation_full', $result->code );
		$this->assertNull( $this->repository->findAccommodationBooking( $edited ) );
	}

	public function test_happy_path_changes_type_and_price(): void {
		$edited = $this->seed( 'pending', 'std' );

		$result = $this->service->editAnswers( $edited, $this->request( 'vip' ) );

		$this->assertSame( 'edited', $result->code );
		$row = $this->repository->findById( $edited );
		$this->assertSame( 'vip', $row['type_key'] );
		$this->assertSame( '500.00', $row['price_total'] );
	}

	public function test_accommodation_swap_replaces_booking(): void {
		$config = new EventConfigRepository();
		$config->save(
			$this->event_id,
			array(
				'types'         => array(
					array( 'key' => 'std', 'label' => 'Standard', 'price' => 100.0, 'capacity' => 10 ),
				),
				'accommodation' => array(
					'packages'  => array( array( 'key' => 'n1', 'label' => 'Noc 1' ) ),
					'rooms'     => array( array( 'key' => 'std', 'label' => 'Standard' ), array( 'key' => 'deluxe', 'label' => 'Deluxe' ) ),
					'inventory' => array(
						array( 'package' => 'n1', 'room' => 'std', 'capacity' => 1, 'price' => 50.0 ),
						array( 'package' => 'n1', 'room' => 'deluxe', 'capacity' => 1, 'price' => 90.0 ),
					),
				),
				'settings'      => array( 'waitlist_enabled' => true ),
			)
		);

		$edited = $this->seed( 'pending', 'std' );
		$this->repository->insertAccommodationBooking( $edited, new AccommodationSelection( 'n1', 'std' ), 50.0 );

		$result = $this->service->editAnswers( $edited, $this->request( 'std', new AccommodationSelection( 'n1', 'deluxe' ) ) );

		$this->assertSame( 'edited', $result->code );
		$booking = $this->repository->findAccommodationBooking( $edited );
		$this->assertSame( 'n1', $booking['package_key'] );
		$this->assertSame( 'deluxe', $booking['room_type_key'] );
	}

	public function test_waitlist_edit_skips_capacity_gate(): void {
		for ( $i = 0; $i < 10; $i++ ) {
			$this->seed( 'confirmed', 'std' ); // fills std 10/10
		}
		$edited = $this->seed( 'waitlist', 'std' );

		$result = $this->service->editAnswers( $edited, $this->request( 'std' ) );

		$this->assertSame( 'edited', $result->code );
		$this->assertSame( 'waitlist', $this->repository->findById( $edited )['status'] );
	}

	public function test_cancelled_is_not_editable(): void {
		$edited = $this->seed( 'cancelled', 'std' );

		$result = $this->service->editAnswers( $edited, $this->request( 'vip' ) );

		$this->assertSame( 'invalid_status', $result->code );
		$this->assertSame( 'std', $this->repository->findById( $edited )['type_key'] );
	}

	public function test_not_found(): void {
		$this->assertSame( 'not_found', $this->service->editAnswers( 999999, $this->request( 'std' ) )->code );
	}

	public function test_edit_enqueues_no_mail(): void {
		Subscriber::register();
		$edited = $this->seed( 'pending', 'std' );

		$result = $this->service->editAnswers( $edited, $this->request( 'vip' ) );

		$this->assertSame( 'edited', $result->code );
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::table( 'mail_queue' ) . ' WHERE registration_id = %d', $edited ) );
		$this->assertSame( 0, $count );
	}
}
