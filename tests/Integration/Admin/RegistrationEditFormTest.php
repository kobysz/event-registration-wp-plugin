<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Admin;

use EvReg\Admin\RegistrationEditForm;
use EvReg\Domain\Schema\FormSchema;
use WP_UnitTestCase;

final class RegistrationEditFormTest extends WP_UnitTestCase {

	private function schema(): FormSchema {
		return FormSchema::fromArray(
			array(
				'version'  => 1,
				'sections' => array(
					array(
						'key'    => 'dane',
						'title'  => 'Dane uczestnika',
						'fields' => array(
							array(
								'key'     => '__type',
								'type'    => 'hidden',
								'label'   => 'Typ zgłoszenia',
							),
							array( 'key' => 'email', 'type' => 'email', 'label' => 'E-mail', 'required' => true ),
							array( 'key' => 'name', 'type' => 'text', 'label' => 'Imię i nazwisko' ),
						),
					),
				),
			)
		);
	}

	private function schemaWithChoicesAndAccommodation(): FormSchema {
		return FormSchema::fromArray(
			array(
				'version'  => 1,
				'sections' => array(
					array(
						'key'    => 'dane',
						'title'  => 'Dane',
						'fields' => array(
							array(
								'key'     => 'koszulka',
								'type'    => 'select',
								'label'   => 'Rozmiar koszulki',
								'options' => array(
									array( 'value' => 's', 'label' => 'S' ),
									array( 'value' => 'm', 'label' => 'M' ),
								),
							),
							array(
								'key'     => 'diety',
								'type'    => 'checkbox-group',
								'label'   => 'Diety',
								'options' => array(
									array( 'value' => 'wege', 'label' => 'Wegetariańska' ),
									array( 'value' => 'bezglutenowa', 'label' => 'Bezglutenowa' ),
								),
							),
							array(
								'key'    => 'nocleg',
								'type'   => 'accommodation',
								'label'  => 'Nocleg',
								'config' => array(
									'packages'  => array( array( 'key' => 'n1', 'label' => 'Noc 1' ) ),
									'rooms'     => array( array( 'key' => 'std', 'label' => 'Standard' ) ),
									'inventory' => array(
										array( 'package' => 'n1', 'room' => 'std', 'capacity' => 5, 'price' => 50.0 ),
									),
								),
							),
						),
					),
				),
			)
		);
	}

	public function test_renders_prefilled_form_posting_to_admin_post_with_nonce(): void {
		$answers = array(
			'email' => 'a@b.pl',
			'name'  => 'Jan',
			'__type' => 'std',
		);

		$html = RegistrationEditForm::render( $this->schema(), $answers, 42 );

		$this->assertStringContainsString( 'action="' . admin_url( 'admin-post.php' ) . '"', $html );
		$this->assertStringContainsString( 'name="action" value="evreg_edit_registration"', $html );
		$this->assertStringContainsString( 'name="registration" value="42"', $html );
		$this->assertStringContainsString( 'value="a@b.pl"', $html );

		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertMatchesRegularExpression( '/name="_wpnonce" value="([^"]+)"/', $html );
		preg_match( '/name="_wpnonce" value="([^"]+)"/', $html, $matches );
		$this->assertNotFalse( wp_verify_nonce( $matches[1], 'evreg_edit_42' ), 'nonce must verify against the registration-scoped action' );
	}

	public function test_errors_show_translated_message_with_field_label_and_submitted_values_take_precedence(): void {
		// Realny kształt errors() z SubmissionAssembler/Validator: klucz pola => kod błędu.
		$answers   = array( 'email' => 'old@example.com', 'name' => 'Old Name' );
		$submitted = array( 'email' => 'zła wartość', 'name' => 'New Name' );
		$errors    = array( 'email' => 'invalid_email' );

		$html = RegistrationEditForm::render( $this->schema(), $answers, 42, $errors, $submitted );

		// Etykieta pola, którego dotyczy błąd, jest widoczna przy komunikacie.
		$this->assertStringContainsString( 'E-mail', $html );
		// Komunikat jest PRZETŁUMACZONY (i18n), nie surowym kodem walidacji.
		$this->assertStringContainsString( 'Nieprawidłowy adres e-mail.', $html );
		$this->assertStringNotContainsString( '>invalid_email<', $html );
		$this->assertStringNotContainsString( 'invalid_email</li>', $html );

		$this->assertStringContainsString( 'value="zła wartość"', $html );
		$this->assertStringNotContainsString( 'value="old@example.com"', $html );
	}

	public function test_unknown_error_code_falls_back_to_generic_translated_message(): void {
		$html = RegistrationEditForm::render( $this->schema(), array(), 42, array( 'email' => 'some_future_code' ) );

		$this->assertStringContainsString( 'Nieprawidłowa wartość.', $html );
		$this->assertStringNotContainsString( 'some_future_code', $html );
	}

	public function test_skips_heading_and_paragraph_fields(): void {
		$schema = FormSchema::fromArray(
			array(
				'version'  => 1,
				'sections' => array(
					array(
						'key'    => 's',
						'title'  => 'S',
						'fields' => array(
							array( 'key' => 'naglowek', 'type' => 'heading', 'label' => 'Sekcja nagłówek' ),
							array( 'key' => 'opis', 'type' => 'paragraph', 'label' => 'Opis akapitu' ),
							array( 'key' => 'email', 'type' => 'email', 'label' => 'E-mail' ),
						),
					),
				),
			)
		);

		$html = RegistrationEditForm::render( $schema, array( 'email' => 'a@b.pl' ), 1 );

		$this->assertStringNotContainsString( 'Sekcja nagłówek', $html );
		$this->assertStringNotContainsString( 'Opis akapitu', $html );
		$this->assertStringContainsString( 'name="evreg_field[email]"', $html );
	}

	public function test_renders_choice_controls_with_selected_options(): void {
		$answers = array(
			'koszulka' => 'm',
			'diety'    => array( 'wege' ),
		);

		$html = RegistrationEditForm::render( $this->schemaWithChoicesAndAccommodation(), $answers, 7 );

		$this->assertStringContainsString( 'name="evreg_field[koszulka]"', $html );
		$this->assertMatchesRegularExpression( '/<option value="m"[^>]*selected[^>]*>M<\/option>/', $html );
		$this->assertMatchesRegularExpression( '/name="evreg_field\[diety\]\[\]" value="wege"[^>]*checked/', $html );
	}

	public function test_renders_accommodation_control_with_selected_slot_and_roommate(): void {
		$answers = array(
			'nocleg' => array(
				'package'  => 'n1',
				'room'     => 'std',
				'roommate' => 'Ala',
			),
		);

		$html = RegistrationEditForm::render( $this->schemaWithChoicesAndAccommodation(), $answers, 7 );

		$this->assertStringContainsString( 'value="n1|std"', $html );
		$this->assertMatchesRegularExpression( '/value="n1\|std"[^>]*selected/', $html );
		$this->assertStringContainsString( 'value="Ala"', $html );
	}

	public function test_no_errors_renders_no_notice(): void {
		$html = RegistrationEditForm::render( $this->schema(), array( 'email' => 'a@b.pl' ), 1 );

		$this->assertStringNotContainsString( 'notice-error', $html );
	}
}
