<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Admin;

use EvReg\Admin\Capabilities;
use EvReg\Admin\RegistrationsOverview;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use WP_UnitTestCase;

final class RegistrationsOverviewTest extends WP_UnitTestCase {

	private RegistrationRepository $repository;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		Capabilities::grant();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->repository = new RegistrationRepository();
		$this->event_id   = self::factory()->post->create(
			array(
				'post_type'  => 'evreg_event',
				'post_title' => 'Zjazd 2026',
			)
		);

		( new EventConfigRepository() )->save(
			$this->event_id,
			array(
				'types'         => array(
					array(
						'key'      => 'uczestnik',
						'label'    => 'Uczestnik',
						'price'    => 100.0,
						'capacity' => 10,
					),
				),
				'accommodation' => array(
					'packages'  => array( array( 'key' => 'n12', 'label' => 'Noc 1–2' ) ),
					'rooms'     => array( array( 'key' => 'double', 'label' => 'Pokój 2-osobowy' ) ),
					'inventory' => array(
						array(
							'package'  => 'n12',
							'room'     => 'double',
							'capacity' => 5,
							'price'    => 180.0,
						),
					),
				),
				'settings'      => array( 'global_cap' => 20 ),
			)
		);
	}

	private function seed( string $status, float $price = 100.0 ): int {
		return $this->repository->insertRegistration(
			array(
				'event_id'    => $this->event_id,
				'type_key'    => 'uczestnik',
				'status'      => $status,
				'email'       => $status . wp_rand( 1, 99999 ) . '@example.com',
				'name'        => 'Jan',
				'token'       => bin2hex( random_bytes( 16 ) ),
				'data'        => '{}',
				'price_total' => $price,
				'expires_at'  => null,
			)
		);
	}

	private function renderFor( int $event_id ): string {
		ob_start();
		RegistrationsOverview::render( $event_id );
		return (string) ob_get_clean();
	}

	public function test_hint_shown_without_event_selected(): void {
		$html = $this->renderFor( 0 );

		$this->assertStringContainsString( 'Wybierz wydarzenie', $html );
		$this->assertStringNotContainsString( 'Przegląd:', $html );
	}

	public function test_summary_counts_occupying_waitlist_and_cancelled(): void {
		$this->seed( 'confirmed' );
		$this->seed( 'pending' );
		$this->seed( 'waitlist' );
		$this->seed( 'cancelled' );

		$html = $this->renderFor( $this->event_id );

		$this->assertStringContainsString( 'Przegląd: Zjazd 2026', $html );
		// 2 zajmują miejsce (confirmed+pending) z limitu 20, wolne 18.
		$this->assertStringContainsString( '2 z 20 (wolne: 18)', $html );
		// Suma kwot tylko za zajmujące miejsce (waitlist/cancelled pominięte).
		$this->assertStringContainsString( '200.00', $html );
	}

	public function test_type_and_accommodation_rows_rendered(): void {
		$id = $this->seed( 'confirmed' );
		$this->repository->insertAccommodationBooking(
			$id,
			new \EvReg\Domain\Accommodation\AccommodationSelection( 'n12', 'double' ),
			360.0,
			2
		);

		$html = $this->renderFor( $this->event_id );

		$this->assertStringContainsString( 'Uczestnik', $html );
		$this->assertStringContainsString( '1 z 10 (wolne: 9)', $html );
		$this->assertStringContainsString( 'Noc 1–2 — Pokój 2-osobowy', $html );
		// Companion zajmuje 2 miejsca noclegowe z 5.
		$this->assertStringContainsString( '2 z 5 (wolne: 3)', $html );
	}
}
