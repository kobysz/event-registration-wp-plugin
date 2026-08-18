<?php
/**
 * Wynik walidacji całego zestawu odpowiedzi formularza.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Validation;

/**
 * Zebrane błędy i znormalizowane wartości po walidacji odpowiedzi na widoczne pola.
 */
final class ValidationResult {

	/**
	 * Tworzy wynik walidacji z zebranych błędów i wartości.
	 *
	 * @param array<string,string> $errors Klucz pola → kod błędu.
	 * @param array<string,mixed>  $values Znormalizowane wartości pól widocznych.
	 */
	public function __construct(
		private readonly array $errors,
		private readonly array $values
	) {
	}

	/**
	 * Czy walidacja zakończyła się bez błędów.
	 */
	public function isValid(): bool {
		return array() === $this->errors;
	}

	/**
	 * Zwraca błędy walidacji.
	 *
	 * @return array<string,string>
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * Zwraca znormalizowane wartości pól widocznych.
	 *
	 * @return array<string,mixed>
	 */
	public function values(): array {
		return $this->values;
	}
}
