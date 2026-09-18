<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Persistence;

use EvReg\Persistence\Migrations;
use WP_UnitTestCase;

final class MigrationsLangColumnTest extends WP_UnitTestCase {

	public function test_registrations_table_has_lang_column(): void {
		Migrations::install();
		global $wpdb;
		$table   = Migrations::table( 'registrations' );
		$columns = $wpdb->get_col( "DESC {$table}", 0 ); // phpcs:ignore WordPress.DB
		$this->assertContains( 'lang', $columns );
	}
}
