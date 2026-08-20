<?php
/**
 * Samodzielny aktualizator wtyczki oparty o wydania GitHub.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Update;

use EvReg\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Sprawdza najnowsze wydanie na GitHubie i wstrzykuje je do WP jako aktualizację wtyczki.
 */
final class GitHubUpdater {

	private const REPO       = 'kobysz/event-registration-wp-plugin';
	private const ASSET_NAME = 'event-registration.zip';
	private const CACHE_KEY  = 'evreg_update_latest';
	private const CACHE_TTL  = 12 * HOUR_IN_SECONDS;

	/**
	 * Basename wtyczki (np. `event-registration/event-registration.php`).
	 *
	 * @var string
	 */
	private string $basename;

	/**
	 * Slug wtyczki (katalog wtyczki).
	 *
	 * @var string
	 */
	private string $slug;

	/**
	 * Derywuje basename i slug z głównego pliku wtyczki.
	 */
	public function __construct() {
		$this->basename = plugin_basename( Plugin::plugin_file() );
		$this->slug     = dirname( $this->basename );
	}

	/**
	 * Podpina filtry sprawdzania i opisu aktualizacji.
	 */
	public static function register(): void {
		$self = new self();
		add_filter( 'pre_set_site_transient_update_plugins', array( $self, 'inject_update' ) );
		add_filter( 'plugins_api', array( $self, 'plugins_api' ), 10, 3 );
	}

	/**
	 * Wstrzykuje najnowsze wydanie do transienta aktualizacji wtyczek, jeśli jest nowsze.
	 *
	 * @param mixed $transient Transient `update_plugins` (obiekt) lub inna wartość.
	 * @return mixed Zmodyfikowany transient lub wartość wejściowa bez zmian.
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$latest = $this->fetch_latest();
		if ( null === $latest || '' === $latest['version'] || '' === $latest['package'] ) {
			return $transient;
		}
		if ( ! version_compare( $latest['version'], Plugin::version(), '>' ) ) {
			return $transient;
		}
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		$transient->response[ $this->basename ] = (object) array(
			'slug'        => $this->slug,
			'plugin'      => $this->basename,
			'new_version' => $latest['version'],
			'package'     => $latest['package'],
			'url'         => $latest['url'],
		);
		return $transient;
	}

	/**
	 * Dostarcza opis wydania dla okna „Szczegóły wtyczki".
	 *
	 * @param mixed  $result Domyślny wynik filtra.
	 * @param string $action Żądana akcja `plugins_api`.
	 * @param mixed  $args   Argumenty żądania (obiekt ze slugiem).
	 * @return mixed Obiekt informacji o wtyczce lub wartość wejściowa bez zmian.
	 */
	public function plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ( is_object( $args ) ? ( $args->slug ?? '' ) : '' ) !== $this->slug ) {
			return $result;
		}
		$latest = $this->fetch_latest();
		if ( null === $latest ) {
			return $result;
		}
		return (object) array(
			'name'          => 'Event Registration',
			'slug'          => $this->slug,
			'version'       => $latest['version'],
			'download_link' => $latest['package'],
			'sections'      => array( 'changelog' => wp_kses_post( wpautop( $latest['changelog'] ) ) ),
		);
	}

	/**
	 * Pobiera najnowsze wydanie z GitHuba, z buforowaniem transientem (pozytywnym i negatywnym).
	 *
	 * @return array{version:string,package:string,changelog:string,url:string}|null
	 */
	private function fetch_latest(): ?array {
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			return '' === $cached ? null : $cached;
		}
		$res = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'event-registration-wp',
				),
			)
		);
		if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
			set_transient( self::CACHE_KEY, '', HOUR_IN_SECONDS );
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $data ) ) {
			set_transient( self::CACHE_KEY, '', HOUR_IN_SECONDS );
			return null;
		}
		$parsed = array(
			'version'   => ltrim( (string) ( $data['tag_name'] ?? '' ), 'v' ),
			'package'   => self::pick_asset( is_array( $data['assets'] ?? null ) ? $data['assets'] : array() ),
			'changelog' => (string) ( $data['body'] ?? '' ),
			'url'       => (string) ( $data['html_url'] ?? '' ),
		);
		set_transient( self::CACHE_KEY, $parsed, self::CACHE_TTL );
		return $parsed;
	}

	/**
	 * Wybiera URL pobierania paczki wydania po nazwie assetu.
	 *
	 * @param array<int,mixed> $assets Lista assetów wydania GitHub.
	 */
	private static function pick_asset( array $assets ): string {
		foreach ( $assets as $asset ) {
			if ( is_array( $asset ) && self::ASSET_NAME === ( $asset['name'] ?? '' ) ) {
				return (string) ( $asset['browser_download_url'] ?? '' );
			}
		}
		return '';
	}
}
