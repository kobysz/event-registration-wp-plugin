<?php

declare( strict_types=1 );

namespace EvReg\Domain\Schema;

final class Option {

	public function __construct(
		public readonly string $value,
		public readonly string $label
	) {
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function fromArray( array $data ): self {
		if ( ! isset( $data['value'] ) || '' === (string) $data['value'] ) {
			throw new SchemaException( 'Opcja wymaga niepustej wartości.' );
		}

		return new self( (string) $data['value'], (string) ( $data['label'] ?? $data['value'] ) );
	}

	/**
	 * @return array<string,string>
	 */
	public function toArray(): array {
		return array(
			'value' => $this->value,
			'label' => $this->label,
		);
	}
}
