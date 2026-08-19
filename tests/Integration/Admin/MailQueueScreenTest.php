<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Admin;

use EvReg\Admin\Capabilities;
use EvReg\Admin\MailQueueScreen;
use EvReg\Persistence\MailQueueRepository;
use EvReg\Persistence\Migrations;
use WP_UnitTestCase;

/**
 * Wyjątek przenoszący docelowy adres redirectu, by przerwać przed exit handlera.
 */
final class RedirectException extends \Exception {

	public string $location;

	public function __construct( string $location ) {
		parent::__construct( 'redirect' );
		$this->location = $location;
	}
}

final class MailQueueScreenTest extends WP_UnitTestCase {

	private MailQueueRepository $repository;

	private int $admin_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( 'mail_queue' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->repository = new MailQueueRepository();
		Capabilities::grant();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	protected function tearDown(): void {
		remove_all_filters( 'wp_redirect' );
		unset( $_POST['id'], $_REQUEST['id'], $_REQUEST['_wpnonce'], $_GET['id'] );
		parent::tearDown();
	}

	private function seedFailed(): int {
		global $wpdb;

		$this->repository->insert(
			array(
				'registration_id' => null,
				'event_id'        => 1,
				'template_key'    => 'optin',
				'recipient'       => 'jan@example.com',
				'subject'         => 'Temat',
				'body'            => 'Treść',
				'headers'         => '',
				'scheduled_at'    => '2026-08-19 10:00:00',
			)
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$id = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . Migrations::table( 'mail_queue' ) );
		$wpdb->update( Migrations::table( 'mail_queue' ), array( 'status' => 'failed' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $id;
	}

	public function test_submenu_registered_under_event_cpt(): void {
		wp_set_current_user( $this->admin_id );
		set_current_screen( 'dashboard' );

		MailQueueScreen::add_menu();

		global $submenu;
		$parent = 'edit.php?post_type=evreg_event';
		$slugs  = array();
		foreach ( $submenu[ $parent ] ?? array() as $entry ) {
			$slugs[] = $entry[2];
		}

		$this->assertContains( MailQueueScreen::SLUG, $slugs );
	}

	public function test_requeue_happy_path_resets_row_and_redirects(): void {
		wp_set_current_user( $this->admin_id );
		$id = $this->seedFailed();

		$_POST['id']            = $id;
		$_REQUEST['id']         = $id;
		$_REQUEST['_wpnonce']   = wp_create_nonce( 'evreg_requeue_mail_' . $id );

		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new RedirectException( (string) $location );
			}
		);

		$caught = null;
		try {
			MailQueueScreen::handle_requeue();
		} catch ( RedirectException $e ) {
			$caught = $e;
		}

		$this->assertNotNull( $caught );
		$this->assertStringContainsString( 'evreg_requeued=1', $caught->location );
		$this->assertSame( MailQueueRepository::STATUS_QUEUED, $this->repository->find( $id )['status'] );
	}

	public function test_requeue_without_capability_dies(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		$id = $this->seedFailed();

		$_POST['id']          = $id;
		$_REQUEST['id']       = $id;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'evreg_requeue_mail_' . $id );

		$this->expectException( \WPDieException::class );
		MailQueueScreen::handle_requeue();
	}

	public function test_requeue_with_bad_nonce_dies(): void {
		wp_set_current_user( $this->admin_id );
		$id = $this->seedFailed();

		$_POST['id']          = $id;
		$_REQUEST['id']       = $id;
		$_REQUEST['_wpnonce'] = 'zły-nonce';

		$this->expectException( \WPDieException::class );
		MailQueueScreen::handle_requeue();
	}
}
