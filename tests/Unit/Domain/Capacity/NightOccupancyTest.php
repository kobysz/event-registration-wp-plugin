<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Capacity;

use EvReg\Domain\Capacity\NightOccupancy;
use PHPUnit\Framework\TestCase;

final class NightOccupancyTest extends TestCase {

	public function test_occupancy_returns_given_numbers_for_a_known_night(): void {
		$occupancy = new NightOccupancy( array( 'n1' => 7 ), array( 'n1' => 2 ) );

		$this->assertSame( 7, $occupancy->seatsFor( 'n1' ) );
		$this->assertSame( 2, $occupancy->pairsFor( 'n1' ) );
	}

	public function test_occupancy_returns_zero_for_an_unknown_night(): void {
		$occupancy = new NightOccupancy( array( 'n1' => 7 ), array( 'n1' => 2 ) );

		$this->assertSame( 0, $occupancy->seatsFor( 'n9' ) );
		$this->assertSame( 0, $occupancy->pairsFor( 'n9' ) );
	}
}
