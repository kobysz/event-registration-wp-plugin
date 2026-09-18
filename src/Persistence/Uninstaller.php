<?php
/**
 * Czyszczenie stanu wtyczki przy odinstalowaniu — za bramką.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Persistence;

use EvReg\Admin\Capabilities;
use EvReg\Admin\EventPostType;
use EvReg\Cron\ExpirePending;
use EvReg\Cron\DispatchMail;
use EvReg\Cron\PurgeMailQueue;
use EvReg\Mail\MailQueue;

defined( 'ABSPATH' ) || exit;

/**
 * Czyszczenie stanu wtyczki przy odinstalowaniu — za bramką.
 */
final class Uninstaller {

	/** Meta CPT do skasowania. */
	private const META_KEYS = array( '_evreg_schema', '_evreg_types', '_evreg_accommodation', '_evreg_settings', '_evreg_mail_templates' );

	/** Tabele wtyczki (bez prefiksu). */
	private const TABLES = array( 'registrations', 'accommodation_bookings', 'mail_queue', 'locks' );

	/** Opcja-bramka. */
	public const DELETE_OPTION = 'evreg_delete_data_on_uninstall';

	/**
	 * Czyści cały stan wtyczki — TYLKO gdy bramka włączona. Wywoływane z uninstall.php.
	 */
	public static function run(): void {
		if ( ! self::shouldDeleteData() ) {
			return;
		}

		global $wpdb;

		foreach ( self::TABLES as $name ) {
			$table = Migrations::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		}

		delete_option( Migrations::VERSION_OPTION );
		delete_option( self::DELETE_OPTION );
		delete_option( \EvReg\Admin\SettingsScreen::LOAD_BOOTSTRAP_OPTION );

		self::deleteEvents();

		foreach ( array( ExpirePending::HOOK, DispatchMail::HOOK, MailQueue::DISPATCH_HOOK, PurgeMailQueue::HOOK ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		Capabilities::remove(); // Zdejmuje capy + kasuje evreg_caps_version.

		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_evreg\\_rate\\_%' OR option_name LIKE '\\_transient\\_timeout\\_evreg\\_rate\\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Ustala, czy bramka kasowania danych jest włączona (stała lub opcja).
	 */
	private static function shouldDeleteData(): bool {
		if ( defined( 'EVREG_DELETE_DATA_ON_UNINSTALL' ) && EVREG_DELETE_DATA_ON_UNINSTALL ) {
			return true;
		}

		return (bool) get_option( self::DELETE_OPTION, false );
	}

	/**
	 * Kasuje wszystkie eventy CPT wraz z ich meta.
	 */
	private static function deleteEvents(): void {
		do {
			$ids = get_posts(
				array(
					'post_type'      => EventPostType::POST_TYPE,
					'post_status'    => 'any',
					'fields'         => 'ids',
					'posts_per_page' => 100,
					'no_found_rows'  => true,
				)
			);

			foreach ( $ids as $id ) {
				foreach ( self::META_KEYS as $meta_key ) {
					delete_post_meta( (int) $id, $meta_key );
				}
				wp_delete_post( (int) $id, true );
			}
		} while ( array() !== $ids );
	}
}
