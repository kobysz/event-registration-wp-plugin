<?php

declare( strict_types=1 );

namespace EvReg\Domain\Accommodation;

use EvReg\Domain\Schema\SchemaException;

final class InventoryItem {

	public function __construct(
		public readonly string $packageKey,
		public readonly string $roomKey,
		public readonly int $capacity,
		public readonly float $price
	) {
	}

	/**
	 * @param array<string,mixed> $data
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

	public function slotKey(): string {
		return $this->packageKey . '|' . $this->roomKey;
	}

	/**
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
