<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Frontend;

use EvReg\Frontend\SubmitHandler;
use WP_UnitTestCase;

final class SubmitRateLimitTest extends WP_UnitTestCase {

	public function test_rate_limit_blocks_after_threshold(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';

		// Poniżej progu — dozwolone.
		for ( $i = 0; $i < SubmitHandler::RATE_LIMIT; $i++ ) {
			$this->assertFalse( SubmitHandler::isRateLimited(), "iteracja {$i}" );
			SubmitHandler::recordAttempt();
		}

		// Po progu — zablokowane.
		$this->assertTrue( SubmitHandler::isRateLimited() );
	}
}
