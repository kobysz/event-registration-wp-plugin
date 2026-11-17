<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Pricing;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Domain\Accommodation\NightSelection;
use EvReg\Domain\Accommodation\NightsConfig;
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

	private function nightsConfig( float $price = 150.0 ): NightsConfig {
		return NightsConfig::fromArray(
			array(
				'mode'   => 'nights',
				'nights' => array(
					array( 'key' => 'n1819', 'label' => '18/19', 'price' => $price, 'seats' => 10 ),
					array( 'key' => 'n1920', 'label' => '19/20', 'price' => $price, 'seats' => 10 ),
				),
			)
		);
	}

	public function test_nights_total_adds_the_type_price_once(): void {
		// Cena typu liczy się RAZ, także dla pary — jak w total() dla trybu classic.
		$config    = $this->nightsConfig();
		$selection = NightSelection::fromKeys( array( 'n1819', 'n1920' ), $config );

		$total = $this->calculator->totalForNights( new RegistrationType( 'uczestnik', 'Uczestnik', 100.0 ), $config, $selection, 2 );

		$this->assertSame( 100.0 + ( 150.0 + 150.0 ) * 2, $total );
	}

	public function test_lone_registrant_pays_for_one_seat_per_night(): void {
		$config    = $this->nightsConfig();
		$selection = NightSelection::fromKeys( array( 'n1819', 'n1920' ), $config );

		$total = $this->calculator->totalForNights( new RegistrationType( 'uczestnik', 'Uczestnik', 100.0 ), $config, $selection, 1 );

		$this->assertSame( 400.0, $total );
	}

	public function test_empty_selection_prices_the_type_only(): void {
		$config    = $this->nightsConfig();
		$selection = NightSelection::fromKeys( array(), $config );

		$total = $this->calculator->totalForNights( new RegistrationType( 'uczestnik', 'Uczestnik', 100.0 ), $config, $selection, 2 );

		$this->assertSame( 100.0, $total );
	}

	public function test_unknown_night_contributes_nothing(): void {
		$config    = $this->nightsConfig();
		$selection = NightSelection::fromKeys( array( 'n1819', 'n9999' ), $config );

		$total = $this->calculator->totalForNights( new RegistrationType( 'uczestnik', 'Uczestnik', 100.0 ), $config, $selection, 2 );

		$this->assertSame( 400.0, $total );
	}

	public function test_total_is_rounded_like_the_classic_path(): void {
		$config    = $this->nightsConfig( 33.333 );
		$selection = NightSelection::fromKeys( array( 'n1819' ), $config );

		$total = $this->calculator->totalForNights( new RegistrationType( 'uczestnik', 'Uczestnik', 100.0 ), $config, $selection, 1 );

		$this->assertSame( 133.33, $total );
	}
}
