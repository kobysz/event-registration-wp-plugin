<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration;

use EvReg\Plugin;
use WP_UnitTestCase;

final class PluginVersionTest extends WP_UnitTestCase {

	public function test_version_matches_plugin_header(): void {
		$expected = get_file_data(
			Plugin::plugin_file(),
			array( 'Version' => 'Version' )
		)['Version'];
		$this->assertNotSame( '', $expected );
		$this->assertSame( $expected, Plugin::version() );
	}
}
