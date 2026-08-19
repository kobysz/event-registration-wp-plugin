<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Mail;

use EvReg\Domain\Mail\RetryPolicy;
use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase {

	private RetryPolicy $policy;

	protected function setUp(): void {
		$this->policy = new RetryPolicy();
	}

	public function test_first_failure_retries_after_one_minute(): void {
		$this->assertSame( 60, $this->policy->next( 1 ) );
	}

	public function test_second_failure_retries_after_five_minutes(): void {
		$this->assertSame( 300, $this->policy->next( 2 ) );
	}

	public function test_third_failure_gives_up(): void {
		$this->assertNull( $this->policy->next( 3 ) );
	}

	public function test_attempts_beyond_maximum_give_up(): void {
		$this->assertNull( $this->policy->next( 7 ) );
	}

	public function test_max_attempts_is_three(): void {
		$this->assertSame( 3, RetryPolicy::MAX_ATTEMPTS );
	}
}
