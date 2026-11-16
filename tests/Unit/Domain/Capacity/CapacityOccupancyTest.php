<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Capacity;

use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Domain\Capacity\CapacityCalculator;
use EvReg\Domain\Capacity\CapacityLimits;
use EvReg\Domain\Capacity\OccupancySnapshot;
use PHPUnit\Framework\TestCase;

final class CapacityOccupancyTest extends TestCase {

	public function test_seats_for_slot_uses_configured_occupancy(): void {
		$limits = new CapacityLimits( null, array(), array(), true, false, array( 'n18|double' => 2 ) );

		$this->assertSame( 2, $limits->seatsForSlot( 'n18|double', false ) );
		$this->assertSame( 2, $limits->seatsForSlot( 'n18|double', true ) );
	}

	public function test_seats_for_slot_without_occupancy_falls_back_to_companion(): void {
		$limits = new CapacityLimits( null, array(), array(), true, false, array( 'n18|any' => 0 ) );

		$this->assertSame( 1, $limits->seatsForSlot( 'n18|any', false ) );
		$this->assertSame( 2, $limits->seatsForSlot( 'n18|any', true ) );
	}

	public function test_seats_for_slot_absent_from_map_falls_back_to_companion(): void {
		$limits = new CapacityLimits( null, array(), array(), true, false, array() );

		$this->assertSame( 1, $limits->seatsForSlot( 'n18|ghost', false ) );
		$this->assertSame( 2, $limits->seatsForSlot( 'n18|ghost', true ) );
	}

	public function test_double_room_takes_two_seats_even_without_companion(): void {
		// Config-drift: pokój oznaczony jako dwuosobowy po tym, jak ktoś wybrał go solo.
		// Pokój zajmuje dwa łóżka niezależnie od flagi — slot ma tylko jedno wolne.
		$limits   = new CapacityLimits( null, array(), array( 'n18|double' => 1 ), true, false, array( 'n18|double' => 2 ) );
		$decision = ( new CapacityCalculator() )->decide(
			$limits,
			new OccupancySnapshot( 0 ),
			'uczestnik',
			new AccommodationSelection( 'n18', 'double' ),
			false
		);

		$this->assertTrue( $decision->isAccepted() );
		$this->assertFalse( $decision->accommodationGranted );
		$this->assertSame( 'accommodation_full', $decision->reason );
	}

	public function test_single_room_takes_one_seat_even_with_companion(): void {
		$limits   = new CapacityLimits( null, array(), array( 'n18|single' => 1 ), true, false, array( 'n18|single' => 1 ) );
		$decision = ( new CapacityCalculator() )->decide(
			$limits,
			new OccupancySnapshot( 0 ),
			'uczestnik',
			new AccommodationSelection( 'n18', 'single' ),
			true
		);

		$this->assertTrue( $decision->accommodationGranted );
	}
}
