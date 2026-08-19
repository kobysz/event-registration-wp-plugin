<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Persistence;

use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use WP_UnitTestCase;

final class RegistrationMutationsTest extends WP_UnitTestCase {

	private RegistrationRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'mail_queue' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$this->repository = new RegistrationRepository();
	}

	private function seed( string $status = 'pending' ): int {
		return $this->repository->insertRegistration(
			array(
				'event_id'    => 1,
				'type_key'    => 'uczestnik',
				'status'      => $status,
				'email'       => 'jan@example.com',
				'name'        => 'Jan',
				'token'       => str_repeat( 'a', 32 ),
				'data'        => '{}',
				'price_total' => 0.0,
				'expires_at'  => '2099-01-01 00:00:00',
			)
		);
	}

	public function test_mark_cancelled(): void {
		$id = $this->seed( 'pending' );

		$this->repository->markCancelled( $id );

		$this->assertSame( 'cancelled', $this->repository->findById( $id )['status'] );
	}

	public function test_mark_pending_sets_expires_at(): void {
		$id = $this->seed( 'waitlist' );

		$this->repository->markPending( $id, '2030-01-01 12:00:00' );

		$row = $this->repository->findById( $id );
		$this->assertSame( 'pending', $row['status'] );
		$this->assertSame( '2030-01-01 12:00:00', $row['expires_at'] );
	}

	public function test_delete_accommodation_booking(): void {
		$id = $this->seed();
		$this->repository->insertAccommodationBooking( $id, new AccommodationSelection( 'n12', 'double' ), 100.0 );
		$this->assertNotNull( $this->repository->findAccommodationBooking( $id ) );

		$this->repository->deleteAccommodationBooking( $id );

		$this->assertNull( $this->repository->findAccommodationBooking( $id ) );
	}

	public function test_delete_mail_queue_by_registration(): void {
		$id = $this->seed();
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Migrations::table( 'mail_queue' ),
			array(
				'registration_id' => $id,
				'event_id'        => 1,
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

		$this->repository->deleteMailQueueByRegistration( $id );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::table( 'mail_queue' ) . ' WHERE registration_id = %d', $id ) );
		$this->assertSame( 0, $count );
	}

	public function test_hard_delete(): void {
		$id = $this->seed( 'cancelled' );

		$this->repository->hardDelete( $id );

		$this->assertNull( $this->repository->findById( $id ) );
	}

	public function test_update_note(): void {
		$id = $this->seed();

		$this->repository->updateNote( $id, "Zadzwonił,\npotwierdził telefonicznie." );

		$this->assertSame( "Zadzwonił,\npotwierdził telefonicznie.", $this->repository->findById( $id )['note'] );
	}
}
