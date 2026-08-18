<?php
/**
 * Migawka aktualnego obłożenia miejsc dla wydarzenia.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Capacity;

/**
 * Liczba zajętych miejsc: globalnie, per typ zgłoszenia i per slot zakwaterowania.
 */
final class OccupancySnapshot {

	/**
	 * Tworzy migawkę obłożenia.
	 *
	 * @param int               $globalCount Liczba zajętych miejsc globalnie.
	 * @param array<string,int> $perType     Liczba zajętych miejsc per typ zgłoszenia.
	 * @param array<string,int> $perSlot     Liczba zajętych miejsc per slot "pakiet|pokój".
	 */
	public function __construct(
		private readonly int $globalCount,
		private readonly array $perType = array(),
		private readonly array $perSlot = array()
	) {
	}

	/**
	 * Zwraca globalną liczbę zajętych miejsc.
	 */
	public function global(): int {
		return $this->globalCount;
	}

	/**
	 * Zwraca liczbę zajętych miejsc dla typu zgłoszenia.
	 *
	 * @param string $key Klucz typu zgłoszenia.
	 */
	public function forType( string $key ): int {
		return $this->perType[ $key ] ?? 0;
	}

	/**
	 * Zwraca liczbę zajętych miejsc dla slotu zakwaterowania.
	 *
	 * @param string $key Klucz slotu "pakiet|pokój".
	 */
	public function forSlot( string $key ): int {
		return $this->perSlot[ $key ] ?? 0;
	}
}
