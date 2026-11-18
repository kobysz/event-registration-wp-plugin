<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Registration;

use EvReg\Domain\Registration\ManualEntry;
use PHPUnit\Framework\TestCase;

final class ManualEntryTest extends TestCase {

	public function test_defaults_to_not_notifying(): void {
		$this->assertFalse( ( new ManualEntry() )->notify );
	}

	public function test_carries_notify_flag(): void {
		$this->assertTrue( ( new ManualEntry( true ) )->notify );
	}
}
