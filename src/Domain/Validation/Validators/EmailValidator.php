<?php
/**
 * Walidator pola adresu e-mail.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Validation\Validators;

use EvReg\Domain\Schema\Field;
use EvReg\Domain\Validation\FieldOutcome;
use EvReg\Domain\Validation\FieldValidator;

/**
 * Sprawdza poprawność formatu adresu e-mail i normalizuje wielkość liter.
 */
final class EmailValidator implements FieldValidator {

	/**
	 * Waliduje surową wartość pola adresu e-mail.
	 *
	 * @param Field $field Definicja pola.
	 * @param mixed $raw   Surowa wartość odpowiedzi.
	 */
	public function validate( Field $field, mixed $raw ): FieldOutcome {
		$value = is_array( $raw ) ? '' : trim( (string) $raw );

		if ( '' === $value ) {
			return FieldOutcome::valid( '' );
		}

		if ( ! filter_var( $value, FILTER_VALIDATE_EMAIL ) ) {
			return FieldOutcome::error( 'invalid_email' );
		}

		return FieldOutcome::valid( mb_strtolower( $value ) );
	}
}
