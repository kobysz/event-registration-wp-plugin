<?php

declare( strict_types=1 );

namespace EvReg\Domain\Pricing;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Domain\Registration\RegistrationType;

final class PriceCalculator {

	public function total(
		RegistrationType $type,
		?AccommodationConfig $config = null,
		?AccommodationSelection $selection = null
	): float {
		$total = $type->price;

		if ( null !== $config && null !== $selection ) {
			$item = $config->item( $selection->packageKey, $selection->roomKey );

			if ( null !== $item ) {
				$total += $item->price;
			}
		}

		return round( $total, 2 );
	}
}
