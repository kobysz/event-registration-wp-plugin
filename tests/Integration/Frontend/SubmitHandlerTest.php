<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Frontend;

use EvReg\Frontend\EventFormLoader;
use EvReg\Frontend\SubmitHandler;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Services\ReservationService;
use WP_UnitTestCase;

final class SubmitHandlerTest extends WP_UnitTestCase {

	private SubmitHandler $handler;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
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
								array( 'key' => 'imie', 'type' => 'text', 'label' => 'Imię', 'required' => true ),
								array( 'key' => 'email', 'type' => 'email', 'label' => 'E-mail', 'required' => true ),
							),
						),
					),
				),
				'types'         => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 450.0, 'capacity' => 1 ) ),
				'accommodation' => array( 'packages' => array(), 'rooms' => array(), 'inventory' => array() ),
				'settings'      => array( 'global_cap' => null, 'waitlist_enabled' => true ),
			)
		);

		$this->handler = new SubmitHandler(
			new EventFormLoader( new EventConfigRepository() ),
			new ReservationService( new RegistrationRepository(), new EventConfigRepository() )
		);
	}

	/**
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function post( array $overrides = array() ): array {
		$controls = array(
			'evreg_hp' => '',
			'evreg_ts' => (string) ( time() - 10 ),
		);
		$fields   = array(
			'__type' => 'uczestnik',
			'imie'   => 'Jan',
			'email'  => 'jan@example.com',
		);

		foreach ( $overrides as $key => $value ) {
			if ( array_key_exists( $key, $controls ) || in_array( $key, array( 'evreg_event', 'evreg_submit' ), true ) ) {
				$controls[ $key ] = $value;
			} else {
				$fields[ $key ] = $value;
			}
		}

		return array_merge( $controls, array( 'evreg_field' => $fields ) );
	}

	public function test_valid_submission_reserves(): void {
		$result = $this->handler->process( $this->event_id, $this->post() );

		$this->assertTrue( $result->isSuccess() );
		$this->assertSame( 'reserved', $result->code() );
	}

	public function test_validation_error_returns_invalid_with_values(): void {
		$result = $this->handler->process( $this->event_id, $this->post( array( 'email' => 'zły@@' ) ) );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( 'invalid_email', $result->errors()['email'] );
		$this->assertSame( 'zły@@', $result->submittedValue( 'email' ) );
	}

	public function test_honeypot_filled_is_spam(): void {
		$result = $this->handler->process( $this->event_id, $this->post( array( 'evreg_hp' => 'bot' ) ) );

		$this->assertSame( 'spam', $result->code() );
	}

	public function test_too_fast_is_spam(): void {
		$result = $this->handler->process( $this->event_id, $this->post( array( 'evreg_ts' => (string) time() ) ) );

		$this->assertSame( 'spam', $result->code() );
	}

	public function test_duplicate_email_reported(): void {
		$this->handler->process( $this->event_id, $this->post() );

		$result = $this->handler->process( $this->event_id, $this->post() );

		$this->assertSame( 'duplicate', $result->code() );
	}

	public function test_second_registration_waitlisted_when_type_full(): void {
		$this->handler->process( $this->event_id, $this->post( array( 'email' => 'a@example.com' ) ) );

		$result = $this->handler->process( $this->event_id, $this->post( array( 'email' => 'b@example.com' ) ) );

		$this->assertSame( 'waitlisted', $result->code() );
	}

	public function test_blank_optional_email_does_not_shadow_later_required_email(): void {
		$event_id = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
		( new EventConfigRepository() )->save(
			$event_id,
			array(
				'schema' => array(
					'version'  => 1,
					'sections' => array(
						array(
							'key'    => 'dane',
							'title'  => 'Dane',
							'fields' => array(
								array( 'key' => '__type', 'type' => 'radio', 'label' => 'Typ' ),
								array( 'key' => 'imie', 'type' => 'text', 'label' => 'Imię', 'required' => true ),
								array( 'key' => 'email_opt', 'type' => 'email', 'label' => 'E-mail opcjonalny', 'required' => false ),
								array( 'key' => 'email', 'type' => 'email', 'label' => 'E-mail', 'required' => true ),
							),
						),
					),
				),
				'types'         => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 450.0, 'capacity' => 5 ) ),
				'accommodation' => array( 'packages' => array(), 'rooms' => array(), 'inventory' => array() ),
				'settings'      => array( 'global_cap' => null, 'waitlist_enabled' => true ),
			)
		);

		$submit = static fn () => array(
			'evreg_hp'    => '',
			'evreg_ts'    => (string) ( time() - 10 ),
			'evreg_field' => array(
				'__type'    => 'uczestnik',
				'imie'      => 'Jan',
				'email_opt' => '',
				'email'     => 'second@example.com',
			),
		);

		$result = $this->handler->process( $event_id, $submit() );

		$this->assertTrue( $result->isSuccess(), 'Puste opcjonalne pole e-mail nie powinno przesłaniać wypełnionego wymaganego pola e-mail.' );
		$this->assertNotSame( 'config_error', $result->code() );

		// Ponowne zgłoszenie z tym samym drugim (wypełnionym) e-mailem powinno zostać
		// wykryte jako duplikat, co potwierdza, że rezerwacja użyła 'second@example.com',
		// a nie pustego 'email_opt'.
		$duplicate = $this->handler->process( $event_id, $submit() );

		$this->assertSame( 'duplicate', $duplicate->code() );
	}
}
