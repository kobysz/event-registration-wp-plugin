<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Persistence;

use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Persistence\MailQueueRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use WP_UnitTestCase;

final class PrivacyQueriesTest extends WP_UnitTestCase {

	private RegistrationRepository $repository;

	private MailQueueRepository $mail_repository;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'locks', 'mail_queue' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$this->repository      = new RegistrationRepository();
		$this->mail_repository = new MailQueueRepository();
		$this->event_id        = 11;
	}

	/**
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function row( array $overrides = array() ): array {
		return array_merge(
			array(
				'event_id'    => $this->event_id,
				'type_key'    => 'std',
				'status'      => 'pending',
				'email'       => 'jan@example.com',
				'name'        => 'Jan',
				'token'       => str_repeat( 'a', 32 ),
				'data'        => '{}',
				'price_total' => 0.0,
				'expires_at'  => '2099-01-01 00:00:00',
			),
			$overrides
		);
	}

	/**
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function mail_row( array $overrides = array() ): array {
		return array_merge(
			array(
				'registration_id' => null,
				'event_id'        => $this->event_id,
				'template_key'    => 'optin',
				'recipient'       => 'jan@example.com',
				'subject'         => 'Potwierdź zgłoszenie',
				'body'            => 'Treść',
				'headers'         => '',
				'scheduled_at'    => '2026-08-19 10:00:00',
			),
			$overrides
		);
	}

	public function test_find_by_email_paged_filters_orders_ascending_and_paginates(): void {
		$first_id = $this->repository->insertRegistration(
			$this->row( array( 'email' => 'a@b.pl', 'token' => str_repeat( 'a', 32 ) ) )
		);
		$second_id = $this->repository->insertRegistration(
			$this->row( array( 'email' => 'a@b.pl', 'token' => str_repeat( 'b', 32 ) ) )
		);
		$this->repository->insertRegistration(
			$this->row( array( 'email' => 'other@b.pl', 'token' => str_repeat( 'c', 32 ) ) )
		);

		$rows = $this->repository->findByEmailPaged( 'a@b.pl', 50, 0 );
		$this->assertCount( 2, $rows );
		$this->assertSame( $first_id, (int) $rows[0]['id'] );

		$this->assertCount( 1, $this->repository->findByEmailPaged( 'a@b.pl', 1, 0 ) );
		$this->assertSame( $second_id, (int) $this->repository->findByEmailPaged( 'a@b.pl', 1, 1 )[0]['id'] );
	}

	public function test_anonymize_overwrites_only_pii_fields(): void {
		$id = $this->repository->insertRegistration(
			$this->row(
				array(
					'status'      => 'confirmed',
					'type_key'    => 'vip',
					'email'       => 'jan@example.com',
					'name'        => 'Jan Kowalski',
					'data'        => '{"pole":"wartosc"}',
					'price_total' => 150.0,
				)
			)
		);
		$this->repository->updateNote( $id, 'Notatka organizatora' );
		$this->repository->insertAccommodationBooking( $id, new AccommodationSelection( 'n12', 'double', 'Jan' ), 180.0 );
		$this->mail_repository->insert( $this->mail_row( array( 'registration_id' => $id ) ) );

		$before = $this->repository->findById( $id );

		$this->repository->anonymizeById( $id );
		$this->repository->anonymizeBookingByRegistration( $id );
		$this->mail_repository->anonymizeByRegistration( $id );

		$row = $this->repository->findById( $id );
		$this->assertStringStartsWith( 'deleted-', (string) $row['email'] );
		$this->assertStringEndsWith( '@example.invalid', (string) $row['email'] );
		$this->assertSame( '', (string) $row['name'] );
		$this->assertSame( '{}', (string) $row['data'] );
		$this->assertSame( '', (string) $row['note'] );
		$this->assertSame( '', (string) $row['token'] );
		$this->assertSame( 'confirmed', (string) $row['status'] );
		$this->assertSame( 'vip', (string) $row['type_key'] );
		$this->assertSame( $before['price_total'], $row['price_total'] );
		$this->assertSame( $before['created_at'], $row['created_at'] );

		$booking = $this->repository->findAccommodationBooking( $id );
		$this->assertNotNull( $booking );
		$this->assertSame( '', (string) $booking['roommate_pref'] );

		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$mail = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Migrations::table( 'mail_queue' ) . ' WHERE registration_id = %d', $id ), ARRAY_A );
		$this->assertNotNull( $mail );
		$this->assertSame( '', (string) $mail['recipient'] );
		$this->assertSame( '', (string) $mail['subject'] );
		$this->assertSame( '', (string) $mail['body'] );
		$this->assertSame( '', (string) $mail['headers'] );
	}
}
