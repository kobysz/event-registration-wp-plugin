<?php
/**
 * Domyślne treści maili transakcyjnych.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Domyślne treści maili transakcyjnych.
 *
 * Używane, gdy event nie ma własnego szablonu danego typu. Plain text —
 * link potwierdzenia stoi w osobnej linii jako goły URL.
 */
final class DefaultTemplates {

	public const KEY_OPTIN     = 'optin';
	public const KEY_CONFIRMED = 'confirmed';
	public const KEY_WAITLIST  = 'waitlist';
	public const KEY_EXPIRED   = 'expired';
	public const KEY_ADMIN_NEW = 'admin_new';

	/**
	 * Zwraca listę obsługiwanych kluczy szablonów.
	 *
	 * @return array<int,string>
	 */
	public static function keys(): array {
		return array(
			self::KEY_OPTIN,
			self::KEY_CONFIRMED,
			self::KEY_WAITLIST,
			self::KEY_EXPIRED,
			self::KEY_ADMIN_NEW,
		);
	}

	/**
	 * Zwraca domyślny szablon o podanym kluczu. Nieznany klucz daje puste pola.
	 *
	 * @param string $key Klucz szablonu.
	 *
	 * @return array{subject: string, body: string}
	 */
	public static function get( string $key ): array {
		$templates = array(
			self::KEY_OPTIN     => array(
				'subject' => __( 'Potwierdź zgłoszenie na {event}', 'event-registration' ),
				'body'    => __(
					'Cześć {imie},

dziękujemy za zgłoszenie na {event} ({typ}).

Potwierdź je, otwierając ten link:
{link_potwierdzenia}

Bez potwierdzenia rezerwacja wygaśnie po 48 godzinach, a miejsce wróci do puli.

Twoje zgłoszenie:
{podsumowanie}',
					'event-registration'
				),
			),
			self::KEY_CONFIRMED => array(
				'subject' => __( 'Zgłoszenie na {event} potwierdzone', 'event-registration' ),
				'body'    => __(
					'Cześć {imie},

Twoje zgłoszenie na {event} ({typ}) jest potwierdzone. Do zobaczenia.

Twoje zgłoszenie:
{podsumowanie}',
					'event-registration'
				),
			),
			self::KEY_WAITLIST  => array(
				'subject' => __( 'Lista rezerwowa — {event}', 'event-registration' ),
				'body'    => __(
					'Cześć {imie},

komplet miejsc na {event} ({typ}) został wyczerpany, więc Twoje zgłoszenie trafiło na listę rezerwową.

Odezwiemy się, gdy zwolni się miejsce.

Twoje zgłoszenie:
{podsumowanie}',
					'event-registration'
				),
			),
			self::KEY_EXPIRED   => array(
				'subject' => __( 'Rezerwacja na {event} wygasła', 'event-registration' ),
				'body'    => __(
					'Cześć {imie},

Twoja rezerwacja na {event} wygasła, bo zgłoszenie nie zostało potwierdzone w ciągu 48 godzin. Miejsce wróciło do puli.

Jeśli nadal chcesz wziąć udział, wypełnij formularz ponownie.',
					'event-registration'
				),
			),
			self::KEY_ADMIN_NEW => array(
				'subject' => __( 'Nowe zgłoszenie: {event}', 'event-registration' ),
				'body'    => __(
					'Nowe zgłoszenie na {event}.

Osoba: {imie} ({email})
Typ: {typ}
Nocleg: {nocleg}

Odpowiedzi:
{podsumowanie}',
					'event-registration'
				),
			),
		);

		return $templates[ $key ] ?? array(
			'subject' => '',
			'body'    => '',
		);
	}
}
