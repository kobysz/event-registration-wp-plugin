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

	public function test_errors_are_escaped_and_submitted_values_take_precedence_over_answers(): void {
		$answers   = array( 'email' => 'old@example.com', 'name' => 'Old Name' );
		$submitted = array( 'email' => 'new@example.com', 'name' => 'New Name' );
		$errors    = array(
			array( 'field' => 'email', 'detail' => '<b>zły</b> adres' ),
		);

		$html = RegistrationEditForm::render( $this->schema(), $answers, 42, $errors, $submitted );

		$this->assertStringContainsString( '&lt;b&gt;zły&lt;/b&gt; adres', $html );
		$this->assertStringNotContainsString( '<b>zły</b>', $html );
		$this->assertStringContainsString( 'value="new@example.com"', $html );
		$this->assertStringNotContainsString( 'value="old@example.com"', $html );
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
		$this->assertStringContainsString( 'name="email"', $html );
	}

	public function test_renders_choice_controls_with_selected_options(): void {
		$answers = array(
			'koszulka' => 'm',
			'diety'    => array( 'wege' ),
		);

		$html = RegistrationEditForm::render( $this->schemaWithChoicesAndAccommodation(), $answers, 7 );

		$this->assertStringContainsString( 'name="koszulka"', $html );
		$this->assertMatchesRegularExpression( '/<option value="m"[^>]*selected[^>]*>M<\/option>/', $html );
		$this->assertMatchesRegularExpression( '/name="diety\[\]" value="wege"[^>]*checked/', $html );
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
