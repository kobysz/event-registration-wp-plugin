<?php
/**
 * Odczyt i zapis konfiguracji eventu w czterech meta CPT jako JSON.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Persistence;

defined( 'ABSPATH' ) || exit;

/**
 * Odczyt i zapis konfiguracji eventu w czterech meta CPT jako JSON.
 */
final class EventConfigRepository {

	public const META_SCHEMA        = '_evreg_schema';
	public const META_TYPES         = '_evreg_types';
	public const META_ACCOMMODATION = '_evreg_accommodation';
	public const META_SETTINGS      = '_evreg_settings';

	/**
	 * Mapa kluczy logicznych na klucze meta.
	 *
	 * @var array<string,string>
	 */
	private const KEY_MAP = array(
		'schema'        => self::META_SCHEMA,
		'types'         => self::META_TYPES,
		'accommodation' => self::META_ACCOMMODATION,
		'settings'      => self::META_SETTINGS,
	);

	/**
	 * Zwraca całą konfigurację eventu. Brakujące meta jako puste tablice.
	 *
	 * @param int $event_id ID posta eventu.
	 *
	 * @return array<string,array<mixed>>
	 */
	public function get( int $event_id ): array {
		$config = array();

		foreach ( self::KEY_MAP as $key => $meta_key ) {
			$config[ $key ] = $this->read( $event_id, $meta_key );
		}

		return $config;
	}

	/**
	 * Zapisuje przekazane fragmenty konfiguracji. Klucze nieobecne pomijane.
	 *
	 * @param int                 $event_id ID posta eventu.
	 * @param array<string,mixed> $config   Dowolny podzbiór kluczy schema/types/accommodation/settings.
	 */
	public function save( int $event_id, array $config ): void {
		foreach ( self::KEY_MAP as $key => $meta_key ) {
			if ( ! array_key_exists( $key, $config ) ) {
				continue;
			}

			$encoded = wp_json_encode( $config[ $key ], JSON_PRESERVE_ZERO_FRACTION );

			// update_post_meta() unslashes the value; pre-slash so backslash-escapes in the
			// JSON (e.g. \uXXXX, \") survive the round trip instead of being corrupted.
			update_post_meta( $event_id, $meta_key, false === $encoded ? '' : wp_slash( $encoded ) );
		}
	}

	/**
	 * Zwraca overlay tłumaczeń treści (_evreg_i18n) lub pustą tablicę.
	 *
	 * @param int $event_id ID eventu.
	 *
	 * @return array<string,mixed>
	 */
	public function getI18n( int $event_id ): array {
		$raw = get_post_meta( $event_id, '_evreg_i18n', true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Zapisuje overlay tłumaczeń treści.
	 *
	 * @param int                 $event_id ID eventu.
	 * @param array<string,mixed> $overlay  Mapa lang → nadpisania.
	 */
	public function saveI18n( int $event_id, array $overlay ): void {
		update_post_meta( $event_id, '_evreg_i18n', wp_slash( (string) wp_json_encode( $overlay ) ) );
	}

	/**
	 * Czyta pojedyncze meta i dekoduje z JSON.
	 *
	 * @param int    $event_id ID posta eventu.
	 * @param string $meta_key Klucz meta.
	 *
	 * @return array<mixed>
	 */
	private function read( int $event_id, string $meta_key ): array {
		$raw = get_post_meta( $event_id, $meta_key, true );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : array();
	}
}
