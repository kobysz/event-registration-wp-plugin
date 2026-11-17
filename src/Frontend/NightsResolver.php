<?php
/**
 * Jednorazowe rozstrzygnięcie trybu „doby” dla schematu formularza.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Frontend;

use EvReg\Domain\Accommodation\NightsConfig;
use EvReg\Domain\Schema\FieldType;
use EvReg\Domain\Schema\FormSchema;
use EvReg\Domain\Schema\SchemaException;

defined( 'ABSPATH' ) || exit;

/**
 * Wejścia (Shortcode, SubmitHandler) wołają to RAZ, w try/catch, i wstrzykują wynik niżej.
 * Reszta ścieżki publicznej nigdy sama nie woła NightsConfig::fromArray, więc niepoprawna
 * konfiguracja (zduplikowany klucz doby, ujemna cena) zatrzymuje się u wejścia.
 */
final class NightsResolver {

	/**
	 * Tryb z surowej konfiguracji noclegu — niezależny od tego, czy schemat się złoży.
	 * Dla powierzchni bez schematu pod ręką albo takich, którym złożenie może się nie udać (mail z crona).
	 *
	 * @param array<string,mixed> $accommodation Surowa konfiguracja noclegu eventu.
	 */
	public static function isNightsRaw( array $accommodation ): bool {
		return 'nights' === ( $accommodation['mode'] ?? 'classic' );
	}

	/**
	 * Czy ZŁOŻONY SCHEMAT niesie pole noclegu w trybie „doby”.
	 *
	 * NIE DLA powierzchni odczytujących zapisane zgłoszenia (eksport, mail, Privacy, panel
	 * obłożenia, ścieżki transakcyjne) — one pytają `isNightsRaw()` o surową konfigurację.
	 * Tryb jest właściwością EVENTU, a nie schematu: organizator może usunąć pole noclegu
	 * z formularza, podczas gdy zgłoszenia dalej trzymają wiersze dób. Ten schemat nie ma
	 * wtedy śladu trybu i ta metoda zwraca false, więc czytana jako „to event klasyczny”
	 * gubi doby (CSV) albo zaniża eksport prywatności.
	 *
	 * Niepoprawna konfiguracja dób oznacza, że tryb JEST „doby” (klasyczny nigdy nie rzuca),
	 * więc degradujemy do true zamiast wywalać powierzchnię, która nie potrzebuje konfiguracji.
	 *
	 * @param FormSchema $schema Złożony schemat formularza.
	 */
	public static function isNightsMode( FormSchema $schema ): bool {
		try {
			return null !== self::forSchema( $schema );
		} catch ( SchemaException ) {
			return true;
		}
	}

	/**
	 * Zwraca konfigurację trybu „doby” albo null dla trybu klasycznego / braku pola noclegu.
	 * Null NIGDY nie znaczy „niepoprawna” — ta rzuca.
	 *
	 * @param FormSchema $schema Złożony schemat formularza.
	 *
	 * @throws SchemaException Gdy konfiguracja dób jest niepoprawna.
	 */
	public static function forSchema( FormSchema $schema ): ?NightsConfig {
		foreach ( $schema->allFields() as $field ) {
			if ( FieldType::Accommodation !== $field->type ) {
				continue;
			}

			$config = NightsConfig::fromArray( $field->config );

			return $config->isNights() ? $config : null;
		}

		return null;
	}
}
