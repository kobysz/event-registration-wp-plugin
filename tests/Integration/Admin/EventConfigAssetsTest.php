<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Admin;

use EvReg\Admin\EventConfigAssets;
use EvReg\Admin\EventPostType;
use WP_UnitTestCase;

final class EventConfigAssetsTest extends WP_UnitTestCase {

	public function test_assets_not_enqueued_outside_event_screen(): void {
		EventConfigAssets::register();

		set_current_screen( 'edit.php' );
		do_action( 'admin_enqueue_scripts', 'edit.php' );

		$this->assertFalse( wp_script_is( EventConfigAssets::HANDLE, 'enqueued' ) );
	}

	public function test_mount_container_printed_after_title_for_event(): void {
		$event = self::factory()->post->create_and_get( array( 'post_type' => EventPostType::POST_TYPE ) );

		ob_start();
		do_action( 'edit_form_after_title', $event );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="evreg-admin-root"', $html );
	}

	public function test_no_mount_container_for_other_post_types(): void {
		$page = self::factory()->post->create_and_get( array( 'post_type' => 'page' ) );

		ob_start();
		do_action( 'edit_form_after_title', $page );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'evreg-admin-root', $html );
	}
}
