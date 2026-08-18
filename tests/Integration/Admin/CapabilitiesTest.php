<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Admin;

use EvReg\Admin\Capabilities;
use WP_UnitTestCase;

final class CapabilitiesTest extends WP_UnitTestCase {

	public function test_grant_gives_capability_to_administrator(): void {
		get_role( 'administrator' )->remove_cap( Capabilities::CAP );

		Capabilities::grant();

		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::CAP ) );
	}

	public function test_subscriber_does_not_have_capability(): void {
		Capabilities::grant();

		$this->assertFalse( get_role( 'subscriber' )->has_cap( Capabilities::CAP ) );
	}
}
