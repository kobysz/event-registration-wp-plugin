<?php

declare( strict_types=1 );

namespace EvReg\Domain\Schema;

enum FieldType: string {
	case Text          = 'text';
	case Email         = 'email';
	case Tel           = 'tel';
	case Textarea      = 'textarea';
	case Number        = 'number';
	case Date          = 'date';
	case Select        = 'select';
	case Radio         = 'radio';
	case Checkbox      = 'checkbox';
	case CheckboxGroup = 'checkbox-group';
	case Hidden        = 'hidden';
	case Heading       = 'heading';
	case Paragraph     = 'paragraph';
	case Accommodation = 'accommodation';

	/** Czy pole zbiera dane od użytkownika. */
	public function isInput(): bool {
		return ! in_array( $this, array( self::Heading, self::Paragraph ), true );
	}

	/** Czy pole wymaga listy opcji. */
	public function hasOptions(): bool {
		return in_array( $this, array( self::Select, self::Radio, self::CheckboxGroup ), true );
	}

	/** Czy odpowiedź jest tablicą wartości. */
	public function isMultiValue(): bool {
		return self::CheckboxGroup === $this;
	}
}
