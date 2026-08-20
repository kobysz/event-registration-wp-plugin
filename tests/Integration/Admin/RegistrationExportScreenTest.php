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
final class RegExportRedirectException extends \Exception {

	public string $location;

	public function __construct( string $location ) {
		parent::__construct( 'redirect' );
		$this->location = $location;
	}
}

final class RegistrationExportScreenTest extends WP_UnitTestCase {

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
		unset( $_GET['status'], $_GET['type_key'], $_GET['event_id'], $_GET['evreg_msg'], $_GET['action'], $_REQUEST['_wpnonce'] );
		parent::tearDown();
	}

	private function catchRedirect( callable $fn ): ?RegExportRedirectException {
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new RegExportRedirectException( (string) $location );
			}
		);

		try {
			$fn();
		} catch ( RegExportRedirectException $e ) {
			return $e;
		}

		return null;
	}

	public function test_handle_export_bad_nonce_dies(): void {
		wp_set_current_user( $this->admin_id );

		$_GET['event_id']    = $this->event_id;
		$_REQUEST['_wpnonce'] = 'zły';

		$this->expectException( \WPDieException::class );
		RegistrationsScreen::handle_export();
	}

	public function test_handle_export_missing_nonce_dies(): void {
		wp_set_current_user( $this->admin_id );

		$_GET['event_id'] = $this->event_id;
		unset( $_REQUEST['_wpnonce'] );

		$this->expectException( \WPDieException::class );
		RegistrationsScreen::handle_export();
	}

	public function test_handle_export_without_cap_dies(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$_GET['event_id']     = $this->event_id;
		$_REQUEST['_wpnonce'] = wp_create_nonce( RegistrationsScreen::ACTION_EXPORT );

		$this->expectException( \WPDieException::class );
		RegistrationsScreen::handle_export();
	}

	public function test_handle_export_without_event_id_redirects_with_notice_and_does_not_stream(): void {
		wp_set_current_user( $this->admin_id );

		// event_id nie ustawiony -> traktowany jako 0.
		$_REQUEST['_wpnonce'] = wp_create_nonce( RegistrationsScreen::ACTION_EXPORT );

		$redirect = $this->catchRedirect( array( RegistrationsScreen::class, 'handle_export' ) );

		$this->assertNotNull( $redirect, 'Brak event_id powinien przekierować (PRG), nie streamować pliku.' );
		$this->assertStringContainsString( 'evreg_msg=export_no_event', $redirect->location );
	}

	public function test_notice_renders_for_export_no_event(): void {
		wp_set_current_user( $this->admin_id );
		set_current_screen( 'evreg_event_page_' . RegistrationsScreen::SLUG );

		$_GET['evreg_msg'] = 'export_no_event';

		ob_start();
		RegistrationsScreen::render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', (string) $html );
		$this->assertStringContainsString( 'Wybierz event', (string) $html );
	}

	public function test_export_button_renders_with_nonce_and_current_filters(): void {
		wp_set_current_user( $this->admin_id );
		set_current_screen( 'evreg_event_page_' . RegistrationsScreen::SLUG );

		$_GET['status']    = 'pending';
		$_GET['event_id']  = $this->event_id;

		ob_start();
		RegistrationsScreen::render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Eksportuj CSV', (string) $html );
		$this->assertStringContainsString( 'action=' . RegistrationsScreen::ACTION_EXPORT, (string) $html );
		$this->assertStringContainsString( 'event_id=' . $this->event_id, (string) $html );
		$this->assertStringContainsString( 'status=pending', (string) $html );
		$this->assertStringContainsString( '_wpnonce=', (string) $html );
	}
}
