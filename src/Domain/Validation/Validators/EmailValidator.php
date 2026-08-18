<?php

declare( strict_types=1 );

namespace EvReg\Domain\Validation\Validators;

use EvReg\Domain\Schema\Field;
use EvReg\Domain\Validation\FieldOutcome;
use EvReg\Domain\Validation\FieldValidator;

final class EmailValidator implements FieldValidator {

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
