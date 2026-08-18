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
	 * Nadaje capability roli administrator. Wywoływane przy aktywacji wtyczki.
	 */
	public static function grant(): void {
		$role = get_role( 'administrator' );

		if ( null !== $role && ! $role->has_cap( self::CAP ) ) {
			$role->add_cap( self::CAP );
		}
	}

	/**
	 * Miejsce na hooki runtime związane z capability. Obecnie brak.
	 */
	public static function register(): void {
	}
}
