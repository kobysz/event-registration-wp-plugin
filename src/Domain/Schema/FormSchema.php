<?php
/**
 * Kompletny schemat formularza rejestracyjnego wydarzenia.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Schema;

/**
 * Uporządkowany zbiór sekcji budujących formularz zgłoszeniowy.
 */
final class FormSchema {

	public const CURRENT_VERSION = 1;

	public const TYPE_FIELD_KEY = '__type';

	/**
	 * Tworzy schemat z gotowej listy sekcji.
	 *
	 * @param Section[] $sections Sekcje formularza.
	 * @param int       $version  Wersja formatu schematu.
	 */
	private function __construct(
		private readonly array $sections,
		private readonly int $version = self::CURRENT_VERSION
	) {
	}

	/**
	 * Zwraca pusty schemat bez sekcji.
	 */
	public static function empty(): self {
		return new self( array() );
	}

	/**
	 * Tworzy schemat z tablicy danych.
	 *
	 * @param array<string,mixed> $data Surowe dane schematu.
	 */
	public static function fromArray( array $data ): self {
		$sections = array();

		foreach ( (array) ( $data['sections'] ?? array() ) as $section ) {
			$sections[] = Section::fromArray( (array) $section );
		}

		return new self( $sections, (int) ( $data['version'] ?? self::CURRENT_VERSION ) );
	}

	/**
	 * Serializuje schemat do tablicy.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'version'  => $this->version,
			'sections' => array_map(
				static fn ( Section $section ): array => $section->toArray(),
				$this->sections
			),
		);
	}

	/**
	 * Zwraca sekcje schematu.
	 *
	 * @return Section[]
	 */
	public function sections(): array {
		return $this->sections;
	}

	/**
	 * Zwraca wszystkie pola ze wszystkich sekcji.
	 *
	 * @return Field[]
	 */
	public function allFields(): array {
		$fields = array();

		foreach ( $this->sections as $section ) {
			foreach ( $section->fields as $field ) {
				$fields[] = $field;
			}
		}

		return $fields;
	}

	/**
	 * Znajduje pole po kluczu.
	 *
	 * @param string $key Klucz pola.
	 */
	public function findField( string $key ): ?Field {
		foreach ( $this->allFields() as $field ) {
			if ( $field->key === $key ) {
				return $field;
			}
		}

		return null;
	}

	/**
	 * Znajduje sekcję zawierającą pole o podanym kluczu.
	 *
	 * @param string $fieldKey Klucz pola.
	 */
	public function sectionOf( string $fieldKey ): ?Section {
		foreach ( $this->sections as $section ) {
			foreach ( $section->fields as $field ) {
				if ( $field->key === $fieldKey ) {
					return $section;
				}
			}
		}

		return null;
	}
}
