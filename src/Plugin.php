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

	public const TEXT_DOMAIN = 'event-registration';

	/**
	 * Ścieżka do głównego pliku wtyczki.
	 *
	 * @var string
	 */
	private static string $plugin_file = '';

	/**
	 * Zderywowana wersja wtyczki (cache).
	 *
	 * @var string
	 */
	private static string $version = '';

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

	/**
	 * Zwraca wersję wtyczki odczytaną z nagłówka głównego pliku (jedno źródło prawdy).
	 *
	 * Wynik jest cache'owany na całe żądanie. Aktualizator czyta z `$refresh = true`,
	 * bo w żądaniu, w którym WordPress podmienia pliki wtyczki, zapamiętana wartość
	 * jest już nieaktualna — porównanie z wydaniem musi patrzeć na plik, nie na pamięć.
	 *
	 * @param bool $refresh Wymusza ponowny odczyt nagłówka z dysku.
	 */
	public static function version( bool $refresh = false ): string {
		if ( $refresh || '' === self::$version ) {
			$data          = get_file_data( self::$plugin_file, array( 'Version' => 'Version' ) );
			self::$version = (string) ( $data['Version'] ?? '' );
		}
		return self::$version;
	}
}
