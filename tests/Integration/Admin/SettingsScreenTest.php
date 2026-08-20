<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Admin;

use EvReg\Admin\SettingsScreen;
use EvReg\Persistence\Uninstaller;
use WP_UnitTestCase;

final class SettingsScreenTest extends WP_UnitTestCase {

	public function test_sanitize_maps_values_to_zero_or_one(): void {
		$this->assertSame( 1, SettingsScreen::sanitize( 'on' ) );
		$this->assertSame( 1, SettingsScreen::sanitize( '1' ) );
		$this->assertSame( 0, SettingsScreen::sanitize( '' ) );
		$this->assertSame( 0, SettingsScreen::sanitize( null ) );
	}

	public function test_register_registers_uninstall_option(): void {
		// wp-admin/includes/admin-filters.php hooks wp_admin_headers() to admin_init,
		// which calls header() — fatal here since PHPUnit's bootstrap already emitted
		// output. Unhook it; irrelevant to what this test verifies.
		remove_action( 'admin_init', 'wp_admin_headers' );
		// Core's wp_add_privacy_policy_content() is also hooked to admin_init and
		// complains via _doing_it_wrong() when fired outside a real is_admin() request.
		// Expected here; irrelevant to what this test verifies.
		$this->setExpectedIncorrectUsage( 'wp_add_privacy_policy_content' );

		SettingsScreen::register();
		do_action( 'admin_init' );

		$registered = get_registered_settings();
		$this->assertArrayHasKey( Uninstaller::DELETE_OPTION, $registered );
	}
}
