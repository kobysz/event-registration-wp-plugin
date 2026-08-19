<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Admin;

use EvReg\Admin\RegistrationsListTable;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use WP_UnitTestCase;

final class RegistrationsListTableTest extends WP_UnitTestCase {

	private RegistrationRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( 'registrations' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->repository = new RegistrationRepository();
		set_current_screen( 'evreg_event_page_evreg-registrations' );
	}

	private function seed( string $status, string $token, int $event_id = 1 ): int {
		return $this->repository->insertRegistration(
			array(
				'event_id'    => $event_id,
				'type_key'    => 'uczestnik',
				'status'      => $status,
				'email'       => $token . '@example.com',
				'name'        => 'Jan',
				'token'       => $token,
				'data'        => '{}',
				'price_total' => 0.0,
				'expires_at'  => null,
			)
		);
	}

	public function test_columns_present(): void {
		$table = new RegistrationsListTable();

		$cols = $table->get_columns();

		foreach ( array( 'name', 'email', 'type', 'status', 'event' ) as $c ) {
			$this->assertArrayHasKey( $c, $cols );
		}
	}

	public function test_prepare_items_loads_and_filters(): void {
		$this->seed( 'pending', str_repeat( 'a', 32 ) );
		$this->seed( 'waitlist', str_repeat( 'b', 32 ) );

		$_REQUEST['status'] = 'waitlist';
		$_GET['status']     = 'waitlist';

		$table = new RegistrationsListTable();
		$table->prepare_items();

		unset( $_REQUEST['status'], $_GET['status'] );

		$this->assertCount( 1, $table->items );
		$this->assertSame( 'waitlist', $table->items[0]['status'] );
	}

	public function test_prepare_items_filters_by_event_id(): void {
		$this->seed( 'pending', str_repeat( 'a', 32 ), 1 );
		$this->seed( 'pending', str_repeat( 'b', 32 ), 2 );

		$_REQUEST['event_id'] = 2;
		$_GET['event_id']     = 2;

		$table = new RegistrationsListTable();
		$table->prepare_items();

		unset( $_REQUEST['event_id'], $_GET['event_id'] );

		$this->assertCount( 1, $table->items );
		$this->assertSame( 2, (int) $table->items[0]['event_id'] );
	}

	public function test_status_label_translates(): void {
		$this->assertNotSame( 'failed', RegistrationsListTable::status_label( 'confirmed' ) );
		$this->assertNotSame( '', RegistrationsListTable::status_label( 'confirmed' ) );
		$this->assertSame( 'nieznany', RegistrationsListTable::status_label( 'nieznany' ) );
	}

	public function test_column_default_escapes(): void {
		$table = new RegistrationsListTable();

		$out = $table->column_default( array( 'name' => '<b>Jan</b>', 'status' => 'pending', 'event_id' => 0 ), 'name' );

		$this->assertStringNotContainsString( '<b>', $out );
	}

	public function test_row_actions_depend_on_status(): void {
		$table = new RegistrationsListTable();

		$pending  = $table->handle_row_actions( array( 'id' => 1, 'status' => 'pending' ), 'name', 'name' );
		$waitlist = $table->handle_row_actions( array( 'id' => 2, 'status' => 'waitlist' ), 'name', 'name' );
		$cancelled = $table->handle_row_actions( array( 'id' => 3, 'status' => 'cancelled' ), 'name', 'name' );

		$this->assertStringContainsString( 'evreg_reg_confirm', $pending );
		$this->assertStringNotContainsString( 'evreg_reg_confirm', $waitlist );
		$this->assertStringContainsString( 'evreg_reg_promote', $waitlist );
		$this->assertStringContainsString( 'evreg_reg_delete', $cancelled );
		$this->assertStringNotContainsString( 'evreg_reg_delete', $pending );
	}
}
