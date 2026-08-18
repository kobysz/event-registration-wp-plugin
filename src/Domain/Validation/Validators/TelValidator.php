<?php

declare( strict_types=1 );

namespace EvReg\Domain\Validation\Validators;

use EvReg\Domain\Schema\Field;
use EvReg\Domain\Validation\FieldOutcome;
use EvReg\Domain\Validation\FieldValidator;

final class TelValidator implements FieldValidator {

	public function validate( Field $field, mixed $raw ): FieldOutcome {
		$value = is_array( $raw ) ? '' : trim( (string) $raw );

		if ( '' === $value ) {
			return FieldOutcome::valid( '' );
		}

		$normalized = preg_replace( '/[\s\-()]/', '', $value ) ?? '';

		if ( 1 !== preg_match( '/^\+?\d{6,15}$/', $normalized ) ) {
			return FieldOutcome::error( 'invalid_tel' );
		}

		return FieldOutcome::valid( $normalized );
	}
}
