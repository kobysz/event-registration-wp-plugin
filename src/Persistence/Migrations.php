<?php
/**
 * Tworzenie i wersjonowanie tabel wtyczki.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Persistence;

defined( 'ABSPATH' ) || exit;

/**
 * Tworzenie i wersjonowanie tabel wtyczki.
 */
final class Migrations {

	public const DB_VERSION = 6;

	public const VERSION_OPTION = 'evreg_db_version';

	private const TABLE_PREFIX = 'evreg_';

	/**
	 * Zwraca pełną, prefiksowaną nazwę tabeli wtyczki.
	 *
	 * @param string $name Nazwa tabeli bez prefiksu.
	 */
	public static function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_PREFIX . $name;
	}

	/**
	 * Tworzy tabele wtyczki i zapisuje aktualną wersję schematu.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		foreach ( self::statements( $charset ) as $sql ) {
			dbDelta( $sql );
		}

		update_option( self::VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Uruchamia instalację, gdy zapisana wersja schematu jest nieaktualna.
	 */
	public static function maybe_upgrade(): void {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) === self::DB_VERSION ) {
			return;
		}

		self::install();
	}

	/**
	 * Zwraca instrukcje SQL tworzące tabele wtyczki.
	 *
	 * @param string $charset Klauzula znaków/kolacji dla silnika bazy danych.
	 *
	 * @return string[]
	 */
	private static function statements( string $charset ): array {
		$registrations = self::table( 'registrations' );
		$bookings      = self::table( 'accommodation_bookings' );
		$mail_queue    = self::table( 'mail_queue' );
		$locks         = self::table( 'locks' );

		return array(
			"CREATE TABLE {$registrations} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL,
				type_key varchar(64) NOT NULL,
				status varchar(20) NOT NULL,
				email varchar(191) NOT NULL,
				name varchar(191) NOT NULL,
				companion tinyint(1) NOT NULL DEFAULT 0,
				companion_name varchar(191) NOT NULL DEFAULT '',
				lang varchar(12) NOT NULL DEFAULT '',
				token char(32) NOT NULL,
				data longtext NOT NULL,
				price_total decimal(10,2) NOT NULL DEFAULT 0,
				note text NULL,
				created_at datetime NOT NULL,
				expires_at datetime NULL,
				confirmed_at datetime NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY idx_event_email (event_id, email),
				KEY idx_event_status (event_id, status),
				KEY idx_token (token),
				KEY idx_expiry (status, expires_at)
			) ENGINE=InnoDB {$charset};",

			"CREATE TABLE {$bookings} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				registration_id bigint(20) unsigned NOT NULL,
				package_key varchar(64) NOT NULL,
				room_type_key varchar(64) NOT NULL,
				package_label varchar(191) NOT NULL DEFAULT '',
				room_label varchar(191) NOT NULL DEFAULT '',
				roommate_pref varchar(191) NULL,
				seats tinyint unsigned NOT NULL DEFAULT 1,
				price decimal(10,2) NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY idx_registration (registration_id),
				KEY idx_inventory (package_key, room_type_key)
			) ENGINE=InnoDB {$charset};",

			"CREATE TABLE {$mail_queue} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				registration_id bigint(20) unsigned NULL,
				event_id bigint(20) unsigned NOT NULL,
				template_key varchar(64) NOT NULL,
				recipient varchar(191) NOT NULL,
				subject text NOT NULL,
				body longtext NOT NULL,
				headers text NULL,
				status varchar(20) NOT NULL,
				attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
				last_error text NULL,
				scheduled_at datetime NOT NULL,
				sent_at datetime NULL,
				PRIMARY KEY  (id),
				KEY idx_dispatch (status, scheduled_at),
				UNIQUE KEY uniq_registration_template (registration_id,template_key)
			) ENGINE=InnoDB {$charset};",

			"CREATE TABLE {$locks} (
				event_id bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (event_id)
			) ENGINE=InnoDB {$charset};",
		);
	}
}
