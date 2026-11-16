<?php
/**
 * Wynik złożenia submisji formularza (walidacja + ekstrakcja).
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Frontend;

use EvReg\Services\ReservationRequest;

defined( 'ABSPATH' ) || exit;

/**
 * Niesie wynik `SubmissionAssembler::assemble()` — albo błędy walidacji z surowymi
 * wartościami do re-renderu, albo gotowy `ReservationRequest`.
 */
final class AssembledSubmission {

	/**
	 * Tworzy wynik złożenia submisji.
	 *
	 * @param bool                    $valid   Czy submisja przeszła walidację.
	 * @param array<string,string>    $errors  Błędy walidacji (puste gdy valid).
	 * @param array<string,mixed>     $values  Odpowiedzi do re-renderu (surowe przy invalid).
	 * @param ReservationRequest|null $request Zbudowane żądanie rezerwacji (tylko gdy valid).
	 * @param array<string,mixed>     $rawAnswers Surowe odpowiedzi z $_POST (do re-renderu).
	 */
	private function __construct(
		private readonly bool $valid,
		private readonly array $errors,
		private readonly array $values,
		private readonly ?ReservationRequest $request,
		private readonly array $rawAnswers
	) {
	}

	/**
	 * Tworzy wynik nieudanej walidacji.
	 *
	 * @param array<string,string> $errors Błędy walidacji.
	 * @param array<string,mixed>  $values Surowe odpowiedzi do re-renderu.
	 */
	public static function invalid( array $errors, array $values ): self {
		return new self( false, $errors, $values, null, $values );
	}

	/**
	 * Tworzy wynik udanej walidacji z gotowym żądaniem rezerwacji.
	 *
	 * @param array<string,mixed> $values     Znormalizowane wartości z Validatora.
	 * @param ReservationRequest  $request    Zbudowane żądanie rezerwacji.
	 * @param array<string,mixed> $rawAnswers Surowe odpowiedzi z $_POST (do re-renderu).
	 */
	public static function valid( array $values, ReservationRequest $request, array $rawAnswers ): self {
		return new self( true, array(), $values, $request, $rawAnswers );
	}

	/**
	 * Czy submisja przeszła walidację.
	 */
	public function isValid(): bool {
		return $this->valid;
	}

	/**
	 * Błędy walidacji (puste gdy valid).
	 *
	 * @return array<string,string>
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * Odpowiedzi do re-renderu (surowe przy invalid, znormalizowane przy valid).
	 *
	 * @return array<string,mixed>
	 */
	public function values(): array {
		return $this->values;
	}

	/**
	 * Surowe odpowiedzi z $_POST — kształt, który FormRenderer umie odtworzyć.
	 *
	 * Różnią się od values() na gałęzi poprawnej walidacji: tam pole noclegu jest
	 * OBIEKTEM AccommodationSelection, a kluczy evreg_companion* nie ma wcale. Podanie
	 * values() do re-renderu wyrenderowałoby „Bez noclegu" jako zaznaczone i skasowało
	 * stan osoby towarzyszącej.
	 *
	 * @return array<string,mixed>
	 */
	public function rawAnswers(): array {
		return $this->rawAnswers;
	}

	/**
	 * Zbudowane żądanie rezerwacji, jeśli submisja jest poprawna.
	 */
	public function request(): ?ReservationRequest {
		return $this->request;
	}
}
