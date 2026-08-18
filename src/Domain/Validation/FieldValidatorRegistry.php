<?php

declare( strict_types=1 );

namespace EvReg\Domain\Validation;

use EvReg\Domain\Schema\FieldType;
use EvReg\Domain\Validation\Validators\AccommodationValidator;
use EvReg\Domain\Validation\Validators\BooleanValidator;
use EvReg\Domain\Validation\Validators\ChoiceValidator;
use EvReg\Domain\Validation\Validators\DateValidator;
use EvReg\Domain\Validation\Validators\EmailValidator;
use EvReg\Domain\Validation\Validators\MultiChoiceValidator;
use EvReg\Domain\Validation\Validators\NumberValidator;
use EvReg\Domain\Validation\Validators\TelValidator;
use EvReg\Domain\Validation\Validators\TextValidator;

final class FieldValidatorRegistry {

	/** @var array<string,FieldValidator> */
	private array $validators = array();

	public function __construct() {
		$text   = new TextValidator();
		$choice = new ChoiceValidator();

		$this->register( FieldType::Text, $text );
		$this->register( FieldType::Textarea, $text );
		$this->register( FieldType::Hidden, $text );
		$this->register( FieldType::Email, new EmailValidator() );
		$this->register( FieldType::Tel, new TelValidator() );
		$this->register( FieldType::Number, new NumberValidator() );
		$this->register( FieldType::Date, new DateValidator() );
		$this->register( FieldType::Select, $choice );
		$this->register( FieldType::Radio, $choice );
		$this->register( FieldType::CheckboxGroup, new MultiChoiceValidator() );
		$this->register( FieldType::Checkbox, new BooleanValidator() );
		$this->register( FieldType::Accommodation, new AccommodationValidator() );
	}

	public function register( FieldType $type, FieldValidator $validator ): void {
		$this->validators[ $type->value ] = $validator;
	}

	public function for( FieldType $type ): ?FieldValidator {
		return $this->validators[ $type->value ] ?? null;
	}
}
