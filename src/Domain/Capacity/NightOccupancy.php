<?php
/**
 * Zajętość każdej doby.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Capacity;

/**
 * Zajętość doby: liczba zajętych miejsc oraz liczba zgłoszeń z osobą towarzyszącą.
 */
final class NightOccupancy {

	/**
	 * Tworzy zajętość.
	 *
	 * @param array<string,int> $seats Zajęte miejsca per doba.
	 * @param array<string,int> $pairs Zgłoszenia z osobą towarzyszącą per doba.
	 */
	public function __construct(
		private readonly array $seats,
		private readonly array $pairs
	) {
	}

	/**
	 * Zajęte miejsca w dobie (0, gdy brak danych).
	 *
	 * @param string $night Klucz doby.
	 */
	public function seatsFor( string $night ): int {
		return $this->seats[ $night ] ?? 0;
	}

	/**
	 * Zajęte miejsca wszystkich dób, które mają jakąkolwiek zajętość.
	 *
	 * @return array<string,int>
	 */
	public function seatsByNight(): array {
		return $this->seats;
	}

	/**
	 * Zgłoszenia z osobą towarzyszącą w dobie (0, gdy brak danych).
	 *
	 * @param string $night Klucz doby.
	 */
	public function pairsFor( string $night ): int {
		return $this->pairs[ $night ] ?? 0;
	}
}
