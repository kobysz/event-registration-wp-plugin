<?php

declare( strict_types=1 );

namespace EvReg\Domain\Accommodation;

use EvReg\Domain\Schema\SchemaException;

final class Package {

	public function __construct(
		public readonly string $key,
		public readonly string $label
	) {
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function fromArray( array $data ): self {
		if ( ! isset( $data['key'] ) || '' === (string) $data['key'] ) {
			throw new SchemaException( 'Pakiet noclegowy wymaga niepustego klucza.' );
		}

		return new self( (string) $data['key'], (string) ( $data['label'] ?? $data['key'] ) );
	}

	/**
	 * @return array<string,string>
	 */
	public function toArray(): array {
		return array(
			'key'   => $this->key,
			'label' => $this->label,
		);
	}
}
