<?php
/**
 * Wynik walidacji pojedynczego pola formularza.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Validation;

/**
 * Znormalizowana wartość pola albo kod błędu walidacji.
 */
final class FieldOutcome {

	/**
	 * Tworzy wynik walidacji pola.
	 *
	 * @param mixed       $value     Znormalizowana wartość pola.
	 * @param string|null $errorCode Kod błędu walidacji albo null, gdy pole jest poprawne.
	 */
	private function __construct(
		public readonly mixed $value,
		public readonly ?string $errorCode
	) {
	}

	/**
	 * Tworzy poprawny wynik walidacji.
	 *
	 * @param mixed $value Znormalizowana wartość pola.
	 */
	public static function valid( mixed $value ): self {
		return new self( $value, null );
	}

	/**
	 * Tworzy wynik walidacji z błędem.
	 *
	 * @param string $code Kod błędu walidacji.
	 */
	public static function error( string $code ): self {
		return new self( null, $code );
	}

	/**
	 * Czy pole przeszło walidację.
	 */
	public function isValid(): bool {
		return null === $this->errorCode;
	}
}
