<?php
/**
 * Typ pokoju dostępny w konfiguracji zakwaterowania.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Accommodation;

use EvReg\Domain\Schema\SchemaException;

/**
 * Nazwany typ pokoju (np. "dwuosobowy") z opcjonalnym polem współlokatora.
 */
final class RoomType {

	/**
	 * Tworzy typ pokoju z klucza, etykiety i flagi współlokatora.
	 *
	 * @param string $key           Unikalny klucz typu pokoju.
	 * @param string $label         Etykieta prezentowana użytkownikowi.
	 * @param bool   $roommateField Czy formularz ma pytać o preferencje współlokatora.
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly bool $roommateField = false
	) {
	}

	/**
	 * Tworzy typ pokoju z tablicy danych konfiguracji.
	 *
	 * @param array<string,mixed> $data Surowe dane typu pokoju.
	 *
	 * @throws SchemaException Gdy brakuje klucza typu pokoju.
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
	 * Serializuje typ pokoju do tablicy.
	 *
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
