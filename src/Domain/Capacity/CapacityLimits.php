<?php

declare( strict_types=1 );

namespace EvReg\Domain\Capacity;

final class CapacityLimits {

	/**
	 * @param array<string,int|null> $perType Limit per typ zgłoszenia; null = bez limitu.
	 * @param array<string,int>      $perSlot Limit per "pakiet|pokój".
	 */
	public function __construct(
		public readonly ?int $global,
		private readonly array $perType,
		private readonly array $perSlot,
		public readonly bool $waitlistEnabled = true
	) {
	}

	public function forType( string $key ): ?int {
		return $this->perType[ $key ] ?? null;
	}

	/** Zwraca null, gdy slot nie istnieje w inwentarzu. */
	public function forSlot( string $key ): ?int {
		return $this->perSlot[ $key ] ?? null;
	}
}
