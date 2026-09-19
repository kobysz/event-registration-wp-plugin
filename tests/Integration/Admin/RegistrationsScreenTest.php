<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Admin;

use EvReg\Admin\Capabilities;
use EvReg\Admin\RegistrationsScreen;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use WP_UnitTestCase;

/**
 * Wyjątek przenoszący adres redirectu, by przerwać przed exit handlera.
 */
final class RegRedirectException extends \Exception {

	public string $location;

	public function __construct( string $location ) {
		parent::__construct( 'redirect' );
		$this->location = $location;
	}
}

final class RegistrationsScreenTest extends WP_UnitTestCase {

	private RegistrationRepository $repository;

	private int $admin_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'locks', 'mail_queue' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$this->repository = new RegistrationRepository();
		Capabilities::grant();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	protected function tearDown(): void {
		remove_all_filters( 'wp_redirect' );
		unset( $_POST['id'], $_REQUEST['id'], $_REQUEST['_wpnonce'], $_GET['id'], $_POST['note'] );
		parent::tearDown();
	}

	private function seed( string $status ): int {
		return $this->repository->insertRegistration(
			array(
				'event_id'    => self::factory()->post->create( array( 'post_type' => 'evreg_event' ) ),
				'type_key'    => 'uczestnik',
				'status'      => $status,
				'email'       => 'jan@example.com',
				'name'        => 'Jan',
				'token'       => str_repeat( 'a', 32 ),
				'data'        => '{}',
				'price_total' => 0.0,
				'expires_at'  => '2099-01-01 00:00:00',
			)
		);
	}

	private function catchRedirect( callable $fn ): ?RegRedirectException {
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new RegRedirectException( (string) $location );
			}
		);

		try {
			$fn();
		} catch ( RegRedirectException $e ) {
			return $e;
		}

		return null;
	}

	public function test_submenu_registered(): void {
		wp_set_current_user( $this->admin_id );
		set_current_screen( 'dashboard' );

		RegistrationsScreen::add_menu();

		global $submenu;
		$slugs = array();
		foreach ( $submenu['edit.php?post_type=evreg_event'] ?? array() as $entry ) {
			$slugs[] = $entry[2];
		}

		$this->assertContains( RegistrationsScreen::SLUG, $slugs );
	}

	public function test_confirm_happy_path(): void {
		wp_set_current_user( $this->admin_id );
		$id = $this->seed( 'pending' );

		$_POST['id']          = $id;
		$_REQUEST['id']       = $id;
		$_REQUEST['_wpnonce'] = wp_create_nonce( RegistrationsScreen::ACTION_CONFIRM . '_' . $id );

		$redirect = $this->catchRedirect( array( RegistrationsScreen::class, 'handle_confirm' ) );

		$this->assertNotNull( $redirect );
		$this->assertStringContainsString( 'evreg_msg=confirmed', $redirect->location );
		$this->assertSame( 'confirmed', $this->repository->findById( $id )['status'] );
	}

	public function test_cancel_happy_path(): void {
		wp_set_current_user( $this->admin_id );
		$id = $this->seed( 'confirmed' );

		$_POST['id']          = $id;
		$_REQUEST['id']       = $id;
		$_REQUEST['_wpnonce'] = wp_create_nonce( RegistrationsScreen::ACTION_CANCEL . '_' . $id );

		$redirect = $this->catchRedirect( array( RegistrationsScreen::class, 'handle_cancel' ) );

		$this->assertStringContainsString( 'evreg_msg=cancelled', $redirect->location );
		$this->assertSame( 'cancelled', $this->repository->findById( $id )['status'] );
	}

	public function test_note_saved(): void {
		wp_set_current_user( $this->admin_id );
		$id = $this->seed( 'pending' );

		$_POST['id']          = $id;
		$_REQUEST['id']       = $id;
		$_POST['note']        = 'Notatka testowa';
		$_REQUEST['_wpnonce'] = wp_create_nonce( RegistrationsScreen::ACTION_NOTE . '_' . $id );

		$this->catchRedirect( array( RegistrationsScreen::class, 'handle_note' ) );

		$this->assertSame( 'Notatka testowa', $this->repository->findById( $id )['note'] );
	}

	public function test_detail_shows_companion_row_when_present(): void {
		wp_set_current_user( $this->admin_id );
		$id = $this->repository->insertRegistration(
			array(
				'event_id'       => self::factory()->post->create( array( 'post_type' => 'evreg_event' ) ),
				'type_key'       => 'uczestnik',
				'status'         => 'confirmed',
				'email'          => 'jan@example.com',
				'name'           => 'Jan',
				'token'          => str_repeat( 'b', 32 ),
				'data'           => '{}',
				'price_total'    => 0.0,
				'expires_at'     => '2099-01-01 00:00:00',
				'companion'      => 1,
				'companion_name' => 'Jan T.',
			)
		);

		$_GET['action'] = 'view';
		$_GET['id']     = $id;

		ob_start();
		RegistrationsScreen::render();
		$html = ob_get_clean();

		unset( $_GET['action'] );

		$this->assertStringContainsString( 'Osoba towarzysząca', (string) $html );
		$this->assertStringContainsString( 'Jan T.', (string) $html );
	}

	public function test_detail_hides_companion_row_when_absent(): void {
		wp_set_current_user( $this->admin_id );
		$id = $this->seed( 'confirmed' );

		$_GET['action'] = 'view';
		$_GET['id']     = $id;

		ob_start();
		RegistrationsScreen::render();
		$html = ob_get_clean();

		unset( $_GET['action'] );

		$this->assertStringNotContainsString( 'Osoba towarzysząca', (string) $html );
	}

	public function test_confirm_without_cap_dies(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		$id = $this->seed( 'pending' );

		$_POST['id']          = $id;
		$_REQUEST['id']       = $id;
		$_REQUEST['_wpnonce'] = wp_create_nonce( RegistrationsScreen::ACTION_CONFIRM . '_' . $id );

		$this->expectException( \WPDieException::class );
		RegistrationsScreen::handle_confirm();
	}

	public function test_confirm_bad_nonce_dies(): void {
		wp_set_current_user( $this->admin_id );
		$id = $this->seed( 'pending' );

		$_POST['id']          = $id;
		$_REQUEST['id']       = $id;
		$_REQUEST['_wpnonce'] = 'zły';

		$this->expectException( \WPDieException::class );
		RegistrationsScreen::handle_confirm();
	}
}
