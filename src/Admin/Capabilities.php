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
	 * Miejsce na hooki runtime związane z capability. Obecnie brak.
	 */
	public static function register(): void {
	}
}
