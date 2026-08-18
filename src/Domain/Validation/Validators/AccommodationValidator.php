<?php

declare( strict_types=1 );

namespace EvReg\Domain\Validation\Validators;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Domain\Schema\Field;
use EvReg\Domain\Validation\FieldOutcome;
use EvReg\Domain\Validation\FieldValidator;

final class AccommodationValidator implements FieldValidator {

	public const MAX_ROOMMATE_LENGTH = 191;

	public function validate( Field $field, mixed $raw ): FieldOutcome {
		$config    = AccommodationConfig::fromArray( $field->config );
		$selection = AccommodationSelection::fromArray( is_array( $raw ) ? $raw : array() );

		if ( null === $selection ) {
			if ( ! $config->allowsNone() || $field->required ) {
				return FieldOutcome::error( 'required' );
			}

			return FieldOutcome::valid( null );
		}

		if ( null === $config->item( $selection->packageKey, $selection->roomKey ) ) {
			return FieldOutcome::error( 'invalid_accommodation' );
		}

		$room = $config->room( $selection->roomKey );

		if ( '' !== $selection->roommatePref && ( null === $room || ! $room->roommateField ) ) {
			return FieldOutcome::error( 'roommate_not_allowed' );
		}

		if ( mb_strlen( $selection->roommatePref ) > self::MAX_ROOMMATE_LENGTH ) {
			return FieldOutcome::error( 'too_long' );
		}

		return FieldOutcome::valid( $selection );
	}
}
