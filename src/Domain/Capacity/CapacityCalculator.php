<?php

declare( strict_types=1 );

namespace EvReg\Domain\Capacity;

use EvReg\Domain\Accommodation\AccommodationSelection;

final class CapacityCalculator {

	public function decide(
		CapacityLimits $limits,
		OccupancySnapshot $taken,
		string $type_key,
		?AccommodationSelection $selection = null
	): CapacityDecision {
		if ( null !== $limits->global && $taken->global() >= $limits->global ) {
			return $this->full( $limits, 'event_full' );
		}

		$type_limit = $limits->forType( $type_key );

		if ( null !== $type_limit && $taken->forType( $type_key ) >= $type_limit ) {
			return $this->full( $limits, 'type_full' );
		}

		if ( null === $selection ) {
			return new CapacityDecision( Outcome::Accepted );
		}

		$slot       = $selection->slotKey();
		$slot_limit = $limits->forSlot( $slot );

		if ( null === $slot_limit || $taken->forSlot( $slot ) >= $slot_limit ) {
			return new CapacityDecision( Outcome::Accepted, false, 'accommodation_full' );
		}

		return new CapacityDecision( Outcome::Accepted, true );
	}

	private function full( CapacityLimits $limits, string $reason ): CapacityDecision {
		return new CapacityDecision(
			$limits->waitlistEnabled ? Outcome::Waitlisted : Outcome::Rejected,
			false,
			$reason
		);
	}
}
