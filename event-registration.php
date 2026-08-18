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

add_action( 'plugins_loaded', array( \EvReg\Admin\EventPostType::class, 'register' ) );
add_action( 'plugins_loaded', array( \EvReg\Admin\Capabilities::class, 'register' ) );
add_action( 'plugins_loaded', array( \EvReg\Rest\EventConfigController::class, 'register' ) );
add_action( 'plugins_loaded', array( \EvReg\Admin\EventConfigAssets::class, 'register' ) );
add_action( 'plugins_loaded', array( \EvReg\Cron\ExpirePending::class, 'register' ) );
add_action( 'plugins_loaded', array( \EvReg\Cron\ExpirePending::class, 'schedule' ) );

register_activation_hook(
	__FILE__,
	static function (): void {
		\EvReg\Persistence\Migrations::install();
		\EvReg\Admin\Capabilities::grant();
		\EvReg\Cron\ExpirePending::register();
		\EvReg\Cron\ExpirePending::schedule();
	}
);
register_deactivation_hook( __FILE__, array( \EvReg\Cron\ExpirePending::class, 'unschedule' ) );
add_action( 'plugins_loaded', array( \EvReg\Persistence\Migrations::class, 'maybe_upgrade' ) );
