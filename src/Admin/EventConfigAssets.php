<?php
/**
 * Ładowanie aplikacji React na ekranie edycji wydarzenia.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Admin;

use EvReg\Plugin;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Ładowanie aplikacji React na ekranie edycji wydarzenia.
 */
final class EventConfigAssets {

	public const HANDLE = 'evreg-admin';

	/**
	 * Podpina enqueue i wypisanie kontenera montowania.
	 */
	public static function register(): void {
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'edit_form_after_title', array( self::class, 'render_root' ) );
	}

	/**
	 * Enqueue skryptu i stylu tylko na ekranie edycji evreg_event.
	 *
	 * @param string $hook_suffix Aktualny ekran admina.
	 */
	public static function enqueue( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( null === $screen || EventPostType::POST_TYPE !== $screen->post_type ) {
			return;
		}

		$dir      = plugin_dir_path( Plugin::plugin_file() );
		$url      = plugin_dir_url( Plugin::plugin_file() );
		$asset    = $dir . 'build/admin/index.asset.php';
		$manifest = file_exists( $asset ) ? require $asset : array(
			'dependencies' => array(),
			'version'      => Plugin::version(),
		);

		wp_enqueue_script(
			self::HANDLE,
			$url . 'build/admin/index.js',
			$manifest['dependencies'],
			$manifest['version'],
			true
		);

		wp_enqueue_style( 'wp-components' );

		wp_enqueue_style(
			self::HANDLE,
			$url . 'build/admin/style-index.css',
			array( 'wp-components' ),
			$manifest['version']
		);
		wp_style_add_data( self::HANDLE, 'rtl', 'replace' );

		$event_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- odczyt ID ekranu, nie akcja.

		$pages = array_map(
			static function ( WP_Post $page ): array {
				return array(
					'value' => $page->ID,
					'label' => $page->post_title,
				);
			},
			get_posts(
				array(
					'post_type'        => 'page',
					'post_status'      => 'publish',
					'numberposts'      => 200,
					'orderby'          => 'title',
					'order'            => 'ASC',
					'suppress_filters' => false,
				)
			)
		);

		wp_localize_script(
			self::HANDLE,
			'evregAdmin',
			array(
				'eventId'         => $event_id,
				'pages'           => $pages,
				'languages'       => self::translationLanguages(),
				'defaultLanguage' => function_exists( 'pll_default_language' ) ? (string) pll_default_language( 'slug' ) : '',
			)
		);
	}

	/**
	 * Wypisuje kontener montowania pod tytułem, tylko dla evreg_event.
	 *
	 * @param WP_Post $post Edytowany wpis.
	 */
	public static function render_root( WP_Post $post ): void {
		if ( EventPostType::POST_TYPE !== $post->post_type ) {
			return;
		}

		echo '<div id="evreg-admin-root"></div>';
	}

	/**
	 * Języki Polylang bez domyślnego, do UI tłumaczeń. Puste bez Polylang.
	 *
	 * @return array<int,array{value:string,label:string}>
	 */
	private static function translationLanguages(): array {
		if ( ! function_exists( 'pll_languages_list' ) || ! function_exists( 'pll_default_language' ) ) {
			return array();
		}
		$default = (string) pll_default_language( 'slug' );
		$slugs   = (array) pll_languages_list( array( 'fields' => 'slug' ) );
		$names   = (array) pll_languages_list( array( 'fields' => 'name' ) );
		$out     = array();
		foreach ( $slugs as $i => $slug ) {
			if ( (string) $slug === $default ) {
				continue;
			}
			$out[] = array(
				'value' => (string) $slug,
				'label' => (string) ( $names[ $i ] ?? $slug ),
			);
		}
		return $out;
	}
}
