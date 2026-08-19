<?php
/**
 * Wynik próby submisji formularza publicznego.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Wynik próby submisji formularza.
 */
final class SubmitResult {

	/**
	 * Tworzy wynik submisji.
	 *
	 * @param string               $code    Kod wyniku (np. "invalid", "spam", "duplicate").
	 * @param bool                 $success Czy submisja zakończyła się sukcesem.
	 * @param array<string,string> $errors  Klucz pola → kod błędu.
	 * @param array<string,mixed>  $values  Wpisane wartości do odtworzenia.
	 */
	private function __construct(
		private readonly string $code,
		private readonly bool $success,
		private readonly array $errors = array(),
		private readonly array $values = array()
	) {
	}

	/**
	 * Tworzy wynik sukcesu.
	 *
	 * @param string $code Kod potwierdzenia sukcesu.
	 */
	public static function success( string $code ): self {
		return new self( $code, true );
	}

	/**
	 * Tworzy wynik nieudanej walidacji z błędami i wpisanymi wartościami.
	 *
	 * @param array<string,string> $errors Klucz pola → kod błędu.
	 * @param array<string,mixed>  $values Wpisane wartości do odtworzenia.
	 */
	public static function invalid( array $errors, array $values ): self {
		return new self( 'invalid', false, $errors, $values );
	}

	/**
	 * Tworzy wynik odrzucenia jako spam.
	 */
	public static function spam(): self {
		return new self( 'spam', false );
	}

	/**
	 * Tworzy wynik odrzucenia jako duplikat.
	 */
	public static function duplicate(): self {
		return new self( 'duplicate', false );
	}

	/**
	 * Tworzy wynik odrzucenia zgłoszenia.
	 */
	public static function rejected(): self {
		return new self( 'rejected', false );
	}

	/**
	 * Tworzy wynik błędu konfiguracji eventu.
	 */
	public static function configError(): self {
		return new self( 'config_error', false );
	}

	/**
	 * Zwraca kod wyniku.
	 */
	public function code(): string {
		return $this->code;
	}

	/**
	 * Czy submisja zakończyła się sukcesem.
	 */
	public function isSuccess(): bool {
		return $this->success;
	}

	/**
	 * Zwraca błędy walidacji per pole.
	 *
	 * @return array<string,string>
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * Zwraca wpisaną wartość pola do odtworzenia w formularzu.
	 *
	 * @param string $key Klucz pola.
	 */
	public function submittedValue( string $key ): mixed {
		return $this->values[ $key ] ?? null;
	}
}
