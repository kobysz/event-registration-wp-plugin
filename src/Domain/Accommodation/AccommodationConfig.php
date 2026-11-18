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
	 * @param string          $companionNote         Przypis pod polem osoby towarzyszącej (pusty = brak).
	 */
	private function __construct(
		private readonly array $packages,
		private readonly array $rooms,
		private readonly array $items,
		private readonly bool $allowNone,
		private readonly bool $companionEnabled = false,
		private readonly bool $companionCountsEvent = false,
		private readonly string $companionNote = ''
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

		// Liczba osób w pokoju ma sens tylko gdy event dopuszcza osobę towarzyszącą.
		// Przy wyłączonym przełączniku SubmissionAssembler twardo wymusza companion=false,
		// więc pokój „na 2 osoby" byłby trwale niewybieralny, a companion_required
		// niespełnialne. Czytamy taką sprzeczność jak „nieustawione" — w jednym miejscu,
		// żeby pojemność, cena, walidacja i render widziały to samo.
		$companion_enabled = (bool) ( $config['companion_enabled'] ?? false );

		foreach ( (array) ( $config['rooms'] ?? array() ) as $room ) {
			$room = (array) $room;
			if ( ! $companion_enabled ) {
				$room['occupancy'] = 0;
			}
			$rooms[] = RoomType::fromArray( $room );
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
			(bool) ( $config['companion_counts_event'] ?? false ),
			(string) ( $config['companion_note'] ?? '' )
		);
	}

	/**
	 * Serializuje konfigurację do tablicy.
	 *
	 * Uwaga: zwracane `occupancy` jest wyzerowane, gdy `companion_enabled` jest wyłączone,
	 * więc wyniku NIE wolno zapisywać z powrotem jako konfiguracji. Nie niesie też
	 * `companion_note` (świadomie — żaden wołający go nie potrzebuje, a emisja zachęcałaby do
	 * zakazanego round-tripu).
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
	 * Zwraca etykietę pakietu albo surowy klucz, gdy pakiet zniknął z konfiguracji.
	 *
	 * @param string $key Klucz pakietu.
	 */
	public function packageLabel( string $key ): string {
		foreach ( $this->packages as $package ) {
			if ( $package->key === $key ) {
				return $package->label;
			}
		}

		return $key;
	}

	/**
	 * Zwraca etykietę pokoju albo surowy klucz, gdy pokój zniknął z konfiguracji.
	 *
	 * @param string $key Klucz pokoju.
	 */
	public function roomLabel( string $key ): string {
		$room = $this->room( $key );

		return null === $room ? $key : $room->label;
	}

	/**
	 * Zwraca typ pokoju o danym kluczu albo null.
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
	 * Ile miejsc zajmuje zgłoszenie w pokoju o danym kluczu.
	 * Nieznany pokój (config-drift) zachowuje się jak pokój bez ustawionej liczby osób.
	 *
	 * @param string $room_key  Klucz typu pokoju.
	 * @param bool   $companion Czy zgłoszenie zawiera osobę towarzyszącą.
	 */
	public function seatsFor( string $room_key, bool $companion ): int {
		$room = $this->room( $room_key );

		return null === $room
			? RoomOccupancy::seats( 0, $companion )
			: $room->seats( $companion );
	}

	/**
	 * Zwraca mapę slot "pakiet|pokój" => liczba osób w pokoju (0 = nieustawione).
	 *
	 * @return array<string,int>
	 */
	public function slotOccupancies(): array {
		$map = array();

		foreach ( $this->items as $item ) {
			$room                    = $this->room( $item->roomKey );
			$map[ $item->slotKey() ] = null === $room ? 0 : $room->occupancy;
		}

		return $map;
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
	 * Przypis pod polem osoby towarzyszącej (pusty = brak).
	 */
	public function companionNote(): string {
		return $this->companionNote;
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
