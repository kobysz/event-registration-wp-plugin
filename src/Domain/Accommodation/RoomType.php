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
	 * @param int    $occupancy     Liczba osób w pokoju: 0 = nieustawione, 1, 2.
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly bool $roommateField = false,
		public readonly int $occupancy = 0
	) {
	}

	/**
	 * Tworzy typ pokoju z tablicy danych konfiguracji.
	 *
	 * @param array<string,mixed> $data Surowe dane typu pokoju.
	 *
	 * @throws SchemaException Gdy brakuje klucza typu pokoju albo liczba osób jest spoza 0..2.
	 */
	public static function fromArray( array $data ): self {
		if ( ! isset( $data['key'] ) || '' === (string) $data['key'] ) {
			throw new SchemaException( 'Typ pokoju wymaga niepustego klucza.' );
		}

		$occupancy = (int) ( $data['occupancy'] ?? 0 );

		if ( $occupancy < 0 || $occupancy > 2 ) {
			throw new SchemaException(
				sprintf( 'Pokój "%s" może mieć 1 albo 2 osoby (0 = nieustawione).', (string) $data['key'] )
			);
		}

		return new self(
			(string) $data['key'],
			(string) ( $data['label'] ?? $data['key'] ),
			(bool) ( $data['roommate_field'] ?? false ),
			$occupancy
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
			'occupancy'      => $this->occupancy,
		);
	}

	/**
	 * Ile miejsc zajmuje w tym pokoju zgłoszenie z lub bez osoby towarzyszącej.
	 *
	 * @param bool $companion Czy zgłoszenie zawiera osobę towarzyszącą.
	 */
	public function seats( bool $companion ): int {
		return RoomOccupancy::seats( $this->occupancy, $companion );
	}

	/**
	 * Zwraca kod błędu, gdy para pokój/osoba towarzysząca jest niespójna, albo null.
	 * Pokój bez ustawionej liczby osób nigdy nie protestuje (zgodność w tył).
	 *
	 * @param bool $companion Czy zgłoszenie zawiera osobę towarzyszącą.
	 */
	public function companionMismatch( bool $companion ): ?string {
		if ( 0 === $this->occupancy ) {
			return null;
		}
		if ( $companion && 1 === $this->occupancy ) {
			return 'companion_not_allowed';
		}
		if ( ! $companion && 2 === $this->occupancy ) {
			return 'companion_required';
		}

		return null;
	}
}
