<?php

declare( strict_types=1 );

namespace EvReg\Domain\Validation;

use EvReg\Domain\Schema\Field;

interface FieldValidator {

	public function validate( Field $field, mixed $raw ): FieldOutcome;
}
