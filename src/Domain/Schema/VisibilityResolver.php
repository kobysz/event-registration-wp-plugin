<?php
/**
 * Wyznaczanie widocznych sekcji i pól na podstawie odpowiedzi.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Schema;

use EvReg\Domain\Conditions\ConditionEngine;

/**
 * Buduje zbiór widocznych sekcji i pól schematu dla danych odpowiedzi.
 */
final class VisibilityResolver {

	/**
	 * Tworzy resolver oparty na podanym silniku warunków.
	 *
	 * @param ConditionEngine $engine Silnik oceny warunków widoczności.
	 */
	public function __construct( private readonly ConditionEngine $engine ) {
	}

	/**
	 * Wylicza zbiór widocznych sekcji i pól dla podanych odpowiedzi.
	 *
	 * @param FormSchema          $schema  Schemat formularza.
	 * @param array<string,mixed> $answers Udzielone odpowiedzi, indeksowane kluczem pola.
	 */
	public function resolve( FormSchema $schema, array $answers ): VisibilitySet {
		$sections = array();
		$fields   = array();

		foreach ( $schema->sections() as $section ) {
			if ( ! $this->engine->isMet( $section->condition, $answers ) ) {
				continue;
			}

			$sections[] = $section->key;

			foreach ( $section->fields as $field ) {
				if ( ! $this->engine->isMet( $field->condition, $answers ) ) {
					continue;
				}

				$fields[] = $field;
			}
		}

		return new VisibilitySet( $sections, $fields );
	}
}
