<?php
/**
 * Sąd, czy wydarzenie może jeszcze kogokolwiek przyjąć.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Capacity;

/**
 * Wydarzenie jest otwarte, gdy choć jeden typ zgłoszenia przyjąłby pojedynczą osobę.
 *
 * Nie powiela żadnej reguły pojemności — każdy typ sądzi CapacityCalculator::decide.
 * Pusta lista typów sprawdza klucz '', czyli sam limit wydarzenia. Pusty/nieznany typ
 * nie jest sprawdzany, gdy typy istnieją (backlog B34).
 */
final class EventAvailability {

	/**
	 * Ustala stan dostępności wydarzenia.
	 *
	 * @param CapacityLimits    $limits   Limity wydarzenia.
	 * @param OccupancySnapshot $taken    Aktualne obłożenie.
	 * @param array<int,string> $typeKeys Klucze typów zgłoszeń wydarzenia.
	 */
	public static function decide( CapacityLimits $limits, OccupancySnapshot $taken, array $typeKeys ): EventAvailabilityState {
		$calculator = new CapacityCalculator();
		$keys       = array() === $typeKeys ? array( '' ) : $typeKeys;

		foreach ( $keys as $key ) {
			if ( Outcome::Accepted === $calculator->decide( $limits, $taken, $key, null, false )->outcome ) {
				return EventAvailabilityState::Open;
			}
		}

		return $limits->waitlistEnabled ? EventAvailabilityState::Waitlist : EventAvailabilityState::Closed;
	}
}
