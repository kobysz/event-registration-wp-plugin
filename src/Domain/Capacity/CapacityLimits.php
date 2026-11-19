<?php
/**
 * Limity liczby miejsc dla wydarzenia.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Capacity;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Accommodation\NightsConfig;
use EvReg\Domain\Accommodation\RoomOccupancy;
use EvReg\Domain\Registration\RegistrationTypeCollection;

/**
 * Konfigurowalne limity: globalny, per typ zgłoszenia i per slot zakwaterowania.
 */
final class CapacityLimits {

	/**
	 * Tworzy zestaw limitów pojemności.
	 *
	 * @param int|null               $globalLimit Globalny limit miejsc; null = bez limitu.
	 * @param array<string,int|null> $perType     Limit per typ zgłoszenia; null = bez limitu.
	 * @param array<string,int>      $perSlot     Limit per "pakiet|pokój".
	 * @param bool                   $waitlistEnabled Czy po wyczerpaniu limitu włączyć listę rezerwową.
	 * @param bool                   $companionCountsEvent Czy osoba towarzysząca wlicza się do limitu globalnego.
	 * @param array<string,int>      $perSlotSeats Liczba osób w pokoju per slot "pakiet|pokój"; 0 = nieustawione.
	 */
	public function __construct(
		public readonly ?int $globalLimit,
		private readonly array $perType,
		private readonly array $perSlot,
		public readonly bool $waitlistEnabled = true,
		public readonly bool $companionCountsEvent = false,
		private readonly array $perSlotSeats = array()
	) {
	}

	/**
	 * Limity trybu klasycznego: limity slotów i liczby osób w pokoju z inwentarza.
	 *
	 * @param array<mixed>               $settings      Ustawienia eventu.
	 * @param RegistrationTypeCollection $types         Typy zgłoszeń eventu.
	 * @param AccommodationConfig        $accommodation Inwentarz noclegowy eventu.
	 */
	public static function forClassic(
		array $settings,
		RegistrationTypeCollection $types,
		AccommodationConfig $accommodation
	): self {
		return self::fromSettings(
			$settings,
			$types,
			$accommodation->capacities(),
			$accommodation->companionCountsEvent(),
			$accommodation->slotOccupancies()
		);
	}

	/**
	 * Limity trybu „doby”: bez limitów slotów, bo doba nie jest slotem klasycznym.
	 *
	 * Puste limity slotów i puste liczby osób w pokoju znaczą, że decide() dostaje
	 * selection=null i rozstrzyga po limicie wydarzenia i typu — pojemność dób bada
	 * osobno NightCapacity. Wspólne flagi czyta z NightsConfig, bo ścieżka dobowa
	 * NIGDY nie buduje AccommodationConfig (jego fromArray rzuca na niespójnym
	 * inwentarzu klasycznym, który event w trybie „doby” może zalegać).
	 *
	 * @param array<mixed>               $settings Ustawienia eventu.
	 * @param RegistrationTypeCollection $types    Typy zgłoszeń eventu.
	 * @param NightsConfig               $nights   Konfiguracja trybu „doby”.
	 */
	public static function forNights(
		array $settings,
		RegistrationTypeCollection $types,
		NightsConfig $nights
	): self {
		return self::fromSettings( $settings, $types, array(), $nights->companionCountsEvent(), array() );
	}

	/**
	 * Składa limity pojemności ze wspólnych ustawień eventu i części zależnej od trybu.
	 *
	 * JEDYNE miejsce czytające `global_cap` i `waitlist_enabled`: sześć ścieżek
	 * transakcyjnych budowało ten obiekt osobno, a rozjazd między kopiami już raz
	 * kosztował błąd (B5 — jedną ścieżkę poprawiono, pozostałych nie). Część zależną
	 * od trybu wnoszą forClassic()/forNights(), bo tylko one wiedzą, skąd biorą
	 * się limity slotów i reguła companion_counts_event.
	 *
	 * @param array<mixed>               $settings               Ustawienia eventu.
	 * @param RegistrationTypeCollection $types                  Typy zgłoszeń eventu.
	 * @param array<string,int>          $slot_caps              Limity slotów „pakiet|pokój”.
	 * @param bool                       $companion_counts_event Czy towarzysząca wlicza się do limitu wydarzenia.
	 * @param array<string,int>          $slot_seats             Liczba osób w pokoju per slot.
	 */
	private static function fromSettings(
		array $settings,
		RegistrationTypeCollection $types,
		array $slot_caps,
		bool $companion_counts_event,
		array $slot_seats
	): self {
		return new self(
			isset( $settings['global_cap'] ) && null !== $settings['global_cap'] ? (int) $settings['global_cap'] : null,
			$types->capacities(),
			$slot_caps,
			(bool) ( $settings['waitlist_enabled'] ?? true ),
			$companion_counts_event,
			$slot_seats
		);
	}

	/**
	 * Zwraca limit dla typu zgłoszenia lub null, gdy typ jest bez limitu.
	 *
	 * @param string $key Klucz typu zgłoszenia.
	 */
	public function forType( string $key ): ?int {
		return $this->perType[ $key ] ?? null;
	}

	/**
	 * Zwraca null, gdy slot nie istnieje w inwentarzu.
	 *
	 * @param string $key Klucz slotu "pakiet|pokój".
	 */
	public function forSlot( string $key ): ?int {
		return $this->perSlot[ $key ] ?? null;
	}

	/**
	 * Ile miejsc zajmie zgłoszenie w tym slocie. Delegacja do RoomOccupancy —
	 * reguła fallbacku ma jeden dom i nie jest tu kopiowana.
	 *
	 * @param string $key       Klucz slotu "pakiet|pokój".
	 * @param bool   $companion Czy zgłoszenie zawiera osobę towarzyszącą.
	 */
	public function seatsForSlot( string $key, bool $companion ): int {
		return RoomOccupancy::seats( $this->perSlotSeats[ $key ] ?? 0, $companion );
	}
}
