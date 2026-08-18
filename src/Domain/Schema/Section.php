<?php

declare( strict_types=1 );

namespace EvReg\Domain\Schema;

use EvReg\Domain\Conditions\Condition;

final class Section {

	/**
	 * @param Field[] $fields
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $title,
		public readonly array $fields,
		public readonly string $description = '',
		public readonly ?Condition $condition = null
	) {
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function fromArray( array $data ): self {
		if ( ! isset( $data['key'] ) || ! is_string( $data['key'] ) || '' === $data['key'] ) {
			throw new SchemaException( 'Sekcja wymaga niepustego klucza "key".' );
		}

		$fields = array();

		foreach ( (array) ( $data['fields'] ?? array() ) as $field ) {
			$fields[] = Field::fromArray( (array) $field );
		}

		return new self(
			$data['key'],
			(string) ( $data['title'] ?? '' ),
			$fields,
			(string) ( $data['description'] ?? '' ),
			isset( $data['condition'] ) && is_array( $data['condition'] )
				? Condition::fromArray( $data['condition'] )
				: null
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		$data = array(
			'key'   => $this->key,
			'title' => $this->title,
		);

		if ( '' !== $this->description ) {
			$data['description'] = $this->description;
		}

		if ( null !== $this->condition ) {
			$data['condition'] = $this->condition->toArray();
		}

		$data['fields'] = array_map(
			static fn ( Field $field ): array => $field->toArray(),
			$this->fields
		);

		return $data;
	}
}
