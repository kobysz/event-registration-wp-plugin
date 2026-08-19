<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Mail;

use EvReg\Cron\ExpirePending;
use EvReg\Mail\Subscriber;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Services\ReservationRequest;
use EvReg\Services\ReservationService;
use WP_UnitTestCase;

final class SubscriberTest extends WP_UnitTestCase {

	private ReservationService $service;

	private RegistrationRepository $registrations;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'locks', 'mail_queue' ) as $table ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$config              = new EventConfigRepository();
		$this->registrations = new RegistrationRepository();
		$this->service       = new ReservationService( $this->registrations, $config );
		$this->event_id      = self::factory()->post->create( array( 'post_type' => 'evreg_event', 'post_title' => 'Zjazd 2026' ) );

		$config->save(
			$this->event_id,
			array(
				'types'    => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 0.0, 'capacity' => 1 ) ),
				'settings' => array( 'waitlist_enabled' => true, 'notify_emails' => array( 'biuro@example.com', 'szef@example.com' ) ),
			)
		);

		Subscriber::register();
	}

	protected function tearDown(): void {
		foreach ( array( 'evreg_registration_reserved', 'evreg_registration_waitlisted', 'evreg_registration_confirmed', 'evreg_registration_expired' ) as $hook ) {
			remove_all_actions( $hook );
		}
		remove_all_filters( 'pre_schedule_event' );
		parent::tearDown();
	}

	/**
	 * Zwraca wiersze kolejki jako mapę template_key => recipient.
	 *
	 * @return array<string,string>
	 */
	private function queued(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( 'SELECT template_key, recipient FROM ' . Migrations::table( 'mail_queue' ) . ' ORDER BY id ASC', ARRAY_A );

		$map = array();

		foreach ( (array) $rows as $row ) {
			$map[ (string) $row['template_key'] ] = (string) $row['recipient'];
		}

		return $map;
	}

	private function reserve( string $email ): \EvReg\Services\ReservationResult {
		return $this->service->reserve(
			$this->event_id,
			new ReservationRequest( $email, 'Jan', 'uczestnik', array( '__type' => 'uczestnik' ) )
		);
	}

	public function test_reservation_queues_optin_and_admin_notifications(): void {
		$this->reserve( 'jan@example.com' );

		$queued = $this->queued();

		$this->assertSame( 'jan@example.com', $queued['optin'] );
		$this->assertSame( 'biuro@example.com', $queued[ 'admin_new:' . md5( 'biuro@example.com' ) ] );
		$this->assertSame( 'szef@example.com', $queued[ 'admin_new:' . md5( 'szef@example.com' ) ] );
		$this->assertCount( 3, $queued );
	}

	public function test_admin_notification_carries_reply_to_participant(): void {
		$this->reserve( 'jan@example.com' );

		global $wpdb;
		$headers = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT headers FROM ' . Migrations::table( 'mail_queue' ) . ' WHERE template_key = %s',
				'admin_new:' . md5( 'biuro@example.com' )
			)
		);

		$this->assertSame( array( 'Reply-To: jan@example.com' ), json_decode( (string) $headers, true ) );
	}

	public function test_waitlisted_reservation_queues_waitlist_mail(): void {
		$this->reserve( 'pierwszy@example.com' );
		$this->reserve( 'drugi@example.com' );

		$queued = $this->queued();

		$this->assertSame( 'drugi@example.com', $queued['waitlist'] );
	}

	public function test_confirmation_queues_confirmed_mail(): void {
		$result = $this->reserve( 'jan@example.com' );

		$this->service->confirm( (string) $result->token );

		$this->assertSame( 'jan@example.com', $this->queued()['confirmed'] );
	}

	public function test_expiry_queues_expired_mail(): void {
		$result = $this->reserve( 'jan@example.com' );

		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Migrations::table( 'registrations' ),
			array( 'expires_at' => '2000-01-01 00:00:00' ),
			array( 'id' => $result->registrationId ),
			array( '%s' ),
			array( '%d' )
		);

		ExpirePending::run();

		$this->assertSame( 'jan@example.com', $this->queued()['expired'] );
	}

	public function test_repeated_event_does_not_duplicate_mail(): void {
		$result = $this->reserve( 'jan@example.com' );

		do_action( 'evreg_registration_reserved', $result->registrationId, $this->event_id, (string) $result->token );

		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . Migrations::table( 'mail_queue' ) . " WHERE template_key = 'optin'" );

		$this->assertSame( 1, $count );
	}

	public function test_falls_back_to_site_admin_when_no_notify_emails(): void {
		( new EventConfigRepository() )->save( $this->event_id, array( 'settings' => array( 'waitlist_enabled' => true ) ) );

		$this->reserve( 'jan@example.com' );

		$this->assertArrayHasKey( 'admin_new:' . md5( (string) get_option( 'admin_email' ) ), $this->queued() );
	}

	public function test_unknown_registration_id_is_ignored(): void {
		do_action( 'evreg_registration_confirmed', 987654, $this->event_id );

		$this->assertSame( array(), $this->queued() );
	}

	/**
	 * Ruling z Taska 8: handlery hooków muszą być exception-safe, bo odpalają się
	 * w środku try/catch ReservationService::reserve() PO commit — rzucony wyjątek
	 * trafiłby do (no-op) ROLLBACK i wypłynąłby jako fałszywa porażka rezerwacji,
	 * mimo że wpis jest już trwale zapisany. Wymuszamy throw w seamie, który
	 * natychmiastowy mail do uczestnika faktycznie odpala: wp_schedule_single_event()
	 * wywołuje filtr pre_schedule_event.
	 */
	public function test_hook_handler_swallows_throw_from_immediate_dispatch(): void {
		add_filter(
			'pre_schedule_event',
			static function () {
				throw new \RuntimeException( 'boom from pre_schedule_event' );
			}
		);

		$exception = null;

		try {
			$this->reserve( 'jan@example.com' );
		} catch ( \Throwable $e ) {
			$exception = $e;
		}

		$this->assertNull( $exception, 'A queued-mail failure must never propagate as a reservation failure.' );

		// The participant row was queued before the throwing scheduling call.
		$this->assertSame( 'jan@example.com', $this->queued()['optin'] );
	}
}
