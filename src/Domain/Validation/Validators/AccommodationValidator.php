<?php
/**
 * Walidator pola wyboru zakwaterowania.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Validation\Validators;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Domain\Accommodation\NightSelection;
use EvReg\Domain\Accommodation\NightsConfig;
use EvReg\Domain\Schema\Field;
use EvReg\Domain\Validation\FieldOutcome;
use EvReg\Domain\Validation\FieldValidator;

/**
 * Waliduje wybór pakietu, pokoju i preferencji współlokatora.
 */
final class AccommodationValidator implements FieldValidator {

	public const MAX_ROOMMATE_LENGTH = 191;

	/**
	 * Waliduje surowy wybór zakwaterowania.
	 *
	 * @param Field $field Definicja pola.
	 * @param mixed $raw   Surowa wartość odpowiedzi.
	 */
	public function validate( Field $field, mixed $raw ): FieldOutcome {
		// Jedno rozgałęzienie po trybie, u wejścia: tryb „doby” NIE buduje AccommodationConfig
		// (rzuca na niespójnym inwentarzu, a stare dane klasyczne mogą zostać w konfiguracji).
		$nights = NightsConfig::fromArray( $field->config );
		if ( $nights->isNights() ) {
			return $this->validateNights( $field, $raw, $nights );
		}

		$config    = AccommodationConfig::fromArray( $field->config );
		$selection = AccommodationSelection::fromArray( is_array( $raw ) ? $raw : array() );

		if ( null === $selection ) {
			if ( ! $config->allowsNone() || $field->required ) {
				return FieldOutcome::error( 'required' );
			}

			return FieldOutcome::valid( null );
		}

		if ( null === $config->item( $selection->packageKey, $selection->roomKey ) ) {
			return FieldOutcome::error( 'invalid_accommodation' );
		}

		$room = $config->room( $selection->roomKey );

		if ( '' !== $selection->roommatePref && ( null === $room || ! $room->roommateField ) ) {
			return FieldOutcome::error( 'roommate_not_allowed' );
		}

		if ( mb_strlen( $selection->roommatePref ) > self::MAX_ROOMMATE_LENGTH ) {
			return FieldOutcome::error( 'too_long' );
		}

		return FieldOutcome::valid( $selection );
	}

	/**
	 * Waliduje wybór dób. Wartością poprawnego wyniku jest PŁASKA LISTA kluczy dób
	 * (znormalizowana: bez nieznanych i duplikatów, w kolejności konfiguracji).
	 *
	 * @param Field        $field  Definicja pola.
	 * @param mixed        $raw    Surowa wartość odpowiedzi (`['nights' => [klucze]]`).
	 * @param NightsConfig $config Konfiguracja trybu „doby”.
	 */
	private function validateNights( Field $field, mixed $raw, NightsConfig $config ): FieldOutcome {
		$keys      = is_array( $raw ) && is_array( $raw['nights'] ?? null ) ? $raw['nights'] : array();
		$selection = NightSelection::fromKeys( $keys, $config );

		// Zaznaczenie przyjmujemy W CAŁOŚCI albo nie przyjmujemy wcale (anty-cel nr 1
		// specyfikacji). Normalizacja odfiltrowuje doby nieznane konfiguracji, więc gdy
		// znormalizowany zbiór jest KRÓTSZY od podanego, przynajmniej jedna doba wypadła
		// i przyjęcie resztki byłoby cichym daniem komuś mniej, niż poprosił.
		//
		// Punktem odniesienia są podane klucze UNIKALNE i skalarne, dokładnie te, które
		// widzi NightSelection::fromKeys. Powtórzony klucz nie jest życzeniem, które
		// gubimy — to ta sama doba zaznaczona dwa razy, więc deduplikacja nic nie zabiera
		// i nie może być błędem (porządek i unikalność pilnuje osobny test).
		$submitted = array_unique( array_map( 'strval', array_filter( $keys, 'is_scalar' ) ) );

		if ( count( $submitted ) !== count( $selection->keys() ) ) {
			return FieldOutcome::error( 'invalid_accommodation' );
		}

		if ( $selection->isEmpty() ) {
			// Podane klucze, z których żaden nie istnieje w konfiguracji, to zły wybór,
			// a nie „bez noclegu” — inaczej literówka po cichu zamieniałaby się w rezygnację.
			if ( array() !== $keys ) {
				return FieldOutcome::error( 'invalid_accommodation' );
			}

			if ( ! $config->allowsNone() || $field->required ) {
				return FieldOutcome::error( 'required' );
			}
		}

		return FieldOutcome::valid( $selection->keys() );
	}
}
