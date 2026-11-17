<?php
/**
 * Doba noclegowa w trybie „doby”.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Accommodation;

use EvReg\Domain\Schema\SchemaException;

/**
 * Doba: jedyny wymiar pojemności w trybie „doby”. Niesie cenę za miejsce,
 * liczbę miejsc i opcjonalny limit zgłoszeń z osobą towarzyszącą.
 */
final class Night {

	/**
	 * Tworzy dobę.
	 *
	 * @param string   $key          Unikalny klucz doby.
	 * @param string   $label        Etykieta prezentowana użytkownikowi.
	 * @param float    $price        Cena za jedno miejsce.
	 * @param int      $seats        Liczba miejsc (0 = doba zamknięta).
	 * @param int|null $companionCap Limit zgłoszeń z osobą towarzyszącą (null = bez limitu, 0 = limit zerowy).
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly float $price,
		public readonly int $seats,
		public readonly ?int $companionCap
	) {
	}

	/**
	 * Tworzy dobę z tablicy danych konfiguracji.
	 *
	 * @param array<string,mixed> $data Surowe dane doby.
	 *
	 * @throws SchemaException Gdy brakuje klucza albo liczba jest ujemna.
	 */
	public static function fromArray( array $data ): self {
		if ( ! isset( $data['key'] ) || '' === (string) $data['key'] ) {
			throw new SchemaException( 'Doba wymaga niepustego klucza.' );
		}

		$key   = (string) $data['key'];
		$price = (float) ( $data['price'] ?? 0 );
		$seats = (int) ( $data['seats'] ?? 0 );
		$cap   = $data['companion_cap'] ?? null;
		// Pusty string i brak klucza znaczą „bez limitu"; liczba (także 0) znaczy limit.
		$cap = ( null === $cap || '' === $cap ) ? null : (int) $cap;

		if ( $price < 0 || $seats < 0 || ( null !== $cap && $cap < 0 ) ) {
			throw new SchemaException( sprintf( 'Doba "%s" nie może mieć ujemnej ceny, liczby miejsc ani limitu.', $key ) );
		}

		return new self( $key, (string) ( $data['label'] ?? $key ), $price, $seats, $cap );
	}

	/**
	 * Serializuje dobę do tablicy.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'key'           => $this->key,
			'label'         => $this->label,
			'price'         => $this->price,
			'seats'         => $this->seats,
			'companion_cap' => $this->companionCap,
		);
	}
}
