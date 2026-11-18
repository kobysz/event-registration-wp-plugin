<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Frontend;

use EvReg\Admin\Capabilities;
use EvReg\Frontend\SubmitHandler;
use WP_UnitTestCase;

final class SubmitRateLimitTest extends WP_UnitTestCase {

	protected function tearDown(): void {
		remove_all_filters( 'evreg_rate_limit' );
		remove_all_filters( 'evreg_bypass_rate_limit' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

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

	public function test_threshold_defaults_to_thirty(): void {
		$this->assertSame( 30, SubmitHandler::RATE_LIMIT );
		$this->assertSame( 30, SubmitHandler::rateLimit() );
	}

	public function test_filter_overrides_threshold(): void {
		add_filter( 'evreg_rate_limit', static fn() => 5 );
		$this->assertSame( 5, SubmitHandler::rateLimit() );
	}

	public function test_filter_cannot_lock_everyone_out_or_break_the_default(): void {
		add_filter( 'evreg_rate_limit', static fn() => 0 );
		$this->assertSame( 1, SubmitHandler::rateLimit() );

		remove_all_filters( 'evreg_rate_limit' );
		add_filter( 'evreg_rate_limit', static fn() => 'nonsens' );
		$this->assertSame( SubmitHandler::RATE_LIMIT, SubmitHandler::rateLimit() );
		$this->assertSame( 30, SubmitHandler::rateLimit() );
	}

	public function test_anonymous_visitor_is_not_exempt(): void {
		wp_set_current_user( 0 );
		$this->assertFalse( SubmitHandler::bypassesRateLimit() );
	}

	public function test_event_manager_is_exempt_and_filter_has_last_word(): void {
		Capabilities::grant();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( SubmitHandler::bypassesRateLimit() );

		add_filter( 'evreg_bypass_rate_limit', static fn() => false );
		$this->assertFalse( SubmitHandler::bypassesRateLimit() );
	}
}
