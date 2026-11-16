<?php
/**
 * Reguła przeliczania liczby osób w pokoju na zajmowane miejsca.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Accommodation;

/**
 * Jedyny dom reguły: ustawiona liczba osób wygrywa, a gdy nieustawiona —
 * zachowanie sprzed wprowadzenia tego pojęcia (`companion ? 2 : 1`).
 *
 * Nie uczy się o globalnym przełączniku `companion_enabled` — sprzeczną
 * konfigurację zeruje AccommodationConfig, zanim tu dotrze.
 */
final class RoomOccupancy {

	/**
	 * Ile miejsc zajmuje zgłoszenie w pokoju o danej liczbie osób.
	 *
	 * @param int  $occupancy Liczba osób w pokoju; 0 = nieustawione.
	 * @param bool $companion Czy zgłoszenie zawiera osobę towarzyszącą.
	 */
	public static function seats( int $occupancy, bool $companion ): int {
		return 0 === $occupancy ? ( $companion ? 2 : 1 ) : $occupancy;
	}
}
