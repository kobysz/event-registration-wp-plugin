<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration;

use EvReg\Persistence\Migrations;
use WP_UnitTestCase;

final class MigrationsTest extends WP_UnitTestCase {

	public function test_install_creates_all_tables(): void {
		global $wpdb;

		Migrations::install();

		foreach ( array( 'registrations', 'accommodation_bookings', 'mail_queue' ) as $name ) {
			$table  = Migrations::table( $name );
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			$this->assertSame( $table, $exists, "Brak tabeli {$table}" );
		}
	}

	public function test_install_is_idempotent(): void {
		Migrations::install();
		Migrations::install();

		$this->assertSame( Migrations::DB_VERSION, (int) get_option( Migrations::VERSION_OPTION ) );
	}

	public function test_registrations_table_has_expected_columns(): void {
		global $wpdb;

		Migrations::install();

		$columns = $wpdb->get_col( 'DESC ' . Migrations::table( 'registrations' ), 0 );

		$expected = array(
			'id',
			'event_id',
			'type_key',
			'status',
			'email',
			'name',
			'token',
			'data',
			'price_total',
			'note',
			'created_at',
			'expires_at',
			'confirmed_at',
			'updated_at',
		);

		foreach ( $expected as $column ) {
			$this->assertContains( $column, $columns, "Brak kolumny {$column}" );
		}
	}
}
