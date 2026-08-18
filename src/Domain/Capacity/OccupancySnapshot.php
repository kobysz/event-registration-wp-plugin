<?php

declare( strict_types=1 );

namespace EvReg\Domain\Capacity;

final class OccupancySnapshot {

	/**
	 * @param array<string,int> $perType
	 * @param array<string,int> $perSlot
	 */
	public function __construct(
		private readonly int $global,
		private readonly array $perType = array(),
		private readonly array $perSlot = array()
	) {
	}

	public function global(): int {
		return $this->global;
	}

	public function forType( string $key ): int {
		return $this->perType[ $key ] ?? 0;
	}

	public function forSlot( string $key ): int {
		return $this->perSlot[ $key ] ?? 0;
	}
}
