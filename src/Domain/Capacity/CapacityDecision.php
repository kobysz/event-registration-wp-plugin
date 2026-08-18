<?php
/**
 * Wynik oceny dostępności miejsc dla zgłoszenia.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Capacity;

/**
 * Decyzja pojemnościowa wraz z informacją o przyznaniu zakwaterowania.
 */
final class CapacityDecision {

	/**
	 * Tworzy decyzję pojemnościową.
	 *
	 * @param Outcome     $outcome              Wynik oceny zgłoszenia.
	 * @param bool        $accommodationGranted Czy przyznano zakwaterowanie.
	 * @param string|null $reason               Kod powodu odrzucenia/listy rezerwowej.
	 */
	public function __construct(
		public readonly Outcome $outcome,
		public readonly bool $accommodationGranted = false,
		public readonly ?string $reason = null
	) {
	}

	/**
	 * Czy zgłoszenie zostało zaakceptowane.
	 */
	public function isAccepted(): bool {
		return Outcome::Accepted === $this->outcome;
	}
}
