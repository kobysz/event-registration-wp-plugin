<?php
/**
 * Limity liczby miejsc dla wydarzenia.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Capacity;

use EvReg\Domain\Accommodation\RoomOccupancy;

/**
 * Konfigurowalne limity: globalny, per typ zgłoszenia i per slot zakwaterowania.
 */
final class CapacityLimits {

	/**
	 * Tworzy zestaw limitów pojemności.
	 *
	 * @param int|null               $globalLimit Globalny limit miejsc; null = bez limitu.
	 * @param array<string,int|null> $perType     Limit per typ zgłoszenia; null = bez limitu.
	 * @param array<string,int>      $perSlot     Limit per "pakiet|pokój".
	 * @param bool                   $waitlistEnabled Czy po wyczerpaniu limitu włączyć listę rezerwową.
	 * @param bool                   $companionCountsEvent Czy osoba towarzysząca wlicza się do limitu globalnego.
	 * @param array<string,int>      $perSlotSeats Liczba osób w pokoju per slot "pakiet|pokój"; 0 = nieustawione.
	 */
	public function __construct(
		public readonly ?int $globalLimit,
		private readonly array $perType,
		private readonly array $perSlot,
		public readonly bool $waitlistEnabled = true,
		public readonly bool $companionCountsEvent = false,
		private readonly array $perSlotSeats = array()
	) {
	}

	/**
	 * Zwraca limit dla typu zgłoszenia lub null, gdy typ jest bez limitu.
	 *
	 * @param string $key Klucz typu zgłoszenia.
	 */
	public function forType( string $key ): ?int {
		return $this->perType[ $key ] ?? null;
	}

	/**
	 * Zwraca null, gdy slot nie istnieje w inwentarzu.
	 *
	 * @param string $key Klucz slotu "pakiet|pokój".
	 */
	public function forSlot( string $key ): ?int {
		return $this->perSlot[ $key ] ?? null;
	}

	/**
	 * Ile miejsc zajmie zgłoszenie w tym slocie. Delegacja do RoomOccupancy —
	 * reguła fallbacku ma jeden dom i nie jest tu kopiowana.
	 *
	 * @param string $key       Klucz slotu "pakiet|pokój".
	 * @param bool   $companion Czy zgłoszenie zawiera osobę towarzyszącą.
	 */
	public function seatsForSlot( string $key, bool $companion ): int {
		return RoomOccupancy::seats( $this->perSlotSeats[ $key ] ?? 0, $companion );
	}
}
