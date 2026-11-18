<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Validation;

use EvReg\Domain\Conditions\ConditionEngine;
use EvReg\Domain\Schema\FormSchema;
use EvReg\Domain\Schema\VisibilityResolver;
use EvReg\Domain\Validation\FieldValidatorRegistry;
use EvReg\Domain\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorOptionalRequiredTest extends TestCase {

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
							array( 'key' => 'imie', 'type' => 'text', 'label' => 'Imię', 'required' => true ),
							array( 'key' => 'email', 'type' => 'email', 'label' => 'E-mail', 'required' => true ),
						),
					),
				),
			)
		);
	}

	public function test_required_field_without_value_errors(): void {
		$result = $this->validator->validate( $this->schema(), array( 'imie' => 'Jan', 'email' => '' ) );

		$this->assertSame( 'required', $result->errors()['email'] ?? null );
	}

	public function test_optional_key_skips_required_check(): void {
		$result = $this->validator->validate( $this->schema(), array( 'imie' => 'Jan', 'email' => '' ), array( 'email' ) );

		$this->assertArrayNotHasKey( 'email', $result->errors() );
		$this->assertSame( array(), $result->errors() );
	}

	public function test_optional_key_still_gets_type_validation(): void {
		$result = $this->validator->validate( $this->schema(), array( 'imie' => 'Jan', 'email' => 'nie-adres' ), array( 'email' ) );

		$this->assertArrayHasKey( 'email', $result->errors() );
		$this->assertNotSame( 'required', $result->errors()['email'] );
	}

	public function test_unlisted_field_still_required(): void {
		$result = $this->validator->validate( $this->schema(), array( 'imie' => '', 'email' => '' ), array( 'email' ) );

		$this->assertSame( 'required', $result->errors()['imie'] ?? null );
		$this->assertArrayNotHasKey( 'email', $result->errors() );
	}
}
