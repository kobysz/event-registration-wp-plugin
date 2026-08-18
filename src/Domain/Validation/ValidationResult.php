<?php

declare( strict_types=1 );

namespace EvReg\Domain\Validation;

final class ValidationResult {

	/**
	 * @param array<string,string> $errors Klucz pola → kod błędu.
	 * @param array<string,mixed>  $values Znormalizowane wartości pól widocznych.
	 */
	public function __construct(
		private readonly array $errors,
		private readonly array $values
	) {
	}

	public function isValid(): bool {
		return array() === $this->errors;
	}

	/**
	 * @return array<string,string>
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function values(): array {
		return $this->values;
	}
}
