<?php
/**
 * Dynamiczny blok osadzający formularz rejestracji eventu.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Dynamiczny blok osadzający formularz rejestracji eventu.
 */
final class Block {

	/**
	 * Rejestruje blok na hooku `init`.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_block' ) );
	}

	/**
	 * Rejestruje dynamiczny typ bloku `evreg/form` z serwerowym render_callback.
	 * Chroni przed powtórną rejestracją, gdy hook `init` odpala się wielokrotnie (np. w testach).
	 */
	public static function register_block(): void {
		if ( \WP_Block_Type_Registry::get_instance()->is_registered( 'evreg/form' ) ) {
			return;
		}

		register_block_type(
			'evreg/form',
			array(
				'api_version'     => '3',
				'title'           => __( 'Formularz rejestracji', 'event-registration' ),
				'category'        => 'widgets',
				'attributes'      => array(
					'eventId' => array(
						'type'    => 'number',
						'default' => 0,
					),
				),
				'render_callback' => array( self::class, 'render' ),
			)
		);
	}

	/**
	 * Renderuje blok, delegując do współdzielonego helpera Shortcode::renderForm().
	 *
	 * @param array<string,mixed> $attributes Atrybuty bloku (w tym eventId).
	 */
	public static function render( array $attributes ): string {
		return Shortcode::renderForm( (int) ( $attributes['eventId'] ?? 0 ) );
	}
}
