<?php

declare( strict_types=1 );

namespace EvReg\Domain\Accommodation;

use EvReg\Domain\Schema\SchemaException;

final class AccommodationConfig {

	/**
	 * @param Package[]       $packages
	 * @param RoomType[]      $rooms
	 * @param InventoryItem[] $items
	 */
	private function __construct(
		private readonly array $packages,
		private readonly array $rooms,
		private readonly array $items,
		private readonly bool $allowNone
	) {
	}

	/**
	 * @param array<string,mixed> $config
	 */
	public static function fromArray( array $config ): self {
		$packages = array();

		foreach ( (array) ( $config['packages'] ?? array() ) as $package ) {
			$packages[] = Package::fromArray( (array) $package );
		}

		$rooms = array();

		foreach ( (array) ( $config['rooms'] ?? array() ) as $room ) {
			$rooms[] = RoomType::fromArray( (array) $room );
		}

		$package_keys = array_map( static fn ( Package $item ): string => $item->key, $packages );
		$room_keys    = array_map( static fn ( RoomType $item ): string => $item->key, $rooms );

		$items = array();
		$slots = array();

		foreach ( (array) ( $config['inventory'] ?? array() ) as $entry ) {
			$item = InventoryItem::fromArray( (array) $entry );

			if ( ! in_array( $item->packageKey, $package_keys, true ) ) {
				throw new SchemaException(
					sprintf( 'Inwentarz wskazuje na nieznany pakiet "%s".', $item->packageKey )
				);
			}

			if ( ! in_array( $item->roomKey, $room_keys, true ) ) {
				throw new SchemaException(
					sprintf( 'Inwentarz wskazuje na nieznany pokój "%s".', $item->roomKey )
				);
			}

			if ( in_array( $item->slotKey(), $slots, true ) ) {
				throw new SchemaException(
					sprintf( 'Zduplikowana pozycja inwentarza "%s".', $item->slotKey() )
				);
			}

			$slots[] = $item->slotKey();
			$items[] = $item;
		}

		return new self( $packages, $rooms, $items, (bool) ( $config['allow_none'] ?? true ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'packages'   => array_map( static fn ( Package $item ): array => $item->toArray(), $this->packages ),
			'rooms'      => array_map( static fn ( RoomType $item ): array => $item->toArray(), $this->rooms ),
			'inventory'  => array_map( static fn ( InventoryItem $item ): array => $item->toArray(), $this->items ),
			'allow_none' => $this->allowNone,
		);
	}

	/**
	 * @return Package[]
	 */
	public function packages(): array {
		return $this->packages;
	}

	/**
	 * @return RoomType[]
	 */
	public function rooms(): array {
		return $this->rooms;
	}

	public function room( string $key ): ?RoomType {
		foreach ( $this->rooms as $room ) {
			if ( $room->key === $key ) {
				return $room;
			}
		}

		return null;
	}

	/**
	 * @return InventoryItem[]
	 */
	public function items(): array {
		return $this->items;
	}

	public function item( string $package_key, string $room_key ): ?InventoryItem {
		foreach ( $this->items as $item ) {
			if ( $item->packageKey === $package_key && $item->roomKey === $room_key ) {
				return $item;
			}
		}

		return null;
	}

	public function allowsNone(): bool {
		return $this->allowNone;
	}

	/**
	 * @return array<string,int>
	 */
	public function capacities(): array {
		$map = array();

		foreach ( $this->items as $item ) {
			$map[ $item->slotKey() ] = $item->capacity;
		}

		return $map;
	}
}
