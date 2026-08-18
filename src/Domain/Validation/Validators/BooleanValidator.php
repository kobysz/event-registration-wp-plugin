<?php
/**
 * Walidator pola typu checkbox.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Validation\Validators;

use EvReg\Domain\Schema\Field;
use EvReg\Domain\Validation\FieldOutcome;
use EvReg\Domain\Validation\FieldValidator;

/**
 * Normalizuje surową odpowiedź do wartości logicznej.
 */
final class BooleanValidator implements FieldValidator {

	/**
	 * Waliduje surową wartość pola checkbox.
	 *
	 * @param Field $field Definicja pola.
	 * @param mixed $raw   Surowa wartość odpowiedzi.
	 */
	public function validate( Field $field, mixed $raw ): FieldOutcome {
		if ( is_array( $raw ) ) {
			return FieldOutcome::valid( array() !== $raw );
		}

		return FieldOutcome::valid( in_array( (string) $raw, array( '1', 'true', 'on', 'yes' ), true ) );
	}
}
