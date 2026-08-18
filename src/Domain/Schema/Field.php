<?php
/**
 * Definicja pojedynczego pola formularza w schemacie.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Schema;

use EvReg\Domain\Conditions\Condition;

/**
 * Pole formularza wraz z typem, opcjami i opcjonalnym warunkiem widoczności.
 */
final class Field {

	/**
	 * Tworzy pole z jego atrybutów.
	 *
	 * @param string              $key         Unikalny klucz pola.
	 * @param FieldType           $type        Typ pola.
	 * @param string              $label       Etykieta prezentowana użytkownikowi.
	 * @param bool                $required    Czy pole jest wymagane.
	 * @param Option[]            $options     Lista opcji dla pól wyboru.
	 * @param array<string,mixed> $config      Dodatkowa konfiguracja pola.
	 * @param string              $description Opis pomocniczy pola.
	 * @param Condition|null      $condition   Warunek widoczności pola.
	 */
	public function __construct(
		public readonly string $key,
		public readonly FieldType $type,
		public readonly string $label,
		public readonly bool $required = false,
		public readonly array $options = array(),
		public readonly array $config = array(),
		public readonly string $description = '',
		public readonly ?Condition $condition = null
	) {
	}

	/**
	 * Tworzy pole z tablicy danych schematu.
	 *
	 * @param array<string,mixed> $data Surowe dane pola.
	 *
	 * @throws SchemaException Gdy brakuje klucza, typ jest nieznany lub brak wymaganych opcji.
	 */
	public static function fromArray( array $data ): self {
		if ( ! isset( $data['key'] ) || ! is_string( $data['key'] ) || '' === $data['key'] ) {
			throw new SchemaException( 'Pole wymaga niepustego klucza "key".' );
		}

		$type = FieldType::tryFrom( (string) ( $data['type'] ?? '' ) );

		if ( null === $type ) {
			throw new SchemaException(
				sprintf( 'Nieznany typ pola "%s" w polu "%s".', (string) ( $data['type'] ?? '' ), $data['key'] )
			);
		}

		$options = array();

		foreach ( (array) ( $data['options'] ?? array() ) as $option ) {
			$options[] = Option::fromArray( (array) $option );
		}

		if ( $type->hasOptions() && array() === $options ) {
			throw new SchemaException(
				sprintf( 'Pole "%s" typu "%s" wymaga listy opcji.', $data['key'], $type->value )
			);
		}

		return new self(
			$data['key'],
			$type,
			(string) ( $data['label'] ?? '' ),
			(bool) ( $data['required'] ?? false ),
			$options,
			(array) ( $data['config'] ?? array() ),
			(string) ( $data['description'] ?? '' ),
			isset( $data['condition'] ) && is_array( $data['condition'] )
				? Condition::fromArray( $data['condition'] )
				: null
		);
	}

	/**
	 * Serializuje pole do tablicy.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		$data = array(
			'key'   => $this->key,
			'type'  => $this->type->value,
			'label' => $this->label,
		);

		if ( $this->required ) {
			$data['required'] = true;
		}

		if ( array() !== $this->options ) {
			$data['options'] = array_map(
				static fn ( Option $option ): array => $option->toArray(),
				$this->options
			);
		}

		if ( array() !== $this->config ) {
			$data['config'] = $this->config;
		}

		if ( '' !== $this->description ) {
			$data['description'] = $this->description;
		}

		if ( null !== $this->condition ) {
			$data['condition'] = $this->condition->toArray();
		}

		return $data;
	}
}
