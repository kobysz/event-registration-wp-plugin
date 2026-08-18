<?php
/**
 * Capability bramkująca dostęp do konfiguracji eventów.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Capability bramkująca dostęp do konfiguracji eventów.
 */
final class Capabilities {

	public const CAP = 'edit_evreg_events';

	/**
	 * Wersja zestawu capabilities. Podbij przy zmianie CAPS, by wymusić samonaprawę.
	 */
	public const CAP_VERSION = 1;

	/**
	 * Nazwa opcji przechowującej ostatnio nadaną wersję capabilities.
	 */
	private const VERSION_OPTION = 'evreg_caps_version';

	/**
	 * Pełny zestaw prymitywnych capabilities CPT evreg_event.
	 *
	 * @var string[]
	 */
	private const CAPS = array(
		'edit_evreg_events',
		'edit_others_evreg_events',
		'edit_private_evreg_events',
		'edit_published_evreg_events',
		'publish_evreg_events',
		'read_private_evreg_events',
		'delete_evreg_events',
		'delete_others_evreg_events',
		'delete_private_evreg_events',
		'delete_published_evreg_events',
	);

	/**
	 * Nadaje pełny zestaw capabilities roli administrator. Wywoływane przy aktywacji wtyczki.
	 */
	public static function grant(): void {
		$role = get_role( 'administrator' );

		if ( null === $role ) {
			return;
		}

		foreach ( self::CAPS as $cap ) {
			if ( ! $role->has_cap( $cap ) ) {
				$role->add_cap( $cap );
			}
		}
	}

	/**
	 * Podpina samonaprawę capabilities do zwykłego żądania panelu admina.
	 */
	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'maybe_grant' ) );
	}

	/**
	 * Nadaje capabilities, jeśli zapisana wersja jest nieaktualna (samonaprawa
	 * dla instalacji zaktualizowanych bez ponownej aktywacji wtyczki).
	 */
	public static function maybe_grant(): void {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) === self::CAP_VERSION ) {
			return;
		}

		self::grant();
		update_option( self::VERSION_OPTION, self::CAP_VERSION );
	}
}
