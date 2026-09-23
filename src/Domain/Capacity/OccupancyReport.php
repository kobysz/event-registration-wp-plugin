<?php
/**
 * Zestawienie obłożenia eventu gotowe do wyświetlenia.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Capacity;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Registration\RegistrationTypeCollection;

/**
 * Łączy obłożenie z limitami w wiersze „zajęte / z ilu / wolne".
 *
 * Czysta domena: cała arytmetyka miejsc tutaj, prezentacja (HTML, i18n)
 * w warstwie WP. Nie liczy niczego z bazy — dostaje gotowe liczby.
 */
final class OccupancyReport {

	/**
	 * Tworzy zestawienie.
	 *
	 * @param OccupancySnapshot          $taken         Obłożenie (miejsca zajęte przez pending+confirmed).
	 * @param CapacityLimits             $limits        Limity eventu.
	 * @param RegistrationTypeCollection $types         Typy zgłoszenia (etykiety + limity).
	 * @param AccommodationConfig        $accommodation Konfiguracja noclegów (inwentarz + etykiety).
	 * @param array<string,int>          $statusCounts  Liczba zgłoszeń per status.
	 * @param float                      $priceTotal    Suma kwot zgłoszeń zajmujących miejsce.
	 */
	public function __construct(
		private readonly OccupancySnapshot $taken,
		private readonly CapacityLimits $limits,
		private readonly RegistrationTypeCollection $types,
		private readonly AccommodationConfig $accommodation,
		private readonly array $statusCounts = array(),
		private readonly float $priceTotal = 0.0
	) {
	}

	/**
	 * Obłożenie globalne eventu; `limit`/`free` są null, gdy limitu nie ustawiono.
	 *
	 * @return array{taken:int,limit:int|null,free:int|null}
	 */
	public function overall(): array {
		return $this->row( $this->taken->global(), $this->limits->globalLimit );
	}

	/**
	 * Obłożenie per typ zgłoszenia, w kolejności konfiguracji.
	 *
	 * @return array<int,array{key:string,label:string,taken:int,limit:int|null,free:int|null}>
	 */
	public function types(): array {
		$rows = array();

		foreach ( $this->types->all() as $type ) {
			$rows[] = array(
				'key'   => $type->key,
				'label' => $type->label,
			) + $this->row( $this->taken->forType( $type->key ), $type->capacity );
		}

		return $rows;
	}

	/**
	 * Obłożenie per slot noclegowy (pakiet+pokój); `taken` uwzględnia miejsca
	 * osób towarzyszących, bo snapshot sumuje miejsca, nie rezerwacje.
	 *
	 * @return array<int,array{slot:string,packageLabel:string,roomLabel:string,taken:int,capacity:int,free:int}>
	 */
	public function slots(): array {
		$packages = array();

		foreach ( $this->accommodation->packages() as $package ) {
			$packages[ $package->key ] = $package->label;
		}

		$rows = array();

		foreach ( $this->accommodation->items() as $item ) {
			$slot  = $item->slotKey();
			$taken = $this->taken->forSlot( $slot );
			$room  = $this->accommodation->room( $item->roomKey );

			$rows[] = array(
				'slot'         => $slot,
				'packageLabel' => $packages[ $item->packageKey ] ?? $item->packageKey,
				'roomLabel'    => null === $room ? $item->roomKey : $room->label,
				'taken'        => $taken,
				'capacity'     => $item->capacity,
				'free'         => max( 0, $item->capacity - $taken ),
			);
		}

		return $rows;
	}

	/**
	 * Liczba osób towarzyszących wliczonych do obłożenia.
	 */
	public function companions(): int {
		return $this->taken->companions();
	}

	/**
	 * Liczba zgłoszeń o danym statusie; nieznany status → 0.
	 *
	 * @param string $status Wartość statusu zgłoszenia.
	 */
	public function statusCount( string $status ): int {
		return (int) ( $this->statusCounts[ $status ] ?? 0 );
	}

	/**
	 * Suma kwot zgłoszeń zajmujących miejsce.
	 */
	public function priceTotal(): float {
		return $this->priceTotal;
	}

	/**
	 * Składa wiersz „zajęte / limit / wolne"; brak limitu → null, wolne nigdy ujemne.
	 *
	 * @param int      $taken Zajęte miejsca.
	 * @param int|null $limit Limit miejsc albo null (bez limitu).
	 *
	 * @return array{taken:int,limit:int|null,free:int|null}
	 */
	private function row( int $taken, ?int $limit ): array {
		return array(
			'taken' => $taken,
			'limit' => $limit,
			'free'  => null === $limit ? null : max( 0, $limit - $taken ),
		);
	}
}
