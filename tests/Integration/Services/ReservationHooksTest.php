<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Services;

use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Services\ReservationRequest;
use EvReg\Services\ReservationService;
use WP_UnitTestCase;

final class ReservationHooksTest extends WP_UnitTestCase {

	private ReservationService $service;

	private int $event_id;

	/**
	 * Zebrane wywołania hooków.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $captured = array();

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'locks' ) as $table ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$config         = new EventConfigRepository();
		$this->service  = new ReservationService( new RegistrationRepository(), $config );
		$this->event_id = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
		$this->captured = array();

		$config->save(
			$this->event_id,
			array(
				'types'    => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 0.0, 'capacity' => 1 ) ),
				'settings' => array( 'waitlist_enabled' => true ),
			)
		);

		foreach ( array( 'evreg_registration_reserved', 'evreg_registration_waitlisted', 'evreg_registration_confirmed' ) as $hook ) {
			add_action(
				$hook,
				function ( int $registration_id, int $event_id ) use ( $hook ): void {
					$this->captured[] = array(
						'hook'  => $hook,
						'id'    => $registration_id,
						'event' => $event_id,
					);
				},
				10,
				2
			);
		}
	}

	protected function tearDown(): void {
		foreach ( array( 'evreg_registration_reserved', 'evreg_registration_waitlisted', 'evreg_registration_confirmed' ) as $hook ) {
			remove_all_actions( $hook );
		}
		parent::tearDown();
	}

	private function request( string $email ): ReservationRequest {
		return new ReservationRequest( $email, 'Jan', 'uczestnik', array( '__type' => 'uczestnik' ) );
	}

	public function test_reserve_fires_reserved_hook_with_token(): void {
		$token = null;
		add_action(
			'evreg_registration_reserved',
			static function ( int $id, int $event_id, string $passed ) use ( &$token ): void {
				$token = $passed;
			},
			10,
			3
		);

		$result = $this->service->reserve( $this->event_id, $this->request( 'jan@example.com' ) );

		$this->assertSame( 'reserved', $result->code );
		$this->assertSame( $result->token, $token );
		$this->assertSame(
			array( array( 'hook' => 'evreg_registration_reserved', 'id' => $result->registrationId, 'event' => $this->event_id ) ),
			$this->captured
		);
	}

	public function test_waitlisted_reservation_fires_waitlisted_hook(): void {
		$this->service->reserve( $this->event_id, $this->request( 'pierwszy@example.com' ) );
		$this->captured = array();

		$result = $this->service->reserve( $this->event_id, $this->request( 'drugi@example.com' ) );

		$this->assertSame( 'waitlisted', $result->code );
		$this->assertSame( 'evreg_registration_waitlisted', $this->captured[0]['hook'] );
		$this->assertSame( $result->registrationId, $this->captured[0]['id'] );
	}

	public function test_confirm_fires_confirmed_hook_once(): void {
		$result = $this->service->reserve( $this->event_id, $this->request( 'jan@example.com' ) );
		$this->captured = array();

		$this->service->confirm( (string) $result->token );
		$this->service->confirm( (string) $result->token );

		$this->assertCount( 1, $this->captured );
		$this->assertSame( 'evreg_registration_confirmed', $this->captured[0]['hook'] );
		$this->assertSame( $result->registrationId, $this->captured[0]['id'] );
		$this->assertSame( $this->event_id, $this->captured[0]['event'] );
	}

	/**
	 * Dowodzi, że emisja hooka jest strukturalnie POZA blokiem try/catch transakcji:
	 * podpina SUROWY (nieowinięty własnym try/catch, w przeciwieństwie do Mail\Subscriber)
	 * nasłuch, który rzuca \RuntimeException. Gdyby do_action nadal leżał wewnątrz try
	 * reserve(), rzut trafiłby do (no-op) ROLLBACK i zamaskował trwale zatwierdzony wiersz
	 * jako porażkę. Po przeniesieniu emisji poza try/catch rzut z nasłuchu propaguje się
	 * do wywołującego bez wpływu na już zatwierdzoną transakcję — dowodem jest to, że
	 * wiersz zgłoszenia pozostaje w bazie ze statusem "pending" NIEZALEŻNIE od tego, czy
	 * wyjątek dotarł do testu, czy PHPUnit/hooki WP go po drodze pochłonęły.
	 */
	public function test_reserved_hook_throwing_listener_does_not_undo_committed_row(): void {
		$captured_token = null;

		add_action(
			'evreg_registration_reserved',
			static function ( int $id, int $event_id, string $token ) use ( &$captured_token ): void {
				$captured_token = $token;
				throw new \RuntimeException( 'evreg raw listener boom' );
			},
			10,
			3
		);

		$threw = false;

		try {
			$this->service->reserve( $this->event_id, $this->request( 'boom@example.com' ) );
		} catch ( \RuntimeException $e ) {
			$threw = true;
			$this->assertSame( 'evreg raw listener boom', $e->getMessage() );
		}

		$this->assertTrue( $threw, 'Rzut z surowego nasłuchu musiał propagować się do wywołującego (poza try/catch reserve()).' );
		$this->assertNotNull( $captured_token, 'Nasłuch musiał zostać wywołany z tokenem zgłoszenia — dowód, że hook odpalił się po COMMIT.' );

		$row = ( new RegistrationRepository() )->findByToken( (string) $captured_token );

		$this->assertNotNull( $row, 'Wiersz zgłoszenia musi istnieć — COMMIT wykonał się przed rzutem z nasłuchu.' );
		$this->assertSame(
			'pending',
			$row['status'],
			'Wiersz musi pozostać "pending" (zatwierdzony), a nie zostać cofnięty przez ROLLBACK po rzucie z nasłuchu.'
		);
	}

	public function test_rejected_reservation_fires_nothing(): void {
		$config = new EventConfigRepository();
		$config->save( $this->event_id, array( 'settings' => array( 'waitlist_enabled' => false ) ) );

		$this->service->reserve( $this->event_id, $this->request( 'pierwszy@example.com' ) );
		$this->captured = array();

		$result = $this->service->reserve( $this->event_id, $this->request( 'drugi@example.com' ) );

		$this->assertSame( 'rejected', $result->code );
		$this->assertSame( array(), $this->captured );
	}
}
