<?php
/**
 * Wynik rezerwacji.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Wynik rezerwacji.
 */
final class ReservationResult {

	/**
	 * Tworzy wynik rezerwacji.
	 *
	 * @param string      $code                 Kod wyniku: reserved|waitlisted|rejected|duplicate.
	 * @param int|null    $registrationId       ID utworzonego zgłoszenia, jeśli dotyczy.
	 * @param string|null $token                Token zgłoszenia, jeśli dotyczy.
	 * @param bool        $accommodationGranted Czy przyznano zakwaterowanie.
	 * @param string|null $reason               Kod powodu odrzucenia/listy rezerwowej.
	 */
	private function __construct(
		public readonly string $code,
		public readonly ?int $registrationId,
		public readonly ?string $token,
		public readonly bool $accommodationGranted,
		public readonly ?string $reason
	) {
	}

	/**
	 * Buduje wynik dla zgłoszenia przyjętego (status pending).
	 *
	 * @param int         $id          ID utworzonego zgłoszenia.
	 * @param string      $token       Token zgłoszenia.
	 * @param bool        $acc_granted Czy przyznano zakwaterowanie.
	 * @param string|null $reason      Kod powodu (np. accommodation_full), jeśli dotyczy.
	 */
	public static function reserved( int $id, string $token, bool $acc_granted, ?string $reason ): self {
		return new self( 'reserved', $id, $token, $acc_granted, $reason );
	}

	/**
	 * Buduje wynik dla zgłoszenia trafiającego na listę rezerwową.
	 *
	 * @param int    $id     ID utworzonego zgłoszenia.
	 * @param string $token  Token zgłoszenia.
	 * @param string $reason Kod powodu wyczerpania limitu.
	 */
	public static function waitlisted( int $id, string $token, string $reason ): self {
		return new self( 'waitlisted', $id, $token, false, $reason );
	}

	/**
	 * Buduje wynik dla zgłoszenia odrzuconego (limit wyczerpany, lista rezerwowa wyłączona).
	 *
	 * @param string $reason Kod powodu odrzucenia.
	 */
	public static function rejected( string $reason ): self {
		return new self( 'rejected', null, null, false, $reason );
	}

	/**
	 * Buduje wynik dla zgłoszenia zduplikowanego (aktywne zgłoszenie na ten e-mail już istnieje).
	 */
	public static function duplicate(): self {
		return new self( 'duplicate', null, null, false, null );
	}
}
