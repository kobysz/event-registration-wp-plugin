<?php

declare( strict_types=1 );

namespace EvReg;

defined( 'ABSPATH' ) || exit;

/**
 * Punkt kompozycji wtyczki.
 */
final class Plugin {

	public const VERSION = '0.1.0';

	public const TEXT_DOMAIN = 'event-registration';

	private static string $plugin_file = '';

	public static function boot( string $plugin_file ): void {
		self::$plugin_file = $plugin_file;
	}

	public static function plugin_file(): string {
		return self::$plugin_file;
	}
}
