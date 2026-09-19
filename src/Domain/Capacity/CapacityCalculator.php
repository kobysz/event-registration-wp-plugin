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
	 * @param bool                   $companion Czy zgłoszenie zawiera osobę towarzyszącą.
	 */
	public function decide(
		CapacityLimits $limits,
		OccupancySnapshot $taken,
		string $type_key,
		?AccommodationSelection $selection = null,
		bool $companion = false
	): CapacityDecision {
		$event_seats  = 1 + ( $companion && $limits->companionCountsEvent ? 1 : 0 );
		$global_taken = $taken->global() + ( $limits->companionCountsEvent ? $taken->companions() : 0 );

		if ( null !== $limits->globalLimit && $global_taken + $event_seats > $limits->globalLimit ) {
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
		$acc_seats  = $companion ? 2 : 1;

		if ( null === $slot_limit || $taken->forSlot( $slot ) + $acc_seats > $slot_limit ) {
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
