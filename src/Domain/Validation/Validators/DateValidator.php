<?php
/**
 * Walidator pola daty.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Validation\Validators;

use DateTimeImmutable;
use EvReg\Domain\Schema\Field;
use EvReg\Domain\Validation\FieldOutcome;
use EvReg\Domain\Validation\FieldValidator;

/**
 * Sprawdza, czy wartość jest poprawną datą w formacie Y-m-d.
 */
final class DateValidator implements FieldValidator {

	/**
	 * Waliduje surową wartość pola daty.
	 *
	 * @param Field $field Definicja pola.
	 * @param mixed $raw   Surowa wartość odpowiedzi.
	 */
	public function validate( Field $field, mixed $raw ): FieldOutcome {
		$value = is_array( $raw ) ? '' : trim( (string) $raw );

		if ( '' === $value ) {
			return FieldOutcome::valid( '' );
		}

		$date = DateTimeImmutable::createFromFormat( 'Y-m-d', $value );

		if ( false === $date || $date->format( 'Y-m-d' ) !== $value ) {
			return FieldOutcome::error( 'invalid_date' );
		}

		return FieldOutcome::valid( $value );
	}
}
