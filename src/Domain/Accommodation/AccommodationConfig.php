<?php
/**
 * Pełna konfiguracja zakwaterowania dla wydarzenia.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Accommodation;

use EvReg\Domain\Schema\SchemaException;

/**
 * Pakiety, pokoje i inwentarz dostępne dla zakwaterowania w ramach wydarzenia.
 */
final class AccommodationConfig {

	/**
	 * Tworzy konfigurację z gotowych list pakietów, pokoi i inwentarza.
	 *
	 * @param Package[]       $packages  Dostępne pakiety.
	 * @param RoomType[]      $rooms     Dostępne typy pokoi.
	 * @param InventoryItem[] $items                 Pozycje inwentarza łączące pakiety i pokoje.
	 * @param bool            $allowNone             Czy zgłaszający się może zrezygnować z zakwaterowania.
	 * @param bool            $companionEnabled      Czy zgłoszenie może zawierać osobę towarzyszącą.
	 * @param bool            $companionCountsEvent  Czy osoba towarzysząca wlicza się do globalnego limitu wydarzenia.
	 */
	private function __construct(
		private readonly array $packages,
		private readonly array $rooms,
		private readonly array $items,
		private readonly bool $allowNone,
		private readonly bool $companionEnabled = false,
		private readonly bool $companionCountsEvent = false
	) {
	}

	/**
	 * Tworzy konfigurację zakwaterowania z tablicy danych.
	 *
	 * @param array<string,mixed> $config Surowe dane konfiguracji.
	 *
	 * @throws SchemaException Gdy inwentarz wskazuje na nieznany pakiet/pokój lub jest zduplikowany.
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

		return new self(
			$packages,
			$rooms,
			$items,
			(bool) ( $config['allow_none'] ?? true ),
			(bool) ( $config['companion_enabled'] ?? false ),
			(bool) ( $config['companion_counts_event'] ?? false )
		);
	}

	/**
	 * Serializuje konfigurację do tablicy.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'packages'               => array_map( static fn ( Package $item ): array => $item->toArray(), $this->packages ),
			'rooms'                  => array_map( static fn ( RoomType $item ): array => $item->toArray(), $this->rooms ),
			'inventory'              => array_map( static fn ( InventoryItem $item ): array => $item->toArray(), $this->items ),
			'allow_none'             => $this->allowNone,
			'companion_enabled'      => $this->companionEnabled,
			'companion_counts_event' => $this->companionCountsEvent,
		);
	}

	/**
	 * Zwraca listę dostępnych pakietów.
	 *
	 * @return Package[]
	 */
	public function packages(): array {
		return $this->packages;
	}

	/**
	 * Zwraca listę dostępnych typów pokoi.
	 *
	 * @return RoomType[]
	 */
	public function rooms(): array {
		return $this->rooms;
	}

	/**
	 * Znajduje typ pokoju po kluczu.
	 *
	 * @param string $key Klucz typu pokoju.
	 */
	public function room( string $key ): ?RoomType {
		foreach ( $this->rooms as $room ) {
			if ( $room->key === $key ) {
				return $room;
			}
		}

		return null;
	}

	/**
	 * Zwraca listę pozycji inwentarza.
	 *
	 * @return InventoryItem[]
	 */
	public function items(): array {
		return $this->items;
	}

	/**
	 * Znajduje pozycję inwentarza dla pary pakiet/pokój.
	 *
	 * @param string $package_key Klucz pakietu.
	 * @param string $room_key    Klucz typu pokoju.
	 */
	public function item( string $package_key, string $room_key ): ?InventoryItem {
		foreach ( $this->items as $item ) {
			if ( $item->packageKey === $package_key && $item->roomKey === $room_key ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * Czy zgłaszający się może zrezygnować z zakwaterowania.
	 */
	public function allowsNone(): bool {
		return $this->allowNone;
	}

	/**
	 * Czy zgłoszenie może zawierać osobę towarzyszącą.
	 */
	public function companionEnabled(): bool {
		return $this->companionEnabled;
	}

	/**
	 * Czy osoba towarzysząca wlicza się do globalnego limitu wydarzenia.
	 */
	public function companionCountsEvent(): bool {
		return $this->companionCountsEvent;
	}

	/**
	 * Zwraca mapę slot => limit miejsc.
	 *
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
