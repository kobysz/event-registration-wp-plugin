<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Frontend;

use EvReg\Admin\SettingsScreen;
use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Frontend\EventFormLoader;
use EvReg\Frontend\Shortcode;
use EvReg\Frontend\SubmitHandler;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Services\ReservationRequest;
use EvReg\Services\ReservationService;
use WP_UnitTestCase;

final class ShortcodeTest extends WP_UnitTestCase {

	private int $event_id;

	protected function tearDown(): void {
		// Sprzątanie tu, nie na końcu testu: porażka asercji nie może wycieknąć do kolejnych testów.
		remove_all_filters( 'wp_redirect' );
		$_POST = array();
		parent::tearDown();
	}

	protected function setUp(): void {
		parent::setUp();
		// reserve() otwiera własną transakcję, więc zewnętrzny rollback WP_UnitTestCase
		// jej nie cofa — czyścimy tabele jawnie, jak w ReservationServiceTest.
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'locks' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
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

	private const WAITLIST_NOTICE = 'Wszystkie miejsca są zajęte. Możesz się zapisać — trafisz na listę rezerwową i damy znać, gdy zwolni się miejsce.';

	private const CLOSED_NOTICE = 'Rejestracja zamknięta — brak wolnych miejsc.';

	/**
	 * Zapisuje konfigurację z typami i ustawieniami, zachowując schemat z setUp().
	 *
	 * @param array<int,array<string,mixed>> $types         Typy zgłoszeń.
	 * @param array<string,mixed>            $settings      Ustawienia eventu.
	 * @param array<string,mixed>|null       $accommodation Konfiguracja noclegów (null = pusta klasyczna).
	 */
	private function configure( array $types, array $settings, ?array $accommodation = null ): void {
		$repo   = new EventConfigRepository();
		$config = $repo->get( $this->event_id );
		$repo->save(
			$this->event_id,
			array(
				'schema'        => $config['schema'],
				'types'         => $types,
				'accommodation' => $accommodation ?? array( 'packages' => array(), 'rooms' => array(), 'inventory' => array() ),
				'settings'      => $settings,
			)
		);
	}

	/**
	 * Zajmuje jedyne miejsce typu przez serwis rezerwacji.
	 *
	 * @param string $type  Klucz typu.
	 * @param string $email Adres zgłaszającego.
	 */
	private function take( string $type, string $email = 'zajete@example.com' ): void {
		( new ReservationService( new RegistrationRepository(), new EventConfigRepository() ) )->reserve(
			$this->event_id,
			new ReservationRequest( $email, 'Jan', $type, array( 'email' => $email ) )
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function singleFullType(): array {
		return array( array( 'key' => 'std', 'label' => 'Standard', 'price' => 0.0, 'capacity' => 1 ) );
	}

	private function html(): string {
		return Shortcode::render( array( 'event' => (string) $this->event_id ) );
	}

	public function test_full_event_without_waitlist_renders_notice_and_no_form(): void {
		$this->configure( $this->singleFullType(), array( 'global_cap' => null, 'waitlist_enabled' => false ) );
		$this->take( 'std' );

		$html = $this->html();

		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringContainsString( self::CLOSED_NOTICE, $html );
		$this->assertStringContainsString( 'evreg-full', $html );
		$this->assertStringContainsString( 'alert-warning', $html );
	}

	public function test_full_event_with_waitlist_renders_notice_and_form(): void {
		$this->configure( $this->singleFullType(), array( 'global_cap' => null, 'waitlist_enabled' => true ) );
		$this->take( 'std' );

		$html = $this->html();

		$this->assertStringContainsString( self::WAITLIST_NOTICE, $html );
		$this->assertStringContainsString( 'alert-info', $html );
		$this->assertStringContainsString( '<form', $html );
		$this->assertLessThan( strpos( $html, '<form' ), strpos( $html, self::WAITLIST_NOTICE ) );
	}

	public function test_custom_waitlist_notice_from_settings_wins_over_default(): void {
		$this->configure( $this->singleFullType(), array( 'global_cap' => null, 'waitlist_enabled' => true, 'full_waitlist_notice' => 'Zapisz się na listę' ) );
		$this->take( 'std' );

		$html = $this->html();

		$this->assertStringContainsString( 'Zapisz się na listę', $html );
		$this->assertStringNotContainsString( self::WAITLIST_NOTICE, $html );
	}

	public function test_open_event_renders_no_full_notice(): void {
		$this->configure( $this->singleFullType(), array( 'global_cap' => null, 'waitlist_enabled' => false ) );

		$html = $this->html();

		$this->assertStringNotContainsString( 'evreg-full', $html );
		$this->assertStringContainsString( '<form', $html );
	}

	public function test_one_full_type_with_other_open_renders_no_notice(): void {
		$this->configure(
			array(
				array( 'key' => 'std', 'label' => 'Standard', 'price' => 0.0, 'capacity' => 1 ),
				array( 'key' => 'vip', 'label' => 'VIP', 'price' => 0.0, 'capacity' => 5 ),
			),
			array( 'global_cap' => null, 'waitlist_enabled' => false )
		);
		$this->take( 'std' );

		$html = $this->html();

		$this->assertStringNotContainsString( 'evreg-full', $html );
		$this->assertStringContainsString( '<form', $html );
	}

	public function test_inactive_open_type_does_not_keep_event_open(): void {
		$this->configure(
			array(
				array( 'key' => 'std', 'label' => 'Standard', 'price' => 0.0, 'capacity' => 1 ),
				array( 'key' => 'vip', 'label' => 'VIP', 'price' => 0.0, 'capacity' => 5, 'active' => false ),
			),
			array( 'global_cap' => null, 'waitlist_enabled' => false )
		);
		$this->take( 'std' );

		$html = $this->html();

		$this->assertStringContainsString( self::CLOSED_NOTICE, $html );
		$this->assertStringNotContainsString( '<form', $html );
	}

	public function test_custom_notice_from_settings_wins_over_default(): void {
		$this->configure( $this->singleFullType(), array( 'global_cap' => null, 'waitlist_enabled' => false, 'full_closed_notice' => 'Koniec zapisów' ) );
		$this->take( 'std' );

		$html = $this->html();

		$this->assertStringContainsString( 'Koniec zapisów', $html );
		$this->assertStringNotContainsString( self::CLOSED_NOTICE, $html );
	}

	public function test_blank_custom_notice_falls_back_to_default(): void {
		$this->configure( $this->singleFullType(), array( 'global_cap' => null, 'waitlist_enabled' => false, 'full_closed_notice' => '   ' ) );
		$this->take( 'std' );

		$this->assertStringContainsString( self::CLOSED_NOTICE, $this->html() );
	}

	public function test_closed_after_rejected_post_shows_only_closed_notice(): void {
		add_filter(
			'wp_redirect',
			static function (): never {
				throw new \RuntimeException( 'Unexpected redirect.' );
			}
		);
		$this->configure( $this->singleFullType(), array( 'global_cap' => null, 'waitlist_enabled' => false ) );
		$this->take( 'std' );

		// Wyścig: formularz był otwarty, miejsce zajął ktoś inny, POST dostaje `rejected`.
		$_POST = array(
			'evreg_submit' => '1',
			'evreg_event'  => (string) $this->event_id,
			'evreg-nonce'  => wp_create_nonce( 'evreg_submit_' . $this->event_id ),
			'evreg_hp'     => '',
			'evreg_ts'     => (string) ( time() - 10 ),
			'evreg_field'  => array( '__type' => 'std', 'email' => 'drugi@example.com' ),
		);
		( new SubmitHandler(
			new EventFormLoader( new EventConfigRepository() ),
			new ReservationService( new RegistrationRepository(), new EventConfigRepository() )
		) )->handle();
		$code = SubmitHandler::resultFor( $this->event_id )?->code();

		$html = $this->html();

		$this->assertSame( 'rejected', $code );
		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringNotContainsString( 'Brak wolnych miejsc.', $html );
		$this->assertStringContainsString( self::CLOSED_NOTICE, $html );
	}

	public function test_closed_event_does_not_enqueue_public_script(): void {
		wp_dequeue_script( 'evreg-public' );
		$this->configure( $this->singleFullType(), array( 'global_cap' => null, 'waitlist_enabled' => false ) );
		$this->take( 'std' );

		$this->html();

		$this->assertFalse( wp_script_is( 'evreg-public', 'enqueued' ) );
	}

	public function test_broken_classic_inventory_renders_form_without_notice(): void {
		$this->configure( $this->singleFullType(), array( 'global_cap' => null, 'waitlist_enabled' => false ) );
		$this->take( 'std' );
		// Inwentarz psuje się dopiero po zajęciu miejsca: reserve() sam rzuciłby na takim configu.
		$this->configure(
			$this->singleFullType(),
			array( 'global_cap' => null, 'waitlist_enabled' => false ),
			array(
				'packages'  => array(),
				'rooms'     => array(),
				'inventory' => array( array( 'package' => 'ghost', 'room' => 'ghost', 'capacity' => 1, 'price' => 1.0 ) ),
			)
		);

		$html = $this->html();

		$this->assertStringContainsString( '<form', $html );
		$this->assertStringNotContainsString( 'evreg-full', $html );
	}

	public function test_duplicate_type_key_does_not_fatal_or_render_full_notice(): void {
		$this->configure(
			array(
				array( 'key' => 'std', 'label' => 'A', 'price' => 0.0, 'capacity' => 1 ),
				array( 'key' => 'std', 'label' => 'B', 'price' => 0.0, 'capacity' => 1 ),
			),
			array( 'global_cap' => null, 'waitlist_enabled' => false )
		);

		$html = $this->html();

		$this->assertStringNotContainsString( 'evreg-full', $html );
	}

	public function test_nights_mode_full_event_renders_notice(): void {
		$this->configure(
			$this->singleFullType(),
			array( 'global_cap' => null, 'waitlist_enabled' => false ),
			array(
				'mode'      => 'nights',
				'nights'    => array( array( 'key' => 'n1819', 'label' => '18/19 XI', 'price' => 80.0, 'seats' => 2 ) ),
				'inventory' => array( array( 'package' => 'ghost', 'room' => 'ghost', 'capacity' => 1, 'price' => 1.0 ) ),
			)
		);
		$this->take( 'std' );

		$html = $this->html();

		$this->assertStringContainsString( self::CLOSED_NOTICE, $html );
		$this->assertStringNotContainsString( '<form', $html );
	}
}
