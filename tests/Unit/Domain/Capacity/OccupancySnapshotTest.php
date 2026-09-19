<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Capacity;

use EvReg\Domain\Capacity\OccupancySnapshot;
use PHPUnit\Framework\TestCase;

final class OccupancySnapshotTest extends TestCase {

	public function test_companions_defaults_to_zero(): void {
		$snap = new OccupancySnapshot( 5, array(), array() );

		$this->assertSame( 0, $snap->companions() );
	}

	public function test_companions_returned(): void {
		$snap = new OccupancySnapshot( 5, array(), array(), 3 );

		$this->assertSame( 3, $snap->companions() );
	}
}
