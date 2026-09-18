<?php
/**
 * Nakłada tłumaczenia treści (labele) na surowy config przed asemblacją.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Schema;

/**
 * Podmienia etykiety sekcji/pól/opcji/typów wg overlay dla danego języka.
 * Czysta domena: brak WordPressa. Nie mutuje wejścia.
 */
final class ContentTranslator {

	/**
	 * Zwraca [schema, types] z nałożonymi tłumaczeniami dla języka $lang.
	 * Brak języka / brak lub pusty override → wartość bazowa.
	 *
	 * @param array<string,mixed>            $schema  Surowa schema.
	 * @param array<int,array<string,mixed>> $types Surowe typy.
	 * @param array<string,mixed>            $overlay Mapa lang → nadpisania.
	 * @param string                         $lang    Slug języka (pusty = baza).
	 *
	 * @return array{0:array<string,mixed>,1:array<int,array<string,mixed>>}
	 */
	public function apply( array $schema, array $types, array $overlay, string $lang ): array {
		if ( '' === $lang || ! isset( $overlay[ $lang ] ) || ! is_array( $overlay[ $lang ] ) ) {
			return array( $schema, $types );
		}

		$over     = $overlay[ $lang ];
		$sections = isset( $over['sections'] ) && is_array( $over['sections'] ) ? $over['sections'] : array();
		$fields   = isset( $over['fields'] ) && is_array( $over['fields'] ) ? $over['fields'] : array();
		$options  = isset( $over['options'] ) && is_array( $over['options'] ) ? $over['options'] : array();
		$type_map = isset( $over['types'] ) && is_array( $over['types'] ) ? $over['types'] : array();

		foreach ( $schema['sections'] ?? array() as $si => $section ) {
			$section_key                        = (string) ( $section['key'] ?? '' );
			$schema['sections'][ $si ]['title'] = self::pick( $sections, $section_key, (string) ( $section['title'] ?? '' ) );

			foreach ( $section['fields'] ?? array() as $fi => $field ) {
				$field_key = (string) ( $field['key'] ?? '' );
				$schema['sections'][ $si ]['fields'][ $fi ]['label'] = self::pick( $fields, $field_key, (string) ( $field['label'] ?? '' ) );

				$field_opts = isset( $options[ $field_key ] ) && is_array( $options[ $field_key ] ) ? $options[ $field_key ] : array();
				foreach ( $field['options'] ?? array() as $oi => $option ) {
					$value = (string) ( $option['value'] ?? '' );
					$schema['sections'][ $si ]['fields'][ $fi ]['options'][ $oi ]['label'] = self::pick( $field_opts, $value, (string) ( $option['label'] ?? '' ) );
				}
			}
		}

		foreach ( $types as $ti => $type ) {
			$type_key              = (string) ( $type['key'] ?? '' );
			$types[ $ti ]['label'] = self::pick( $type_map, $type_key, (string) ( $type['label'] ?? '' ) );
		}

		return array( $schema, $types );
	}

	/**
	 * Zwraca niepuste tłumaczenie z mapy pod kluczem, inaczej wartość bazową.
	 *
	 * @param array<string,mixed> $map  Mapa tłumaczeń.
	 * @param string              $key  Klucz.
	 * @param string              $base Wartość bazowa (fallback).
	 */
	private static function pick( array $map, string $key, string $base ): string {
		$value = isset( $map[ $key ] ) ? (string) $map[ $key ] : '';
		return '' !== $value ? $value : $base;
	}
}
