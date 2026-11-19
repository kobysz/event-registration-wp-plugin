<?php
/**
 * Domyślne treści komunikatów o pełnym wydarzeniu.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Domyślne treści komunikatów o pełnym wydarzeniu.
 *
 * JEDYNY dom tych tekstów. Czytają je dwie powierzchnie: `Shortcode::fullNotice`
 * (gdy organizator nie wpisał własnego) oraz `EventConfigAssets`, które podaje je
 * aplikacji React jako placeholder w ustawieniach i bazę wiersza tłumaczenia.
 * Kopia w JS była przez chwilę drugim domem (backlog B41) — zmiana tylko jednej
 * strony zamieniłaby placeholder w panelu w kłamstwo o tym, co zobaczy odwiedzający.
 * Ten sam wzór co `Mail\DefaultTemplates`, które serwuje swoje domyślne treści
 * przez REST, zamiast pozwalać panelowi je powtarzać.
 */
final class DefaultNotices {

	public const KEY_FULL_WAITLIST = 'full_waitlist';
	public const KEY_FULL_CLOSED   = 'full_closed';

	/**
	 * Zwraca listę obsługiwanych kluczy komunikatów.
	 *
	 * @return array<int,string>
	 */
	public static function keys(): array {
		return array( self::KEY_FULL_WAITLIST, self::KEY_FULL_CLOSED );
	}

	/**
	 * Zwraca klucz ustawienia eventu trzymający własny tekst organizatora.
	 *
	 * @param string $key Klucz komunikatu.
	 */
	public static function settingsKey( string $key ): string {
		return $key . '_notice';
	}

	/**
	 * Zwraca domyślny tekst komunikatu. Nieznany klucz daje pusty string.
	 *
	 * @param string $key Klucz komunikatu.
	 */
	public static function get( string $key ): string {
		return self::all()[ $key ] ?? '';
	}

	/**
	 * Zwraca wszystkie domyślne teksty jako mapę klucz → tekst.
	 *
	 * @return array<string,string>
	 */
	public static function all(): array {
		return array(
			self::KEY_FULL_WAITLIST => __( 'Wszystkie miejsca są zajęte. Możesz się zapisać — trafisz na listę rezerwową i damy znać, gdy zwolni się miejsce.', 'event-registration' ),
			self::KEY_FULL_CLOSED   => __( 'Rejestracja zamknięta — brak wolnych miejsc.', 'event-registration' ),
		);
	}
}
