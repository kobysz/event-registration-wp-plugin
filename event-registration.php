<?php
/**
 * Plugin Name:       Event Registration
 * Description:       Formularze rejestracji na wydarzenia z limitami miejsc, noclegami i potwierdzeniami mailowymi.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Kuba
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       event-registration
 *
 * @package EvReg
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

\EvReg\Plugin::boot( __FILE__ );
