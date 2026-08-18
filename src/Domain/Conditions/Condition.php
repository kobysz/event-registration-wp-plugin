<?php
/**
 * Warunek widoczności sekcji lub pola w schemacie formularza.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Conditions;

use EvReg\Domain\Schema\SchemaException;

/**
 * Porównanie odpowiedzi na wskazane pole z oczekiwaną wartością.
 */
final class Condition {

	/**
	 * Tworzy warunek z pola, operatora i oczekiwanej wartości.
	 *
	 * @param string               $field    Klucz pola, którego dotyczy warunek.
	 * @param Operator             $operator Operator porównania.
	 * @param string[]|string|null $value    Oczekiwana wartość (lub lista wartości).
	 */
	public function __construct(
		public readonly string $field,
		public readonly Operator $operator,
		public readonly array|string|null $value = null
	) {
	}

	/**
	 * Tworzy warunek z tablicy danych schematu.
	 *
	 * @param array<string,mixed> $data Surowe dane warunku.
	 *
	 * @throws SchemaException Gdy brakuje pola, operator jest nieznany lub brak wymaganej wartości.
	 */
	public static function fromArray( array $data ): self {
		if ( ! isset( $data['field'] ) || ! is_string( $data['field'] ) || '' === $data['field'] ) {
			throw new SchemaException( 'Warunek wymaga niepustego klucza "field".' );
		}

		$operator = Operator::tryFrom( (string) ( $data['operator'] ?? '' ) );

		if ( null === $operator ) {
			throw new SchemaException(
				sprintf( 'Nieznany operator warunku: "%s".', (string) ( $data['operator'] ?? '' ) )
			);
		}

		$value = $data['value'] ?? null;

		if ( $operator->needsValue() && null === $value ) {
			throw new SchemaException(
				sprintf( 'Operator "%s" wymaga wartości.', $operator->value )
			);
		}

		if ( null !== $value && ! is_array( $value ) && ! is_string( $value ) ) {
			$value = (string) $value;
		}

		return new self( $data['field'], $operator, $value );
	}

	/**
	 * Serializuje warunek do tablicy.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		$data = array(
			'field'    => $this->field,
			'operator' => $this->operator->value,
		);

		if ( $this->operator->needsValue() ) {
			$data['value'] = $this->value;
		}

		return $data;
	}
}
