<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Persistence;

use EvReg\Persistence\Migrations;
use WP_UnitTestCase;

final class MailQueueMigrationTest extends WP_UnitTestCase {

	public function test_db_version_is_five(): void {
		$this->assertSame( 6, Migrations::DB_VERSION );
	}

	public function test_mail_queue_has_headers_column(): void {
		global $wpdb;

		Migrations::install();

		$columns = $wpdb->get_col( 'DESC ' . Migrations::table( 'mail_queue' ), 0 );

		$this->assertContains( 'headers', $columns );
	}

	public function test_mail_queue_has_unique_registration_template_index(): void {
		global $wpdb;

		Migrations::install();

		$table = Migrations::table( 'mail_queue' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$index = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'uniq_registration_template'", ARRAY_A );

		$this->assertCount( 2, $index );
		$this->assertSame( '0', (string) $index[0]['Non_unique'] );
		$this->assertSame( array( 'registration_id', 'template_key' ), array( $index[0]['Column_name'], $index[1]['Column_name'] ) );
	}

	public function test_install_records_current_db_version(): void {
		Migrations::install();

		$this->assertSame( Migrations::DB_VERSION, (int) get_option( Migrations::VERSION_OPTION ) );
	}
}
