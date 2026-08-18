<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Pricing;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Domain\Pricing\PriceCalculator;
use EvReg\Domain\Registration\RegistrationType;
use PHPUnit\Framework\TestCase;

final class PriceCalculatorTest extends TestCase {

	private PriceCalculator $calculator;

	protected function setUp(): void {
		$this->calculator = new PriceCalculator();
	}

	private function config(): AccommodationConfig {
		return AccommodationConfig::fromArray(
			array(
				'packages'  => array( array( 'key' => 'n12', 'label' => 'Noc 1–2' ) ),
				'rooms'     => array( array( 'key' => 'double', 'label' => '2-os.' ) ),
				'inventory' => array(
					array( 'package' => 'n12', 'room' => 'double', 'capacity' => 5, 'price' => 180.50 ),
				),
			)
		);
	}

	public function test_sums_type_and_accommodation_price(): void {
		$total = $this->calculator->total(
			new RegistrationType( 'uczestnik', 'Uczestnik', 450.0 ),
			$this->config(),
			new AccommodationSelection( 'n12', 'double' )
		);

		$this->assertSame( 630.50, $total );
	}

	public function test_returns_type_price_without_accommodation(): void {
		$total = $this->calculator->total(
			new RegistrationType( 'online', 'Online', 150.0 ),
			$this->config(),
			null
		);

		$this->assertSame( 150.0, $total );
	}

	public function test_ignores_selection_outside_inventory(): void {
		$total = $this->calculator->total(
			new RegistrationType( 'uczestnik', 'Uczestnik', 450.0 ),
			$this->config(),
			new AccommodationSelection( 'n99', 'double' )
		);

		$this->assertSame( 450.0, $total );
	}

	public function test_handles_missing_accommodation_config(): void {
		$total = $this->calculator->total(
			new RegistrationType( 'wykladowca', 'Wykładowca', 0.0 ),
			null,
			null
		);

		$this->assertSame( 0.0, $total );
	}
}
