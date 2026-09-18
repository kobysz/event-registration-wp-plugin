<?php
/**
 * Plugin Name:       Event Registration
 * Description:       Formularze rejestracji na wydarzenia z limitami miejsc, noclegami i potwierdzeniami mailowymi.
 * Version:           0.1.2
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Eorta.pl
 * Author URI:        https://eorta.pl
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       event-registration
 * Domain Path:       /languages
 * Update URI:        https://github.com/kobysz/event-registration-wp-plugin
 *
 * @package EvReg
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

\EvReg\Plugin::boot( __FILE__ );

add_action( 'plugins_loaded', array( \EvReg\I18n::class, 'register' ) );
add_action( 'plugins_loaded', array( \EvReg\Admin\EventPostType::class, 'register' ) );
add_action( 'plugins_loaded', array( \EvReg\Admin\Capabilities::class, 'register' ) );
add_action( 'plugins_loaded', array( \EvReg\Rest\EventConfigController::class, 'register' ) );
add_action( 'plugins_loaded', array( \EvReg\Rest\MailTemplateController::class, 'register' ) );
add_action( 'plugins_loaded', array( \EvReg\Rest\I18nController::class, 'register' ) );
add_action( 'plugins_loaded', array( \EvReg\Admin\EventConfigAssets::class, 'register' ) );
add_action( 'plugins_loaded', array( \EvReg\Frontend\SubmitHandler::class, 'register' ) );
add_action( 'plugins_loaded', array( \EvReg\Frontend\Shortcode::class, 'register' ) );
add_action( 'plugins_loaded', array( \EvReg\Frontend\Block::class, 'register' ) );
add_action( 'plugins_loaded', array( \EvReg\Frontend\ConfirmationController::class, 'register' ) );
add_action( 'plugins_loaded', array( \EvReg\Cron\ExpirePending::class, 'register' ) );
add_action( 'plugins_loaded', array( \EvReg\Cron\ExpirePending::class, 'schedule' ) );
add_action( 'plugins_loaded', array( \EvReg\Mail\Subscriber::class, 'register' ) );
add_action( 'plugins_loaded', array( \EvReg\Cron\DispatchMail::class, 'register' ) );
add_action( 'plugins_loaded', array( \EvReg\Cron\DispatchMail::class, 'schedule' ) );
add_action( 'plugins_loaded', array( \EvReg\Cron\PurgeMailQueue::class, 'register' ) );
add_action( 'plugins_loaded', array( \EvReg\Cron\PurgeMailQueue::class, 'schedule' ) );
add_action( 'plugins_loaded', array( \EvReg\Admin\MailQueueScreen::class, 'register' ) );
add_action( 'plugins_loaded', array( \EvReg\Admin\RegistrationsScreen::class, 'register' ) );
add_action( 'plugins_loaded', array( \EvReg\Admin\SettingsScreen::class, 'register' ) );
add_action( 'plugins_loaded', array( \EvReg\Privacy\PrivacyProvider::class, 'register' ) );
add_action( 'init', array( \EvReg\Update\GitHubUpdater::class, 'register' ) );

add_action(
	'init',
	static function (): void {
		$url = plugin_dir_url( \EvReg\Plugin::plugin_file() );
		wp_register_style( 'evreg-bootstrap', $url . 'assets/public/bootstrap.min.css', array(), '5.3.3' );
		wp_register_style( 'evreg-public', $url . 'assets/public/form.css', array(), \EvReg\Plugin::version() );
		wp_register_script( 'evreg-public', $url . 'assets/public/form.js', array(), \EvReg\Plugin::version(), true );
	}
);

register_activation_hook(
	__FILE__,
	static function (): void {
		\EvReg\Persistence\Migrations::install();
		\EvReg\Admin\Capabilities::grant();
		\EvReg\Cron\ExpirePending::register();
		\EvReg\Cron\ExpirePending::schedule();
		\EvReg\Cron\DispatchMail::register();
		\EvReg\Cron\DispatchMail::schedule();
		\EvReg\Cron\PurgeMailQueue::register();
		\EvReg\Cron\PurgeMailQueue::schedule();
	}
);
register_deactivation_hook(
	__FILE__,
	static function (): void {
		\EvReg\Cron\ExpirePending::unschedule();
		\EvReg\Cron\DispatchMail::unschedule();
		\EvReg\Cron\PurgeMailQueue::unschedule();
	}
);
add_action( 'plugins_loaded', array( \EvReg\Persistence\Migrations::class, 'maybe_upgrade' ) );
