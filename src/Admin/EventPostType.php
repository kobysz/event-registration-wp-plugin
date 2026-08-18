<?php
/**
 * Rejestracja typu wpisu evreg_event i wyłączenie edytora blokowego.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Rejestracja typu wpisu evreg_event i wyłączenie edytora blokowego.
 */
final class EventPostType {

	public const POST_TYPE = 'evreg_event';

	/**
	 * Podpina rejestrację CPT i wyłączenie Gutenberga.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_post_type' ) );
		add_filter( 'use_block_editor_for_post_type', array( self::class, 'disable_block_editor' ), 10, 2 );
	}

	/**
	 * Rejestruje typ wpisu.
	 */
	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Wydarzenia', 'event-registration' ),
					'singular_name' => __( 'Wydarzenie', 'event-registration' ),
					'add_new_item'  => __( 'Dodaj wydarzenie', 'event-registration' ),
					'edit_item'     => __( 'Edytuj wydarzenie', 'event-registration' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'show_in_rest'    => false,
				'menu_icon'       => 'dashicons-tickets-alt',
				'supports'        => array( 'title' ),
				'has_archive'     => false,
				'rewrite'         => false,
				'capability_type' => array( 'evreg_event', 'evreg_events' ),
				'map_meta_cap'    => true,
			)
		);
	}

	/**
	 * Wyłącza edytor blokowy dla evreg_event.
	 *
	 * @param bool   $use_block_editor Czy użyć edytora blokowego.
	 * @param string $post_type        Typ wpisu.
	 */
	public static function disable_block_editor( bool $use_block_editor, string $post_type ): bool {
		if ( self::POST_TYPE === $post_type ) {
			return false;
		}

		return $use_block_editor;
	}
}
