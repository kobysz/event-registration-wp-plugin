<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Validation;

use EvReg\Domain\Conditions\ConditionEngine;
use EvReg\Domain\Schema\FormSchema;
use EvReg\Domain\Schema\VisibilityResolver;
use EvReg\Domain\Validation\FieldValidatorRegistry;
use EvReg\Domain\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase {

	private Validator $validator;

	protected function setUp(): void {
		$this->validator = new Validator(
			new VisibilityResolver( new ConditionEngine() ),
			new FieldValidatorRegistry()
		);
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
							array(
								'key'     => '__type',
								'type'    => 'radio',
								'label'   => 'Typ',
								'options' => array(
									array( 'value' => 'uczestnik', 'label' => 'Uczestnik' ),
									array( 'value' => 'online', 'label' => 'Online' ),
								),
							),
							array( 'key' => 'imie', 'type' => 'text', 'label' => 'Imię', 'required' => true ),
							array( 'key' => 'email', 'type' => 'email', 'label' => 'E-mail', 'required' => true ),
							array( 'key' => 'tel', 'type' => 'tel', 'label' => 'Telefon' ),
							array( 'key' => 'wiek', 'type' => 'number', 'label' => 'Wiek' ),
							array(
								'key'     => 'atrakcje',
								'type'    => 'checkbox-group',
								'label'   => 'Atrakcje',
								'options' => array(
									array( 'value' => 'kolacja', 'label' => 'Kolacja' ),
									array( 'value' => 'zwiedzanie', 'label' => 'Zwiedzanie' ),
								),
							),
							array( 'key' => 'zgoda', 'type' => 'checkbox', 'label' => 'Zgoda', 'required' => true ),
						),
					),
					array(
						'key'       => 'stacjonarne',
						'title'     => 'Stacjonarne',
						'condition' => array( 'field' => '__type', 'operator' => 'in', 'value' => array( 'uczestnik' ) ),
						'fields'    => array(
							array( 'key' => 'dojazd', 'type' => 'text', 'label' => 'Dojazd', 'required' => true ),
						),
					),
				),
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function valid_answers(): array {
		return array(
			'__type'   => 'uczestnik',
			'imie'     => '  Jan Kowalski  ',
			'email'    => 'jan@example.com',
			'tel'      => '+48 600 100 200',
			'wiek'     => '34',
			'atrakcje' => array( 'kolacja' ),
			'zgoda'    => '1',
			'dojazd'   => 'samochód',
		);
	}

	public function test_accepts_valid_answers_and_trims_text(): void {
		$result = $this->validator->validate( $this->schema(), $this->valid_answers() );

		$this->assertTrue( $result->isValid(), print_r( $result->errors(), true ) );
		$this->assertSame( 'Jan Kowalski', $result->values()['imie'] );
		$this->assertSame( 34, $result->values()['wiek'] );
		$this->assertTrue( $result->values()['zgoda'] );
	}

	public function test_reports_missing_required_field(): void {
		$answers = $this->valid_answers();
		unset( $answers['imie'] );

		$result = $this->validator->validate( $this->schema(), $answers );

		$this->assertFalse( $result->isValid() );
		$this->assertSame( 'required', $result->errors()['imie'] );
	}

	public function test_reports_invalid_email(): void {
		$answers          = $this->valid_answers();
		$answers['email'] = 'jan[at]example.com';

		$result = $this->validator->validate( $this->schema(), $answers );

		$this->assertSame( 'invalid_email', $result->errors()['email'] );
	}

	public function test_reports_value_outside_options(): void {
		$answers             = $this->valid_answers();
		$answers['atrakcje'] = array( 'kolacja', 'nurkowanie' );

		$result = $this->validator->validate( $this->schema(), $answers );

		$this->assertSame( 'not_in_options', $result->errors()['atrakcje'] );
	}

	/**
	 * Regresja: element listy checkbox-group będący tablicą (spreparowane pole
	 * POST, np. atrakcje[a][]=x) musi zostać odrzucony bez emitowania ostrzeżenia
	 * "Array to string conversion" przy rzutowaniu na string.
	 */
	public function test_reports_array_element_in_multi_choice_as_not_in_options(): void {
		$answers             = $this->valid_answers();
		$answers['atrakcje'] = array( 'kolacja', array( 'x' ) );

		$result = $this->validator->validate( $this->schema(), $answers );

		$this->assertSame( 'not_in_options', $result->errors()['atrakcje'] );
	}

	public function test_hidden_fields_are_not_required(): void {
		$answers           = $this->valid_answers();
		$answers['__type'] = 'online';
		unset( $answers['dojazd'] );

		$result = $this->validator->validate( $this->schema(), $answers );

		$this->assertTrue( $result->isValid(), print_r( $result->errors(), true ) );
	}

	public function test_hidden_field_values_are_discarded(): void {
		$answers           = $this->valid_answers();
		$answers['__type'] = 'online';
		$answers['dojazd'] = 'przemycone';

		$result = $this->validator->validate( $this->schema(), $answers );

		$this->assertArrayNotHasKey( 'dojazd', $result->values() );
	}

	public function test_rejects_text_over_limit(): void {
		$answers         = $this->valid_answers();
		$answers['imie'] = str_repeat( 'a', 5001 );

		$result = $this->validator->validate( $this->schema(), $answers );

		$this->assertSame( 'too_long', $result->errors()['imie'] );
	}

	public function test_unchecked_required_consent_is_error(): void {
		$answers          = $this->valid_answers();
		$answers['zgoda'] = '';

		$result = $this->validator->validate( $this->schema(), $answers );

		$this->assertSame( 'required', $result->errors()['zgoda'] );
	}
}
