<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Frontend;

use EvReg\Domain\Accommodation\NightSelection;
use EvReg\Domain\Capacity\NightOccupancy;
use EvReg\Domain\Registration\RegistrationStatus;
use EvReg\Domain\Schema\FormSchema;
use EvReg\Frontend\EventFormLoader;
use EvReg\Frontend\FormRenderer;
use EvReg\Frontend\Shortcode;
use EvReg\Frontend\SubmissionAssembler;
use EvReg\Frontend\SubmitHandler;
use EvReg\Frontend\SubmitResult;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Services\ReservationService;
use WP_UnitTestCase;

/**
 * Formularz publiczny w trybie „doby”: checkboxy, submit i odtwarzanie błędów.
 */
final class NightFormTest extends WP_UnitTestCase {

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'booking_nights', 'locks', 'mail_queue' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$this->event_id = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
		$this->configure();
	}

	/**
	 * Zapisuje konfigurację eventu w trybie „doby”.
	 *
	 * @param array<string,mixed> $accommodation Nadpisania konfiguracji noclegów.
	 * @param bool                $required      Czy pole noclegu jest wymagane.
	 */
	private function configure( array $accommodation = array(), bool $required = false ): void {
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
								array( 'key' => 'nocleg', 'type' => 'accommodation', 'label' => 'Nocleg', 'required' => $required ),
							),
						),
					),
				),
				'types'         => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 0.0 ) ),
				'accommodation' => array_merge(
					array(
						'mode'              => 'nights',
						'nights'            => array(
							array( 'key' => 'n1819', 'label' => '18/19 XI', 'price' => 80.0, 'seats' => 2 ),
							array( 'key' => 'n1920', 'label' => '19/20 XI', 'price' => 90.0, 'seats' => 4, 'companion_cap' => 1 ),
						),
						'allow_none'        => true,
						'companion_enabled' => true,
						// Stare dane klasyczne: AccommodationConfig::fromArray rzuciłby na tym inwentarzu.
						'inventory'         => array( array( 'package' => 'ghost', 'room' => 'ghost', 'capacity' => 1, 'price' => 1.0 ) ),
					),
					$accommodation
				),
				'settings'      => array( 'global_cap' => null, 'waitlist_enabled' => true ),
			)
		);
	}

	private function schema(): FormSchema {
		$schema = ( new EventFormLoader( new EventConfigRepository() ) )->load( $this->event_id );
		$this->assertNotNull( $schema );

		return $schema;
	}

	private function render( ?SubmitResult $result = null, ?NightOccupancy $occupancy = null ): string {
		return ( new FormRenderer( null, $occupancy ) )->render( $this->schema(), $this->event_id, $result );
	}

	private function handler(): SubmitHandler {
		return new SubmitHandler(
			new EventFormLoader( new EventConfigRepository() ),
			new ReservationService( new RegistrationRepository(), new EventConfigRepository() )
		);
	}

	/**
	 * @param array<int,string> $nights Klucze dób.
	 * @return array<string,mixed>
	 */
	private function post( string $email, array $nights, bool $companion = false ): array {
		$post = array(
			'evreg_field' => array(
				'__type' => 'uczestnik',
				'email'  => $email,
				'nocleg' => array( 'nights' => $nights ),
			),
		);

		if ( $companion ) {
			$post['evreg_companion']      = '1';
			$post['evreg_companion_name'] = 'Anna Kowalska';
		}

		return $post;
	}

	public function test_nights_render_as_checkboxes_under_the_field_namespace(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'type="checkbox" id="evreg-nocleg-night-0" name="evreg_field[nocleg][nights][]" value="n1819"', $html );
		$this->assertStringContainsString( 'name="evreg_field[nocleg][nights][]" value="n1920"', $html );
		$this->assertStringContainsString( '18/19 XI', $html );
		$this->assertMatchesRegularExpression( '/value="n1819"[^>]*data-evreg-night-seats-left="2" data-evreg-night-pairs-left=""/', $html );
		$this->assertMatchesRegularExpression( '/value="n1920"[^>]*data-evreg-night-seats-left="4" data-evreg-night-pairs-left="1"/', $html );
		// Nie ma radio klasycznego trybu ani un-namespaced nazw.
		$this->assertStringNotContainsString( '[slot]', $html );
		$this->assertStringNotContainsString( 'name="nights', $html );
		$this->assertStringContainsString( 'name="evreg_companion"', $html );
	}

	public function test_full_night_is_disabled_with_a_note(): void {
		$html = $this->render( null, new NightOccupancy( array( 'n1819' => 2 ), array() ) );

		$this->assertMatchesRegularExpression( '/value="n1819"[^>]*data-evreg-night-seats-left="0"[^>]*disabled/', $html );
		$this->assertStringContainsString( '<span class="evreg-seats-note" data-evreg-night-note="full">(brak miejsc)</span>', $html );
		$this->assertDoesNotMatchRegularExpression( '/value="n1920"[^>]*disabled/', $html );
	}

	public function test_night_with_seats_but_no_pair_room_is_disabled_only_for_a_pair(): void {
		$occupancy = new NightOccupancy( array( 'n1920' => 2 ), array( 'n1920' => 1 ) );

		$alone = $this->render( null, $occupancy );
		$this->assertDoesNotMatchRegularExpression( '/value="n1920"[^>]*disabled/', $alone );
		$this->assertMatchesRegularExpression( '/value="n1920"[^>]*data-evreg-night-seats-left="2" data-evreg-night-pairs-left="0"/', $alone );
		$this->assertStringContainsString( 'data-evreg-night-note="pair" hidden>(brak miejsc dla dwóch osób)', $alone );

		$pair = $this->render( SubmitResult::invalid( array( 'email' => 'required' ), array( 'evreg_companion' => true ) ), $occupancy );
		$this->assertMatchesRegularExpression( '/value="n1920"[^>]*disabled/', $pair );
		$this->assertStringContainsString( 'data-evreg-night-note="pair">(brak miejsc dla dwóch osób)', $pair );
	}

	public function test_a_ticked_night_that_became_unavailable_is_unticked_with_a_note(): void {
		$result = SubmitResult::invalid(
			array( 'email' => 'required' ),
			array( 'nocleg' => array( 'nights' => array( 'n1819', 'n1920' ) ) )
		);

		$html = $this->render( $result, new NightOccupancy( array( 'n1819' => 2 ), array() ) );

		$this->assertDoesNotMatchRegularExpression( '/value="n1819"[^>]*checked/', $html );
		$this->assertMatchesRegularExpression( '/value="n1920"[^>]*checked/', $html );
		$this->assertStringContainsString( '<p class="evreg-nights-dropped">', $html );
	}

	public function test_submit_extracts_the_selected_night_keys(): void {
		$assembled = ( new SubmissionAssembler() )->assemble(
			$this->schema(),
			$this->post( 'a@example.com', array( 'n1920', 'n1819', 'n1819', 'bogus' ) )
		);

		$this->assertTrue( $assembled->isValid() );
		$request = $assembled->request();
		$this->assertNotNull( $request );
		$this->assertInstanceOf( NightSelection::class, $request->nights );
		$this->assertSame( array( 'n1819', 'n1920' ), $request->nights->keys() );
		$this->assertNull( $request->selection );
		$this->assertSame( array( 'n1819', 'n1920' ), $assembled->values()['nocleg'] );

		$result = $this->handler()->process( $this->event_id, $this->post( 'b@example.com', array( 'n1819' ) ) );
		$this->assertSame( 'reserved', $result->code() );
		$occupancy = ( new RegistrationRepository() )->nightOccupancy( $this->event_id, RegistrationStatus::occupyingValues() );
		$this->assertSame( 1, $occupancy->seatsFor( 'n1819' ) );
	}

	public function test_empty_selection_is_still_a_supplied_selection(): void {
		$assembled = ( new SubmissionAssembler() )->assemble( $this->schema(), $this->post( 'a@example.com', array() ) );

		$this->assertTrue( $assembled->isValid() );
		$request = $assembled->request();
		$this->assertNotNull( $request );
		$this->assertNotNull( $request->nights );
		$this->assertTrue( $request->nights->isEmpty() );
	}

	public function test_required_accommodation_rejects_an_empty_selection(): void {
		$this->configure( array( 'allow_none' => false ) );

		$result = $this->handler()->process( $this->event_id, $this->post( 'a@example.com', array() ) );

		$this->assertSame( 'invalid', $result->code() );
		$this->assertSame( 'required', $result->errors()['nocleg'] ?? null );
		$this->assertSame( array( 'nights' => array() ), $result->submittedValue( 'nocleg' ) );
		$this->assertSame( 'a@example.com', $result->submittedValue( 'email' ) );

		// Pole oznaczone jako wymagane odrzuca pusty wybór także przy allow_none.
		$this->configure( array(), true );
		$result = $this->handler()->process( $this->event_id, $this->post( 'a@example.com', array() ) );
		$this->assertSame( 'required', $result->errors()['nocleg'] ?? null );
	}

	public function test_unknown_keys_only_is_an_invalid_choice(): void {
		$result = $this->handler()->process( $this->event_id, $this->post( 'a@example.com', array( 'bogus' ) ) );

		$this->assertSame( 'invalid', $result->code() );
		$this->assertSame( 'invalid_accommodation', $result->errors()['nocleg'] ?? null );
	}

	public function test_allow_none_accepts_an_empty_selection(): void {
		$result = $this->handler()->process( $this->event_id, $this->post( 'a@example.com', array() ) );

		$this->assertSame( 'reserved', $result->code() );
	}

	public function test_blocking_night_comes_back_as_a_field_error_naming_it(): void {
		$first = $this->handler()->process( $this->event_id, $this->post( 'a@example.com', array( 'n1819' ), true ) );
		$this->assertSame( 'reserved', $first->code() );

		// n1819 ma 2 miejsca, oba zajęte przez parę.
		$result = $this->handler()->process( $this->event_id, $this->post( 'b@example.com', array( 'n1920', 'n1819' ) ) );

		$this->assertSame( 'invalid', $result->code() );
		$this->assertSame( 'night_full:n1819', $result->errors()['nocleg'] ?? null );
		// Odtworzenie z SUROWYCH odpowiedzi: lista kluczy w [nights], nie obiekt.
		$this->assertSame( array( 'nights' => array( 'n1920', 'n1819' ) ), $result->submittedValue( 'nocleg' ) );

		$html = $this->render( $result );
		$this->assertMatchesRegularExpression( '/evreg-error-msg[^>]*>[^<]*18\/19 XI[^<]*nie ma już wolnych miejsc/u', $html );
		$this->assertMatchesRegularExpression( '/value="n1920"[^>]*checked/', $html );
	}

	public function test_a_storage_failure_becomes_a_generic_form_level_failure(): void {
		$long = str_repeat( 'n', 70 );
		$this->configure(
			array(
				'nights' => array(
					array( 'key' => 'n1819', 'label' => '18/19 XI', 'price' => 80.0, 'seats' => 5 ),
					array( 'key' => $long, 'label' => 'Za długi klucz', 'price' => 90.0, 'seats' => 5 ),
				),
			)
		);

		$file = (string) tempnam( sys_get_temp_dir(), 'evreg' );
		$prev = ini_set( 'error_log', $file ); // phpcs:ignore WordPress.PHP.IniSet.Risky

		global $wpdb;
		$wpdb->suppress_errors( true );
		$result = $this->handler()->process( $this->event_id, $this->post( 'a@example.com', array( 'n1819', $long ) ) );
		$wpdb->suppress_errors( false );

		ini_set( 'error_log', false === $prev ? '' : $prev ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		$log = (string) file_get_contents( $file );
		unlink( $file );

		$this->assertSame( 'error', $result->code() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( array(), $result->errors() );
		$this->assertStringContainsString( 'evreg reservation failed', $log );

		$method = new \ReflectionMethod( Shortcode::class, 'resultNotice' );
		$method->setAccessible( true );
		$notice = (string) $method->invoke( null, $result );
		$this->assertStringContainsString( 'evreg-notice', $notice );
		$this->assertStringNotContainsString( 'Brak wolnych miejsc', $notice );
	}

	public function test_a_nights_event_with_stale_classic_data_still_renders(): void {
		// Inwentarz z configure() jest niespójny dla AccommodationConfig — nic w gałęzi
		// „doby” nie może go budować, także Shortcode ani renderer.
		$html = Shortcode::renderForm( $this->event_id );

		$this->assertStringContainsString( 'evreg_field[nocleg][nights][]', $html );
	}
}
