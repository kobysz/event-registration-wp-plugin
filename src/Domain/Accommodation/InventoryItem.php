<?php
/**
 * Pozycja inwentarza zakwaterowania (limit i cena dla pary pakiet/pokój).
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Accommodation;

use EvReg\Domain\Schema\SchemaException;

/**
 * Limit miejsc i cena dla konkretnej kombinacji pakietu i typu pokoju.
 */
final class InventoryItem {

	/**
	 * Tworzy pozycję inwentarza z limitu i ceny.
	 *
	 * @param string $packageKey Klucz pakietu.
	 * @param string $roomKey    Klucz typu pokoju.
	 * @param int    $capacity   Limit miejsc.
	 * @param float  $price      Cena za miejsce.
	 */
	public function __construct(
		public readonly string $packageKey,
		public readonly string $roomKey,
		public readonly int $capacity,
		public readonly float $price
	) {
	}

	/**
	 * Tworzy pozycję inwentarza z tablicy danych konfiguracji.
	 *
	 * @param array<string,mixed> $data Surowe dane pozycji.
	 *
	 * @throws SchemaException Gdy brakuje kluczy pakietu/pokoju lub wartości są ujemne.
	 */
	public static function fromArray( array $data ): self {
		$package = (string) ( $data['package'] ?? '' );
		$room    = (string) ( $data['room'] ?? '' );

		if ( '' === $package || '' === $room ) {
			throw new SchemaException( 'Pozycja inwentarza wymaga kluczy "package" i "room".' );
		}

		$capacity = (int) ( $data['capacity'] ?? 0 );
		$price    = (float) ( $data['price'] ?? 0 );

		if ( $capacity < 0 || $price < 0 ) {
			throw new SchemaException(
				sprintf( 'Limit i cena pozycji "%s|%s" nie mogą być ujemne.', $package, $room )
			);
		}

		return new self( $package, $room, $capacity, $price );
	}

	/**
	 * Zwraca klucz identyfikujący slot pakiet/pokój.
	 */
	public function slotKey(): string {
		return $this->packageKey . '|' . $this->roomKey;
	}

	/**
	 * Serializuje pozycję inwentarza do tablicy.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'package'  => $this->packageKey,
			'room'     => $this->roomKey,
			'capacity' => $this->capacity,
			'price'    => $this->price,
		);
	}
}
