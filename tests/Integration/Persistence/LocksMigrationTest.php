<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Persistence;

use EvReg\Persistence\Migrations;
use WP_UnitTestCase;

final class LocksMigrationTest extends WP_UnitTestCase {

	public function test_install_creates_locks_table(): void {
		global $wpdb;

		Migrations::install();

		$table  = Migrations::table( 'locks' );
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		$this->assertSame( $table, $exists );
	}

	public function test_locks_table_has_event_id_primary_key(): void {
		global $wpdb;

		Migrations::install();

		$columns = $wpdb->get_col( 'DESC ' . Migrations::table( 'locks' ), 0 );

		$this->assertSame( array( 'event_id' ), $columns );
	}

	public function test_db_version_is_two(): void {
		$this->assertSame( 2, Migrations::DB_VERSION );
	}
}
