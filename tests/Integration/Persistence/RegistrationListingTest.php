<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Persistence;

use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use WP_UnitTestCase;

final class RegistrationListingTest extends WP_UnitTestCase {

	private RegistrationRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( 'registrations' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->repository = new RegistrationRepository();
	}

	/**
	 * @param array<string,mixed> $overrides
	 */
	private function seed( array $overrides = array() ): int {
		return $this->repository->insertRegistration(
			array_merge(
				array(
					'event_id'    => 1,
					'type_key'    => 'uczestnik',
					'status'      => 'pending',
					'email'       => 'jan@example.com',
					'name'        => 'Jan',
					'token'       => str_repeat( 'a', 32 ),
					'data'        => '{}',
					'price_total' => 0.0,
					'expires_at'  => '2099-01-01 00:00:00',
				),
				$overrides
			)
		);
	}

	public function test_paginate_all_newest_first(): void {
		$this->seed( array( 'name' => 'A', 'token' => str_repeat( 'a', 32 ) ) );
		$this->seed( array( 'name' => 'B', 'token' => str_repeat( 'b', 32 ) ) );

		$rows = $this->repository->paginateRegistrations( array(), 20, 0 );

		$this->assertCount( 2, $rows );
		$this->assertSame( 'B', $rows[0]['name'] );
		$this->assertSame( 'A', $rows[1]['name'] );
	}

	public function test_paginate_filters_by_status(): void {
		$this->seed( array( 'status' => 'pending', 'token' => str_repeat( 'a', 32 ) ) );
		$this->seed( array( 'status' => 'waitlist', 'name' => 'W', 'token' => str_repeat( 'b', 32 ) ) );

		$rows = $this->repository->paginateRegistrations( array( 'status' => 'waitlist' ), 20, 0 );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'W', $rows[0]['name'] );
	}

	public function test_paginate_filters_by_type_and_event(): void {
		$this->seed( array( 'event_id' => 1, 'type_key' => 'uczestnik', 'token' => str_repeat( 'a', 32 ) ) );
		$this->seed( array( 'event_id' => 1, 'type_key' => 'wykladowca', 'name' => 'T', 'token' => str_repeat( 'b', 32 ) ) );
		$this->seed( array( 'event_id' => 2, 'type_key' => 'wykladowca', 'token' => str_repeat( 'c', 32 ) ) );

		$rows = $this->repository->paginateRegistrations( array( 'event_id' => 1, 'type_key' => 'wykladowca' ), 20, 0 );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'T', $rows[0]['name'] );
	}

	public function test_paginate_limit_offset(): void {
		foreach ( array( 'a', 'b', 'c' ) as $i => $t ) {
			$this->seed( array( 'name' => strtoupper( $t ), 'token' => str_repeat( $t, 32 ) ) );
		}

		$page2 = $this->repository->paginateRegistrations( array(), 2, 2 );

		$this->assertCount( 1, $page2 );
		$this->assertSame( 'A', $page2[0]['name'] );
	}

	public function test_paginate_ignores_unknown_status(): void {
		$this->seed();

		$this->assertCount( 1, $this->repository->paginateRegistrations( array( 'status' => 'nonsense' ), 20, 0 ) );
	}

	public function test_count_matches_filter(): void {
		$this->seed( array( 'status' => 'pending', 'token' => str_repeat( 'a', 32 ) ) );
		$this->seed( array( 'status' => 'waitlist', 'token' => str_repeat( 'b', 32 ) ) );
		$this->seed( array( 'status' => 'waitlist', 'token' => str_repeat( 'c', 32 ) ) );

		$this->assertSame( 3, $this->repository->countRegistrations( array() ) );
		$this->assertSame( 2, $this->repository->countRegistrations( array( 'status' => 'waitlist' ) ) );
	}

	public function test_distinct_type_keys(): void {
		$this->seed( array( 'event_id' => 1, 'type_key' => 'uczestnik', 'token' => str_repeat( 'a', 32 ) ) );
		$this->seed( array( 'event_id' => 1, 'type_key' => 'uczestnik', 'token' => str_repeat( 'b', 32 ) ) );
		$this->seed( array( 'event_id' => 1, 'type_key' => 'wykladowca', 'token' => str_repeat( 'c', 32 ) ) );
		$this->seed( array( 'event_id' => 2, 'type_key' => 'online', 'token' => str_repeat( 'd', 32 ) ) );

		$types = $this->repository->distinctTypeKeys( 1 );

		sort( $types );
		$this->assertSame( array( 'uczestnik', 'wykladowca' ), $types );
	}

	public function test_distinct_event_ids(): void {
		$this->seed( array( 'event_id' => 2, 'token' => str_repeat( 'a', 32 ) ) );
		$this->seed( array( 'event_id' => 1, 'token' => str_repeat( 'b', 32 ) ) );
		$this->seed( array( 'event_id' => 1, 'token' => str_repeat( 'c', 32 ) ) );

		$ids = $this->repository->distinctEventIds();

		$this->assertSame( array( 1, 2 ), $ids );
	}
}
