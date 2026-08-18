<?php
/**
 * Łączy rozdzielone źródła konfiguracji w kompletny schemat formularza.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Schema;

use EvReg\Domain\Registration\RegistrationType;
use EvReg\Domain\Registration\RegistrationTypeCollection;

/**
 * Składa kompletną FormSchema z rozdzielonych źródeł: surowej schemy,
 * typów zgłoszenia i konfiguracji noclegów.
 *
 * Pole __type dostaje opcje z aktywnych typów; pola accommodation dostają
 * config z konfiguracji noclegów. Wynik jest poprawną, samodzielną FormSchema.
 */
final class SchemaAssembler {

	/**
	 * Składa kompletną FormSchema z surowej schemy, typów zgłoszenia i konfiguracji noclegów.
	 *
	 * @param array<string,mixed>            $schema        Surowy _evreg_schema.
	 * @param array<int,array<string,mixed>> $types         Surowy _evreg_types.
	 * @param array<string,mixed>            $accommodation Surowy _evreg_accommodation.
	 */
	public function assemble( array $schema, array $types, array $accommodation ): FormSchema {
		$options = $this->typeOptions( $types );

		$sections = $schema['sections'] ?? array();

		if ( is_array( $sections ) ) {
			foreach ( $sections as $s => $section ) {
				$fields = $section['fields'] ?? array();

				if ( ! is_array( $fields ) ) {
					continue;
				}

				foreach ( $fields as $f => $field ) {
					$key  = $field['key'] ?? '';
					$type = $field['type'] ?? '';

					if ( FormSchema::TYPE_FIELD_KEY === $key ) {
						$fields[ $f ]['options'] = $options;
					}

					if ( FieldType::Accommodation->value === $type ) {
						$fields[ $f ]['config'] = $accommodation;
					}
				}

				$sections[ $s ]['fields'] = $fields;
			}
		}

		$schema['sections'] = $sections;

		return FormSchema::fromArray( $schema );
	}

	/**
	 * Waliduje złożoną schemę. Rzuca SchemaException, gdy niepoprawna.
	 *
	 * @param array<string,mixed>            $schema        Surowy _evreg_schema.
	 * @param array<int,array<string,mixed>> $types         Surowy _evreg_types.
	 * @param array<string,mixed>            $accommodation Surowy _evreg_accommodation.
	 */
	public function validate( array $schema, array $types, array $accommodation ): void {
		$form_schema = $this->assemble( $schema, $types, $accommodation );

		( new SchemaIntegrityChecker() )->check( $form_schema );
	}

	/**
	 * Buduje listę opcji pola __type z aktywnych typów zgłoszenia.
	 *
	 * @param array<int,array<string,mixed>> $types Surowy _evreg_types.
	 * @return array<int,array<string,string>>
	 */
	private function typeOptions( array $types ): array {
		$collection = RegistrationTypeCollection::fromArray( $types );

		return array_map(
			static fn ( RegistrationType $type ): array => array(
				'value' => $type->key,
				'label' => $type->label,
			),
			$collection->active()
		);
	}
}
