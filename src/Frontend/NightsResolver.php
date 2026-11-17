<?php
/**
 * Jednorazowe rozstrzygnięcie trybu „doby” z konfiguracji noclegu eventu.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Frontend;

use EvReg\Domain\Accommodation\NightsConfig;
use EvReg\Domain\Schema\SchemaException;

defined( 'ABSPATH' ) || exit;

/**
 * JEDYNY rozstrzygacz trybu noclegu — i jedyna droga do niego: klucz `mode` konfiguracji
 * noclegu EVENTU. Tryb jest właściwością eventu, a nie schematu: organizator może usunąć
 * pole noclegu z formularza, podczas gdy zgłoszenia dalej trzymają wiersze dób. Schemat nie
 * ma wtedy ŻADNEGO śladu trybu, więc pytany o niego odpowiadał „klasyczny” i powierzchnie
 * czytające zapisane zgłoszenia gubiły doby (to był B19). Dlatego oba wejścia niżej czytają
 * ten sam klucz tej samej tablicy i NIE MOGĄ się rozejść.
 *
 * Konfigurację pod ręką daje {@see EventFormLoader::nights()} (z nałożonym overlayem
 * językowym) — tamtędy chodzą powierzchnie, którym potrzebny jest obiekt `NightsConfig`.
 * Wejścia (Shortcode, SubmitHandler, panel zgłoszeń) wołają to RAZ, w try/catch,
 * i wstrzykują wynik niżej. Reszta ścieżki nigdy sama nie woła NightsConfig::fromArray,
 * więc niepoprawna konfiguracja (zduplikowany klucz doby, ujemna cena) zatrzymuje się
 * u wejścia.
 */
final class NightsResolver {

	/**
	 * Czy event jest w trybie „doby”.
	 *
	 * Czyta WYŁĄCZNIE `mode`, więc nie rzuca nigdy i nie potrzebuje ani schematu (ten może
	 * się nie złożyć), ani overlayu (ten tłumaczy tylko etykiety). Dla powierzchni, którym
	 * wystarczy sam tryb i które nie mogą paść na błędzie konfiguracji: mail z crona,
	 * eksport CSV, WP Privacy.
	 *
	 * @param array<string,mixed> $accommodation Konfiguracja noclegu eventu.
	 */
	public static function isNights( array $accommodation ): bool {
		return 'nights' === ( $accommodation['mode'] ?? 'classic' );
	}

	/**
	 * Zwraca konfigurację trybu „doby” albo null dla trybu klasycznego.
	 * Null NIGDY nie znaczy „niepoprawna” — ta rzuca.
	 *
	 * Poza trybem „doby” nic nie czyta listy dób (i odwrotnie: w trybie „doby” nic nie
	 * buduje AccommodationConfig — ta rzuca na zapomnianym inwentarzu klasycznym).
	 *
	 * @param array<string,mixed> $accommodation Konfiguracja noclegu eventu.
	 *
	 * @throws SchemaException Gdy konfiguracja dób jest niepoprawna.
	 */
	public static function forAccommodation( array $accommodation ): ?NightsConfig {
		if ( ! self::isNights( $accommodation ) ) {
			return null;
		}

		return NightsConfig::fromArray( $accommodation );
	}
}
