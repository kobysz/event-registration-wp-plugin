<?php
/**
 * Ładowanie tłumaczeń PHP wtyczki (textdomain).
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg;

defined( 'ABSPATH' ) || exit;

/**
 * Rejestruje ładowanie textdomain event-registration z katalogu languages/.
 */
final class I18n {

	/**
	 * Podpina load_plugin_textdomain na init.
	 */
	public static function register(): void {
		add_action(
			'init',
			static function (): void {
				load_plugin_textdomain(
					'event-registration',
					false,
					dirname( plugin_basename( Plugin::plugin_file() ) ) . '/languages'
				);
			}
		);
	}
}
