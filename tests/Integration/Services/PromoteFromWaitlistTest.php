<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Services;

use EvReg\Mail\Subscriber;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Services\ReservationService;
use WP_UnitTestCase;

final class PromoteFromWaitlistTest extends WP_UnitTestCase {

	private ReservationService $service;

	private RegistrationRepository $repository;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'locks', 'mail_queue' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$config           = new EventConfigRepository();
		$this->repository = new RegistrationRepository();
		$this->service    = new ReservationService( $this->repository, $config );
		$this->event_id   = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );

		// Typ z limitem 1 miejsca.
		$config->save(
			$this->event_id,
			array(
				'types'    => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 0.0, 'capacity' => 1 ) ),
				'settings' => array( 'waitlist_enabled' => true ),
			)
		);
	}

	protected function tearDown(): void {
		foreach ( array( 'evreg_registration_reserved', 'evreg_registration_waitlisted', 'evreg_registration_confirmed', 'evreg_registration_expired' ) as $hook ) {
			remove_all_actions( $hook );
		}
		parent::tearDown();
	}

	private function seed( string $status, string $token ): int {
		return $this->repository->insertRegistration(
			array(
				'event_id'    => $this->event_id,
				'type_key'    => 'uczestnik',
				'status'      => $status,
				'email'       => $token . '@example.com',
				'name'        => 'Jan',
				'token'       => $token,
				'data'        => '{}',
				'price_total' => 0.0,
				'expires_at'  => null,
			)
		);
	}

	private function mail_count( string $template_key ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::table( 'mail_queue' ) . ' WHERE template_key = %s', $template_key ) );
	}

	public function test_promote_when_seat_free(): void {
		Subscriber::register();
		$id = $this->seed( 'waitlist', str_repeat( 'a', 32 ) );

		$result = $this->service->promoteFromWaitlist( $id );

		$this->assertSame( 'promoted', $result->code );
		$row = $this->repository->findById( $id );
		$this->assertSame( 'pending', $row['status'] );
		$this->assertNotNull( $row['expires_at'] );
		$this->assertSame( 1, $this->mail_count( 'optin' ) );
	}

	public function test_promote_rejected_when_full(): void {
		$this->seed( 'confirmed', str_repeat( 'a', 32 ) );      // zajmuje jedyne miejsce
		$waitlisted = $this->seed( 'waitlist', str_repeat( 'b', 32 ) );

		$result = $this->service->promoteFromWaitlist( $waitlisted );

		$this->assertSame( 'rejected', $result->code );
		$this->assertSame( 'waitlist', $this->repository->findById( $waitlisted )['status'] );
	}

	public function test_promote_rejects_non_waitlist(): void {
		$id = $this->seed( 'pending', str_repeat( 'a', 32 ) );

		$this->assertSame( 'invalid_status', $this->service->promoteFromWaitlist( $id )->code );
	}

	public function test_promote_rejects_unknown_type(): void {
		// Typ usunięty z configu po zawaitlistowaniu (config-drift) → nie promuj, nie zeruj ceny.
		$id = $this->repository->insertRegistration(
			array(
				'event_id'    => $this->event_id,
				'type_key'    => 'ghost',
				'status'      => 'waitlist',
				'email'       => 'ghost@example.com',
				'name'        => 'Jan',
				'token'       => str_repeat( 'c', 32 ),
				'data'        => '{}',
				'price_total' => 0.0,
				'expires_at'  => null,
			)
		);

		$this->assertSame( 'invalid_status', $this->service->promoteFromWaitlist( $id )->code );
		$this->assertSame( 'waitlist', $this->repository->findById( $id )['status'] );
	}

	public function test_delete_registration_removes_cancelled_and_orphans(): void {
		$id = $this->seed( 'cancelled', str_repeat( 'a', 32 ) );
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Migrations::table( 'mail_queue' ),
			array(
				'registration_id' => $id,
				'event_id'        => $this->event_id,
				'template_key'    => 'optin',
				'recipient'       => 'jan@example.com',
				'subject'         => 'S',
				'body'            => 'B',
				'headers'         => '',
				'status'          => 'sent',
				'attempts'        => 1,
				'scheduled_at'    => '2026-08-19 10:00:00',
			)
		);

		$result = $this->service->deleteRegistration( $id );

		$this->assertSame( 'deleted', $result->code );
		$this->assertNull( $this->repository->findById( $id ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::table( 'mail_queue' ) . ' WHERE registration_id = %d', $id ) ) );
	}

	public function test_delete_registration_rejects_active(): void {
		$id = $this->seed( 'pending', str_repeat( 'a', 32 ) );

		$this->assertSame( 'invalid_status', $this->service->deleteRegistration( $id )->code );
		$this->assertNotNull( $this->repository->findById( $id ) );
	}
}
