<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Frontend;

use EvReg\Admin\SettingsScreen;
use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Frontend\Shortcode;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Services\ReservationRequest;
use EvReg\Services\ReservationService;
use WP_UnitTestCase;

final class ShortcodeTest extends WP_UnitTestCase {

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		$this->event_id = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
		( new EventConfigRepository() )->save(
			$this->event_id,
			array(
				'schema' => array(
					'version'  => 1,
					'sections' => array(
						array(
							'key'    => 'dane',
							'title'  => 'Dane',
							'fields' => array(
								array( 'key' => '__type', 'type' => 'radio', 'label' => 'Typ' ),
								array( 'key' => 'email', 'type' => 'email', 'label' => 'E-mail', 'required' => true ),
							),
						),
					),
				),
				'types'         => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 0.0 ) ),
				'accommodation' => array( 'packages' => array(), 'rooms' => array(), 'inventory' => array() ),
			)
		);
	}

	public function test_shortcode_renders_form_for_event(): void {
		$html = Shortcode::render( array( 'event' => (string) $this->event_id ) );

		$this->assertStringContainsString( '<form', $html );
		$this->assertStringContainsString( 'name="evreg_field[email]"', $html );
	}

	public function test_shortcode_shows_success_message_after_reserved(): void {
		$_GET['evreg'] = 'reserved';

		$html = Shortcode::render( array( 'event' => (string) $this->event_id ) );

		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringContainsString( 'evreg-success', $html );

		unset( $_GET['evreg'] );
	}

	public function test_shortcode_without_valid_event_renders_nothing_useful(): void {
		$html = Shortcode::render( array( 'event' => '0' ) );

		$this->assertStringNotContainsString( '<form', $html );
	}

	public function test_confirmed_link_shows_message_not_form(): void {
		$_GET['evreg_confirmed'] = 'confirmed';

		$html = Shortcode::render( array( 'event' => (string) $this->event_id ) );

		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringContainsString( 'Zgłoszenie potwierdzone', $html );
		$this->assertStringContainsString( 'alert alert-success', $html );

		unset( $_GET['evreg_confirmed'] );
	}

	public function test_confirmed_expired_uses_warning_alert(): void {
		$_GET['evreg_confirmed'] = 'expired';

		$html = Shortcode::render( array( 'event' => (string) $this->event_id ) );

		$this->assertStringContainsString( 'alert alert-warning', $html );

		unset( $_GET['evreg_confirmed'] );
	}

	public function test_reserved_success_is_bootstrap_alert(): void {
		$_GET['evreg'] = 'reserved';

		$html = Shortcode::render( array( 'event' => (string) $this->event_id ) );

		$this->assertStringContainsString( 'evreg-success', $html );
		$this->assertStringContainsString( 'alert alert-success', $html );

		unset( $_GET['evreg'] );
	}

	public function test_bootstrap_not_enqueued_by_default(): void {
		delete_option( SettingsScreen::LOAD_BOOTSTRAP_OPTION );

		Shortcode::render( array( 'event' => (string) $this->event_id ) );

		$this->assertTrue( wp_style_is( 'evreg-public', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'evreg-bootstrap', 'enqueued' ) );
	}

	public function test_bootstrap_enqueued_when_option_enabled(): void {
		update_option( SettingsScreen::LOAD_BOOTSTRAP_OPTION, 1 );

		Shortcode::render( array( 'event' => (string) $this->event_id ) );

		$this->assertTrue( wp_style_is( 'evreg-bootstrap', 'enqueued' ) );

		delete_option( SettingsScreen::LOAD_BOOTSTRAP_OPTION );
	}

	public function test_shortcode_renders_exhausted_slot_as_disabled(): void {
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'locks' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
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
				'types'         => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 0.0 ) ),
				'accommodation' => array(
					'packages'  => array( array( 'key' => 'n18', 'label' => 'Noc 18/19' ) ),
					'rooms'     => array( array( 'key' => 'single', 'label' => 'Jednoosobowy' ) ),
					'inventory' => array( array( 'package' => 'n18', 'room' => 'single', 'capacity' => 1, 'price' => 0.0 ) ),
				),
			)
		);

		// Zajmij jedyne miejsce przez serwis — obłożenie ma się liczyć jak w produkcji.
		( new ReservationService( new RegistrationRepository(), new EventConfigRepository() ) )->reserve(
			$this->event_id,
			new ReservationRequest(
				'zajete@example.com',
				'Jan',
				'uczestnik',
				array( 'email' => 'zajete@example.com' ),
				new AccommodationSelection( 'n18', 'single' )
			)
		);

		$html = Shortcode::render( array( 'event' => (string) $this->event_id ) );

		$this->assertMatchesRegularExpression( '/value="n18\|single"[^>]*disabled/', $html );
		$this->assertStringContainsString( '(brak miejsc)', $html );
	}
}
