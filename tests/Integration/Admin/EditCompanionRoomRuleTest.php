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

/**
 * Wyjątek przenoszący adres redirectu, by przerwać przed exit handlera.
 */
final class OccEditRedirectException extends \Exception {

	public string $location;

	public function __construct( string $location ) {
		parent::__construct( 'redirect' );
		$this->location = $location;
	}
}

final class EditCompanionRoomRuleTest extends WP_UnitTestCase {

	private RegistrationRepository $repository;

	private int $admin_id;

	private int $event_id;

	private int $registration_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'locks', 'mail_queue' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$this->repository = new RegistrationRepository();
		Capabilities::grant();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->event_id = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );

		// Konfiguracja BEZ liczby osób — tak wyglądał event, gdy zgłoszenie powstało.
		$this->saveConfig( 0 );

		$this->registration_id = $this->repository->insertRegistration(
			array(
				'event_id'    => $this->event_id,
				'type_key'    => 'uczestnik',
				'status'      => 'pending',
				'email'       => 'jan@example.com',
				'name'        => 'Jan',
				'token'       => str_repeat( 'a', 32 ),
				'data'        => (string) wp_json_encode(
					array(
						'__type' => 'uczestnik',
						'email'  => 'jan@example.com',
						'nocleg' => array( 'package' => 'n18', 'room' => 'double', 'roommate' => '' ),
					)
				),
				'price_total' => 100.0,
				'expires_at'  => '2099-01-01 00:00:00',
				'companion'   => 0,
			)
		);

		$this->repository->insertAccommodationBooking(
			$this->registration_id,
			new AccommodationSelection( 'n18', 'double' ),
			180.0,
			1,
			'Noc 18/19',
			'Dwuosobowy'
		);

		// Organizator oznacza pokój jako dwuosobowy PO fakcie.
		$this->saveConfig( 2 );

		wp_set_current_user( $this->admin_id );
	}

	protected function tearDown(): void {
		remove_all_filters( 'wp_redirect' );
		unset( $_POST['registration'], $_REQUEST['_wpnonce'], $_POST['evreg_field'], $_POST['evreg_companion'], $_POST['evreg_companion_name'] );
		parent::tearDown();
	}

	/**
	 * @param int $occupancy Liczba osób pokoju "double".
	 */
	private function saveConfig( int $occupancy ): void {
		$room = array( 'key' => 'double', 'label' => 'Dwuosobowy' );
		if ( 0 !== $occupancy ) {
			$room['occupancy'] = $occupancy;
		}

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
								array( 'key' => '__type', 'type' => 'radio', 'label' => 'Typ' ),
								array( 'key' => 'email', 'type' => 'email', 'label' => 'E-mail', 'required' => true ),
								array( 'key' => 'nocleg', 'type' => 'accommodation', 'label' => 'Nocleg' ),
							),
						),
					),
				),
				'types'         => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 100.0 ) ),
				'accommodation' => array(
					'packages'          => array( array( 'key' => 'n18', 'label' => 'Noc 18/19' ) ),
					'rooms'             => array( $room ),
					'inventory'         => array( array( 'package' => 'n18', 'room' => 'double', 'capacity' => 10, 'price' => 180.0 ) ),
					'allow_none'        => true,
					'companion_enabled' => true,
				),
				'settings'      => array( 'global_cap' => null, 'waitlist_enabled' => true ),
			)
		);
	}

	private function catchRedirect(): ?OccEditRedirectException {
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new OccEditRedirectException( (string) $location );
			}
		);

		try {
			RegistrationsScreen::handle_edit();
		} catch ( OccEditRedirectException $e ) {
			return $e;
		}

		return null;
	}

	public function test_edit_without_touching_accommodation_passes_for_legacy_row(): void {
		// DECYZJA PARTNERA: pokój dostał liczbę osób PO zapisaniu zgłoszenia, więc
		// poprawka innego pola MUSI przejść. Inaczej admin nie zmieniłby nawet adresu
		// e-mail bez rozstrzygania osoby towarzyszącej.
		$_POST['registration'] = $this->registration_id;
		$_REQUEST['_wpnonce']  = wp_create_nonce( 'evreg_edit_' . $this->registration_id );
		$_POST['evreg_field']  = array(
			'__type' => 'uczestnik',
			'email'  => 'nowy@example.com',
			'nocleg' => array( 'slot' => 'n18|double', 'roommate' => '' ),
		);

		$redirect = $this->catchRedirect();

		$this->assertNotNull( $redirect, 'Zapis bez zmiany noclegu i flagi powinien przejść (PRG).' );
		$this->assertStringContainsString( 'evreg_msg=edited', $redirect->location );
		$this->assertSame( 'nowy@example.com', $this->repository->findById( $this->registration_id )['email'] );
	}

	public function test_edit_changing_the_flag_into_a_consistent_pair_passes(): void {
		// Zmiana jest (flaga 0 → 1), więc reguła się odpala — ale para pokój dwuosobowy
		// + osoba towarzysząca jest spójna, więc zapis przechodzi. To dowód, że reguła
		// nie blokuje na samą obecność zmiany.
		$_POST['registration']        = $this->registration_id;
		$_REQUEST['_wpnonce']         = wp_create_nonce( 'evreg_edit_' . $this->registration_id );
		$_POST['evreg_field']         = array(
			'__type' => 'uczestnik',
			'email'  => 'jan@example.com',
			'nocleg' => array( 'slot' => 'n18|double', 'roommate' => '' ),
		);
		$_POST['evreg_companion']     = '1';
		$_POST['evreg_companion_name'] = 'Anna Kowalska';

		$redirect = $this->catchRedirect();

		$this->assertNotNull( $redirect, 'Zmiana flagi na spójną z pokojem powinna przejść.' );
		$this->assertStringContainsString( 'evreg_msg=edited', $redirect->location );
		$this->assertSame( 1, (int) $this->repository->findById( $this->registration_id )['companion'] );
	}

	public function test_edit_changing_the_slot_into_a_mismatch_is_refused(): void {
		// Niespójna zmiana: pokój jednoosobowy z zaznaczoną osobą towarzyszącą.
		$this->addSingleRoom();

		$_POST['registration']        = $this->registration_id;
		$_REQUEST['_wpnonce']         = wp_create_nonce( 'evreg_edit_' . $this->registration_id );
		$_POST['evreg_field']         = array(
			'__type' => 'uczestnik',
			'email'  => 'jan@example.com',
			'nocleg' => array( 'slot' => 'n18|single', 'roommate' => '' ),
		);
		$_POST['evreg_companion']     = '1';
		$_POST['evreg_companion_name'] = 'Anna Kowalska';

		$redirect = null;
		ob_start();
		try {
			$redirect = $this->catchRedirect();
		} finally {
			$html = (string) ob_get_clean();
		}

		$this->assertNull( $redirect, 'Niespójna zmiana nie powinna przekierowywać (inline re-render).' );
		$this->assertStringContainsString( 'Ten pokój jest jednoosobowy', $html );

		// Zgłoszenie w bazie nietknięte.
		$row = $this->repository->findById( $this->registration_id );
		$this->assertSame( 0, (int) $row['companion'] );
		$this->assertSame( 'double', (string) $this->repository->findAccommodationBooking( $this->registration_id )['room_type_key'] );
	}

	/**
	 * Dokłada do eventu pokój jednoosobowy obok istniejącego dwuosobowego.
	 */
	private function addSingleRoom(): void {
		( new EventConfigRepository() )->save(
			$this->event_id,
			array(
				'accommodation' => array(
					'packages'          => array( array( 'key' => 'n18', 'label' => 'Noc 18/19' ) ),
					'rooms'             => array(
						array( 'key' => 'double', 'label' => 'Dwuosobowy', 'occupancy' => 2 ),
						array( 'key' => 'single', 'label' => 'Jednoosobowy', 'occupancy' => 1 ),
					),
					'inventory'         => array(
						array( 'package' => 'n18', 'room' => 'double', 'capacity' => 10, 'price' => 180.0 ),
						array( 'package' => 'n18', 'room' => 'single', 'capacity' => 10, 'price' => 100.0 ),
					),
					'allow_none'        => true,
					'companion_enabled' => true,
				),
			)
		);
	}
}
