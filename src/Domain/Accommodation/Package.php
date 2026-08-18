<?php
/**
 * Pakiet noclegowy dostępny w konfiguracji zakwaterowania.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Accommodation;

use EvReg\Domain\Schema\SchemaException;

/**
 * Nazwany pakiet noclegowy (np. "3 dni", "cały pobyt").
 */
final class Package {

	/**
	 * Tworzy pakiet z klucza i etykiety.
	 *
	 * @param string $key   Unikalny klucz pakietu.
	 * @param string $label Etykieta prezentowana użytkownikowi.
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $label
	) {
	}

	/**
	 * Tworzy pakiet z tablicy danych konfiguracji.
	 *
	 * @param array<string,mixed> $data Surowe dane pakietu.
	 *
	 * @throws SchemaException Gdy brakuje klucza pakietu.
	 */
	public static function fromArray( array $data ): self {
		if ( ! isset( $data['key'] ) || '' === (string) $data['key'] ) {
			throw new SchemaException( 'Pakiet noclegowy wymaga niepustego klucza.' );
		}

		return new self( (string) $data['key'], (string) ( $data['label'] ?? $data['key'] ) );
	}

	/**
	 * Serializuje pakiet do tablicy.
	 *
	 * @return array<string,string>
	 */
	public function toArray(): array {
		return array(
			'key'   => $this->key,
			'label' => $this->label,
		);
	}
}
