<?php
/**
 * Konfiguracja zakwaterowania w trybie „doby”.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Accommodation;

use EvReg\Domain\Schema\SchemaException;

/**
 * Tryb „doby”: doba jest jedynym wymiarem pojemności. Celowo oddzielna od
 * AccommodationConfig — NIE czyta pakietów, pokoi ani inwentarza (tamta rzuca
 * na niespójnym inwentarzu), tylko tryb, listę dób i wspólne flagi.
 */
final class NightsConfig {

	/**
	 * Tworzy konfigurację z gotowych danych.
	 *
	 * @param bool    $nightsMode           Czy aktywny jest tryb „doby”.
	 * @param Night[] $nights               Doby (puste poza trybem „doby”).
	 * @param bool    $allowNone            Czy można zrezygnować z noclegu.
	 * @param bool    $companionEnabled     Czy zgłoszenie może mieć osobę towarzyszącą.
	 * @param bool    $companionCountsEvent Czy osoba towarzysząca wlicza się do limitu wydarzenia.
	 */
	private function __construct(
		private readonly bool $nightsMode,
		private readonly array $nights,
		private readonly bool $allowNone,
		private readonly bool $companionEnabled,
		private readonly bool $companionCountsEvent
	) {
	}

	/**
	 * Tworzy konfigurację z tablicy danych.
	 *
	 * @param array<string,mixed> $config Surowe dane konfiguracji zakwaterowania.
	 *
	 * @throws SchemaException Gdy doba jest niepoprawna lub klucz doby się powtarza.
	 */
	public static function fromArray( array $config ): self {
		$nights_mode = 'nights' === ( $config['mode'] ?? 'classic' );
		$nights      = array();

		if ( $nights_mode ) {
			$seen = array();

			foreach ( (array) ( $config['nights'] ?? array() ) as $entry ) {
				$night = Night::fromArray( (array) $entry );

				if ( in_array( $night->key, $seen, true ) ) {
					throw new SchemaException( sprintf( 'Zduplikowana doba "%s".', $night->key ) );
				}

				$seen[]   = $night->key;
				$nights[] = $night;
			}
		}

		return new self(
			$nights_mode,
			$nights,
			(bool) ( $config['allow_none'] ?? true ),
			(bool) ( $config['companion_enabled'] ?? false ),
			(bool) ( $config['companion_counts_event'] ?? false )
		);
	}

	/**
	 * Czy aktywny jest tryb „doby”.
	 */
	public function isNights(): bool {
		return $this->nightsMode;
	}

	/**
	 * Zwraca doby (puste poza trybem „doby”).
	 *
	 * @return Night[]
	 */
	public function nights(): array {
		return $this->nights;
	}

	/**
	 * Zwraca dobę o danym kluczu albo null.
	 *
	 * @param string $key Klucz doby.
	 */
	public function night( string $key ): ?Night {
		foreach ( $this->nights as $night ) {
			if ( $night->key === $key ) {
				return $night;
			}
		}

		return null;
	}

	/**
	 * Zwraca etykietę doby albo surowy klucz, gdy doba zniknęła z konfiguracji.
	 *
	 * @param string $key Klucz doby.
	 */
	public function nightLabel( string $key ): string {
		$night = $this->night( $key );

		return null === $night ? $key : $night->label;
	}

	/**
	 * Czy można zrezygnować z noclegu.
	 */
	public function allowsNone(): bool {
		return $this->allowNone;
	}

	/**
	 * Czy zgłoszenie może zawierać osobę towarzyszącą.
	 */
	public function companionEnabled(): bool {
		return $this->companionEnabled;
	}

	/**
	 * Czy osoba towarzysząca wlicza się do globalnego limitu wydarzenia.
	 */
	public function companionCountsEvent(): bool {
		return $this->companionCountsEvent;
	}
}
