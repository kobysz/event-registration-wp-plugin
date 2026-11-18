<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Registration;

use EvReg\Domain\Registration\ManualEntry;
use PHPUnit\Framework\TestCase;

final class ManualEntryTest extends TestCase {

	public function test_defaults_to_not_notifying(): void {
		$this->assertFalse( ( new ManualEntry() )->notify );
	}

	public function test_notify_is_immutable(): void {
		$entry = new ManualEntry( true );

		$this->expectException( \Error::class );
		// @phpstan-ignore-next-line Celowo łamiemy readonly, by sprawdzić niemutowalność.
		$entry->notify = false;
	}

	public function test_carries_notify_flag(): void {
		$this->assertTrue( ( new ManualEntry( true ) )->notify );
	}
}
