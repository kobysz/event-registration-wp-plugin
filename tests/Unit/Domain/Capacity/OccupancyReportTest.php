<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Capacity;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Capacity\CapacityLimits;
use EvReg\Domain\Capacity\OccupancyReport;
use EvReg\Domain\Capacity\OccupancySnapshot;
use EvReg\Domain\Registration\RegistrationTypeCollection;
use PHPUnit\Framework\TestCase;

final class OccupancyReportTest extends TestCase {

	private function types(): RegistrationTypeCollection {
		return RegistrationTypeCollection::fromArray(
			array(
				array(
					'key'      => 'uczestnik',
					'label'    => 'Uczestnik',
					'price'    => 100.0,
					'capacity' => 10,
				),
				array(
					'key'   => 'wykladowca',
					'label' => 'Wykładowca',
					'price' => 0.0,
				),
			)
		);
	}

	private function accommodation(): AccommodationConfig {
		return AccommodationConfig::fromArray(
			array(
				'packages'  => array( array( 'key' => 'n12', 'label' => 'Noc 1–2' ) ),
				'rooms'     => array(
					array( 'key' => 'double', 'label' => 'Pokój 2-osobowy' ),
					array( 'key' => 'single', 'label' => 'Pokój 1-osobowy' ),
				),
				'inventory' => array(
					array( 'package' => 'n12', 'room' => 'double', 'capacity' => 5, 'price' => 180.0 ),
					array( 'package' => 'n12', 'room' => 'single', 'capacity' => 2, 'price' => 250.0 ),
				),
			)
		);
	}

	/**
	 * @param array<string,int> $status_counts
	 */
	private function report(
		OccupancySnapshot $taken,
		?int $global_limit = 20,
		array $status_counts = array(),
		float $price_total = 0.0
	): OccupancyReport {
		$types = $this->types();
		$acc   = $this->accommodation();

		return new OccupancyReport(
			$taken,
			new CapacityLimits( $global_limit, $types->capacities(), $acc->capacities() ),
			$types,
			$acc,
			$status_counts,
			$price_total
		);
	}

	public function test_overall_reports_taken_limit_and_free(): void {
		$report = $this->report( new OccupancySnapshot( 8, array(), array() ) );

		$this->assertSame(
			array(
				'taken' => 8,
				'limit' => 20,
				'free'  => 12,
			),
			$report->overall()
		);
	}

	public function test_overall_without_limit_has_null_limit_and_free(): void {
		$report = $this->report( new OccupancySnapshot( 8, array(), array() ), null );

		$overall = $report->overall();

		$this->assertSame( 8, $overall['taken'] );
		$this->assertNull( $overall['limit'] );
		$this->assertNull( $overall['free'] );
	}

	public function test_overall_free_never_negative(): void {
		$report = $this->report( new OccupancySnapshot( 25, array(), array() ), 20 );

		$this->assertSame( 0, $report->overall()['free'] );
	}

	public function test_types_row_per_type_with_limit_and_free(): void {
		$report = $this->report( new OccupancySnapshot( 8, array( 'uczestnik' => 6 ), array() ) );

		$this->assertSame(
			array(
				array(
					'key'   => 'uczestnik',
					'label' => 'Uczestnik',
					'taken' => 6,
					'limit' => 10,
					'free'  => 4,
				),
				array(
					'key'   => 'wykladowca',
					'label' => 'Wykładowca',
					'taken' => 0,
					'limit' => null,
					'free'  => null,
				),
			),
			$report->types()
		);
	}

	public function test_slots_row_per_inventory_item_with_labels(): void {
		// Companion zajmuje 2 miejsca — snapshot podaje już SUM(seats).
		$report = $this->report( new OccupancySnapshot( 8, array(), array( 'n12|double' => 4 ) ) );

		$this->assertSame(
			array(
				array(
					'slot'         => 'n12|double',
					'packageLabel' => 'Noc 1–2',
					'roomLabel'    => 'Pokój 2-osobowy',
					'taken'        => 4,
					'capacity'     => 5,
					'free'         => 1,
				),
				array(
					'slot'         => 'n12|single',
					'packageLabel' => 'Noc 1–2',
					'roomLabel'    => 'Pokój 1-osobowy',
					'taken'        => 0,
					'capacity'     => 2,
					'free'         => 2,
				),
			),
			$report->slots()
		);
	}

	public function test_passthrough_companions_statuses_and_price(): void {
		$report = $this->report(
			new OccupancySnapshot( 8, array(), array(), 3 ),
			20,
			array(
				'confirmed' => 5,
				'pending'   => 3,
				'waitlist'  => 2,
				'cancelled' => 1,
			),
			1234.5
		);

		$this->assertSame( 3, $report->companions() );
		$this->assertSame( 5, $report->statusCount( 'confirmed' ) );
		$this->assertSame( 2, $report->statusCount( 'waitlist' ) );
		$this->assertSame( 0, $report->statusCount( 'nieznany' ) );
		$this->assertSame( 1234.5, $report->priceTotal() );
	}
}
