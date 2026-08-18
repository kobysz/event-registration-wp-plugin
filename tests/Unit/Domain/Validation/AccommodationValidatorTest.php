<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Validation;

use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Domain\Conditions\ConditionEngine;
use EvReg\Domain\Schema\Field;
use EvReg\Domain\Schema\FieldType;
use EvReg\Domain\Schema\FormSchema;
use EvReg\Domain\Schema\VisibilityResolver;
use EvReg\Domain\Validation\FieldValidatorRegistry;
use EvReg\Domain\Validation\Validator;
use EvReg\Domain\Validation\Validators\AccommodationValidator;
use PHPUnit\Framework\TestCase;

final class AccommodationValidatorTest extends TestCase {

	private AccommodationValidator $validator;

	protected function setUp(): void {
		$this->validator = new AccommodationValidator();
	}

	private function field( bool $required = false, bool $allow_none = true ): Field {
		return new Field(
			'nocleg',
			FieldType::Accommodation,
			'Nocleg',
			$required,
			array(),
			array(
				'packages'   => array( array( 'key' => 'n12', 'label' => 'Noc 1–2' ) ),
				'rooms'      => array(
					array( 'key' => 'single', 'label' => '1-os.' ),
					array( 'key' => 'double', 'label' => '2-os.', 'roommate_field' => true ),
				),
				'inventory'  => array(
					array( 'package' => 'n12', 'room' => 'single', 'capacity' => 5, 'price' => 250.0 ),
					array( 'package' => 'n12', 'room' => 'double', 'capacity' => 5, 'price' => 180.0 ),
				),
				'allow_none' => $allow_none,
			)
		);
	}

	public function test_accepts_valid_selection(): void {
		$outcome = $this->validator->validate(
			$this->field(),
			array( 'package' => 'n12', 'room' => 'double', 'roommate' => 'Anna Nowak' )
		);

		$this->assertTrue( $outcome->isValid() );
		$this->assertSame( 'n12|double', $outcome->value->slotKey() );
		$this->assertSame( 'Anna Nowak', $outcome->value->roommatePref );
	}

	public function test_accepts_empty_selection_when_allowed(): void {
		$outcome = $this->validator->validate( $this->field(), array() );

		$this->assertTrue( $outcome->isValid() );
		$this->assertNull( $outcome->value );
	}

	public function test_rejects_empty_selection_when_not_allowed(): void {
		$outcome = $this->validator->validate( $this->field( false, false ), array() );

		$this->assertSame( 'required', $outcome->errorCode );
	}

	public function test_rejects_combination_outside_inventory(): void {
		$outcome = $this->validator->validate(
			$this->field(),
			array( 'package' => 'n12', 'room' => 'suite' )
		);

		$this->assertSame( 'invalid_accommodation', $outcome->errorCode );
	}

	public function test_rejects_roommate_for_room_without_roommate_field(): void {
		$outcome = $this->validator->validate(
			$this->field(),
			array( 'package' => 'n12', 'room' => 'single', 'roommate' => 'Anna Nowak' )
		);

		$this->assertSame( 'roommate_not_allowed', $outcome->errorCode );
	}

	/**
	 * Regresja: wybór noclegu jest obiektem, a nie skalarem — walidator całego
	 * formularza nie może rzutować go na string przy sprawdzaniu pustości.
	 */
	public function test_full_validator_accepts_accommodation_selection(): void {
		$schema = FormSchema::fromArray(
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
							array(
								'key'      => 'nocleg',
								'type'     => 'accommodation',
								'label'    => 'Nocleg',
								'required' => true,
								'config'   => $this->field()->config,
							),
						),
					),
				),
			)
		);

		$validator = new Validator(
			new VisibilityResolver( new ConditionEngine() ),
			new FieldValidatorRegistry()
		);

		$result = $validator->validate(
			$schema,
			array(
				'__type' => 'uczestnik',
				'nocleg' => array( 'package' => 'n12', 'room' => 'double', 'roommate' => 'Anna Nowak' ),
			)
		);

		$this->assertTrue( $result->isValid(), print_r( $result->errors(), true ) );
		$this->assertInstanceOf( AccommodationSelection::class, $result->values()['nocleg'] );
		$this->assertSame( 'n12|double', $result->values()['nocleg']->slotKey() );
	}
}
