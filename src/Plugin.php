<?php
/**
 * Punkt kompozycji wtyczki.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg;

defined( 'ABSPATH' ) || exit;

/**
 * Punkt kompozycji wtyczki.
 */
final class Plugin {

	public const VERSION = '0.1.0';

	public const TEXT_DOMAIN = 'event-registration';

	/**
	 * Ścieżka do głównego pliku wtyczki.
	 *
	 * @var string
	 */
	private static string $plugin_file = '';

	/**
	 * Zapamiętuje ścieżkę głównego pliku wtyczki.
	 *
	 * @param string $plugin_file Ścieżka do głównego pliku wtyczki.
	 */
	public static function boot( string $plugin_file ): void {
		self::$plugin_file = $plugin_file;
	}

	/**
	 * Zwraca ścieżkę do głównego pliku wtyczki.
	 */
	public static function plugin_file(): string {
		return self::$plugin_file;
	}
}
