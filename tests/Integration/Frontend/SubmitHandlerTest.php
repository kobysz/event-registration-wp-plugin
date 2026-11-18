<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Frontend;

use EvReg\Admin\Capabilities;
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

	/**
	 * Ustawia surowy POST jak z przeglądarki: z nonce'em, honeypotem i znacznikiem czasu.
	 *
	 * @param array<string,mixed> $fields Pola formularza.
	 * @param string|null         $nonce  Nonce; domyślnie poprawny dla eventu.
	 */
	private function rawPost( array $fields, ?string $nonce = null ): void {
		$_POST = array(
			'evreg_submit' => '1',
			'evreg_event'  => (string) $this->event_id,
			'evreg-nonce'  => $nonce ?? wp_create_nonce( 'evreg_submit_' . $this->event_id ),
			'evreg_hp'     => '',
			'evreg_ts'     => (string) ( time() - 10 ),
			'evreg_field'  => $fields,
		);
	}

	private function fillCounter( string $ip ): void {
		$_SERVER['REMOTE_ADDR'] = $ip;
		delete_transient( 'evreg_rate_' . md5( $ip ) );
		for ( $i = 0; $i < SubmitHandler::rateLimit(); $i++ ) {
			SubmitHandler::recordAttempt();
		}
	}

	private function asEventManager(): void {
		Capabilities::grant();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	protected function tearDown(): void {
		$_POST = array();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_handle_over_limit_reports_rate_limited(): void {
		wp_set_current_user( 0 );
		$this->fillCounter( '203.0.113.21' );
		$this->rawPost( array( '__type' => 'uczestnik', 'imie' => 'Jan', 'email' => 'jan@example.com' ) );

		$this->handler->handle();

		$this->assertSame( 'rate_limited', SubmitHandler::resultFor( $this->event_id )->code() );
	}

	public function test_handle_over_limit_lets_event_manager_through(): void {
		$this->asEventManager();
		$this->fillCounter( '203.0.113.22' );
		$this->rawPost( array() );

		$this->handler->handle();

		$this->assertSame( 'invalid', SubmitHandler::resultFor( $this->event_id )->code() );
	}

	public function test_exempt_submission_does_not_increment_counter(): void {
		$this->asEventManager();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.23';
		delete_transient( 'evreg_rate_' . md5( '203.0.113.23' ) );
		$this->rawPost( array() );

		$this->handler->handle();

		$this->assertEmpty( get_transient( 'evreg_rate_' . md5( '203.0.113.23' ) ) );
	}

	public function test_bad_nonce_still_blocks_event_manager(): void {
		$this->asEventManager();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.24';
		$this->rawPost( array(), 'bzdura' );

		$this->handler->handle();

		$this->assertSame( 'spam', SubmitHandler::resultFor( $this->event_id )->code() );
	}

	public function test_antispam_rejections_stay_spam_not_rate_limited(): void {
		wp_set_current_user( 0 );
		$this->assertSame( 'spam', $this->handler->process( $this->event_id, $this->post( array( 'evreg_hp' => 'bot' ) ) )->code() );
		$this->assertSame( 'spam', $this->handler->process( $this->event_id, $this->post( array( 'evreg_ts' => (string) time() ) ) )->code() );
	}
}
