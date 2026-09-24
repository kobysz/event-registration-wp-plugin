<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Admin;

use EvReg\Admin\Capabilities;
use EvReg\Admin\RegistrationsScreen;
use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use WP_UnitTestCase;

final class RegistrationsDetailBookingTest extends WP_UnitTestCase {

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
		$this->event_id   = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );

		( new EventConfigRepository() )->save(
			$this->event_id,
			array(
				'schema'        => array(
					'version'  => 1,
					'sections' => array(
						array(
							'key'    => 'dane',
							'title'  => 'Dane',
							'fields' => array(
								array(
									'key'     => '__type',
									'type'    => 'radio',
									'label'   => 'Typ',
									'options' => array( array( 'value' => 'uczestnik', 'label' => 'Uczestnik' ) ),
								),
								array(
									'key'   => 'nocleg',
									'type'  => 'accommodation',
									'label' => 'Nocleg',
								),
							),
						),
					),
				),
				'types'         => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 0.0 ) ),
				'accommodation' => array(
					'packages'  => array( array( 'key' => 'pkg_1', 'label' => 'Noc 1–2' ) ),
					'rooms'     => array( array( 'key' => 'room_1', 'label' => 'Pokój 2-osobowy', 'roommate_field' => true ) ),
					'inventory' => array(
						array(
							'package'  => 'pkg_1',
							'room'     => 'room_1',
							'capacity' => 5,
							'price'    => 180.0,
						),
					),
				),
			)
		);
	}

	protected function tearDown(): void {
		unset( $_GET['action'], $_GET['id'] );
		parent::tearDown();
	}

	/**
	 * @param array<string,mixed> $selection Wartość odpowiedzi pola noclegowego.
	 */
	private function seed( array $selection, bool $with_booking = true, string $roommate = '' ): int {
		$id = $this->repository->insertRegistration(
			array(
				'event_id'    => $this->event_id,
				'type_key'    => 'uczestnik',
				'status'      => 'confirmed',
				'email'       => 'jan@example.com',
				'name'        => 'Jan',
				'token'       => str_repeat( 'c', 32 ),
				'data'        => (string) wp_json_encode(
					array(
						'__type' => 'uczestnik',
						'nocleg' => $selection,
					)
				),
				'price_total' => 180.0,
				'expires_at'  => null,
			)
		);

		if ( $with_booking ) {
			$this->repository->insertAccommodationBooking(
				$id,
				new AccommodationSelection(
					(string) ( $selection['package'] ?? '' ),
					(string) ( $selection['room'] ?? '' ),
					$roommate
				),
				180.0
			);
		}

		return $id;
	}

	private function renderDetail( int $id ): string {
		$_GET['action'] = 'view';
		$_GET['id']     = $id;

		ob_start();
		RegistrationsScreen::render();

		return (string) ob_get_clean();
	}

	public function test_detail_shows_accommodation_labels_not_raw_keys(): void {
		$id = $this->seed(
			array(
				'package'  => 'pkg_1',
				'room'     => 'room_1',
				'roommate' => '',
			)
		);

		$html = $this->renderDetail( $id );

		$this->assertStringContainsString( 'Noc 1–2', $html );
		$this->assertStringContainsString( 'Pokój 2-osobowy', $html );
		$this->assertStringNotContainsString( 'pkg_1', $html );
		$this->assertStringNotContainsString( 'room_1', $html );
	}

	public function test_detail_shows_roommate_next_to_accommodation_answer(): void {
		$id = $this->seed(
			array(
				'package'  => 'pkg_1',
				'room'     => 'room_1',
				'roommate' => 'Piotr N.',
			),
			true,
			'Piotr N.'
		);

		$html = $this->renderDetail( $id );

		$this->assertStringContainsString( 'Piotr N.', $html );
	}

	public function test_detail_uses_label_snapshot_when_room_removed_from_config(): void {
		// Dokładnie przypadek z produkcji: pokój `room_usuniety` zniknął z konfiguracji
		// po rezerwacji. Snapshot z chwili zapisu utrzymuje czytelność historii.
		$id = $this->repository->insertRegistration(
			array(
				'event_id'    => $this->event_id,
				'type_key'    => 'uczestnik',
				'status'      => 'confirmed',
				'email'       => 'ola@example.com',
				'name'        => 'Ola',
				'token'       => str_repeat( 'f', 32 ),
				'data'        => (string) wp_json_encode(
					array(
						'__type' => 'uczestnik',
						'nocleg' => array(
							'package'  => 'pkg_1',
							'room'     => 'room_usuniety',
							'roommate' => '',
						),
					)
				),
				'price_total' => 180.0,
				'expires_at'  => null,
			)
		);

		$this->repository->insertAccommodationBooking(
			$id,
			new AccommodationSelection( 'pkg_1', 'room_usuniety' ),
			180.0,
			1,
			'Nocleg 18-19 grudnia',
			'Pokój 1-osobowy (zlikwidowany)'
		);

		$html = $this->renderDetail( $id );

		$this->assertStringContainsString( 'Pokój 1-osobowy (zlikwidowany)', $html );
		$this->assertStringNotContainsString( 'room_usuniety', $html );
	}

	public function test_detail_falls_back_to_raw_key_when_unknown(): void {
		// Pakiet usunięty z konfiguracji po zgłoszeniu — lepiej pokazać klucz niż pustkę.
		$id = $this->seed(
			array(
				'package'  => 'pkg_usuniety',
				'room'     => 'room_1',
				'roommate' => '',
			),
			false
		);

		$html = $this->renderDetail( $id );

		$this->assertStringContainsString( 'pkg_usuniety', $html );
		$this->assertStringContainsString( 'Pokój 2-osobowy', $html );
	}
}
