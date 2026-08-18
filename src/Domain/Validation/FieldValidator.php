<?php
/**
 * Kontrakt walidatora wartości pojedynczego typu pola.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Validation;

use EvReg\Domain\Schema\Field;

/**
 * Waliduje i normalizuje surową wartość odpowiedzi dla danego pola.
 */
interface FieldValidator {

	/**
	 * Waliduje surową wartość odpowiedzi dla pola.
	 *
	 * @param Field $field Definicja pola.
	 * @param mixed $raw   Surowa wartość odpowiedzi.
	 */
	public function validate( Field $field, mixed $raw ): FieldOutcome;
}
