<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Capacity;

use EvReg\Domain\Capacity\CapacityLimits;
use EvReg\Domain\Capacity\EventAvailability;
use EvReg\Domain\Capacity\EventAvailabilityState;
use EvReg\Domain\Capacity\OccupancySnapshot;
use PHPUnit\Framework\TestCase;

final class EventAvailabilityTest extends TestCase {

	public function test_one_type_open_keeps_event_open(): void {
		$limits = new CapacityLimits( null, array( 'std' => 1, 'vip' => 2 ), array() );
		$taken  = new OccupancySnapshot( 1, array( 'std' => 1, 'vip' => 0 ) );
		$this->assertSame( EventAvailabilityState::Open, EventAvailability::decide( $limits, $taken, array( 'std', 'vip' ) ) );
	}

	public function test_all_types_full_without_global_cap_is_waitlist_when_enabled(): void {
		$limits = new CapacityLimits( null, array( 'std' => 1, 'vip' => 2 ), array(), true );
		$taken  = new OccupancySnapshot( 3, array( 'std' => 1, 'vip' => 2 ) );
		$this->assertSame( EventAvailabilityState::Waitlist, EventAvailability::decide( $limits, $taken, array( 'std', 'vip' ) ) );
	}

	public function test_all_types_full_without_global_cap_is_closed_when_waitlist_disabled(): void {
		$limits = new CapacityLimits( null, array( 'std' => 1, 'vip' => 2 ), array(), false );
		$taken  = new OccupancySnapshot( 3, array( 'std' => 1, 'vip' => 2 ) );
		$this->assertSame( EventAvailabilityState::Closed, EventAvailability::decide( $limits, $taken, array( 'std', 'vip' ) ) );
	}

	/**
	 * Ostatnie wolne miejsce przyjmie singla, ale nie parę — bo przy włączonym
	 * `companion_counts_event` zgłoszenie z osobą towarzyszącą potrzebuje dwóch miejsc.
	 * Formularz pyta o oba sądy osobno, żeby nie obiecywać pary tam, gdzie jej nie przyjmie.
	 */
	public function test_last_seat_is_open_for_one_person_and_full_for_a_pair(): void {
		$limits = new CapacityLimits( 10, array( 'std' => null ), array(), false, true );
		$taken  = new OccupancySnapshot( 9, array( 'std' => 9 ) );

		$this->assertSame( EventAvailabilityState::Open, EventAvailability::decide( $limits, $taken, array( 'std' ) ) );
		$this->assertSame( EventAvailabilityState::Closed, EventAvailability::decide( $limits, $taken, array( 'std' ), true ) );
	}

	public function test_pair_fits_while_two_seats_are_left(): void {
		$limits = new CapacityLimits( 10, array( 'std' => null ), array(), false, true );
		$taken  = new OccupancySnapshot( 8, array( 'std' => 8 ) );

		$this->assertSame( EventAvailabilityState::Open, EventAvailability::decide( $limits, $taken, array( 'std' ), true ) );
	}

	/**
	 * Bez reguły „towarzysząca zajmuje miejsce wydarzenia" para waży tyle co singiel,
	 * więc flaga nie może niczego zmienić.
	 */
	public function test_companion_flag_changes_nothing_when_companions_do_not_take_a_seat(): void {
		$limits = new CapacityLimits( 10, array( 'std' => null ), array(), false );
		$taken  = new OccupancySnapshot( 9, array( 'std' => 9 ) );

		$this->assertSame( EventAvailabilityState::Open, EventAvailability::decide( $limits, $taken, array( 'std' ), true ) );
	}

	/**
	 * Towarzyszące już zapisanych też ważą: 100 zgłoszeń + 47 par to 147 z 180.
	 * Para potrzebuje dwóch miejsc, więc mieści się jeszcze przy 178 zajętych
	 * (131 + 47), a przy 179 już nie. Singiel wchodzi jako 133. zgłoszenie
	 * (133 + 47 = 180) i to on domyka limit.
	 */
	public function test_existing_companions_count_towards_the_event_limit(): void {
		$limits = new CapacityLimits( 180, array( 'std' => null ), array(), false, true );

		$this->assertSame(
			EventAvailabilityState::Open,
			EventAvailability::decide( $limits, new OccupancySnapshot( 131, array( 'std' => 131 ), array(), 47 ), array( 'std' ), true )
		);
		$this->assertSame(
			EventAvailabilityState::Closed,
			EventAvailability::decide( $limits, new OccupancySnapshot( 132, array( 'std' => 132 ), array(), 47 ), array( 'std' ), true )
		);
		$this->assertSame(
			EventAvailabilityState::Open,
			EventAvailability::decide( $limits, new OccupancySnapshot( 132, array( 'std' => 132 ), array(), 47 ), array( 'std' ) )
		);
		$this->assertSame(
			EventAvailabilityState::Closed,
			EventAvailability::decide( $limits, new OccupancySnapshot( 133, array( 'std' => 133 ), array(), 47 ), array( 'std' ) )
		);
	}

	public function test_uncapped_type_keeps_event_open(): void {
		$limits = new CapacityLimits( null, array( 'std' => null ), array() );
		$taken  = new OccupancySnapshot( 100, array( 'std' => 100 ) );
		$this->assertSame( EventAvailabilityState::Open, EventAvailability::decide( $limits, $taken, array( 'std' ) ) );
	}

	public function test_global_cap_exhausted_closes_regardless_of_types(): void {
		$limits = new CapacityLimits( 10, array( 'std' => null ), array(), false );
		$taken  = new OccupancySnapshot( 10, array( 'std' => 10 ) );
		$this->assertSame( EventAvailabilityState::Closed, EventAvailability::decide( $limits, $taken, array( 'std' ) ) );
	}

	public function test_global_cap_zero_is_full(): void {
		$limits = new CapacityLimits( 0, array( 'std' => null ), array(), true );
		$this->assertSame( EventAvailabilityState::Waitlist, EventAvailability::decide( $limits, new OccupancySnapshot( 0 ), array( 'std' ) ) );
	}

	public function test_companions_count_toward_global_when_flag_set(): void {
		$taken = new OccupancySnapshot( 8, array(), array(), 2 );
		$on    = new CapacityLimits( 10, array(), array(), false, true );
		$off   = new CapacityLimits( 10, array(), array(), false, false );
		$this->assertSame( EventAvailabilityState::Closed, EventAvailability::decide( $on, $taken, array() ) );
		$this->assertSame( EventAvailabilityState::Open, EventAvailability::decide( $off, $taken, array() ) );
	}

	public function test_single_fits_even_if_pair_would_not(): void {
		$limits = new CapacityLimits( 10, array(), array(), false, true );
		$this->assertSame( EventAvailabilityState::Open, EventAvailability::decide( $limits, new OccupancySnapshot( 9 ), array() ) );
	}

	public function test_no_types_falls_back_to_global_cap(): void {
		$full = new CapacityLimits( 5, array(), array(), false );
		$none = new CapacityLimits( null, array(), array(), false );
		$this->assertSame( EventAvailabilityState::Closed, EventAvailability::decide( $full, new OccupancySnapshot( 5 ), array() ) );
		$this->assertSame( EventAvailabilityState::Open, EventAvailability::decide( $none, new OccupancySnapshot( 5 ), array() ) );
	}
}
