<?php
/**
 * Wynik wyznaczenia widoczności sekcji i pól schematu.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Schema;

/**
 * Zbiór kluczy widocznych sekcji oraz widocznych pól dla danego zestawu odpowiedzi.
 */
final class VisibilitySet {

	/**
	 * Tworzy zbiór widoczności z gotowych list sekcji i pól.
	 *
	 * @param string[] $visibleSections Klucze widocznych sekcji.
	 * @param Field[]  $visibleFields   Widoczne pola.
	 */
	public function __construct(
		private readonly array $visibleSections,
		private readonly array $visibleFields
	) {
	}

	/**
	 * Sprawdza, czy sekcja o podanym kluczu jest widoczna.
	 *
	 * @param string $key Klucz sekcji.
	 */
	public function isSectionVisible( string $key ): bool {
		return in_array( $key, $this->visibleSections, true );
	}

	/**
	 * Sprawdza, czy pole o podanym kluczu jest widoczne.
	 *
	 * @param string $key Klucz pola.
	 */
	public function isFieldVisible( string $key ): bool {
		foreach ( $this->visibleFields as $field ) {
			if ( $field->key === $key ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Zwraca listę widocznych pól.
	 *
	 * @return Field[]
	 */
	public function visibleFields(): array {
		return $this->visibleFields;
	}
}
