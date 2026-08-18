<?php
/**
 * Walidacja pełnego zestawu odpowiedzi formularza.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Validation;

use EvReg\Domain\Schema\FormSchema;
use EvReg\Domain\Schema\VisibilityResolver;

/**
 * Waliduje wszystkie widoczne pola formularza dla podanego zestawu odpowiedzi.
 */
final class Validator {

	/**
	 * Tworzy walidator oparty na resolverze widoczności i rejestrze walidatorów.
	 *
	 * @param VisibilityResolver     $visibility Wyznacza widoczne sekcje i pola.
	 * @param FieldValidatorRegistry $registry   Rejestr walidatorów per typ pola.
	 */
	public function __construct(
		private readonly VisibilityResolver $visibility,
		private readonly FieldValidatorRegistry $registry
	) {
	}

	/**
	 * Waliduje odpowiedzi na widoczne pola schematu.
	 *
	 * @param FormSchema          $schema  Schemat formularza.
	 * @param array<string,mixed> $answers Udzielone odpowiedzi, indeksowane kluczem pola.
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

	/**
	 * Sprawdza, czy znormalizowana wartość jest pusta.
	 *
	 * @param mixed $value Znormalizowana wartość pola.
	 */
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
