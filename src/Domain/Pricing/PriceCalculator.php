<?php
/**
 * Wyliczanie całkowitej ceny zgłoszenia.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Pricing;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Domain\Registration\RegistrationType;

/**
 * Sumuje cenę typu zgłoszenia z ceną wybranego zakwaterowania.
 */
final class PriceCalculator {

	/**
	 * Wylicza całkowitą cenę zgłoszenia.
	 *
	 * @param RegistrationType            $type      Wybrany typ zgłoszenia.
	 * @param AccommodationConfig|null    $config    Konfiguracja zakwaterowania, jeśli dotyczy.
	 * @param AccommodationSelection|null $selection Wybór zakwaterowania, jeśli dotyczy.
	 * @param bool                        $companion Czy zgłoszenie zawiera osobę towarzyszącą (wpływa na liczbę miejsc w pokojach bez ustawionej liczby osób).
	 * @param int|null                    $seats     Liczba miejsc już zajmowanych przez rezerwację; gdy podana, zastępuje wyliczenie z konfiguracji (zgłoszenie, które się nie przesuwa).
	 */
	public function total(
		RegistrationType $type,
		?AccommodationConfig $config = null,
		?AccommodationSelection $selection = null,
		bool $companion = false,
		?int $seats = null
	): float {
		$total = $type->price;

		if ( null !== $config && null !== $selection ) {
			$item = $config->item( $selection->packageKey, $selection->roomKey );

			if ( null !== $item ) {
				// Mnożnik idzie z liczby osób w pokoju, nie z flagi — inaczej cena
				// rozjechałaby się z pojemnością (pokój dwuosobowy zajmuje 2 łóżka
				// także wtedy, gdy flaga mówi inaczej).
				$total += $item->price * ( $seats ?? $config->seatsFor( $selection->roomKey, $companion ) );
			}
		}

		return round( $total, 2 );
	}
}
