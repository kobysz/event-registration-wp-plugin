<?php
/**
 * Weryfikacja integralności schematu formularza.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Schema;

/**
 * Sprawdza unikalność kluczy oraz spójność warunków w schemacie.
 */
final class SchemaIntegrityChecker {

	/**
	 * Uruchamia komplet asercji integralności schematu.
	 *
	 * @param FormSchema $schema Schemat do zweryfikowania.
	 *
	 * @throws SchemaException Gdy schemat jest niespójny.
	 */
	public function check( FormSchema $schema ): void {
		$this->assertUniqueKeys( $schema );
		$this->assertTypeFieldPresent( $schema );
		$this->assertConditionsResolvable( $schema );
	}

	/**
	 * Klucze sekcji i pól muszą być unikalne w całym schemacie.
	 *
	 * @param FormSchema $schema Schemat do zweryfikowania.
	 *
	 * @throws SchemaException Gdy wykryto zduplikowany klucz.
	 */
	private function assertUniqueKeys( FormSchema $schema ): void {
		$section_keys = array();
		$field_keys   = array();

		foreach ( $schema->sections() as $section ) {
			if ( in_array( $section->key, $section_keys, true ) ) {
				throw new SchemaException( sprintf( 'Zduplikowany klucz sekcji: "%s".', $section->key ) );
			}

			$section_keys[] = $section->key;

			foreach ( $section->fields as $field ) {
				if ( in_array( $field->key, $field_keys, true ) ) {
					throw new SchemaException( sprintf( 'Zduplikowany klucz pola: "%s".', $field->key ) );
				}

				$field_keys[] = $field->key;
			}
		}
	}

	/**
	 * Schemat musi zawierać pole determinujące typ rejestracji.
	 *
	 * @param FormSchema $schema Schemat do zweryfikowania.
	 *
	 * @throws SchemaException Gdy pole typu jest nieobecne lub ma zły typ.
	 */
	private function assertTypeFieldPresent( FormSchema $schema ): void {
		$field = $schema->findField( FormSchema::TYPE_FIELD_KEY );

		if ( null === $field ) {
			throw new SchemaException(
				sprintf( 'Schema musi zawierać pole "%s".', FormSchema::TYPE_FIELD_KEY )
			);
		}

		if ( ! in_array( $field->type, array( FieldType::Radio, FieldType::Select ), true ) ) {
			throw new SchemaException(
				sprintf( 'Pole "%s" musi być typu radio lub select.', FormSchema::TYPE_FIELD_KEY )
			);
		}
	}

	/**
	 * Warunek może wskazywać wyłącznie na pole zadeklarowane wcześniej.
	 *
	 * @param FormSchema $schema Schemat do zweryfikowania.
	 *
	 * @throws SchemaException Gdy warunek wskazuje na nieznane pole.
	 */
	private function assertConditionsResolvable( FormSchema $schema ): void {
		$declared = array();

		foreach ( $schema->sections() as $section ) {
			if ( null !== $section->condition && ! in_array( $section->condition->field, $declared, true ) ) {
				throw new SchemaException(
					sprintf(
						'Warunek sekcji "%s" wskazuje na pole "%s", które nie występuje wcześniej.',
						$section->key,
						$section->condition->field
					)
				);
			}

			foreach ( $section->fields as $field ) {
				if ( null !== $field->condition && ! in_array( $field->condition->field, $declared, true ) ) {
					throw new SchemaException(
						sprintf(
							'Warunek pola "%s" wskazuje na pole "%s", które nie występuje wcześniej.',
							$field->key,
							$field->condition->field
						)
					);
				}

				$declared[] = $field->key;
			}
		}
	}
}
