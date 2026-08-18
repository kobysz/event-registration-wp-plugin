<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Validation;

use EvReg\Domain\Schema\Field;
use EvReg\Domain\Schema\FieldType;
use EvReg\Domain\Schema\Option;
use EvReg\Domain\Validation\FieldValidator;
use EvReg\Domain\Validation\Validators\ChoiceValidator;
use EvReg\Domain\Validation\Validators\DateValidator;
use EvReg\Domain\Validation\Validators\NumberValidator;
use EvReg\Domain\Validation\Validators\TelValidator;
use PHPUnit\Framework\TestCase;

/**
 * Dopełnienie pokrycia ścieżek błędów walidatorów pól prostych (tel, number,
 * date, pojedynczy wybór), których kod błędu nie był dotąd testowany wprost.
 */
final class ValidatorsErrorCodeTest extends TestCase {

	private function choiceField(): Field {
		return new Field(
			'wybor',
			FieldType::Select,
			'Wybór',
			false,
			array(
				new Option( 'a', 'Opcja A' ),
				new Option( 'b', 'Opcja B' ),
			)
		);
	}

	private function telField(): Field {
		return new Field( 'tel', FieldType::Tel, 'Telefon' );
	}

	private function numberField(): Field {
		return new Field( 'liczba', FieldType::Number, 'Liczba' );
	}

	private function dateField(): Field {
		return new Field( 'data', FieldType::Date, 'Data' );
	}

	/**
	 * @return array<string,array{0:FieldValidator,1:Field,2:mixed,3:string}>
	 */
	public function invalidValueProvider(): array {
		return array(
			'tel: malformed phone number'   => array( new TelValidator(), $this->telField(), 'nie-numer', 'invalid_tel' ),
			'number: non-integer value'     => array( new NumberValidator(), $this->numberField(), '12.5', 'invalid_number' ),
			'date: invalid calendar date'   => array( new DateValidator(), $this->dateField(), '2026-13-40', 'invalid_date' ),
			'choice: value outside options' => array( new ChoiceValidator(), $this->choiceField(), 'c', 'not_in_options' ),
		);
	}

	/**
	 * @return array<string,array{0:FieldValidator,1:Field,2:mixed}>
	 */
	public function validValueProvider(): array {
		return array(
			'tel: well-formed phone number' => array( new TelValidator(), $this->telField(), '+48 600 100 200' ),
			'number: well-formed integer'   => array( new NumberValidator(), $this->numberField(), '42' ),
			'date: well-formed Y-m-d date'  => array( new DateValidator(), $this->dateField(), '2026-08-18' ),
			'choice: value within options'  => array( new ChoiceValidator(), $this->choiceField(), 'a' ),
		);
	}

	/**
	 * @dataProvider invalidValueProvider
	 *
	 * @param mixed $raw Surowa wartość odpowiedzi.
	 */
	public function test_validator_reports_expected_error_code(
		FieldValidator $validator,
		Field $field,
		mixed $raw,
		string $expected_error_code
	): void {
		$outcome = $validator->validate( $field, $raw );

		$this->assertSame( $expected_error_code, $outcome->errorCode );
	}

	/**
	 * @dataProvider validValueProvider
	 *
	 * @param mixed $raw Surowa wartość odpowiedzi.
	 */
	public function test_validator_accepts_expected_valid_value( FieldValidator $validator, Field $field, mixed $raw ): void {
		$outcome = $validator->validate( $field, $raw );

		$this->assertTrue( $outcome->isValid(), (string) $outcome->errorCode );
	}
}
