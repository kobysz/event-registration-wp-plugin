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
use EvReg\Domain\Accommodation\NightSelection;
use EvReg\Domain\Accommodation\NightsConfig;
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

	/**
	 * Wylicza cenę zgłoszenia w trybie „doby”.
	 *
	 * Cena typu liczy się RAZ (jak w total()): osoba towarzysząca płaci za
	 * łóżka, nie za drugie wejście na wydarzenie. Każda wybrana doba kosztuje
	 * swoją cenę za miejsce razy liczba miejsc.
	 *
	 * @param RegistrationType $type      Wybrany typ zgłoszenia.
	 * @param NightsConfig     $config    Konfiguracja trybu „doby”.
	 * @param NightSelection   $selection Wybrane doby.
	 * @param int              $seats     Liczba miejsc na dobę (1 albo 2 z osobą towarzyszącą).
	 */
	public function totalForNights(
		RegistrationType $type,
		NightsConfig $config,
		NightSelection $selection,
		int $seats
	): float {
		$total = $type->price;

		foreach ( $selection->keys() as $key ) {
			$night = $config->night( $key );

			if ( null !== $night ) {
				$total += $night->price * $seats;
			}
		}

		return round( $total, 2 );
	}
}
