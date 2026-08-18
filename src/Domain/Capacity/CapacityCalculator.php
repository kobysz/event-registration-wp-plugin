<?php
/**
 * Wyznacza decyzję pojemnościową dla nowego zgłoszenia.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Capacity;

use EvReg\Domain\Accommodation\AccommodationSelection;

/**
 * Sprawdza limity globalne, per typ zgłoszenia i per slot zakwaterowania.
 */
final class CapacityCalculator {

	/**
	 * Ustala, czy zgłoszenie mieści się w dostępnych limitach.
	 *
	 * @param CapacityLimits         $limits    Skonfigurowane limity.
	 * @param OccupancySnapshot      $taken     Aktualne obłożenie.
	 * @param string                 $type_key  Klucz typu zgłoszenia.
	 * @param AccommodationSelection $selection Wybór zakwaterowania, jeśli dotyczy.
	 */
	public function decide(
		CapacityLimits $limits,
		OccupancySnapshot $taken,
		string $type_key,
		?AccommodationSelection $selection = null
	): CapacityDecision {
		if ( null !== $limits->globalLimit && $taken->global() >= $limits->globalLimit ) {
			return $this->full( $limits, 'event_full' );
		}

		$type_limit = $limits->forType( $type_key );

		if ( null !== $type_limit && $taken->forType( $type_key ) >= $type_limit ) {
			return $this->full( $limits, 'type_full' );
		}

		if ( null === $selection ) {
			return new CapacityDecision( Outcome::Accepted );
		}

		$slot       = $selection->slotKey();
		$slot_limit = $limits->forSlot( $slot );

		if ( null === $slot_limit || $taken->forSlot( $slot ) >= $slot_limit ) {
			return new CapacityDecision( Outcome::Accepted, false, 'accommodation_full' );
		}

		return new CapacityDecision( Outcome::Accepted, true );
	}

	/**
	 * Buduje decyzję dla wyczerpanego limitu, uwzględniając listę rezerwową.
	 *
	 * @param CapacityLimits $limits Skonfigurowane limity.
	 * @param string         $reason Kod powodu odrzucenia/listy rezerwowej.
	 */
	private function full( CapacityLimits $limits, string $reason ): CapacityDecision {
		return new CapacityDecision(
			$limits->waitlistEnabled ? Outcome::Waitlisted : Outcome::Rejected,
			false,
			$reason
		);
	}
}
