<?php

declare( strict_types=1 );

namespace EvReg\Domain\Validation;

use EvReg\Domain\Schema\FormSchema;
use EvReg\Domain\Schema\VisibilityResolver;

final class Validator {

	public function __construct(
		private readonly VisibilityResolver $visibility,
		private readonly FieldValidatorRegistry $registry
	) {
	}

	/**
	 * @param array<string,mixed> $answers
	 */
	public function validate( FormSchema $schema, array $answers ): ValidationResult {
		$errors = array();
		$values = array();

		foreach ( $this->visibility->resolve( $schema, $answers )->visibleFields() as $field ) {
			if ( ! $field->type->isInput() ) {
				continue;
			}

			$raw       = $answers[ $field->key ] ?? null;
			$validator = $this->registry->for( $field->type );

			$outcome = null === $validator
				? FieldOutcome::valid( $raw )
				: $validator->validate( $field, $raw );

			if ( ! $outcome->isValid() ) {
				$errors[ $field->key ] = (string) $outcome->errorCode;
				continue;
			}

			if ( $field->required && $this->isBlank( $outcome->value ) ) {
				$errors[ $field->key ] = 'required';
				continue;
			}

			$values[ $field->key ] = $outcome->value;
		}

		return new ValidationResult( $errors, $values );
	}

	private function isBlank( mixed $value ): bool {
		if ( is_array( $value ) ) {
			return array() === $value;
		}

		if ( is_bool( $value ) ) {
			return false === $value;
		}

		// Wartości obiektowe (np. wybór noclegu) nigdy nie są puste — pusty wybór to null.
		if ( is_object( $value ) ) {
			return false;
		}

		return null === $value || '' === (string) $value;
	}
}
