<?php

declare( strict_types=1 );

namespace EvReg\Domain\Accommodation;

use EvReg\Domain\Schema\SchemaException;

final class RoomType {

	public function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly bool $roommateField = false
	) {
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function fromArray( array $data ): self {
		if ( ! isset( $data['key'] ) || '' === (string) $data['key'] ) {
			throw new SchemaException( 'Typ pokoju wymaga niepustego klucza.' );
		}

		return new self(
			(string) $data['key'],
			(string) ( $data['label'] ?? $data['key'] ),
			(bool) ( $data['roommate_field'] ?? false )
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'key'            => $this->key,
			'label'          => $this->label,
			'roommate_field' => $this->roommateField,
		);
	}
}
