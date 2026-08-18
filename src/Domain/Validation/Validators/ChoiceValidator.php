<?php

declare( strict_types=1 );

namespace EvReg\Domain\Validation\Validators;

use EvReg\Domain\Schema\Field;
use EvReg\Domain\Schema\Option;
use EvReg\Domain\Validation\FieldOutcome;
use EvReg\Domain\Validation\FieldValidator;

final class ChoiceValidator implements FieldValidator {

	public function validate( Field $field, mixed $raw ): FieldOutcome {
		$value = is_array( $raw ) ? '' : trim( (string) $raw );

		if ( '' === $value ) {
			return FieldOutcome::valid( '' );
		}

		$allowed = array_map(
			static fn ( Option $option ): string => $option->value,
			$field->options
		);

		if ( ! in_array( $value, $allowed, true ) ) {
			return FieldOutcome::error( 'not_in_options' );
		}

		return FieldOutcome::valid( $value );
	}
}
