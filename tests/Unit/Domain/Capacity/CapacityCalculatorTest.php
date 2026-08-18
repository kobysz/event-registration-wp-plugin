<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Capacity;

use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Domain\Capacity\CapacityCalculator;
use EvReg\Domain\Capacity\CapacityLimits;
use EvReg\Domain\Capacity\OccupancySnapshot;
use EvReg\Domain\Capacity\Outcome;
use PHPUnit\Framework\TestCase;

final class CapacityCalculatorTest extends TestCase {

	private CapacityCalculator $calculator;

	protected function setUp(): void {
		$this->calculator = new CapacityCalculator();
	}

	private function limits( ?int $global = 100, bool $waitlist = true ): CapacityLimits {
		return new CapacityLimits(
			$global,
			array( 'uczestnik' => 50, 'online' => null ),
			array( 'n12|double' => 20 ),
			$waitlist
		);
	}

	private function taken( int $global = 0, int $type = 0, int $slot = 0 ): OccupancySnapshot {
		return new OccupancySnapshot(
			$global,
			array( 'uczestnik' => $type ),
			array( 'n12|double' => $slot )
		);
	}

	public function test_accepts_when_everything_is_available(): void {
		$decision = $this->calculator->decide(
			$this->limits(),
			$this->taken(),
			'uczestnik',
			new AccommodationSelection( 'n12', 'double' )
		);

		$this->assertSame( Outcome::Accepted, $decision->outcome );
		$this->assertTrue( $decision->accommodationGranted );
		$this->assertNull( $decision->reason );
	}

	public function test_waitlists_when_event_is_full(): void {
		$decision = $this->calculator->decide(
			$this->limits( 100 ),
			$this->taken( 100 ),
			'uczestnik',
			new AccommodationSelection( 'n12', 'double' )
		);

		$this->assertSame( Outcome::Waitlisted, $decision->outcome );
		$this->assertFalse( $decision->accommodationGranted );
		$this->assertSame( 'event_full', $decision->reason );
	}

	public function test_rejects_when_event_full_and_waitlist_disabled(): void {
		$decision = $this->calculator->decide(
			$this->limits( 100, false ),
			$this->taken( 100 ),
			'uczestnik',
			null
		);

		$this->assertSame( Outcome::Rejected, $decision->outcome );
		$this->assertSame( 'event_full', $decision->reason );
	}

	/**
	 * Regresja: limit globalny równy 0 oznacza "pełne" (0 miejsc), a nie brak
	 * limitu — to odróżnia go od `null`. Sprawdzenie musi być `>=`, nie `>`.
	 */
	public function test_global_limit_of_zero_is_full_with_waitlist(): void {
		$decision = $this->calculator->decide(
			$this->limits( 0, true ),
			$this->taken( 0 ),
			'uczestnik',
			null
		);

		$this->assertSame( Outcome::Waitlisted, $decision->outcome );
		$this->assertSame( 'event_full', $decision->reason );
	}

	/**
	 * Regresja: jak wyżej, ale bez listy rezerwowej zgłoszenie musi zostać odrzucone.
	 */
	public function test_global_limit_of_zero_is_full_without_waitlist(): void {
		$decision = $this->calculator->decide(
			$this->limits( 0, false ),
			$this->taken( 0 ),
			'uczestnik',
			null
		);

		$this->assertSame( Outcome::Rejected, $decision->outcome );
		$this->assertSame( 'event_full', $decision->reason );
	}

	public function test_waitlists_when_type_is_full(): void {
		$decision = $this->calculator->decide(
			$this->limits(),
			$this->taken( 10, 50 ),
			'uczestnik',
			null
		);

		$this->assertSame( Outcome::Waitlisted, $decision->outcome );
		$this->assertSame( 'type_full', $decision->reason );
	}

	public function test_accepts_without_accommodation_when_rooms_are_full(): void {
		$decision = $this->calculator->decide(
			$this->limits(),
			$this->taken( 10, 10, 20 ),
			'uczestnik',
			new AccommodationSelection( 'n12', 'double' )
		);

		$this->assertSame( Outcome::Accepted, $decision->outcome );
		$this->assertFalse( $decision->accommodationGranted );
		$this->assertSame( 'accommodation_full', $decision->reason );
	}

	public function test_accepts_when_type_has_no_limit(): void {
		$decision = $this->calculator->decide(
			$this->limits(),
			$this->taken( 10, 9999 ),
			'online',
			null
		);

		$this->assertSame( Outcome::Accepted, $decision->outcome );
	}

	public function test_accepts_when_global_limit_is_null(): void {
		$decision = $this->calculator->decide(
			$this->limits( null ),
			$this->taken( 100000 ),
			'online',
			null
		);

		$this->assertSame( Outcome::Accepted, $decision->outcome );
	}

	public function test_unknown_slot_is_not_granted(): void {
		$decision = $this->calculator->decide(
			$this->limits(),
			$this->taken(),
			'uczestnik',
			new AccommodationSelection( 'n99', 'single' )
		);

		$this->assertSame( Outcome::Accepted, $decision->outcome );
		$this->assertFalse( $decision->accommodationGranted );
		$this->assertSame( 'accommodation_full', $decision->reason );
	}
}
