<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Capacity;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Accommodation\NightsConfig;
use EvReg\Domain\Capacity\CapacityLimits;
use EvReg\Domain\Capacity\NightOccupancy;
use EvReg\Domain\Capacity\OccupancyReport;
use EvReg\Domain\Capacity\OccupancySnapshot;
use EvReg\Domain\Registration\RegistrationTypeCollection;
use PHPUnit\Framework\TestCase;

/**
 * Zestawienie obłożenia w trybie „doby”: wiersz per doba z limitem miejsc,
 * zajętością, wolnymi miejscami oraz parami i ich limitem.
 */
final class OccupancyReportNightsTest extends TestCase {

	/**
	 * Konfiguracja trybu „doby” z dwiema dobami (druga z limitem par).
	 */
	private function nightsConfig(): NightsConfig {
		return NightsConfig::fromArray(
			array(
				'mode'   => 'nights',
				'nights' => array(
					array(
						'key'   => 'n1819',
						'label' => '18/19 XI',
						'price' => 80.0,
						'seats' => 4,
					),
					array(
						'key'           => 'n1920',
						'label'         => '19/20 XI',
						'price'         => 90.0,
						'seats'         => 6,
						'companion_cap' => 1,
					),
				),
			)
		);
	}

	/**
	 * Składa zestawienie z podanym obłożeniem dób.
	 *
	 * @param NightOccupancy|null $taken Zajętość dób albo null.
	 * @param NightsConfig|null   $nights Konfiguracja dób albo null (tryb klasyczny).
	 */
	private function report( ?NightOccupancy $taken, ?NightsConfig $nights ): OccupancyReport {
		return new OccupancyReport(
			new OccupancySnapshot( 0, array(), array(), 0 ),
			new CapacityLimits( null, array(), array() ),
			RegistrationTypeCollection::fromArray( array() ),
			AccommodationConfig::fromArray( array() ),
			array(),
			0.0,
			$nights,
			$taken
		);
	}

	public function test_nights_rows_carry_limit_taken_free_and_pairs(): void {
		$report = $this->report(
			new NightOccupancy( array( 'n1819' => 3 ), array( 'n1819' => 1 ) ),
			$this->nightsConfig()
		);

		$rows = $report->nights();

		$this->assertCount( 2, $rows );

		$this->assertSame( 'n1819', $rows[0]['key'] );
		$this->assertSame( '18/19 XI', $rows[0]['label'] );
		$this->assertSame( 4, $rows[0]['limit'] );
		$this->assertSame( 3, $rows[0]['taken'] );
		$this->assertSame( 1, $rows[0]['free'] );
		$this->assertSame( 1, $rows[0]['pairsTaken'] );
		$this->assertNull( $rows[0]['pairCap'] );

		$this->assertSame( 'n1920', $rows[1]['key'] );
		$this->assertSame( 6, $rows[1]['limit'] );
		$this->assertSame( 0, $rows[1]['taken'] );
		$this->assertSame( 6, $rows[1]['free'] );
		$this->assertSame( 0, $rows[1]['pairsTaken'] );
		$this->assertSame( 1, $rows[1]['pairCap'] );
	}

	public function test_free_never_goes_negative_when_a_night_is_oversold(): void {
		$report = $this->report(
			new NightOccupancy( array( 'n1819' => 9 ), array() ),
			$this->nightsConfig()
		);

		$rows = $report->nights();

		$this->assertSame( 9, $rows[0]['taken'] );
		$this->assertSame( 0, $rows[0]['free'] );
	}

	public function test_missing_occupancy_counts_as_zero(): void {
		$rows = $this->report( null, $this->nightsConfig() )->nights();

		$this->assertSame( 0, $rows[0]['taken'] );
		$this->assertSame( 4, $rows[0]['free'] );
	}

	public function test_classic_mode_has_no_night_rows(): void {
		$this->assertSame( array(), $this->report( null, null )->nights() );
	}
}
