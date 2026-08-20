<?php
/**
 * Wynik akcji administracyjnej na zgłoszeniu.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Wynik akcji administracyjnej na zgłoszeniu.
 */
final class AdminActionResult {

	/**
	 * Tworzy wynik.
	 *
	 * @param string      $code   Kod: confirmed|cancelled|promoted|deleted|rejected|invalid_status|not_found|edited|capacity_full|accommodation_full.
	 * @param string|null $reason Kod powodu (np. przy rejected), jeśli dotyczy.
	 */
	private function __construct(
		public readonly string $code,
		public readonly ?string $reason = null
	) {
	}

	/** Zgłoszenie potwierdzone ręcznie. */
	public static function confirmed(): self {
		return new self( 'confirmed' );
	}

	/** Zgłoszenie anulowane. */
	public static function cancelled(): self {
		return new self( 'cancelled' );
	}

	/** Zgłoszenie awansowane z listy rezerwowej. */
	public static function promoted(): self {
		return new self( 'promoted' );
	}

	/** Zgłoszenie trwale usunięte. */
	public static function deleted(): self {
		return new self( 'deleted' );
	}

	/**
	 * Akcja odrzucona przez limity (promocja bez miejsca).
	 *
	 * @param string|null $reason Kod powodu.
	 */
	public static function rejected( ?string $reason = null ): self {
		return new self( 'rejected', $reason );
	}

	/** Status startowy nie pozwala na akcję. */
	public static function invalidStatus(): self {
		return new self( 'invalid_status' );
	}

	/** Nie znaleziono zgłoszenia. */
	public static function notFound(): self {
		return new self( 'not_found' );
	}

	/** Odpowiedzi zgłoszenia zaktualizowane. */
	public static function edited(): self {
		return new self( 'edited' );
	}

	/** Edycja odrzucona: wybrany typ/globalny limit pełny. */
	public static function capacityFull(): self {
		return new self( 'capacity_full' );
	}

	/** Edycja odrzucona: wybrany slot noclegu pełny. */
	public static function accommodationFull(): self {
		return new self( 'accommodation_full' );
	}
}
