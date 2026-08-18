<?php

declare( strict_types=1 );

namespace EvReg\Domain\Validation\Validators;

use DateTimeImmutable;
use EvReg\Domain\Schema\Field;
use EvReg\Domain\Validation\FieldOutcome;
use EvReg\Domain\Validation\FieldValidator;

final class DateValidator implements FieldValidator {

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
