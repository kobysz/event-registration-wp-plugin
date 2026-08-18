<?php
/**
 * Kolekcja typów zgłoszeń dostępnych dla wydarzenia.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Registration;

use EvReg\Domain\Schema\SchemaException;

/**
 * Uporządkowany, niemutowalny zbiór typów zgłoszeń z gwarancją unikalności kluczy.
 */
final class RegistrationTypeCollection {

	/**
	 * Tworzy kolekcję z gotowej listy typów.
	 *
	 * @param RegistrationType[] $types Typy zgłoszeń w kolekcji.
	 */
	private function __construct( private readonly array $types ) {
	}

	/**
	 * Tworzy kolekcję z tablicy danych konfiguracji.
	 *
	 * @param array<int,array<string,mixed>> $items Surowe dane typów zgłoszeń.
	 *
	 * @throws SchemaException Gdy wykryto zduplikowany klucz typu.
	 */
	public static function fromArray( array $items ): self {
		$types = array();
		$keys  = array();

		foreach ( $items as $item ) {
			$type = RegistrationType::fromArray( (array) $item );

			if ( in_array( $type->key, $keys, true ) ) {
				throw new SchemaException( sprintf( 'Zduplikowany typ zgłoszenia: "%s".', $type->key ) );
			}

			$keys[]  = $type->key;
			$types[] = $type;
		}

		return new self( $types );
	}

	/**
	 * Serializuje kolekcję do tablicy.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function toArray(): array {
		return array_map(
			static fn ( RegistrationType $type ): array => $type->toArray(),
			$this->types
		);
	}

	/**
	 * Zwraca wszystkie typy zgłoszeń.
	 *
	 * @return RegistrationType[]
	 */
	public function all(): array {
		return $this->types;
	}

	/**
	 * Zwraca tylko aktywne typy zgłoszeń.
	 *
	 * @return RegistrationType[]
	 */
	public function active(): array {
		return array_values(
			array_filter( $this->types, static fn ( RegistrationType $type ): bool => $type->active )
		);
	}

	/**
	 * Znajduje typ zgłoszenia po kluczu.
	 *
	 * @param string $key Klucz typu zgłoszenia.
	 */
	public function get( string $key ): ?RegistrationType {
		foreach ( $this->types as $type ) {
			if ( $type->key === $key ) {
				return $type;
			}
		}

		return null;
	}

	/**
	 * Sprawdza, czy kolekcja zawiera typ o podanym kluczu.
	 *
	 * @param string $key Klucz typu zgłoszenia.
	 */
	public function has( string $key ): bool {
		return null !== $this->get( $key );
	}

	/**
	 * Zwraca mapę klucz typu => limit miejsc.
	 *
	 * @return array<string,int|null>
	 */
	public function capacities(): array {
		$map = array();

		foreach ( $this->types as $type ) {
			$map[ $type->key ] = $type->capacity;
		}

		return $map;
	}
}
