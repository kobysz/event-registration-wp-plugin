<?php
/**
 * Bramka pojemności w trybie „doby”.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Capacity;

use EvReg\Domain\Accommodation\NightSelection;
use EvReg\Domain\Accommodation\NightsConfig;

/**
 * Rozstrzyga, czy wybrany zbiór dób mieści się w pojemności. Zwraca kod
 * (klucz doby), nigdy komunikat.
 */
final class NightCapacity {

	/**
	 * Zwraca klucz pierwszej doby (w kolejności wyboru), która nie przechodzi, albo null.
	 *
	 * @param NightsConfig   $config      Konfiguracja trybu „doby”.
	 * @param NightOccupancy $taken       Bieżąca zajętość dób.
	 * @param NightSelection $selection   Wybrane doby.
	 * @param bool           $companion   Czy zgłoszenie ma osobę towarzyszącą.
	 */
	public static function blockingNight(
		NightsConfig $config,
		NightOccupancy $taken,
		NightSelection $selection,
		bool $companion
	): ?string {
		$seats = $companion ? 2 : 1;

		foreach ( $selection->keys() as $key ) {
			$night = $config->night( $key );

			if ( null === $night ) {
				continue;
			}

			if ( $taken->seatsFor( $key ) + $seats > $night->seats ) {
				return $key;
			}

			if ( $companion && null !== $night->companionCap
				&& $taken->pairsFor( $key ) + 1 > $night->companionCap ) {
				return $key;
			}
		}

		return null;
	}
}
