<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Mail;

use EvReg\Domain\Conditions\ConditionEngine;
use EvReg\Domain\Mail\SummaryBuilder;
use EvReg\Domain\Schema\FormSchema;
use EvReg\Domain\Schema\VisibilityResolver;
use PHPUnit\Framework\TestCase;

final class SummaryBuilderTest extends TestCase {

	private SummaryBuilder $builder;

	protected function setUp(): void {
		$this->builder = new SummaryBuilder( new VisibilityResolver( new ConditionEngine() ), 'Tak' );
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
							array( 'key' => 'naglowek', 'type' => 'heading', 'label' => 'Twoje dane' ),
							array( 'key' => '__type', 'type' => 'radio', 'label' => 'Typ', 'options' => array( array( 'value' => 'uczestnik', 'label' => 'Uczestnik' ) ) ),
							array( 'key' => 'imie', 'type' => 'text', 'label' => 'Imię i nazwisko' ),
							array( 'key' => 'email', 'type' => 'email', 'label' => 'E-mail' ),
							array( 'key' => 'dieta', 'type' => 'select', 'label' => 'Dieta', 'options' => array( array( 'value' => 'wege', 'label' => 'Wegetariańska' ) ) ),
							array( 'key' => 'warsztaty', 'type' => 'checkbox-group', 'label' => 'Warsztaty', 'options' => array( array( 'value' => 'a', 'label' => 'Warsztat A' ), array( 'value' => 'b', 'label' => 'Warsztat B' ) ) ),
							array( 'key' => 'zgoda', 'type' => 'checkbox', 'label' => 'Zgoda na regulamin' ),
							array( 'key' => 'faktura', 'type' => 'text', 'label' => 'Dane do faktury', 'condition' => array( 'field' => '__type', 'operator' => 'in', 'value' => array( 'firma' ) ) ),
						),
					),
				),
			)
		);
	}

	public function test_lists_visible_answers_in_schema_order(): void {
		$summary = $this->builder->build(
			$this->schema(),
			array(
				'__type' => 'uczestnik',
				'imie'   => 'Jan Kowalski',
				'email'  => 'jan@example.com',
				'zgoda'  => true,
			)
		);

		$this->assertSame(
			"Imię i nazwisko: Jan Kowalski\nE-mail: jan@example.com\nZgoda na regulamin: Tak",
			$summary
		);
	}

	public function test_uses_option_labels_and_joins_multi_values(): void {
		$summary = $this->builder->build(
			$this->schema(),
			array(
				'__type'    => 'uczestnik',
				'imie'      => 'Jan',
				'dieta'     => 'wege',
				'warsztaty' => array( 'a', 'b' ),
			)
		);

		$this->assertStringContainsString( 'Dieta: Wegetariańska', $summary );
		$this->assertStringContainsString( 'Warsztaty: Warsztat A, Warsztat B', $summary );
	}

	public function test_skips_type_field_headings_empty_values_and_false_checkbox(): void {
		$summary = $this->builder->build(
			$this->schema(),
			array(
				'__type' => 'uczestnik',
				'imie'   => 'Jan',
				'email'  => '',
				'zgoda'  => false,
			)
		);

		$this->assertSame( 'Imię i nazwisko: Jan', $summary );
	}

	public function test_skips_fields_hidden_by_conditions(): void {
		$summary = $this->builder->build(
			$this->schema(),
			array(
				'__type'  => 'uczestnik',
				'imie'    => 'Jan',
				'faktura' => 'NIP 123',
			)
		);

		$this->assertStringNotContainsString( 'Dane do faktury', $summary );
	}

	public function test_returns_empty_string_when_nothing_to_show(): void {
		$this->assertSame( '', $this->builder->build( $this->schema(), array( '__type' => 'uczestnik' ) ) );
	}
}
