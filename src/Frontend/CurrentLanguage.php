<?php
/**
 * Bieżący język treści (Polylang lub filtr).
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Zwraca slug bieżącego języka: Polylang, z możliwością nadpisania filtrem.
 */
final class CurrentLanguage {

	/**
	 * Slug bieżącego języka; '' gdy brak (→ treść bazowa).
	 */
	public static function get(): string {
		$lang = function_exists( 'pll_current_language' ) ? (string) pll_current_language( 'slug' ) : '';
		return (string) apply_filters( 'evreg_current_language', $lang );
	}
}
