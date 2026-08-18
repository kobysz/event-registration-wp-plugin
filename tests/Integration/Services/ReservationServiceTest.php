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

final class ReservationServiceTest extends WP_UnitTestCase {

	private ReservationService $service;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'locks' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$this->event_id = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
		$this->service  = new ReservationService( new RegistrationRepository(), new EventConfigRepository() );
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
						array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 450.0, 'capacity' => 2 ),
						array( 'key' => 'online', 'label' => 'Online', 'price' => 150.0 ),
					),
					'accommodation' => array(
						'packages'  => array( array( 'key' => 'n12', 'label' => 'Noc 1–2' ) ),
						'rooms'     => array( array( 'key' => 'double', 'label' => '2-os.' ) ),
						'inventory' => array( array( 'package' => 'n12', 'room' => 'double', 'capacity' => 1, 'price' => 180.0 ) ),
					),
					'settings'      => array( 'global_cap' => 3, 'waitlist_enabled' => true ),
				),
				$overrides
			)
		);
	}

	private function request( string $email, string $type = 'uczestnik', ?AccommodationSelection $sel = null ): ReservationRequest {
		return new ReservationRequest( $email, 'Jan', $type, array( 'email' => $email ), $sel );
	}

	public function test_first_registration_is_reserved_with_price(): void {
		$this->configure();

		$result = $this->service->reserve( $this->event_id, $this->request( 'a@example.com' ) );

		$this->assertSame( 'reserved', $result->code );
		$this->assertGreaterThan( 0, $result->registrationId );
		$this->assertNotEmpty( $result->token );
	}

	public function test_reserved_registration_stores_price_from_type_and_accommodation(): void {
		$this->configure();

		$result = $this->service->reserve(
			$this->event_id,
			$this->request( 'a@example.com', 'uczestnik', new AccommodationSelection( 'n12', 'double' ) )
		);

		global $wpdb;
		$price = $wpdb->get_var(
			$wpdb->prepare( 'SELECT price_total FROM ' . Migrations::table( 'registrations' ) . ' WHERE id = %d', $result->registrationId )
		);
		$this->assertSame( '630.00', $price ); // 450 typ + 180 nocleg
	}

	public function test_duplicate_email_is_rejected_without_storing(): void {
		$this->configure();
		$this->service->reserve( $this->event_id, $this->request( 'dup@example.com' ) );

		$result = $this->service->reserve( $this->event_id, $this->request( 'dup@example.com' ) );

		$this->assertSame( 'duplicate', $result->code );
		$this->assertNull( $result->registrationId );
	}

	public function test_type_full_waitlists_further_registrations(): void {
		$this->configure(); // typ uczestnik capacity=2
		$this->service->reserve( $this->event_id, $this->request( 'a@example.com' ) );
		$this->service->reserve( $this->event_id, $this->request( 'b@example.com' ) );

		$result = $this->service->reserve( $this->event_id, $this->request( 'c@example.com' ) );

		$this->assertSame( 'waitlisted', $result->code );
		$this->assertSame( 'type_full', $result->reason );
	}

	public function test_rejected_when_full_and_waitlist_disabled(): void {
		$this->configure( array( 'settings' => array( 'global_cap' => 1, 'waitlist_enabled' => false ) ) );
		$this->service->reserve( $this->event_id, $this->request( 'a@example.com' ) );

		$result = $this->service->reserve( $this->event_id, $this->request( 'b@example.com' ) );

		$this->assertSame( 'rejected', $result->code );
		$this->assertSame( 'event_full', $result->reason );
	}

	public function test_accommodation_full_accepts_without_room(): void {
		$this->configure(); // slot n12|double capacity=1
		$this->service->reserve( $this->event_id, $this->request( 'a@example.com', 'uczestnik', new AccommodationSelection( 'n12', 'double' ) ) );

		$result = $this->service->reserve( $this->event_id, $this->request( 'b@example.com', 'uczestnik', new AccommodationSelection( 'n12', 'double' ) ) );

		$this->assertSame( 'reserved', $result->code );
		$this->assertFalse( $result->accommodationGranted );
		$this->assertSame( 'accommodation_full', $result->reason );
	}
}
