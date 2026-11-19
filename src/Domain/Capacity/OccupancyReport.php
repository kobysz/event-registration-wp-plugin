<?php
/**
 * Zestawienie obłożenia eventu gotowe do wyświetlenia.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Capacity;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Accommodation\NightsConfig;
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
	 * @param NightsConfig|null          $nights        Konfiguracja trybu „doby” rozstrzygnięta u wejścia;
	 *                                                  null = tryb klasyczny (brak wierszy dób).
	 * @param NightOccupancy|null        $nightTaken    Zajętość dób; null = zero miejsc zajętych.
	 */
	public function __construct(
		private readonly OccupancySnapshot $taken,
		private readonly CapacityLimits $limits,
		private readonly RegistrationTypeCollection $types,
		private readonly AccommodationConfig $accommodation,
		private readonly array $statusCounts = array(),
		private readonly float $priceTotal = 0.0,
		private readonly ?NightsConfig $nights = null,
		private readonly ?NightOccupancy $nightTaken = null
	) {
	}

	/**
	 * Obłożenie globalne eventu; `limit`/`free` są null, gdy limitu nie ustawiono.
	 *
	 * `taken` liczy MIEJSCA, nie zgłoszenia: przy włączonym `companion_counts_event`
	 * osoby towarzyszące doliczają się tak samo, jak robi to CapacityCalculator::decide
	 * przy przyjmowaniu zgłoszenia. Inaczej panel obiecywałby miejsca, których
	 * rejestracja już nie przyjmie — przy 100 zgłoszeniach i 47 towarzyszących
	 * pokazywał „100 z 180 (wolne: 80)", a zapisy stawały na 133 zgłoszeniach.
	 * Rozbicie na zgłoszenia i towarzyszących niesie osobny wiersz {@see self::companions()}.
	 *
	 * @return array{taken:int,limit:int|null,free:int|null}
	 */
	public function overall(): array {
		$companions = $this->limits->companionCountsEvent ? $this->taken->companions() : 0;

		return $this->row( $this->taken->global() + $companions, $this->limits->globalLimit );
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
	 * Obłożenie per doba (tryb „doby”), w kolejności konfiguracji; poza tym trybem pusta lista.
	 *
	 * Doba jest jedynym wymiarem pojemności tego trybu, więc `limit` to jej liczba miejsc,
	 * a NIE pojemność slotu klasycznego — ta jest tu bez znaczenia i {@see self::slots()}
	 * zwraca w tym trybie pustą listę (inwentarz klasyczny nie istnieje). `taken` liczy
	 * MIEJSCA (para zajmuje dwa), `pairsTaken` zgłoszenia z osobą towarzyszącą, a `pairCap`
	 * jest null, gdy doba nie ogranicza par.
	 *
	 * @return array<int,array{key:string,label:string,limit:int,taken:int,free:int,pairsTaken:int,pairCap:int|null}>
	 */
	public function nights(): array {
		if ( null === $this->nights || ! $this->nights->isNights() ) {
			return array();
		}

		$taken = $this->nightTaken;
		$rows  = array();

		foreach ( $this->nights->nights() as $night ) {
			$seats = null === $taken ? 0 : $taken->seatsFor( $night->key );

			$rows[] = array(
				'key'        => $night->key,
				'label'      => $night->label,
				'limit'      => $night->seats,
				'taken'      => $seats,
				'free'       => max( 0, $night->seats - $seats ),
				'pairsTaken' => null === $taken ? 0 : $taken->pairsFor( $night->key ),
				'pairCap'    => $night->companionCap,
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
