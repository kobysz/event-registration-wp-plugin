<?php

declare( strict_types=1 );

namespace EvReg\Domain\Capacity;

final class CapacityDecision {

	public function __construct(
		public readonly Outcome $outcome,
		public readonly bool $accommodationGranted = false,
		public readonly ?string $reason = null
	) {
	}

	public function isAccepted(): bool {
		return Outcome::Accepted === $this->outcome;
	}
}
