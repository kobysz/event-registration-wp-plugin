<?php
/**
 * Dostępność miejsc w slotach zakwaterowania.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Accommodation;

use EvReg\Domain\Capacity\OccupancySnapshot;

/**
 * Liczba wolnych miejsc per slot "pakiet|pokój". Czysta arytmetyka — bez WordPressa.
 */
final class SlotAvailability {

	/**
	 * Tworzy dostępność z gotowej mapy wolnych miejsc.
	 *
	 * @param array<string,int> $left Mapa slot "pakiet|pokój" => liczba wolnych miejsc.
	 */
	private function __construct( private readonly array $left ) {
	}

	/**
	 * Składa dostępność z konfiguracji zakwaterowania i migawki obłożenia.
	 *
	 * @param AccommodationConfig $config Konfiguracja zakwaterowania eventu.
	 * @param OccupancySnapshot   $taken  Migawka aktualnego obłożenia.
	 */
	public static function fromSnapshot( AccommodationConfig $config, OccupancySnapshot $taken ): self {
		$left = array();

		foreach ( $config->capacities() as $slot => $capacity ) {
			$left[ $slot ] = max( 0, $capacity - $taken->forSlot( $slot ) );
		}

		return new self( $left );
	}

	/**
	 * Zwraca liczbę wolnych miejsc w slocie. Slot nieobecny w inwentarzu => 0
	 * (lustro CapacityCalculator, gdzie brak limitu slotu też znaczy "nieprzyznany").
	 *
	 * @param string $slot Klucz slotu "pakiet|pokój".
	 */
	public function left( string $slot ): int {
		return $this->left[ $slot ] ?? 0;
	}

	/**
	 * Czy slot nie pomieści żądanej liczby miejsc.
	 *
	 * @param string $slot  Klucz slotu "pakiet|pokój".
	 * @param int    $seats Liczba potrzebnych miejsc (2 przy osobie towarzyszącej).
	 */
	public function isFull( string $slot, int $seats = 1 ): bool {
		return $this->left( $slot ) < $seats;
	}
}
