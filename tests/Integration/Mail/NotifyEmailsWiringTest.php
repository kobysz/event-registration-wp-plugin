<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Mail;

use EvReg\Mail\Subscriber;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Services\ReservationRequest;
use EvReg\Services\ReservationService;
use WP_UnitTestCase;

final class NotifyEmailsWiringTest extends WP_UnitTestCase {

	private ReservationService $service;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'locks', 'mail_queue' ) as $table ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$config         = new EventConfigRepository();
		$this->service  = new ReservationService( new RegistrationRepository(), $config );
		$this->event_id = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );

		$config->save(
			$this->event_id,
			array(
				'types'    => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 0.0, 'capacity' => 5 ) ),
				'settings' => array( 'waitlist_enabled' => true, 'notify_emails' => array( 'biuro@example.com' ) ),
			)
		);

		Subscriber::register();
	}

	protected function tearDown(): void {
		foreach ( array( 'evreg_registration_reserved', 'evreg_registration_waitlisted', 'evreg_registration_confirmed', 'evreg_registration_expired' ) as $hook ) {
			remove_all_actions( $hook );
		}
		parent::tearDown();
	}

	public function test_reservation_notifies_configured_organizer_address(): void {
		$this->service->reserve(
			$this->event_id,
			new ReservationRequest( 'jan@example.com', 'Jan', 'uczestnik', array( '__type' => 'uczestnik' ) )
		);

		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$recipients = $wpdb->get_col( "SELECT recipient FROM " . Migrations::table( 'mail_queue' ) . " WHERE template_key LIKE 'admin_new%'" );

		$this->assertContains( 'biuro@example.com', $recipients );
		$this->assertNotContains( get_option( 'admin_email' ), $recipients );
	}
}
