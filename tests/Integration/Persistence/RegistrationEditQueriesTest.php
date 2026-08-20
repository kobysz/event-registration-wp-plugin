<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Persistence;

use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use WP_UnitTestCase;

final class RegistrationEditQueriesTest extends WP_UnitTestCase {

	private RegistrationRepository $repository;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'locks' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$this->repository = new RegistrationRepository();
		$this->event_id   = 7;
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
				'price_total' => 100.0,
				'expires_at'  => '2099-01-01 00:00:00',
			),
			$overrides
		);
	}

	public function test_occupancy_excluding_omits_own_row_globally_and_per_type(): void {
		$id_a = $this->repository->insertRegistration( $this->row( array( 'token' => str_repeat( 'a', 32 ) ) ) );
		$this->repository->insertRegistration( $this->row( array( 'token' => str_repeat( 'b', 32 ) ) ) );

		$this->assertSame( 2, $this->repository->occupancy( $this->event_id )->global() );
		$this->assertSame( 2, $this->repository->occupancy( $this->event_id )->forType( 'std' ) );

		$snap = $this->repository->occupancyExcluding( $this->event_id, $id_a );

		$this->assertSame( 1, $snap->forType( 'std' ) );
		$this->assertSame( 1, $snap->global() );
	}

	public function test_occupancy_excluding_omits_own_accommodation_booking(): void {
		$id_a = $this->repository->insertRegistration( $this->row( array( 'token' => str_repeat( 'c', 32 ) ) ) );
		$id_b = $this->repository->insertRegistration( $this->row( array( 'token' => str_repeat( 'd', 32 ) ) ) );
		$this->repository->insertAccommodationBooking( $id_a, new AccommodationSelection( 'n12', 'double' ), 180.0 );
		$this->repository->insertAccommodationBooking( $id_b, new AccommodationSelection( 'n12', 'double' ), 180.0 );

		$this->assertSame( 2, $this->repository->occupancy( $this->event_id )->forSlot( 'n12|double' ) );

		$snap = $this->repository->occupancyExcluding( $this->event_id, $id_a );

		$this->assertSame( 1, $snap->forSlot( 'n12|double' ) );
	}

	public function test_update_registration_overwrites_editable_fields_only(): void {
		$id = $this->repository->insertRegistration( $this->row( array( 'token' => str_repeat( 'e', 32 ) ) ) );
		$before = $this->repository->findById( $id );

		$this->repository->updateRegistration( $id, 'vip', 'x@y.pl', 'Nowy', '{"__type":"vip"}', 199.0 );

		$after = $this->repository->findById( $id );
		$this->assertSame( 'vip', $after['type_key'] );
		$this->assertSame( 'x@y.pl', $after['email'] );
		$this->assertSame( 'Nowy', $after['name'] );
		$this->assertSame( '{"__type":"vip"}', $after['data'] );
		$this->assertSame( '199.00', $after['price_total'] );
		$this->assertSame( $before['status'], $after['status'] );
		$this->assertSame( $before['token'], $after['token'] );
		$this->assertSame( $before['created_at'], $after['created_at'] );
	}
}
