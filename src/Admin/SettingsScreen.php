<?php
/**
 * Ekran ustawień wtyczki — bramka kasowania danych przy odinstalowaniu.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Admin;

use EvReg\Persistence\Uninstaller;

defined( 'ABSPATH' ) || exit;

/**
 * Ekran ustawień wtyczki — bramka kasowania danych przy odinstalowaniu.
 */
final class SettingsScreen {

	private const SLUG         = 'evreg-settings';
	private const OPTION_GROUP = 'evreg_settings';

	/** Opcja: czy wtyczka ma ładować Bootstrap 5 CSS na stronie formularza. */
	public const LOAD_BOOTSTRAP_OPTION = 'evreg_load_bootstrap';

	/** Podpina menu i rejestrację ustawienia. */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_init', array( self::class, 'register_setting' ) );
	}

	/** Dodaje pozycję menu pod CPT eventu. */
	public static function add_menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . EventPostType::POST_TYPE,
			__( 'Ustawienia', 'event-registration' ),
			__( 'Ustawienia', 'event-registration' ),
			Capabilities::CAP,
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/** Rejestruje ustawienie i pole formularza w Settings API. */
	public static function register_setting(): void {
		register_setting(
			self::OPTION_GROUP,
			Uninstaller::DELETE_OPTION,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => false,
			)
		);
		register_setting(
			self::OPTION_GROUP,
			self::LOAD_BOOTSTRAP_OPTION,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => false,
			)
		);
		add_settings_section( 'evreg_settings_main', '', '__return_null', self::SLUG );
		add_settings_field(
			Uninstaller::DELETE_OPTION,
			__( 'Usuwanie danych', 'event-registration' ),
			array( self::class, 'render_field' ),
			self::SLUG,
			'evreg_settings_main'
		);
		add_settings_field(
			self::LOAD_BOOTSTRAP_OPTION,
			__( 'Bootstrap 5', 'event-registration' ),
			array( self::class, 'render_bootstrap_field' ),
			self::SLUG,
			'evreg_settings_main'
		);
	}

	/** Renderuje checkbox ładowania Bootstrap 5 na froncie. */
	public static function render_bootstrap_field(): void {
		printf(
			'<label><input type="checkbox" name="%s" value="1" %s /> %s</label><p class="description">%s</p>',
			esc_attr( self::LOAD_BOOTSTRAP_OPTION ),
			checked( (bool) get_option( self::LOAD_BOOTSTRAP_OPTION, false ), true, false ),
			esc_html__( 'Załaduj Bootstrap 5 CSS na stronie z formularzem.', 'event-registration' ),
			esc_html__( 'Włącz tylko jeśli motyw nie ładuje już Bootstrapa 5 — styluje całą stronę.', 'event-registration' )
		);
	}

	/**
	 * Normalizuje wartość checkboxa do 0/1.
	 *
	 * @param mixed $value Surowa wartość z formularza.
	 */
	public static function sanitize( $value ): int {
		return ( '' === $value || null === $value || '0' === $value || false === $value ) ? 0 : 1;
	}

	/** Renderuje checkbox bramki, uwzględniając wymuszenie przez stałą. */
	public static function render_field(): void {
		$forced  = defined( 'EVREG_DELETE_DATA_ON_UNINSTALL' ) && EVREG_DELETE_DATA_ON_UNINSTALL;
		$checked = $forced || (bool) get_option( Uninstaller::DELETE_OPTION, false );
		printf(
			'<label><input type="checkbox" name="%s" value="1" %s %s /> %s</label>',
			esc_attr( Uninstaller::DELETE_OPTION ),
			checked( $checked, true, false ),
			disabled( $forced, true, false ),
			esc_html__( 'Usuń wszystkie dane wtyczki (zgłoszenia, ustawienia) przy odinstalowaniu. Nieodwracalne.', 'event-registration' )
		);
		if ( $forced ) {
			echo '<p class="description">' . esc_html__( 'Wymuszone stałą EVREG_DELETE_DATA_ON_UNINSTALL w wp-config.', 'event-registration' ) . '</p>';
		}
	}

	/** Renderuje formularz ekranu ustawień. */
	public static function render(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Event Registration — Ustawienia', 'event-registration' ) . '</h1>';
		echo '<form action="options.php" method="post">';
		settings_fields( self::OPTION_GROUP );
		do_settings_sections( self::SLUG );
		submit_button();
		echo '</form></div>';
	}
}
