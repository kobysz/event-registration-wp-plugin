<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Admin;

use EvReg\Admin\Capabilities;
use EvReg\Admin\RegistrationsScreen;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use WP_UnitTestCase;

/**
 * Wyjątek przenoszący adres redirectu, by przerwać przed exit handlera.
 */
final class RegEditRedirectException extends \Exception {

	public string $location;

	public function __construct( string $location ) {
		parent::__construct( 'redirect' );
		$this->location = $location;
	}
}

final class RegistrationEditScreenTest extends WP_UnitTestCase {

	private RegistrationRepository $repository;

	private int $admin_id;

	private int $event_id;

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
		$this->event_id = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );

		( new EventConfigRepository() )->save(
			$this->event_id,
			array(
				'schema'        => array(
					'version'  => 1,
					'sections' => array(
						array(
							'key'    => 'dane',
							'title'  => 'Dane',
							'fields' => array(
								array( 'key' => '__type', 'type' => 'radio', 'label' => 'Typ' ),
								array( 'key' => 'imie', 'type' => 'text', 'label' => 'Imię', 'required' => true ),
								array( 'key' => 'email', 'type' => 'email', 'label' => 'E-mail', 'required' => true ),
							),
						),
					),
				),
				'types'         => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 100.0, 'capacity' => 10 ) ),
				'accommodation' => array(
					'packages'  => array(),
					'rooms'     => array(),
					'inventory' => array(),
				),
				'settings'      => array( 'global_cap' => null, 'waitlist_enabled' => true ),
			)
		);
	}

	protected function tearDown(): void {
		remove_all_filters( 'wp_redirect' );
		unset( $_POST['registration'], $_REQUEST['_wpnonce'], $_GET['action'], $_GET['id'], $_POST['__type'], $_POST['imie'], $_POST['email'] );
		parent::tearDown();
	}

	private function seed( string $status, string $type_key = 'uczestnik' ): int {
		static $counter = 0;
		++$counter;

		return $this->repository->insertRegistration(
			array(
				'event_id'    => $this->event_id,
				'type_key'    => $type_key,
				'status'      => $status,
				'email'       => 'jan' . $counter . '@example.com',
				'name'        => 'Jan',
				'token'       => str_pad( (string) $counter, 32, 'a', STR_PAD_LEFT ),
				'data'        => '{}',
				'price_total' => 0.0,
				'expires_at'  => '2099-01-01 00:00:00',
			)
		);
	}

	private function catchRedirect( callable $fn ): ?RegEditRedirectException {
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new RegEditRedirectException( (string) $location );
			}
		);

		try {
			$fn();
		} catch ( RegEditRedirectException $e ) {
			return $e;
		}

		return null;
	}

	public function test_handle_edit_bad_nonce_dies(): void {
		wp_set_current_user( $this->admin_id );
		$id = $this->seed( 'pending' );

		$_POST['registration']  = $id;
		$_REQUEST['_wpnonce']   = 'zły';

		$this->expectException( \WPDieException::class );
		RegistrationsScreen::handle_edit();
	}

	public function test_handle_edit_without_cap_dies(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		$id = $this->seed( 'pending' );

		$_POST['registration'] = $id;
		$_REQUEST['_wpnonce']  = wp_create_nonce( 'evreg_edit_' . $id );

		$this->expectException( \WPDieException::class );
		RegistrationsScreen::handle_edit();
	}

	public function test_handle_edit_valid_post_updates_and_redirects_to_view(): void {
		wp_set_current_user( $this->admin_id );
		$id = $this->seed( 'pending' );

		$_POST['registration'] = $id;
		$_REQUEST['_wpnonce']  = wp_create_nonce( 'evreg_edit_' . $id );
		$_POST['__type']       = 'uczestnik';
		$_POST['imie']         = 'Jan Nowy';
		$_POST['email']        = 'nowy@example.com';

		$redirect = $this->catchRedirect( array( RegistrationsScreen::class, 'handle_edit' ) );

		$this->assertNotNull( $redirect, 'Poprawny POST powinien zakończyć się przekierowaniem (PRG).' );
		$this->assertStringContainsString( 'evreg_msg=edited', $redirect->location );
		$this->assertStringContainsString( 'action=view', $redirect->location );
		$this->assertStringContainsString( 'id=' . $id, $redirect->location );

		$row = $this->repository->findById( $id );
		$this->assertSame( 'nowy@example.com', $row['email'] );
		$this->assertSame( 'Jan Nowy', $row['name'] );
	}

	public function test_handle_edit_invalid_post_rerenders_form_without_redirect(): void {
		wp_set_current_user( $this->admin_id );
		$id = $this->seed( 'pending' );

		$_POST['registration'] = $id;
		$_REQUEST['_wpnonce']  = wp_create_nonce( 'evreg_edit_' . $id );
		$_POST['__type']       = 'uczestnik';
		$_POST['imie']         = 'Jan Nowy';
		$_POST['email']        = ''; // required, missing -> validation error.

		$redirect = null;
		ob_start();
		try {
			$redirect = $this->catchRedirect( array( RegistrationsScreen::class, 'handle_edit' ) );
		} finally {
			$html = ob_get_clean();
		}

		$this->assertNull( $redirect, 'Niepoprawny POST nie powinien przekierowywać (inline re-render).' );
		$this->assertStringContainsString( 'name="action" value="evreg_edit_registration"', (string) $html );
		$this->assertStringContainsString( 'notice-error', (string) $html );

		// Wartość zgłoszenia w bazie nie zmieniła się.
		$row = $this->repository->findById( $id );
		$this->assertSame( 'Jan', $row['name'] );
	}

	public function test_render_edit_on_cancelled_registration_does_not_render_form(): void {
		wp_set_current_user( $this->admin_id );
		$id = $this->seed( 'cancelled' );

		$_GET['action'] = 'edit';
		$_GET['id']     = $id;

		ob_start();
		RegistrationsScreen::render();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'name="action" value="evreg_edit_registration"', (string) $html );
		$this->assertStringContainsString( 'notice-error', (string) $html );
	}
}
