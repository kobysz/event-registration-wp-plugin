<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Services;

use EvReg\Services\AdminActionResult;
use PHPUnit\Framework\TestCase;

final class AdminActionResultTest extends TestCase {

	public function test_edited_code(): void {
		$this->assertSame( 'edited', AdminActionResult::edited()->code );
	}

	public function test_capacity_full_code(): void {
		$this->assertSame( 'capacity_full', AdminActionResult::capacityFull()->code );
	}

	public function test_accommodation_full_code(): void {
		$this->assertSame( 'accommodation_full', AdminActionResult::accommodationFull()->code );
	}
}
