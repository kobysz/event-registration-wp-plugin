<?php
/**
 * Limity liczby miejsc dla wydarzenia.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Capacity;

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
	 */
	public function __construct(
		public readonly ?int $globalLimit,
		private readonly array $perType,
		private readonly array $perSlot,
		public readonly bool $waitlistEnabled = true
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
}
