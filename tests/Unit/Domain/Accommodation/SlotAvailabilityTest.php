<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Accommodation;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Accommodation\SlotAvailability;
use EvReg\Domain\Capacity\OccupancySnapshot;
use PHPUnit\Framework\TestCase;

final class SlotAvailabilityTest extends TestCase {

	private function config(): AccommodationConfig {
		return AccommodationConfig::fromArray(
			array(
				'packages'  => array( array( 'key' => 'n18', 'label' => 'Noc 18/19' ) ),
				'rooms'     => array(
					array( 'key' => 'single', 'label' => '1-os.' ),
					array( 'key' => 'double', 'label' => '2-os.' ),
				),
				'inventory' => array(
					array( 'package' => 'n18', 'room' => 'single', 'capacity' => 10, 'price' => 100.0 ),
					array( 'package' => 'n18', 'room' => 'double', 'capacity' => 1, 'price' => 180.0 ),
				),
			)
		);
	}

	public function test_left_subtracts_taken_seats(): void {
		$availability = SlotAvailability::fromSnapshot(
			$this->config(),
			new OccupancySnapshot( 4, array(), array( 'n18|single' => 4 ) )
		);

		$this->assertSame( 6, $availability->left( 'n18|single' ) );
	}

	public function test_left_never_goes_below_zero(): void {
		$availability = SlotAvailability::fromSnapshot(
			$this->config(),
			new OccupancySnapshot( 5, array(), array( 'n18|double' => 5 ) )
		);

		$this->assertSame( 0, $availability->left( 'n18|double' ) );
	}

	public function test_empty_snapshot_leaves_full_capacity(): void {
		$availability = SlotAvailability::fromSnapshot( $this->config(), new OccupancySnapshot( 0 ) );

		$this->assertSame( 10, $availability->left( 'n18|single' ) );
		$this->assertSame( 1, $availability->left( 'n18|double' ) );
	}

	public function test_unknown_slot_has_no_seats(): void {
		$availability = SlotAvailability::fromSnapshot( $this->config(), new OccupancySnapshot( 0 ) );

		$this->assertSame( 0, $availability->left( 'n18|suite' ) );
		$this->assertTrue( $availability->isFull( 'n18|suite' ) );
	}

	public function test_single_free_seat_is_full_for_companion_only(): void {
		$availability = SlotAvailability::fromSnapshot( $this->config(), new OccupancySnapshot( 0 ) );

		$this->assertFalse( $availability->isFull( 'n18|double', 1 ) );
		$this->assertTrue( $availability->isFull( 'n18|double', 2 ) );
	}
}
