<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Mail;

use EvReg\Mail\DefaultTemplates;
use EvReg\Mail\Subscriber;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Services\ReservationRequest;
use EvReg\Services\ReservationService;
use WP_UnitTestCase;

final class EnqueueLangTest extends WP_UnitTestCase {

	private ReservationService $service;

	private EventConfigRepository $config;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'locks', 'mail_queue' ) as $table ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$this->config   = new EventConfigRepository();
		$registrations  = new RegistrationRepository();
		$this->service  = new ReservationService( $registrations, $this->config );
		$this->event_id = self::factory()->post->create(
			array(
				'post_type'  => 'evreg_event',
				'post_title' => 'Zjazd 2026',
			)
		);

		$this->config->save(
			$this->event_id,
			array(
				'types'    => array(
					array(
						'key'      => 'uczestnik',
						'label'    => 'Uczestnik',
						'price'    => 0.0,
						'capacity' => 5,
					),
				),
				'settings' => array(
					'waitlist_enabled' => true,
					'notify_emails'    => array( 'biuro@example.com' ),
				),
			)
		);

		$this->config->saveI18n(
			$this->event_id,
			array(
				'en' => array(
					'mail' => array(
						DefaultTemplates::KEY_OPTIN => array(
							'subject' => 'EN subject',
							'body'    => 'EN body',
						),
					),
				),
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

	/**
	 * @return array<string,array{subject:string,body:string}>
	 */
	private function queued(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( 'SELECT template_key, subject, body FROM ' . Migrations::table( 'mail_queue' ) . ' ORDER BY id ASC', ARRAY_A );

		$map = array();

		foreach ( (array) $rows as $row ) {
			$map[ (string) $row['template_key'] ] = array(
				'subject' => (string) $row['subject'],
				'body'    => (string) $row['body'],
			);
		}

		return $map;
	}

	public function test_participant_mail_uses_registration_language(): void {
		$request = new ReservationRequest( 'jan@example.com', 'Jan', 'uczestnik', array( '__type' => 'uczestnik' ), null, 'en' );

		$this->service->reserve( $this->event_id, $request );

		$queued = $this->queued();

		$this->assertSame( 'EN subject', $queued['optin']['subject'] );
		$this->assertSame( 'EN body', $queued['optin']['body'] );
	}

	public function test_admin_mail_uses_base_language_regardless_of_registration_lang(): void {
		$request = new ReservationRequest( 'jan@example.com', 'Jan', 'uczestnik', array( '__type' => 'uczestnik' ), null, 'en' );

		$this->service->reserve( $this->event_id, $request );

		$queued    = $this->queued();
		$admin_key = DefaultTemplates::KEY_ADMIN_NEW . ':' . md5( 'biuro@example.com' );

		$this->assertStringContainsString( 'Nowe zgłoszenie', $queued[ $admin_key ]['subject'] );
		$this->assertStringNotContainsString( 'EN subject', $queued[ $admin_key ]['subject'] );
	}
}
