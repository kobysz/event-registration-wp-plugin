<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Persistence;

use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use WP_UnitTestCase;

final class RegistrationExportQueriesTest extends WP_UnitTestCase {

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
		$this->event_id   = 9;
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

	public function test_export_returns_all_rows_without_pagination_ordered_by_id_asc(): void {
		$first_id = null;

		for ( $i = 0; $i < 22; $i++ ) {
			$id = $this->repository->insertRegistration(
				$this->row(
					array(
						'type_key' => 'std',
						'token'    => str_pad( (string) $i, 32, 'x', STR_PAD_LEFT ),
					)
				)
			);
			if ( null === $first_id ) {
				$first_id = $id;
			}
		}

		for ( $i = 0; $i < 3; $i++ ) {
			$this->repository->insertRegistration(
				$this->row(
					array(
						'type_key' => 'vip',
						'token'    => str_pad( 'vip' . $i, 32, 'y', STR_PAD_LEFT ),
					)
				)
			);
		}

		$rows = $this->repository->exportRegistrations( array( 'event_id' => $this->event_id ) );

		$this->assertCount( 25, $rows );
		$this->assertSame( $first_id, (int) $rows[0]['id'] );

		$typed = $this->repository->exportRegistrations( array( 'event_id' => $this->event_id, 'type_key' => 'vip' ) );
		$this->assertCount( 3, $typed );
	}

	public function test_export_with_no_filters_returns_everything_no_query_error(): void {
		$this->repository->insertRegistration( $this->row( array( 'token' => str_repeat( 'a', 32 ) ) ) );
		$this->repository->insertRegistration( $this->row( array( 'token' => str_repeat( 'b', 32 ), 'event_id' => $this->event_id + 1 ) ) );

		$rows = $this->repository->exportRegistrations( array() );

		$this->assertCount( 2, $rows );
	}

	public function test_accommodation_bookings_for_returns_map_keyed_by_registration_id(): void {
		$id_a = $this->repository->insertRegistration( $this->row( array( 'token' => str_repeat( 'c', 32 ) ) ) );
		$id_b = $this->repository->insertRegistration( $this->row( array( 'token' => str_repeat( 'd', 32 ) ) ) );
		$id_c = $this->repository->insertRegistration( $this->row( array( 'token' => str_repeat( 'e', 32 ) ) ) );

		$this->repository->insertAccommodationBooking( $id_a, new AccommodationSelection( 'n12', 'double' ), 180.0 );
		$this->repository->insertAccommodationBooking( $id_b, new AccommodationSelection( 'n34', 'single' ), 220.0 );

		$map = $this->repository->accommodationBookingsFor( array( $id_a, $id_b, $id_c ) );

		$this->assertArrayHasKey( $id_a, $map );
		$this->assertArrayHasKey( $id_b, $map );
		$this->assertArrayNotHasKey( $id_c, $map );
		$this->assertSame( 'n12', $map[ $id_a ]['package_key'] );
		$this->assertSame( 'single', $map[ $id_b ]['room_type_key'] );
	}

	public function test_accommodation_bookings_for_empty_ids_returns_empty_array_without_query(): void {
		$map = $this->repository->accommodationBookingsFor( array() );

		$this->assertSame( array(), $map );
	}
}
