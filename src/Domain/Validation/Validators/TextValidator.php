<?php
/**
 * Walidator pola tekstowego.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Validation\Validators;

use EvReg\Domain\Schema\Field;
use EvReg\Domain\Validation\FieldOutcome;
use EvReg\Domain\Validation\FieldValidator;

/**
 * Sprawdza maksymalną długość wartości tekstowej.
 */
final class TextValidator implements FieldValidator {

	public const MAX_LENGTH = 5000;

	/**
	 * Waliduje surową wartość pola tekstowego.
	 *
	 * @param Field $field Definicja pola.
	 * @param mixed $raw   Surowa wartość odpowiedzi.
	 */
	public function validate( Field $field, mixed $raw ): FieldOutcome {
		$value = is_array( $raw ) ? '' : trim( (string) $raw );

		if ( mb_strlen( $value ) > self::MAX_LENGTH ) {
			return FieldOutcome::error( 'too_long' );
		}

		return FieldOutcome::valid( $value );
	}
}
