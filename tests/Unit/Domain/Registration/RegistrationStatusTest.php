<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Registration;

use EvReg\Domain\Registration\RegistrationStatus;
use PHPUnit\Framework\TestCase;

final class RegistrationStatusTest extends TestCase {

	public function test_pending_and_confirmed_occupy_a_seat(): void {
		$this->assertTrue( RegistrationStatus::Pending->occupiesSeat() );
		$this->assertTrue( RegistrationStatus::Confirmed->occupiesSeat() );
	}

	public function test_waitlist_and_cancelled_do_not_occupy_a_seat(): void {
		$this->assertFalse( RegistrationStatus::Waitlist->occupiesSeat() );
		$this->assertFalse( RegistrationStatus::Cancelled->occupiesSeat() );
	}

	public function test_occupying_values_are_pending_and_confirmed(): void {
		$this->assertSame( array( 'pending', 'confirmed' ), RegistrationStatus::occupyingValues() );
	}
}
