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
	 * @param bool                        $companion Czy zgłoszenie zawiera osobę towarzyszącą (podwaja cenę noclegu).
	 */
	public function total(
		RegistrationType $type,
		?AccommodationConfig $config = null,
		?AccommodationSelection $selection = null,
		bool $companion = false
	): float {
		$total = $type->price;

		if ( null !== $config && null !== $selection ) {
			$item = $config->item( $selection->packageKey, $selection->roomKey );

			if ( null !== $item ) {
				$total += $item->price * ( $companion ? 2 : 1 );
			}
		}

		return round( $total, 2 );
	}
}
