<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Pricing;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Domain\Pricing\PriceCalculator;
use EvReg\Domain\Registration\RegistrationType;
use PHPUnit\Framework\TestCase;

final class PriceCalculatorCompanionTest extends TestCase {

	public function test_companion_doubles_accommodation_price(): void {
		$type = new RegistrationType( 'u', 'Uczestnik', 100.0, null );
		$cfg  = AccommodationConfig::fromArray(
			array(
				'packages'  => array( array( 'key' => 'n12', 'label' => 'N' ) ),
				'rooms'     => array( array( 'key' => 'double', 'label' => 'D' ) ),
				'inventory' => array( array( 'package' => 'n12', 'room' => 'double', 'capacity' => 5, 'price' => 180.0 ) ),
			)
		);
		$sel  = new AccommodationSelection( 'n12', 'double' );
		$calc = new PriceCalculator();

		$this->assertSame( 460.0, $calc->total( $type, $cfg, $sel, true ) );  // 100 + 2×180.
		$this->assertSame( 280.0, $calc->total( $type, $cfg, $sel, false ) ); // 100 + 180.
	}
}
