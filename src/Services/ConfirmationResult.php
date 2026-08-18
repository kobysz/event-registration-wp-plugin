<?php
/**
 * Wynik próby potwierdzenia zgłoszenia.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Wynik próby potwierdzenia zgłoszenia.
 */
final class ConfirmationResult {

	/**
	 * Tworzy wynik potwierdzenia.
	 *
	 * @param string $code Kod wyniku: confirmed|already_confirmed|expired|waitlist|not_found.
	 */
	private function __construct( public readonly string $code ) {
	}

	/**
	 * Buduje wynik dla zgłoszenia potwierdzonego (status zmieniony z pending).
	 */
	public static function confirmed(): self {
		return new self( 'confirmed' );
	}

	/**
	 * Buduje wynik dla zgłoszenia już wcześniej potwierdzonego.
	 */
	public static function alreadyConfirmed(): self {
		return new self( 'already_confirmed' );
	}

	/**
	 * Buduje wynik dla zgłoszenia wygasłego (anulowanego po przekroczeniu terminu).
	 */
	public static function expired(): self {
		return new self( 'expired' );
	}

	/**
	 * Buduje wynik dla zgłoszenia na liście rezerwowej.
	 */
	public static function onWaitlist(): self {
		return new self( 'waitlist' );
	}

	/**
	 * Buduje wynik dla tokenu nieodnalezionego w bazie.
	 */
	public static function notFound(): self {
		return new self( 'not_found' );
	}
}
