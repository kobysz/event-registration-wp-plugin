<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Admin;

use EvReg\Admin\MailQueueListTable;
use EvReg\Persistence\MailQueueRepository;
use EvReg\Persistence\Migrations;
use WP_UnitTestCase;

final class MailQueueListTableTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( 'mail_queue' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		set_current_screen( 'toplevel_page_evreg-mail-queue' );
	}

	/**
	 * @param array<string,mixed> $overrides
	 */
	private function seed( array $overrides = array() ): void {
		( new MailQueueRepository() )->insert(
			array_merge(
				array(
					'registration_id' => null,
					'event_id'        => 1,
					'template_key'    => 'optin',
					'recipient'       => 'jan@example.com',
					'subject'         => 'Temat',
					'body'            => 'Treść',
					'headers'         => '',
					'scheduled_at'    => '2026-08-19 10:00:00',
				),
				$overrides
			)
		);
	}

	public function test_columns_include_status_recipient_event(): void {
		$table = new MailQueueListTable();

		$columns = $table->get_columns();

		$this->assertArrayHasKey( 'status', $columns );
		$this->assertArrayHasKey( 'recipient', $columns );
		$this->assertArrayHasKey( 'event', $columns );
	}

	public function test_prepare_items_loads_rows(): void {
		$this->seed( array( 'template_key' => 'a' ) );
		$this->seed( array( 'template_key' => 'b' ) );

		$table = new MailQueueListTable();
		$table->prepare_items();

		$this->assertCount( 2, $table->items );
	}

	public function test_prepare_items_applies_status_filter_from_request(): void {
		$this->seed( array( 'template_key' => 'q' ) );
		$id = ( new MailQueueRepository() )->insert(
			array(
				'registration_id' => null,
				'event_id'        => 1,
				'template_key'    => 'f',
				'recipient'       => 'x@example.com',
				'subject'         => 'S',
				'body'            => 'B',
				'headers'         => '',
				'scheduled_at'    => '2026-08-19 10:00:00',
			)
		);
		global $wpdb;
		$max = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . Migrations::table( 'mail_queue' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->update( Migrations::table( 'mail_queue' ), array( 'status' => 'failed' ), array( 'id' => $max ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$_REQUEST['status'] = 'failed';
		$_GET['status']     = 'failed';

		$table = new MailQueueListTable();
		$table->prepare_items();

		unset( $_REQUEST['status'], $_GET['status'] );

		$this->assertCount( 1, $table->items );
		$this->assertSame( 'f', $table->items[0]['template_key'] );
	}

	public function test_column_default_escapes_recipient(): void {
		$table  = new MailQueueListTable();
		$output = $table->column_default(
			array( 'recipient' => '<b>x@example.com</b>', 'status' => 'queued' ),
			'recipient'
		);

		$this->assertStringNotContainsString( '<b>', $output );
	}

	public function test_requeue_action_only_for_failed_rows(): void {
		$table = new MailQueueListTable();

		$failed = $table->handle_row_actions( array( 'id' => 1, 'status' => 'failed' ), 'status', 'status' );
		$sent   = $table->handle_row_actions( array( 'id' => 2, 'status' => 'sent' ), 'status', 'status' );

		$this->assertStringContainsString( 'evreg_requeue_mail', $failed );
		$this->assertStringNotContainsString( 'evreg_requeue_mail', $sent );
	}
}
