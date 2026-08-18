<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Services;

use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use WP_UnitTestCase;
use wpdb;

/**
 * Dowodzi, że blokada per event serializuje rezerwacje: gdy jedno połączenie
 * trzyma FOR UPDATE na wierszu-zamku, drugie blokuje się do timeoutu.
 */
final class ReservationConcurrencyTest extends WP_UnitTestCase {

	public function test_second_connection_blocks_on_event_lock(): void {
		global $wpdb;

		Migrations::install();
		foreach ( array( 'registrations', 'accommodation_bookings', 'locks' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$event_id = 777;
		$repo     = new RegistrationRepository();

		// Połączenie A (główne $wpdb): zablokuj wiersz-zamek i NIE commituj.
		$wpdb->query( 'START TRANSACTION' );
		$repo->lockEvent( $event_id );

		// Połączenie B: osobne $wpdb, krótki timeout blokady.
		$second = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$second->query( 'SET SESSION innodb_lock_wait_timeout = 1' );
		$second->query( 'START TRANSACTION' );
		$second->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . Migrations::table( 'locks' ) . ' (event_id) VALUES (%d)', $event_id ) );

		$second->suppress_errors( true );
		$blocked = $second->query(
			$second->prepare( 'SELECT event_id FROM ' . Migrations::table( 'locks' ) . ' WHERE event_id = %d FOR UPDATE', $event_id )
		);
		$error = $second->last_error;
		$second->query( 'ROLLBACK' );

		// Zwolnij A.
		$wpdb->query( 'COMMIT' );

		$this->assertFalse( $blocked, 'Drugie połączenie powinno zostać zablokowane przez FOR UPDATE.' );
		$this->assertStringContainsStringIgnoringCase( 'lock wait timeout', $error );
	}
}
