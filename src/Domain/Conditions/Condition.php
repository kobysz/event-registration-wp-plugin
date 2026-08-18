<?php

declare( strict_types=1 );

namespace EvReg\Domain\Conditions;

use EvReg\Domain\Schema\SchemaException;

final class Condition {

	/**
	 * @param string[]|string|null $value
	 */
	public function __construct(
		public readonly string $field,
		public readonly Operator $operator,
		public readonly array|string|null $value = null
	) {
	}

	/**
	 * @param array<string,mixed> $data
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
