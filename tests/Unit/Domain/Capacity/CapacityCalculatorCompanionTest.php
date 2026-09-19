<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Capacity;

use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Domain\Capacity\CapacityCalculator;
use EvReg\Domain\Capacity\CapacityLimits;
use EvReg\Domain\Capacity\OccupancySnapshot;
use EvReg\Domain\Capacity\Outcome;
use PHPUnit\Framework\TestCase;

final class CapacityCalculatorCompanionTest extends TestCase {

	private CapacityCalculator $calc;

	protected function setUp(): void {
		$this->calc = new CapacityCalculator();
	}

	public function test_companion_counts_to_event_when_enabled(): void {
		// global limit 2, zajęte 1 zgłoszenie (0 companionów). Companion counts → 1+2=3 > 2 → waitlist.
		$limits = new CapacityLimits( 2, array(), array(), true, true );
		$taken  = new OccupancySnapshot( 1, array(), array(), 0 );
		$d      = $this->calc->decide( $limits, $taken, 'uczestnik', null, true );
		$this->assertSame( Outcome::Waitlisted, $d->outcome );
	}

	public function test_companion_ignored_for_event_when_flag_off(): void {
		$limits = new CapacityLimits( 2, array(), array(), true, false );
		$taken  = new OccupancySnapshot( 1, array(), array(), 0 );
		$d      = $this->calc->decide( $limits, $taken, 'uczestnik', null, true );
		$this->assertSame( Outcome::Accepted, $d->outcome );
	}

	public function test_companion_needs_two_accommodation_seats(): void {
		// slot limit 3, zajęte 2 miejsca → companion żąda 2 → 2+2=4 > 3 → nocleg nieprzyznany.
		$sel    = new AccommodationSelection( 'n12', 'double' );
		$limits = new CapacityLimits( null, array(), array( 'n12|double' => 3 ), true, false );
		$taken  = new OccupancySnapshot( 5, array(), array( 'n12|double' => 2 ) );
		$d      = $this->calc->decide( $limits, $taken, 'uczestnik', $sel, true );
		$this->assertSame( Outcome::Accepted, $d->outcome );
		$this->assertFalse( $d->accommodationGranted );
		$this->assertSame( 'accommodation_full', $d->reason );
	}

	public function test_companion_granted_when_two_seats_free(): void {
		$sel    = new AccommodationSelection( 'n12', 'double' );
		$limits = new CapacityLimits( null, array(), array( 'n12|double' => 4 ), true, false );
		$taken  = new OccupancySnapshot( 5, array(), array( 'n12|double' => 2 ) );
		$d      = $this->calc->decide( $limits, $taken, 'uczestnik', $sel, true );
		$this->assertTrue( $d->accommodationGranted );
	}

	public function test_no_companion_matches_legacy_behavior(): void {
		// slot limit 1, zajęte 0 → bez companiona grant; zajęte 1 → deny.
		$sel    = new AccommodationSelection( 'n12', 'double' );
		$limits = new CapacityLimits( null, array(), array( 'n12|double' => 1 ), true, false );
		$grant  = $this->calc->decide( $limits, new OccupancySnapshot( 0, array(), array() ), 'x', $sel, false );
		$this->assertTrue( $grant->accommodationGranted );
		$deny   = $this->calc->decide( $limits, new OccupancySnapshot( 0, array(), array( 'n12|double' => 1 ) ), 'x', $sel, false );
		$this->assertFalse( $deny->accommodationGranted );
	}
}
