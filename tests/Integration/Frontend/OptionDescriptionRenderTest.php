<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Frontend;

use EvReg\Domain\Schema\FormSchema;
use EvReg\Frontend\FormRenderer;
use WP_UnitTestCase;

final class OptionDescriptionRenderTest extends WP_UnitTestCase {

	private FormRenderer $renderer;

	protected function setUp(): void {
		parent::setUp();
		$this->renderer = new FormRenderer();
	}

	/**
	 * @param array<string,mixed> $field Nadpisania pola wyboru.
	 */
	private function schema( array $field ): FormSchema {
		return FormSchema::fromArray(
			array(
				'version'  => 1,
				'sections' => array(
					array(
						'key'    => 'dane',
						'title'  => 'Dane',
						'fields' => array(
							array(
								'key'     => '__type',
								'type'    => 'radio',
								'label'   => 'Typ',
								'options' => array( array( 'value' => 'uczestnik', 'label' => 'Uczestnik' ) ),
							),
							$field,
						),
					),
				),
			)
		);
	}

	private function choiceField( string $type ): array {
		return array(
			'key'     => 'wariant',
			'type'    => $type,
			'label'   => 'Wariant uczestnictwa',
			'options' => array(
				array(
					'value'       => 'warsztat',
					'label'       => 'Warsztat',
					'description' => 'Program: <a href="https://example.com/program">zobacz</a>',
				),
				array( 'value' => 'wyklad', 'label' => 'Wykład' ),
			),
		);
	}

	public function test_radio_option_description_is_hidden_and_tied_to_its_option(): void {
		$html = $this->renderer->render( $this->schema( $this->choiceField( 'radio' ) ), 1 );

		$this->assertStringContainsString( 'evreg-option-desc', $html );
		$this->assertStringContainsString( 'data-evreg-when-field="wariant"', $html );
		$this->assertStringContainsString( 'data-evreg-when-operator="equals"', $html );
		$this->assertStringContainsString( 'data-evreg-when-value="&quot;warsztat&quot;"', $html );
		$this->assertMatchesRegularExpression( '/<p class="evreg-option-desc[^"]*"[^>]*hidden>/', $html );
	}

	public function test_option_description_keeps_link_and_drops_script(): void {
		$field                       = $this->choiceField( 'radio' );
		$field['options'][0]['description'] = 'Program: <a href="https://example.com/program">zobacz</a><script>alert(1)</script>';

		$html = $this->renderer->render( $this->schema( $field ), 1 );

		$this->assertStringContainsString( '<a href="https://example.com/program">zobacz</a>', $html );
		$this->assertStringNotContainsString( '<script>', $html );
	}

	public function test_option_without_description_renders_no_hint(): void {
		$html = $this->renderer->render( $this->schema( $this->choiceField( 'radio' ) ), 1 );

		// Tylko jedna opcja ma opis — drugi akapit nie powstaje.
		$this->assertSame( 1, substr_count( $html, 'evreg-option-desc' ) );
	}

	public function test_checkbox_group_uses_in_operator(): void {
		$html = $this->renderer->render( $this->schema( $this->choiceField( 'checkbox-group' ) ), 1 );

		$this->assertStringContainsString( 'data-evreg-when-operator="in"', $html );
	}

	public function test_select_renders_hints_after_the_control(): void {
		$html = $this->renderer->render( $this->schema( $this->choiceField( 'select' ) ), 1 );

		$this->assertStringContainsString( 'evreg-option-desc', $html );
		$this->assertStringContainsString( 'data-evreg-when-field="wariant"', $html );
		$this->assertGreaterThan( strpos( $html, '</select>' ), strpos( $html, 'evreg-option-desc' ) );
	}
}
