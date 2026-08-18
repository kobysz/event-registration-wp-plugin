<?php

declare( strict_types=1 );

namespace EvReg\Domain\Validation\Validators;

use EvReg\Domain\Schema\Field;
use EvReg\Domain\Validation\FieldOutcome;
use EvReg\Domain\Validation\FieldValidator;

final class NumberValidator implements FieldValidator {

	public function validate( Field $field, mixed $raw ): FieldOutcome {
		$value = is_array( $raw ) ? '' : trim( (string) $raw );

		if ( '' === $value ) {
			return FieldOutcome::valid( null );
		}

		if ( 1 !== preg_match( '/^-?\d+$/', $value ) ) {
			return FieldOutcome::error( 'invalid_number' );
		}

		return FieldOutcome::valid( (int) $value );
	}
}
