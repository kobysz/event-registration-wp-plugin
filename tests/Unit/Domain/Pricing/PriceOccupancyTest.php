<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Pricing;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Domain\Pricing\PriceCalculator;
use EvReg\Domain\Registration\RegistrationType;
use PHPUnit\Framework\TestCase;

final class PriceOccupancyTest extends TestCase {

	private function config(): AccommodationConfig {
		return AccommodationConfig::fromArray(
			array(
				'packages'          => array( array( 'key' => 'n18', 'label' => 'Noc 18/19' ) ),
				'rooms'             => array(
					array( 'key' => 'single', 'label' => '1-os.', 'occupancy' => 1 ),
					array( 'key' => 'double', 'label' => '2-os.', 'occupancy' => 2 ),
					array( 'key' => 'any', 'label' => 'dowolny' ),
				),
				'inventory'         => array(
					array( 'package' => 'n18', 'room' => 'single', 'capacity' => 10, 'price' => 100.0 ),
					array( 'package' => 'n18', 'room' => 'double', 'capacity' => 4, 'price' => 180.0 ),
					array( 'package' => 'n18', 'room' => 'any', 'capacity' => 2, 'price' => 150.0 ),
				),
				'companion_enabled' => true,
			)
		);
	}

	private function type(): RegistrationType {
		return RegistrationType::fromArray( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 450.0 ) );
	}

	public function test_double_room_is_charged_twice_without_companion(): void {
		$total = ( new PriceCalculator() )->total(
			$this->type(),
			$this->config(),
			new AccommodationSelection( 'n18', 'double' ),
			false
		);

		$this->assertSame( 810.0, $total ); // 450 + 2 × 180
	}

	public function test_single_room_is_charged_once_with_companion(): void {
		$total = ( new PriceCalculator() )->total(
			$this->type(),
			$this->config(),
			new AccommodationSelection( 'n18', 'single' ),
			true
		);

		$this->assertSame( 550.0, $total ); // 450 + 1 × 100
	}

	public function test_room_without_occupancy_keeps_companion_based_multiplier(): void {
		$calculator = new PriceCalculator();

		$this->assertSame( 600.0, $calculator->total( $this->type(), $this->config(), new AccommodationSelection( 'n18', 'any' ), false ) );
		$this->assertSame( 750.0, $calculator->total( $this->type(), $this->config(), new AccommodationSelection( 'n18', 'any' ), true ) );
	}
}
