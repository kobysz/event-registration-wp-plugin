<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Admin;

use EvReg\Admin\Capabilities;
use EvReg\Admin\RegistrationEditForm;
use EvReg\Admin\RegistrationsScreen;
use EvReg\Admin\RegistrationView;
use EvReg\Domain\Accommodation\NightsConfig;
use EvReg\Domain\Schema\FormSchema;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use WP_UnitTestCase;

/**
 * Render ekranu ręcznego dodawania: rama formularza, wybór wydarzenia, znacznik na detalu.
 */
final class ManualAddFormRenderTest extends WP_UnitTestCase {

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( 'registrations' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		Capabilities::grant();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->event_id = self::factory()->post->create(
			array(
				'post_type'  => 'evreg_event',
				'post_title' => 'Zjazd <b>wiosenny</b>',
			)
		);

		( new EventConfigRepository() )->save(
			$this->event_id,
			array(
				'schema' => array(
					'version'  => 1,
					'sections' => array(
						array(
							'key'    => 'dane',
							'title'  => 'Dane',
							'fields' => array(
								array( 'key' => '__type', 'type' => 'radio', 'label' => 'Typ' ),
								array( 'key' => 'email', 'type' => 'email', 'label' => 'E-mail' ),
								array( 'key' => 'name', 'type' => 'text', 'label' => 'Imię i nazwisko' ),
							),
						),
					),
				),
				'types'  => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 0.0 ) ),
			)
		);
	}

	protected function tearDown(): void {
		unset( $_GET['action'], $_GET['id'] );
		parent::tearDown();
	}

	private function schema(): FormSchema {
		return FormSchema::fromArray(
			array(
				'version'  => 1,
				'sections' => array(
					array(
						'key'    => 'dane',
						'title'  => 'Dane',
						'fields' => array(
							array( 'key' => 'email', 'type' => 'email', 'label' => 'E-mail' ),
							array( 'key' => 'name', 'type' => 'text', 'label' => 'Imię i nazwisko' ),
							array( 'key' => 'nocleg', 'type' => 'accommodation', 'label' => 'Nocleg' ),
						),
					),
				),
			)
		);
	}

	private function view(): RegistrationView {
		return new RegistrationView( new RegistrationRepository(), new EventConfigRepository() );
	}

	public function test_add_form_has_add_nonce_event_id_and_no_registration_field(): void {
		$html = RegistrationEditForm::renderAdd( $this->schema(), 7 );

		$this->assertStringContainsString( 'name="action" value="' . RegistrationsScreen::ACTION_ADD . '"', $html );
		$this->assertStringContainsString( 'name="event_id" value="7"', $html );
		$this->assertStringNotContainsString( 'name="registration"', $html );
		$this->assertStringNotContainsString( 'evreg_edit_registration', $html );

		preg_match( '/name="_wpnonce" value="([^"]+)"/', $html, $m );
		$this->assertNotEmpty( $m, 'nonce field present' );
		$this->assertNotFalse( wp_verify_nonce( $m[1], 'evreg_add_7' ) );
		$this->assertFalse( wp_verify_nonce( $m[1], 'evreg_edit_7' ) );
	}

	public function test_add_form_fields_are_empty_and_notify_toggle_is_unchecked(): void {
		$html = RegistrationEditForm::renderAdd( $this->schema(), 7 );

		$this->assertMatchesRegularExpression( '/name="evreg_field\[email\]"[^>]*value=""/', $html );
		$this->assertMatchesRegularExpression( '/name="evreg_field\[name\]"[^>]*value=""/', $html );

		$this->assertSame( 1, preg_match( '/<input type="checkbox" name="evreg_notify"[^>]*>/', $html, $m ) );
		$this->assertStringNotContainsString( 'checked', $m[0] );
		$this->assertStringContainsString( 'Dodaj zgłoszenie', $html );
		$this->assertStringNotContainsString( 'Zapisz zmiany', $html );
	}

	public function test_add_form_does_not_promise_organizers_a_copy(): void {
		$html = RegistrationEditForm::renderAdd( $this->schema(), 7 );

		$this->assertStringNotContainsStringIgnoringCase( 'organizator', $html );
	}

	public function test_add_form_restores_submitted_values_and_toggle_on_error(): void {
		$html = RegistrationEditForm::renderAdd(
			$this->schema(),
			7,
			array( 'email' => 'invalid_email' ),
			array(
				'email'        => 'zle"<x>',
				'evreg_notify' => '1',
			)
		);

		$this->assertStringContainsString( 'Nieprawidłowy adres e-mail.', $html );
		$this->assertStringContainsString( 'value="zle&quot;&lt;x&gt;"', $html );
		$this->assertMatchesRegularExpression( '/<input type="checkbox" name="evreg_notify"[^>]*checked/', $html );
	}

	public function test_night_full_error_names_the_night_instead_of_generic_message(): void {
		$nights = NightsConfig::fromArray(
			array(
				'mode'   => 'nights',
				'nights' => array( array( 'key' => 'n1819', 'label' => '18/19 XI', 'price' => 80.0, 'seats' => 0 ) ),
			)
		);

		$html = RegistrationEditForm::renderAdd( $this->schema(), 7, array( 'nocleg' => 'night_full:n1819' ), array(), $nights );

		$this->assertStringContainsString( '18/19 XI', $html );
		$this->assertStringContainsString( 'nie ma już wolnych miejsc', $html );
		$this->assertStringNotContainsString( 'Nieprawidłowa wartość.', $html );
	}

	public function test_edit_form_keeps_its_own_frame(): void {
		$html = RegistrationEditForm::renderEdit( $this->schema(), array(), 42 );

		$this->assertStringContainsString( 'name="action" value="evreg_edit_registration"', $html );
		$this->assertStringContainsString( 'name="registration" value="42"', $html );
		$this->assertStringContainsString( 'Zapisz zmiany', $html );
		$this->assertStringNotContainsString( 'evreg_notify', $html );
		$this->assertStringNotContainsString( 'name="event_id"', $html );
	}

	public function test_add_screen_without_event_shows_picker_and_no_registration_form(): void {
		ob_start();
		$this->view()->render_add( 0 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="event_id"', $html );
		$this->assertStringContainsString( 'value="' . $this->event_id . '"', $html );
		$this->assertStringContainsString( 'Zjazd &lt;b&gt;wiosenny&lt;/b&gt;', $html );
		$this->assertStringNotContainsString( 'name="evreg_notify"', $html );
		$this->assertStringNotContainsString( 'admin-post.php', $html );
		$this->assertStringNotContainsString( 'name="_wpnonce"', $html );
	}

	public function test_add_screen_with_event_renders_the_form(): void {
		ob_start();
		$this->view()->render_add( $this->event_id );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="event_id" value="' . $this->event_id . '"', $html );
		$this->assertStringContainsString( 'name="evreg_field[email]"', $html );
		$this->assertStringContainsString( 'name="evreg_notify"', $html );
	}

	private function seedRegistration( int $manual ): int {
		return ( new RegistrationRepository() )->insertRegistration(
			array(
				'event_id'    => $this->event_id,
				'type_key'    => 'uczestnik',
				'status'      => 'confirmed',
				'email'       => 'jan@example.com',
				'name'        => 'Jan',
				'token'       => str_repeat( 'd', 32 ),
				'data'        => '{}',
				'price_total' => 0.0,
				'expires_at'  => null,
				'manual'      => $manual,
			)
		);
	}

	private function renderDetail( int $id ): string {
		$_GET['action'] = 'view';
		$_GET['id']     = $id;

		ob_start();
		RegistrationsScreen::render();

		return (string) ob_get_clean();
	}

	public function test_detail_marks_manual_entries_only(): void {
		$manual = $this->renderDetail( $this->seedRegistration( 1 ) );
		$public = $this->renderDetail( $this->seedRegistration( 0 ) );

		$this->assertStringContainsString( 'Wpis ręczny', $manual );
		$this->assertStringNotContainsString( 'Wpis ręczny', $public );
	}
}
