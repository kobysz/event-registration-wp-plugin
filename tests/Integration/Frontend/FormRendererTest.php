<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Frontend;

use EvReg\Domain\Schema\FormSchema;
use EvReg\Frontend\FormRenderer;
use EvReg\Frontend\SubmitResult;
use WP_UnitTestCase;

final class FormRendererTest extends WP_UnitTestCase {

	private FormRenderer $renderer;

	protected function setUp(): void {
		parent::setUp();
		$this->renderer = new FormRenderer();
	}

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
								'type'    => 'radio',
								'label'   => 'Typ zgłoszenia',
								'options' => array( array( 'value' => 'uczestnik', 'label' => 'Uczestnik' ) ),
							),
							array( 'key' => 'email', 'type' => 'email', 'label' => 'E-mail', 'required' => true ),
							array( 'key' => 'uwagi', 'type' => 'textarea', 'label' => 'Uwagi' ),
						),
					),
				),
			)
		);
	}

	private function accommodationSchema( bool $roommateField ): FormSchema {
		return FormSchema::fromArray(
			array(
				'version'  => 1,
				'sections' => array(
					array(
						'key'    => 'dane',
						'title'  => 'Dane',
						'fields' => array(
							array(
								'key'    => 'nocleg',
								'type'   => 'accommodation',
								'label'  => 'Nocleg',
								'config' => array(
									'packages'   => array( array( 'key' => 'std', 'label' => 'Standard' ) ),
									'rooms'      => array(
										array( 'key' => 'double', 'label' => 'Dwuosobowy', 'roommate_field' => $roommateField ),
									),
									'inventory'  => array(
										array( 'package' => 'std', 'room' => 'double', 'capacity' => 10, 'price' => 0 ),
									),
									'allow_none' => true,
								),
							),
						),
					),
				),
			)
		);
	}

	public function test_roommate_input_absent_when_no_room_allows_it(): void {
		$html = $this->renderer->render( $this->accommodationSchema( false ), 1 );

		$this->assertStringContainsString( 'name="evreg_field[nocleg][slot]"', $html );
		$this->assertStringNotContainsString( '[nocleg][roommate]', $html );
	}

	public function test_roommate_input_present_and_tagged_when_a_room_allows_it(): void {
		$html = $this->renderer->render( $this->accommodationSchema( true ), 1 );

		$this->assertStringContainsString( 'name="evreg_field[nocleg][roommate]"', $html );
		$this->assertStringContainsString( 'data-evreg-roommate="1"', $html );
	}

	public function test_single_checkbox_renders_label_inline_after_the_box(): void {
		$schema = FormSchema::fromArray(
			array(
				'version'  => 1,
				'sections' => array(
					array(
						'key'    => 'zgody',
						'title'  => 'Zgody',
						'fields' => array(
							array( 'key' => 'zgoda', 'type' => 'checkbox', 'label' => 'Wyrażam zgodę', 'required' => true ),
						),
					),
				),
			)
		);

		$html = $this->renderer->render( $schema, 1 );

		// Checkbox input poprzedza tekst etykiety w jednym, wewnętrznym <label>.
		$this->assertMatchesRegularExpression(
			'/<label class="evreg-checkbox-label"[^>]*>\s*<input type="checkbox"[^>]*>\s*<span[^>]*>Wyrażam zgodę/',
			$html
		);
		// Brak stackowanej etykiety blokowej dla checkboxa.
		$this->assertStringNotContainsString( '<label class="evreg-label" for="evreg-zgoda"', $html );
		// Gwiazdka wymagania nadal obecna.
		$this->assertStringContainsString( 'evreg-required', $html );
	}

	public function test_renders_form_with_fields_sections_and_hidden_controls(): void {
		$html = $this->renderer->render( $this->schema(), 123 );

		$this->assertStringContainsString( '<form', $html );
		$this->assertStringContainsString( 'Dane uczestnika', $html );
		$this->assertStringContainsString( 'name="evreg_field[email]"', $html );
		$this->assertStringContainsString( 'type="radio"', $html );
		$this->assertStringContainsString( 'value="uczestnik"', $html );
		$this->assertStringContainsString( 'name="evreg_event"', $html );
		$this->assertStringContainsString( 'value="123"', $html );
		$this->assertStringContainsString( 'name="evreg_submit"', $html );
		$this->assertStringContainsString( 'name="evreg_hp"', $html );
		$this->assertStringContainsString( 'name="evreg_ts"', $html );
		$this->assertStringContainsString( 'evreg-nonce', $html );
	}

	public function test_escapes_field_labels(): void {
		$schema = FormSchema::fromArray(
			array(
				'version'  => 1,
				'sections' => array(
					array(
						'key'    => 's',
						'title'  => 'S',
						'fields' => array(
							array( 'key' => '__type', 'type' => 'radio', 'label' => '<script>x</script>', 'options' => array( array( 'value' => 'a', 'label' => 'A' ) ) ),
						),
					),
				),
			)
		);

		$html = $this->renderer->render( $schema, 1 );

		$this->assertStringNotContainsString( '<script>x</script>', $html );
	}

	public function test_error_re_render_shows_message_and_preserves_value(): void {
		$result = SubmitResult::invalid(
			array( 'email' => 'invalid_email' ),
			array( 'email' => 'zły@@adres' )
		);

		$html = $this->renderer->render( $this->schema(), 1, $result );

		$this->assertStringContainsString( 'zły@@adres', $html );          // wartość odtworzona
		$this->assertStringContainsString( 'evreg-field-error', $html );   // klasa błędu
	}

	public function test_non_scalar_submitted_value_renders_as_empty_without_warning(): void {
		// Symuluje wrogie wejście `email[]=x`: Validator odrzuca pole, ale surowa
		// wartość z $_POST trafia do SubmitResult::invalid() jako tablica.
		$result = SubmitResult::invalid(
			array( 'email' => 'invalid_email' ),
			array( 'email' => array( 'x', 'y' ) )
		);

		$html = $this->renderer->render( $this->schema(), 1, $result );

		$this->assertStringContainsString( 'name="evreg_field[email]" value=""', $html );
		$this->assertStringNotContainsString( 'Array', $html );
	}
}
