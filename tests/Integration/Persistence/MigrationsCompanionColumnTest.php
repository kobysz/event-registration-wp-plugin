<?php
declare( strict_types=1 );
namespace EvReg\Tests\Integration\Persistence;
use EvReg\Persistence\Migrations;
use WP_UnitTestCase;

final class MigrationsCompanionColumnTest extends WP_UnitTestCase {
	public function test_registrations_have_companion_columns(): void {
		Migrations::install();
		global $wpdb;
		$cols = $wpdb->get_col( 'DESC ' . Migrations::table( 'registrations' ), 0 ); // phpcs:ignore WordPress.DB
		$this->assertContains( 'companion', $cols );
		$this->assertContains( 'companion_name', $cols );
	}

	public function test_bookings_have_seats_column(): void {
		Migrations::install();
		global $wpdb;
		$cols = $wpdb->get_col( 'DESC ' . Migrations::table( 'accommodation_bookings' ), 0 ); // phpcs:ignore WordPress.DB
		$this->assertContains( 'seats', $cols );
	}

	public function test_companion_column_ships_with_current_schema_version(): void {
		$this->assertGreaterThanOrEqual( 7, Migrations::DB_VERSION );
	}
}
