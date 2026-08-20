<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Persistence;

use EvReg\Admin\Capabilities;
use EvReg\Cron\DispatchMail;
use EvReg\Cron\ExpirePending;
use EvReg\Cron\PurgeMailQueue;
use EvReg\Mail\MailQueue;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\Uninstaller;
use WP_UnitTestCase;

final class UninstallerTest extends WP_UnitTestCase {

	public function test_run_without_gate_deletes_nothing(): void {
		global $wpdb;

		Capabilities::grant();
		$event_id = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
		update_post_meta( $event_id, '_evreg_schema', array() );

		delete_option( Uninstaller::DELETE_OPTION );

		Uninstaller::run();

		$this->assertNotEmpty( $wpdb->get_var( "SHOW TABLES LIKE '" . Migrations::table( 'registrations' ) . "'" ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'edit_evreg_events' ) );
		$this->assertNotFalse( get_post_status( $event_id ) );
	}

	public function test_run_with_gate_option_deletes_everything(): void {
		global $wpdb;

		// WP_UnitTestCase silently rewrites CREATE/DROP TABLE into their
		// TEMPORARY-table equivalents (via the `query` filter added by
		// start_transaction()) so DDL run during a test never touches the
		// real schema. Uninstaller's DROP TABLE statements must hit the
		// real tables for this test to mean anything, so bypass that
		// rewrite for the DDL this test exercises.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		// Defensive: schema is already installed via the plugins_loaded
		// self-heal, but re-assert it (idempotent dbDelta) before relying
		// on it being present.
		Migrations::install();
		Capabilities::grant();
		$event_id = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
		update_post_meta( $event_id, '_evreg_schema', array() );

		wp_schedule_single_event( time() + HOUR_IN_SECONDS, DispatchMail::HOOK );
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, ExpirePending::HOOK );
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, PurgeMailQueue::HOOK );
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, MailQueue::DISPATCH_HOOK );

		update_option( Uninstaller::DELETE_OPTION, 1 );

		Uninstaller::run();

		$this->assertEmpty( $wpdb->get_var( "SHOW TABLES LIKE '" . Migrations::table( 'registrations' ) . "'" ) );
		$this->assertEmpty( $wpdb->get_var( "SHOW TABLES LIKE '" . Migrations::table( 'mail_queue' ) . "'" ) );
		$this->assertFalse( get_option( Migrations::VERSION_OPTION ) );
		$this->assertFalse( get_option( 'evreg_caps_version' ) );
		$this->assertFalse( get_role( 'administrator' )->has_cap( 'edit_evreg_events' ) );
		$this->assertFalse( get_post_status( $event_id ) );
		$this->assertFalse( wp_next_scheduled( DispatchMail::HOOK ) );
		$this->assertFalse( wp_next_scheduled( ExpirePending::HOOK ) );
		$this->assertFalse( wp_next_scheduled( PurgeMailQueue::HOOK ) );
		$this->assertFalse( wp_next_scheduled( MailQueue::DISPATCH_HOOK ) );

		// This test performs real (non-rolled-back) DDL, so restore the
		// schema and capabilities afterwards for any tests that run later
		// in the same process.
		Migrations::install();
		Capabilities::grant();
	}
}
