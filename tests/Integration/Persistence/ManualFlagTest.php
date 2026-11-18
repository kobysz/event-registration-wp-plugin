<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Persistence;

use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use WP_UnitTestCase;

/**
 * Znacznik wpisu ręcznego (db v8): kolumna `manual` na zgłoszeniu.
 */
final class ManualFlagTest extends WP_UnitTestCase {

	private RegistrationRepository $repo;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( 'registrations' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$this->repo     = new RegistrationRepository();
		$this->event_id = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
	}

	/**
	 * @param array<string,mixed> $extra Dodatkowe klucze wiersza.
	 */
	private function insert( array $extra = array() ): int {
		return $this->repo->insertRegistration(
			array_merge(
				array(
					'event_id'    => $this->event_id,
					'type_key'    => 'uczestnik',
					'status'      => 'pending',
					'email'       => 'jan@example.com',
					'name'        => 'Jan',
					'token'       => str_repeat( 'a', 32 ),
					'data'        => '{}',
					'price_total' => 0,
					'expires_at'  => null,
				),
				$extra
			)
		);
	}

	public function test_schema_version_is_eight(): void {
		$this->assertSame( 8, Migrations::DB_VERSION );
	}

	public function test_insert_without_manual_key_reads_back_zero(): void {
		$row = $this->repo->findById( $this->insert() );

		$this->assertIsArray( $row );
		$this->assertSame( 0, (int) $row['manual'] );
	}

	public function test_insert_with_manual_flag_reads_back_one(): void {
		$row = $this->repo->findById( $this->insert( array( 'manual' => 1 ) ) );

		$this->assertIsArray( $row );
		$this->assertSame( 1, (int) $row['manual'] );
	}

	public function test_row_that_predates_the_column_reads_as_zero(): void {
		global $wpdb;
		$now = current_time( 'mysql', true );

		// Surowy INSERT wymieniający wyłącznie kolumny sprzed migracji v8.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'INSERT INTO ' . Migrations::table( 'registrations' ) . ' (event_id, type_key, status, email, name, token, data, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL
				$this->event_id,
				'uczestnik',
				'confirmed',
				'stary@example.com',
				'Stary',
				str_repeat( 'b', 32 ),
				'{}',
				$now,
				$now
			)
		);

		$row = $this->repo->findById( (int) $wpdb->insert_id );

		$this->assertIsArray( $row );
		$this->assertSame( 0, (int) $row['manual'] );
	}
}
