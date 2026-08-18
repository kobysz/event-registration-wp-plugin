<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Admin;

use EvReg\Admin\EventPostType;
use WP_UnitTestCase;

final class EventPostTypeTest extends WP_UnitTestCase {

	public function test_post_type_is_registered(): void {
		EventPostType::register();
		do_action( 'init' );

		$this->assertTrue( post_type_exists( EventPostType::POST_TYPE ) );
	}

	public function test_post_type_is_not_public_but_admin_editable(): void {
		EventPostType::register();
		do_action( 'init' );

		$object = get_post_type_object( EventPostType::POST_TYPE );

		$this->assertNotNull( $object );
		$this->assertFalse( $object->public );
		$this->assertTrue( $object->show_ui );
		$this->assertTrue( (bool) $object->show_in_menu );
	}

	public function test_block_editor_disabled_for_post_type(): void {
		EventPostType::register();
		do_action( 'init' );

		$this->assertFalse(
			apply_filters( 'use_block_editor_for_post_type', true, EventPostType::POST_TYPE )
		);
		$this->assertTrue(
			apply_filters( 'use_block_editor_for_post_type', true, 'page' )
		);
	}
}
