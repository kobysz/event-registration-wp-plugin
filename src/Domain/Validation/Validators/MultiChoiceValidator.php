<?php

declare( strict_types=1 );

namespace EvReg\Domain\Validation\Validators;

use EvReg\Domain\Schema\Field;
use EvReg\Domain\Schema\Option;
use EvReg\Domain\Validation\FieldOutcome;
use EvReg\Domain\Validation\FieldValidator;

final class MultiChoiceValidator implements FieldValidator {

	public function validate( Field $field, mixed $raw ): FieldOutcome {
		if ( null === $raw || '' === $raw ) {
			return FieldOutcome::valid( array() );
		}

		$values = array_map(
			static fn ( $item ): string => trim( (string) $item ),
			is_array( $raw ) ? $raw : array( $raw )
		);

		$allowed = array_map(
			static fn ( Option $option ): string => $option->value,
			$field->options
		);

		foreach ( $values as $value ) {
			if ( ! in_array( $value, $allowed, true ) ) {
				return FieldOutcome::error( 'not_in_options' );
			}
		}

		return FieldOutcome::valid( array_values( array_unique( $values ) ) );
	}
}
