<?php
/**
 * Typ zgłoszenia dostępny w konfiguracji wydarzenia.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Registration;

use EvReg\Domain\Schema\SchemaException;

/**
 * Nazwany typ zgłoszenia z ceną, opcjonalnym limitem miejsc i stanem aktywności.
 */
final class RegistrationType {

	/**
	 * Tworzy typ zgłoszenia z jego atrybutów.
	 *
	 * @param string   $key      Unikalny klucz typu zgłoszenia.
	 * @param string   $label    Etykieta prezentowana użytkownikowi.
	 * @param float    $price    Cena bazowa typu zgłoszenia.
	 * @param int|null $capacity Limit miejsc; null = bez limitu.
	 * @param bool     $active   Czy typ jest aktualnie dostępny do wyboru.
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly float $price = 0.0,
		public readonly ?int $capacity = null,
		public readonly bool $active = true
	) {
	}

	/**
	 * Tworzy typ zgłoszenia z tablicy danych konfiguracji.
	 *
	 * @param array<string,mixed> $data Surowe dane typu zgłoszenia.
	 *
	 * @throws SchemaException Gdy brakuje klucza lub cena/limit są ujemne.
	 */
	public static function fromArray( array $data ): self {
		if ( ! isset( $data['key'] ) || '' === (string) $data['key'] ) {
			throw new SchemaException( 'Typ zgłoszenia wymaga niepustego klucza.' );
		}

		$price = (float) ( $data['price'] ?? 0 );

		if ( $price < 0 ) {
			throw new SchemaException(
				sprintf( 'Cena typu "%s" nie może być ujemna.', (string) $data['key'] )
			);
		}

		$capacity = $data['capacity'] ?? null;

		if ( null !== $capacity && (int) $capacity < 0 ) {
			throw new SchemaException(
				sprintf( 'Limit typu "%s" nie może być ujemny.', (string) $data['key'] )
			);
		}

		return new self(
			(string) $data['key'],
			(string) ( $data['label'] ?? $data['key'] ),
			$price,
			null === $capacity ? null : (int) $capacity,
			(bool) ( $data['active'] ?? true )
		);
	}

	/**
	 * Serializuje typ zgłoszenia do tablicy.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'key'      => $this->key,
			'label'    => $this->label,
			'price'    => $this->price,
			'capacity' => $this->capacity,
			'active'   => $this->active,
		);
	}
}
