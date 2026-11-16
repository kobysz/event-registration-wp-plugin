<?php
/**
 * Pojedyncza opcja pola wyboru w schemacie formularza.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Schema;

/**
 * Wartość i etykieta jednej opcji pola typu select/radio/checkbox.
 */
final class Option {

	/**
	 * Tworzy opcję z wartości i etykiety.
	 *
	 * @param string $value       Wartość zapisywana w odpowiedzi.
	 * @param string $label       Etykieta prezentowana użytkownikowi.
	 * @param string $description Opis pokazywany po wybraniu tej opcji (dozwolony prosty HTML).
	 */
	public function __construct(
		public readonly string $value,
		public readonly string $label,
		public readonly string $description = ''
	) {
	}

	/**
	 * Tworzy opcję z tablicy danych schematu.
	 *
	 * @param array<string,mixed> $data Surowe dane opcji.
	 *
	 * @throws SchemaException Gdy brakuje wartości opcji.
	 */
	public static function fromArray( array $data ): self {
		if ( ! isset( $data['value'] ) || '' === (string) $data['value'] ) {
			throw new SchemaException( 'Opcja wymaga niepustej wartości.' );
		}

		return new self(
			(string) $data['value'],
			(string) ( $data['label'] ?? $data['value'] ),
			(string) ( $data['description'] ?? '' )
		);
	}

	/**
	 * Serializuje opcję do tablicy.
	 *
	 * @return array<string,string>
	 */
	public function toArray(): array {
		$data = array(
			'value' => $this->value,
			'label' => $this->label,
		);

		if ( '' !== $this->description ) {
			$data['description'] = $this->description;
		}

		return $data;
	}
}
