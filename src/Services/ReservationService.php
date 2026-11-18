<?php
/**
 * Transakcyjna rezerwacja miejsc i potwierdzanie zgłoszeń.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Services;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Domain\Accommodation\NightSelection;
use EvReg\Domain\Accommodation\NightsConfig;
use EvReg\Domain\Capacity\CapacityCalculator;
use EvReg\Domain\Capacity\CapacityDecision;
use EvReg\Domain\Capacity\CapacityLimits;
use EvReg\Domain\Capacity\NightCapacity;
use EvReg\Domain\Capacity\Outcome;
use EvReg\Domain\Pricing\PriceCalculator;
use EvReg\Domain\Registration\ManualEntry;
use EvReg\Domain\Registration\RegistrationStatus;
use EvReg\Domain\Registration\RegistrationTypeCollection;
use EvReg\Domain\Schema\FieldType;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\RegistrationRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Transakcyjna rezerwacja miejsc i potwierdzanie zgłoszeń.
 */
final class ReservationService {

	private const PENDING_TTL = '+48 hours';

	/**
	 * Kalkulator decyzji pojemnościowych.
	 *
	 * @var CapacityCalculator
	 */
	private CapacityCalculator $calculator;

	/**
	 * Kalkulator ceny zgłoszenia.
	 *
	 * @var PriceCalculator
	 */
	private PriceCalculator $pricing;

	/**
	 * Tworzy serwis rezerwacji.
	 *
	 * @param RegistrationRepository $repository Adapter zapisu/odczytu zgłoszeń.
	 * @param EventConfigRepository  $config     Adapter odczytu konfiguracji eventu.
	 */
	public function __construct(
		private readonly RegistrationRepository $repository,
		private readonly EventConfigRepository $config
	) {
		$this->calculator = new CapacityCalculator();
		$this->pricing    = new PriceCalculator();
	}

	/**
	 * Składa limity pojemności ze wspólnych ustawień eventu i części zależnej od trybu.
	 *
	 * JEDYNE miejsce czytające `global_cap` i `waitlist_enabled`: sześć ścieżek
	 * transakcyjnych budowało ten obiekt osobno, a rozjazd między kopiami już raz
	 * kosztował błąd (B5 — jedną ścieżkę poprawiono, pozostałych nie). Część zależną
	 * od trybu wnoszą classicLimits()/nightsLimits(), bo tylko one wiedzą, skąd biorą
	 * się limity slotów i reguła companion_counts_event.
	 *
	 * @param array<mixed>               $settings               Ustawienia eventu.
	 * @param RegistrationTypeCollection $types                  Typy zgłoszeń eventu.
	 * @param array<string,int>          $slot_caps              Limity slotów „pakiet|pokój”.
	 * @param bool                       $companion_counts_event Czy towarzysząca wlicza się do limitu wydarzenia.
	 * @param array<string,int>          $slot_seats             Liczba osób w pokoju per slot.
	 */
	private function limits(
		array $settings,
		RegistrationTypeCollection $types,
		array $slot_caps,
		bool $companion_counts_event,
		array $slot_seats
	): CapacityLimits {
		return new CapacityLimits(
			isset( $settings['global_cap'] ) && null !== $settings['global_cap'] ? (int) $settings['global_cap'] : null,
			$types->capacities(),
			$slot_caps,
			(bool) ( $settings['waitlist_enabled'] ?? true ),
			$companion_counts_event,
			$slot_seats
		);
	}

	/**
	 * Limity trybu klasycznego: limity slotów i liczby osób w pokoju z inwentarza.
	 *
	 * @param array<mixed>               $settings      Ustawienia eventu.
	 * @param RegistrationTypeCollection $types         Typy zgłoszeń eventu.
	 * @param AccommodationConfig        $accommodation Inwentarz noclegowy eventu.
	 */
	private function classicLimits(
		array $settings,
		RegistrationTypeCollection $types,
		AccommodationConfig $accommodation
	): CapacityLimits {
		return $this->limits(
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
	 * @param array<mixed>               $settings      Ustawienia eventu.
	 * @param RegistrationTypeCollection $types         Typy zgłoszeń eventu.
	 * @param NightsConfig               $nights_config Konfiguracja trybu „doby”.
	 */
	private function nightsLimits(
		array $settings,
		RegistrationTypeCollection $types,
		NightsConfig $nights_config
	): CapacityLimits {
		return $this->limits( $settings, $types, array(), $nights_config->companionCountsEvent(), array() );
	}

	/**
	 * Rozstrzyga pojemność na zajętości zgłoszeń odczytanej TUTAJ — tylko POD blokadą eventu.
	 *
	 * WYMÓG WYWOŁANIA, nie ozdoba nazwy: `lockEvent( $event_id )` musi być już wykonane
	 * w tej samej transakcji. To tutaj pada odczyt zajętości, a inwariant 3A (InnoDB
	 * REPEATABLE READ ustala migawkę przy pierwszym spójnym odczycie) wymaga, by blokada
	 * stała PRZED nim — inaczej dwóch rywali zajmie to samo ostatnie miejsce.
	 *
	 * Metoda świadomie NIE bierze blokady sama: ścieżki różnią się tym, co robią między
	 * blokadą a tym odczytem (guard duplikatu w reserve(), odczyt zapisanego bookingu
	 * w editAnswers(), warunek `$occupies`), a blokada schowana tutaj zniknęłaby
	 * z widoku ścieżek, które mają ją pokazywać wprost.
	 *
	 * @param CapacityLimits              $limits    Limity pojemności eventu.
	 * @param int                         $event_id  ID eventu.
	 * @param int|null                    $exclude   ID zgłoszenia pomijanego w zajętości (edycja siebie); null = licz wszystkie.
	 * @param string                      $type_key  Klucz typu zgłoszenia.
	 * @param AccommodationSelection|null $selection Wybór noclegu dla bramki slotowej; null = bez niej.
	 * @param bool                        $companion Czy zgłoszenie obejmuje osobę towarzyszącą.
	 */
	private function decideUnderLock(
		CapacityLimits $limits,
		int $event_id,
		?int $exclude,
		string $type_key,
		?AccommodationSelection $selection,
		bool $companion
	): CapacityDecision {
		$taken = null === $exclude
			? $this->repository->occupancy( $event_id )
			: $this->repository->occupancyExcluding( $event_id, $exclude );

		return $this->calculator->decide( $limits, $taken, $type_key, $selection, $companion );
	}

	/**
	 * Klucz pierwszej doby bez miejsca albo null — na zajętości dób odczytanej TUTAJ.
	 *
	 * Ten sam kontrakt co decideUnderLock() i z tego samego powodu: wołać WYŁĄCZNIE po
	 * `lockEvent( $event_id )` w tej samej transakcji. Odmowa jest całościowa (jedna
	 * ciasna doba przepada cały wybór) — rozstrzyga o tym NightCapacity, nie wołający.
	 *
	 * @param NightsConfig   $nights_config Konfiguracja trybu „doby”.
	 * @param int            $event_id      ID eventu.
	 * @param int|null       $exclude       ID zgłoszenia pomijanego w zajętości dób; null = licz wszystkie.
	 * @param NightSelection $selection     Wybrane doby.
	 * @param bool           $companion     Czy zgłoszenie obejmuje osobę towarzyszącą.
	 */
	private function blockingNightUnderLock(
		NightsConfig $nights_config,
		int $event_id,
		?int $exclude,
		NightSelection $selection,
		bool $companion
	): ?string {
		return NightCapacity::blockingNight(
			$nights_config,
			$this->repository->nightOccupancy( $event_id, RegistrationStatus::occupyingValues(), $exclude ?? 0 ),
			$selection,
			$companion
		);
	}

	/**
	 * Pierwszy klucz doby, którego konfiguracja eventu już nie zna (null = wszystkie znane).
	 *
	 * JEDEN dom pytania „czy ta doba jeszcze istnieje”. Odpowiedź na nie mają ścieżki
	 * RÓŻNĄ (rezerwacja odmawia accommodationFull, edycja accommodationGone, awans
	 * zdejmuje nocleg i melduje utratę), więc metoda tylko nazywa dobę i nie rozstrzyga
	 * za wołającego. Pytać trzeba o konfigurację odczytaną pod blokadą — admin mógł ją
	 * zmienić między renderem a submitem.
	 *
	 * @param NightsConfig $nights_config Konfiguracja trybu „doby”.
	 * @param string[]     $keys          Klucze dób do sprawdzenia, w kolejności wyboru.
	 */
	private function unknownNight( NightsConfig $nights_config, array $keys ): ?string {
		foreach ( $keys as $key ) {
			if ( null === $nights_config->night( $key ) ) {
				return $key;
			}
		}

		return null;
	}

	/**
	 * Wiersze dób do zapisu, ze SNAPSHOTEM etykiety i ceny z podanej konfiguracji.
	 *
	 * `label` i `price` w wierszu są snapshotami: niosą stan konfiguracji z chwili zapisu,
	 * żeby zmiana nazwy albo ceny doby nie przepisywała historii ani nie przeliczała
	 * zajętości. Dlatego pobiera je ta jedna metoda, w chwili zapisu, i nikt nie wyprowadza
	 * ich z konfiguracji ponownie później.
	 *
	 * Klucz nieznany konfiguracji jest POMIJANY — o odmowie rozstrzyga wołający pytaniem
	 * unknownNight() przed zapisem, bo tylko on wie, jakim kodem odmówić. Ostatnią siatką
	 * jest i tak porównanie liczby dób ŻĄDANYCH z liczbą zapisanych u wołającego: wiersz
	 * pominięty tutaj nie przejdzie po cichu jako niedoliczone miejsca.
	 *
	 * @param NightsConfig $nights_config Konfiguracja trybu „doby”.
	 * @param string[]     $keys          Klucze dób do zapisania, w kolejności wyboru.
	 * @param int          $seats         Miejsca zajmowane w każdej dobie (2 z osobą towarzyszącą).
	 *
	 * @return array<int,array{key:string,label:string,seats:int,price:float}>
	 */
	private function nightRows( NightsConfig $nights_config, array $keys, int $seats ): array {
		$rows = array();

		foreach ( $keys as $key ) {
			$night = $nights_config->night( $key );

			if ( null === $night ) {
				continue;
			}

			$rows[] = array(
				'key'   => $night->key,
				'label' => $night->label,
				'seats' => $seats,
				'price' => $night->price * $seats,
			);
		}

		return $rows;
	}

	/**
	 * Zapisuje komplet wierszy dób i rzuca, gdy baza przyjęła mniej, niż zażądano.
	 *
	 * Nieudany INSERT nie rzuca — replaceBookingNights() zwraca liczbę zapisanych wierszy
	 * właśnie po to, by cichy zapis częściowy nie przeszedł niezauważony. Niedoliczona doba
	 * to niedoliczone miejsca, czyli dokładnie ta awaria, której tryb „doby” ma nie dopuścić.
	 * Rzut trafia do catch wołającego → ROLLBACK → nic nie zostaje.
	 *
	 * Punktem odniesienia jest liczba dób ŻĄDANYCH, podawana przez wołającego — nie liczba
	 * zbudowanych wierszy, bo tę dałoby się ściszyć pominięciem przy ich budowie.
	 *
	 * @param int                                                             $id        ID zgłoszenia.
	 * @param array<int,array{key:string,label:string,seats:int,price:float}> $rows      Wiersze dób do zapisu.
	 * @param int                                                             $requested Liczba dób ŻĄDANYCH.
	 *
	 * @throws \RuntimeException Gdy liczba zapisanych wierszy nie zgadza się z żądaną.
	 */
	private function writeNightRows( int $id, array $rows, int $requested ): void {
		$written = $this->repository->replaceBookingNights( $id, $rows );

		if ( $requested !== $written ) {
			throw new \RuntimeException(
				sprintf( 'Zapisano %d z %d dób zgłoszenia %d.', $written, $requested, $id )
			);
		}
	}

	/**
	 * Stempluje `confirmed_at` wpisu ręcznego przyjętego — w TEJ SAMEJ, otwartej transakcji.
	 *
	 * WYMÓG WYWOŁANIA: zaraz po insertRegistration(), przed COMMIT, żeby wiersz nigdy nie był
	 * widoczny jako potwierdzony bez daty potwierdzenia. Stempel idzie przez markConfirmed(),
	 * bo to JEDYNE miejsce znające konwencję „potwierdzone = status + confirmed_at + updated_at”;
	 * dopisanie kolumny do insertRegistration() rozdzieliłoby tę wiedzę na dwa miejsca.
	 *
	 * Lista rezerwowa i ścieżka publiczna nie dostają stempla — tam nic nie jest potwierdzone.
	 *
	 * @param int              $id          ID zapisanego zgłoszenia.
	 * @param bool             $is_waitlist Czy zgłoszenie trafiło na listę rezerwową.
	 * @param ManualEntry|null $manual      Znacznik wpisu ręcznego; null = ścieżka publiczna.
	 */
	private function stampConfirmedUnderTransaction( int $id, bool $is_waitlist, ?ManualEntry $manual ): void {
		if ( $is_waitlist || null === $manual ) {
			return;
		}

		$this->repository->markConfirmed( $id );
	}

	/**
	 * Status zapisywanego zgłoszenia: lista rezerwowa, wpis ręczny potwierdzony, albo oczekujący.
	 *
	 * Jedna kopia dla obu gałęzi trybów noclegu, z tego samego powodu co announceAfterCommit():
	 * rozjazd znaczyłby wpis ręczny, który w jednym trybie wchodzi potwierdzony, a w drugim
	 * czeka na link, którego nikt nie dostanie.
	 *
	 * @param bool             $is_waitlist Czy zgłoszenie trafiło na listę rezerwową.
	 * @param ManualEntry|null $manual      Znacznik wpisu ręcznego; null = ścieżka publiczna.
	 */
	private function statusFor( bool $is_waitlist, ?ManualEntry $manual ): RegistrationStatus {
		if ( $is_waitlist ) {
			return RegistrationStatus::Waitlist;
		}

		return null === $manual ? RegistrationStatus::Pending : RegistrationStatus::Confirmed;
	}

	/**
	 * Ogłasza wynik rezerwacji hookiem cyklu życia — tylko PO COMMIT, poza try/catch.
	 *
	 * WYMÓG WYWOŁANIA, jak sufiks *UnderLock przy odczytach: wołać dopiero po zamknięciu
	 * try/catch udanej transakcji. Gdyby to szło wewnątrz try, rzut z nasłuchu trafiłby do
	 * (no-op) ROLLBACK i wypłynął jako fałszywa porażka rezerwacji mimo trwale
	 * zatwierdzonego wiersza (patrz komentarze w reserve()/reserveNights()).
	 *
	 * Rozstrzygnięcie, KTÓRY hook, siedzi tu w jednej kopii, bo obie gałęzie trybów muszą
	 * ogłaszać identycznie — a rozjazd między kopiami tego wyboru znaczyłby mail z linkiem
	 * potwierdzającym wysłany na wiersz już potwierdzony (albo brak maila, o który poproszono).
	 *
	 * Wpis ręczny przyjęty ogłasza evreg_registration_confirmed, NIE ...reserved: szablon
	 * opt-in niesie link potwierdzający, a wiersz wpisu ręcznego jest już potwierdzony, więc
	 * link prowadziłby w nikąd. Lista rezerwowa zostaje przy swoim hooku — tam nic nie jest
	 * potwierdzone i mail mówi dokładnie to, co powinien. Przy notify === false nie leci nic:
	 * organizator wpisuje ludzi, którzy o rejestracji wiedzą z rozmowy, nie z maila.
	 *
	 * @param bool             $is_waitlist Czy zgłoszenie trafiło na listę rezerwową.
	 * @param ManualEntry|null $manual      Znacznik wpisu ręcznego; null = ścieżka publiczna.
	 * @param int              $id          ID zapisanego zgłoszenia.
	 * @param int              $event_id    ID eventu.
	 * @param string           $token       Token zgłoszenia (link potwierdzający opt-in).
	 */
	private function announceAfterCommit( bool $is_waitlist, ?ManualEntry $manual, int $id, int $event_id, string $token ): void {
		if ( $is_waitlist ) {
			if ( null === $manual || $manual->notify ) {
				do_action( 'evreg_registration_waitlisted', $id, $event_id );
			}

			return;
		}

		if ( null === $manual ) {
			do_action( 'evreg_registration_reserved', $id, $event_id, $token );

			return;
		}

		if ( $manual->notify ) {
			do_action( 'evreg_registration_confirmed', $id, $event_id );
		}
	}

	/**
	 * Rezerwuje miejsce dla zgłoszenia w ramach jednej transakcji z blokadą per event.
	 *
	 * Kolejność: START → lockEvent → guard duplikatu → zajętość → decyzja →
	 * (ROLLBACK przy rejected) → insert (+booking gdy przyznano zakwaterowanie) → COMMIT →
	 * (poza try/catch) do_action cyklu życia. Przy dowolnym wyjątku wewnątrz transakcji:
	 * ROLLBACK i ponowne rzucenie. Hook odpala się strukturalnie po zamknięciu try/catch,
	 * więc rzut z nasłuchu propaguje się do wywołującego bez wpływu na już zatwierdzony wiersz.
	 *
	 * Wpis ręczny (`$manual` niepuste) zmienia DOKŁADNIE pięć rzeczy: pomija guard duplikatu,
	 * wchodzi jako potwierdzony zamiast oczekującego, zostaje bez `expires_at`, zapisuje
	 * znacznik `manual` i ogłasza inny hook (patrz announceAfterCommit()). Pojemność, arytmetyka
	 * miejsc osoby towarzyszącej, bramka noclegu, wycena i kolejność blokady są nietknięte —
	 * organizator wpisuje człowieka na tych samych zasadach, na jakich wpisałby się sam.
	 *
	 * @param int                $event_id ID eventu.
	 * @param ReservationRequest $request  Dane zgłoszenia.
	 * @param ManualEntry|null   $manual   Znacznik wpisu ręcznego organizatora; null (domyślnie) =
	 *                                     ścieżka publiczna bez żadnej zmiany zachowania.
	 *
	 * @throws \Throwable Gdy operacja w transakcji się nie powiedzie (ROLLBACK przed ponownym rzuceniem)
	 *                    albo gdy nasłuch evreg_registration_reserved/evreg_registration_waitlisted/
	 *                    evreg_registration_confirmed rzuci po COMMIT (wiersz pozostaje zatwierdzony).
	 */
	public function reserve( int $event_id, ReservationRequest $request, ?ManualEntry $manual = null ): ReservationResult {
		$config            = $this->config->get( $event_id );
		$types             = RegistrationTypeCollection::fromArray( is_array( $config['types'] ) ? $config['types'] : array() );
		$settings          = is_array( $config['settings'] ) ? $config['settings'] : array();
		$accommodation_raw = is_array( $config['accommodation'] ) ? $config['accommodation'] : array();

		// Rozgałęzienie trybu stoi na WEJŚCIU, przed AccommodationConfig::fromArray —
		// ta rzuca SchemaException na niespójnym inwentarzu, a event w trybie „doby”
		// może nieść zapomniane dane klasyczne. Gałąź dobowa nie buduje jej nigdy.
		$nights_config = NightsConfig::fromArray( $accommodation_raw );

		if ( $nights_config->isNights() ) {
			// Tryb wpisu ręcznego MUSI przejść i tutaj: gałąź dobowa ma własny guard duplikatu,
			// własny status, własne `expires_at` i własne ogłoszenie, więc pominięta dałaby wpis
			// ręczny, który mimo znacznika wygasa po 48 godzinach.
			return $this->reserveNights( $event_id, $request, $nights_config, $settings, $types, $manual );
		}

		$accommodation = AccommodationConfig::fromArray( $accommodation_raw );

		$this->repository->beginTransaction();

		try {
			// KRYTYCZNA KOLEJNOŚĆ: lockEvent() (SELECT ... FOR UPDATE) MUSI wykonać się
			// przed pierwszym odczytem COUNT (duplikat/zajętość). Pod InnoDB REPEATABLE
			// READ migawka odczytu transakcji ustala się przy pierwszym spójnym odczycie —
			// blokada jako pierwsza wymusza, że zablokowany rywal ustali migawkę dopiero
			// po commicie poprzednika (widzi jego zatwierdzony wiersz). To jedyna rzecz
			// zapobiegająca zajęciu ostatniego miejsca przez dwóch rywali. NIE ZMIENIAJ KOLEJNOŚCI.
			$this->repository->lockEvent( $event_id );

			// Guard duplikatu broni formularza publicznego od przypadkowej podwójnej wysyłki.
			// Wpis ręczny pomija go BEZWARUNKOWO: organizator widzi listę zgłoszeń i ma powody
			// użyć adresu drugi raz (rodzina na jednej skrzynce), a odmowa kazałaby mu obejść
			// system zmyślonym adresem. Zgłoszeń admina nie da się wysłać przez pomyłkę dwa razy.
			if ( null === $manual && $this->repository->activeRegistrationExists( $event_id, $request->email ) ) {
				$this->repository->rollback();
				return ReservationResult::duplicate();
			}

			$limits = $this->classicLimits( $settings, $types, $accommodation );

			$decision = $this->decideUnderLock( $limits, $event_id, null, $request->typeKey, $request->selection, $request->companion );

			if ( Outcome::Rejected === $decision->outcome ) {
				$this->repository->rollback();
				return ReservationResult::rejected( (string) $decision->reason );
			}

			// Wybrany nocleg przepadł między renderem a submitem. NIE zapisujemy zgłoszenia
			// bez bookingu — to byłby cichy downgrade (człowiek dostaje potwierdzenie bez
			// łóżka). Wywołujący zamienia ten kod na błąd przy polu noclegu i re-render.
			// Warunek Accepted jest konieczny: przy pełnym limicie wydarzenia/typu decide()
			// wraca wcześniej z full(), gdzie accommodationGranted też jest false — bez
			// tego warunku zapis na listę rezerwową zamieniłby się w błąd noclegu.
			if ( Outcome::Accepted === $decision->outcome
				&& null !== $request->selection
				&& ! $decision->accommodationGranted ) {
				$this->repository->rollback();
				return ReservationResult::accommodationFull();
			}

			$is_waitlist = Outcome::Waitlisted === $decision->outcome;
			// Wpis ręczny przyjęty jest potwierdzony OD RAZU i bez terminu: uczestnik nigdy nie
			// zobaczy linku potwierdzającego, więc `pending` z terminem 48 h znaczyłoby tylko
			// tyle, że cron anuluje go po dwóch dniach. Lista rezerwowa zostaje przy swoim
			// statusie — tam nic nie jest potwierdzone, a `expires_at` ma null już dziś.
			$status     = $this->statusFor( $is_waitlist, $manual );
			$expires_at = $is_waitlist || null !== $manual ? null : gmdate( 'Y-m-d H:i:s', strtotime( self::PENDING_TTL, time() ) );

			$type = $types->get( $request->typeKey );
			// Cena noclegu tylko gdy nocleg faktycznie przyznany — przy accommodation_full
			// zgłoszenie wchodzi bez bookingu, więc nie może być obciążone (companion: bez ×2).
			$price = null === $type ? 0.0 : $this->pricing->total( $type, $accommodation, $decision->accommodationGranted ? $request->selection : null, $request->companion );
			$token = bin2hex( random_bytes( 16 ) );

			$id = $this->repository->insertRegistration(
				array(
					'event_id'       => $event_id,
					'type_key'       => $request->typeKey,
					'status'         => $status->value,
					'email'          => $request->email,
					'name'           => $request->name,
					'token'          => $token,
					'data'           => (string) wp_json_encode( $request->data ),
					'price_total'    => $price,
					'expires_at'     => $expires_at,
					'lang'           => $request->lang,
					'companion'      => $request->companion ? 1 : 0,
					'companion_name' => $request->companion ? $request->companionName : '',
					'manual'         => null !== $manual ? 1 : 0,
				)
			);

			$this->stampConfirmedUnderTransaction( $id, $is_waitlist, $manual );

			if ( $decision->accommodationGranted && null !== $request->selection ) {
				$item      = $accommodation->item( $request->selection->packageKey, $request->selection->roomKey );
				$seats     = $accommodation->seatsFor( $request->selection->roomKey, $request->companion );
				$acc_price = ( null === $item ? 0.0 : $item->price ) * $seats;
				$this->repository->insertAccommodationBooking(
					$id,
					$request->selection,
					$acc_price,
					$seats,
					$accommodation->packageLabel( $request->selection->packageKey ),
					$accommodation->roomLabel( $request->selection->roomKey )
				);
			}

			$this->repository->commit();

			$result = $is_waitlist
				? ReservationResult::waitlisted( $id, $token, (string) $decision->reason )
				: ReservationResult::reserved( $id, $token, $decision->accommodationGranted, $decision->reason );
		} catch ( \Throwable $e ) {
			$this->repository->rollback();
			throw $e;
		}

		// Hooki cyklu życia odpalają się celowo POZA try/catch, już po $result
		// zbudowanym wewnątrz udanej transakcji: gdyby leżały wewnątrz try, rzut
		// z nasłuchu trafiłby do (no-op) ROLLBACK powyżej i wypłynąłby jako fałszywa
		// porażka rezerwacji mimo trwale zatwierdzonego wiersza. Tutaj rzut nasłuchu
		// propaguje się do wywołującego bez cofania COMMIT — wiersz zostaje zapisany.
		$this->announceAfterCommit( $is_waitlist, $manual, $id, $event_id, $token );

		return $result;
	}

	/**
	 * Rezerwuje miejsca w każdej wybranej dobie (tryb „doby”).
	 *
	 * Powtarza szkielet transakcji ścieżki klasycznej — START → lockEvent → guard duplikatu →
	 * zajętość → limit globalny i typu → bramka dób → INSERT (+ doby) → COMMIT → hooki poza
	 * try/catch — podmieniając bramkę slotu na NightCapacity::blockingNight i zapis bookingu na
	 * replaceBookingNights. W trybie „doby” NIE powstaje wiersz w accommodation_bookings: nie
	 * ma pakietu ani pokoju, którym można by go wypełnić, a wiersze dób SĄ rezerwacją noclegu.
	 * Świadomie nie buduje AccommodationConfig — wspólne flagi czyta z NightsConfig.
	 *
	 * Tryb wpisu ręcznego zmienia tu dokładnie to samo co w reserve() i przez te same, wspólne
	 * metody (statusFor(), stampConfirmedUnderTransaction(), announceAfterCommit()) — pojemność
	 * dób, wycena i kolejność blokady są nietknięte.
	 *
	 * @param int                        $event_id      ID eventu.
	 * @param ReservationRequest         $request       Dane zgłoszenia.
	 * @param NightsConfig               $nights_config Konfiguracja trybu „doby”.
	 * @param array<mixed>               $settings      Ustawienia eventu.
	 * @param RegistrationTypeCollection $types         Typy zgłoszeń eventu.
	 * @param ManualEntry|null           $manual        Znacznik wpisu ręcznego organizatora;
	 *                                                  null = ścieżka publiczna bez zmian.
	 *
	 * @throws \Throwable Gdy operacja w transakcji się nie powiedzie (ROLLBACK przed ponownym
	 *                    rzuceniem), w tym \RuntimeException z writeNightRows() przy liczbie
	 *                    zapisanych dób innej niż żądana (niedoliczona doba = niedoliczone
	 *                    miejsca), albo gdy nasłuch rzuci po COMMIT.
	 */
	private function reserveNights(
		int $event_id,
		ReservationRequest $request,
		NightsConfig $nights_config,
		array $settings,
		RegistrationTypeCollection $types,
		?ManualEntry $manual
	): ReservationResult {
		// UWAGA: tutaj `nights === null` znaczy „nie poproszono o nocleg” i pusty zbiór jest
		// tym samym co brak wyboru — nowe zgłoszenie nie ma żadnego stanu, który mógłby na
		// tym stracić. W editAnswersNights() ten sam null czyta się INACZEJ („wybór nie
		// został podany”, więc zapisane doby zostają), bo tam pusty zbiór skasowałby wiersze
		// istniejącej rezerwacji. Asymetria jest zamierzona — nie ujednolicaj jej.
		$selection = $request->nights ?? NightSelection::fromKeys( array(), $nights_config );
		$seats     = $request->companion ? 2 : 1;

		$this->repository->beginTransaction();

		try {
			// KRYTYCZNA KOLEJNOŚĆ (jak w reserve()): lockEvent() (SELECT ... FOR UPDATE) MUSI
			// wykonać się przed pierwszym odczytem zajętości — zgłoszeń ORAZ dób. Pod InnoDB
			// REPEATABLE READ migawka odczytu ustala się przy pierwszym spójnym odczycie, więc
			// blokada jako pierwsza wymusza, że zablokowany rywal zobaczy zatwierdzone miejsca
			// poprzednika. To jedyna rzecz zapobiegająca sprzedaniu ostatniego łóżka dwa razy.
			// NIE ZMIENIAJ KOLEJNOŚCI.
			$this->repository->lockEvent( $event_id );

			// Guard duplikatu pomijany bezwarunkowo dla wpisu ręcznego — jak w reserve().
			if ( null === $manual && $this->repository->activeRegistrationExists( $event_id, $request->email ) ) {
				$this->repository->rollback();
				return ReservationResult::duplicate();
			}

			// Limit wydarzenia i limit typu rozstrzygają PRZED bramką dób, dokładnie jak dziś —
			// łącznie z regułą companion_counts_event (patrz nightsLimits()).
			$limits = $this->nightsLimits( $settings, $types, $nights_config );

			$decision = $this->decideUnderLock( $limits, $event_id, null, $request->typeKey, null, $request->companion );

			if ( Outcome::Rejected === $decision->outcome ) {
				$this->repository->rollback();
				return ReservationResult::rejected( (string) $decision->reason );
			}

			$is_waitlist = Outcome::Waitlisted === $decision->outcome;
			$granted     = ! $is_waitlist && ! $selection->isEmpty();
			$rows        = array();

			if ( $granted ) {
				// Rozstrzygnięcie wybranych dób względem konfiguracji odczytanej TUTAJ,
				// PRZED bramką. $selection powstał z odczytu wywołującego; gdy admin
				// wyedytuje konfigurację między renderem a submitem, wybrana doba może
				// w tym odczycie już nie istnieć. Ciche pominięcie dałoby „reserved”
				// z przyznanym noclegiem, bez wiersza i bez opłaty, choć `data` niesie
				// życzenie. Ścieżka klasyczna odrzuca nieznany slot wprost — tutaj
				// odmawiamy tak samo i nazywamy dobę, żeby wybór dał się poprawić.
				$unknown = $this->unknownNight( $nights_config, $selection->keys() );

				if ( null !== $unknown ) {
					$this->repository->rollback();
					return ReservationResult::accommodationFull( $unknown );
				}

				$rows = $this->nightRows( $nights_config, $selection->keys(), $seats );

				// Bramka dób — po blokadzie, na świeżo policzonej zajętości dób.
				// Odmowa jest całościowa: jedna ciasna doba przepada cały wybór i NIE
				// zapisujemy ani wiersza dla doby wolnej (żadnego cichego przyjęcia części).
				$blocking = $this->blockingNightUnderLock( $nights_config, $event_id, null, $selection, $request->companion );

				if ( null !== $blocking ) {
					$this->repository->rollback();
					return ReservationResult::accommodationFull( $blocking );
				}
			}

			// Status i brak terminu wpisu ręcznego — ta sama reguła co w reserve() (patrz statusFor()).
			$status     = $this->statusFor( $is_waitlist, $manual );
			$expires_at = $is_waitlist || null !== $manual ? null : gmdate( 'Y-m-d H:i:s', strtotime( self::PENDING_TTL, time() ) );

			$type = $types->get( $request->typeKey );
			// Doby liczą się do ceny tylko wtedy, gdy faktycznie przyznane — zgłoszenie
			// z listy rezerwowej nie dostaje wierszy dób, więc nie może być nimi obciążone.
			$price = null === $type
				? 0.0
				: $this->pricing->totalForNights(
					$type,
					$nights_config,
					$granted ? $selection : NightSelection::fromKeys( array(), $nights_config ),
					$seats
				);
			$token = bin2hex( random_bytes( 16 ) );

			$id = $this->repository->insertRegistration(
				array(
					'event_id'       => $event_id,
					'type_key'       => $request->typeKey,
					'status'         => $status->value,
					'email'          => $request->email,
					'name'           => $request->name,
					'token'          => $token,
					'data'           => (string) wp_json_encode( $this->nightAnswers( $request->data ) ),
					'price_total'    => $price,
					'expires_at'     => $expires_at,
					'lang'           => $request->lang,
					'companion'      => $request->companion ? 1 : 0,
					'companion_name' => $request->companion ? $request->companionName : '',
					'manual'         => null !== $manual ? 1 : 0,
				)
			);

			$this->stampConfirmedUnderTransaction( $id, $is_waitlist, $manual );

			if ( $granted ) {
				// Punktem odniesienia jest liczba dób ŻĄDANYCH, nie liczba zbudowanych
				// wierszy: po odmowie nieznanej doby wyżej są równe, ale baseline z
				// $selection nie da się ściszyć pominięciem przy budowie wierszy.
				$this->writeNightRows( $id, $rows, count( $selection->keys() ) );
			}

			$this->repository->commit();

			$result = $is_waitlist
				? ReservationResult::waitlisted( $id, $token, (string) $decision->reason )
				: ReservationResult::reserved( $id, $token, $granted, $decision->reason );
		} catch ( \Throwable $e ) {
			$this->repository->rollback();
			throw $e;
		}

		// Hooki cyklu życia POZA try/catch, po COMMIT — nasłuch nigdy nie działa
		// w transakcji rezerwacji (patrz komentarz w reserve()).
		$this->announceAfterCommit( $is_waitlist, $manual, $id, $event_id, $token );

		return $result;
	}

	/**
	 * Normalizuje odpowiedzi trybu „doby”: wybór dób zapisuje się jako PŁASKA LISTA
	 * kluczy, nigdy jako obiekt.
	 *
	 * Ścieżka klasyczna trzyma tam obiekt selekcji i kosztowało to błąd produkcyjny
	 * (ekran edycji admina czytał inne klucze, niż zapisano) — tryb „doby” tego kształtu
	 * nie dziedziczy. Normalizacja siedzi tutaj, żeby kształt zapisu nie zależał od tego,
	 * co w `data` włoży wywołujący.
	 *
	 * @param array<string,mixed> $data Odpowiedzi zgłoszenia.
	 *
	 * @return array<string,mixed>
	 */
	private function nightAnswers( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( $value instanceof NightSelection ) {
				$data[ $key ] = $value->keys();
			}
		}

		return $data;
	}

	/**
	 * Potwierdza zgłoszenie na podstawie tokenu z linku mailowego.
	 *
	 * @param string $token Token zgłoszenia.
	 */
	public function confirm( string $token ): ConfirmationResult {
		$row = $this->repository->findByToken( $token );

		if ( null === $row ) {
			return ConfirmationResult::notFound();
		}

		$status = RegistrationStatus::tryFrom( (string) $row['status'] );

		return match ( $status ) {
			RegistrationStatus::Pending   => $this->doConfirm( (int) $row['id'], (int) $row['event_id'] ),
			RegistrationStatus::Confirmed => ConfirmationResult::alreadyConfirmed(),
			RegistrationStatus::Cancelled => ConfirmationResult::expired(),
			RegistrationStatus::Waitlist  => ConfirmationResult::onWaitlist(),
			default                       => ConfirmationResult::notFound(),
		};
	}

	/**
	 * Oznacza zgłoszenie jako potwierdzone i ogłasza zdarzenie.
	 *
	 * @param int $registration_id ID zgłoszenia.
	 * @param int $event_id        ID eventu.
	 */
	private function doConfirm( int $registration_id, int $event_id ): ConfirmationResult {
		$this->repository->markConfirmed( $registration_id );

		do_action( 'evreg_registration_confirmed', $registration_id, $event_id );

		return ConfirmationResult::confirmed();
	}

	/**
	 * Ręcznie potwierdza zgłoszenie pending BEZ wysyłki maila.
	 *
	 * Nie emituje evreg_registration_confirmed — inaczej Subscriber (4A) wysłałby mail.
	 * Dla przypadków, gdy uczestnik potwierdził telefonicznie.
	 *
	 * @param int $id ID zgłoszenia.
	 */
	public function confirmManually( int $id ): AdminActionResult {
		$row = $this->repository->findById( $id );

		if ( null === $row ) {
			return AdminActionResult::notFound();
		}

		if ( RegistrationStatus::Pending->value !== $row['status'] ) {
			return AdminActionResult::invalidStatus();
		}

		$this->repository->markConfirmed( $id );

		return AdminActionResult::confirmed();
	}

	/**
	 * Anuluje zgłoszenie (miękkie): status=cancelled, kasuje nocleg, zwalnia miejsce.
	 *
	 * Anulowanie tylko zmniejsza zajętość, więc nie wymaga blokady lock→count.
	 * Nocleg zwalnia JEDNA metoda repozytorium (booking klasyczny + wiersze dób),
	 * więc anulowanie nie musi znać trybu eventu i nie da się tu skasować połowy.
	 *
	 * @param int $id ID zgłoszenia.
	 *
	 * @throws \Throwable Gdy operacja w transakcji się nie powiedzie (ROLLBACK przed ponownym rzuceniem).
	 */
	public function cancel( int $id ): AdminActionResult {
		$row = $this->repository->findById( $id );

		if ( null === $row ) {
			return AdminActionResult::notFound();
		}

		$cancellable = array(
			RegistrationStatus::Pending->value,
			RegistrationStatus::Confirmed->value,
			RegistrationStatus::Waitlist->value,
		);

		if ( ! in_array( (string) $row['status'], $cancellable, true ) ) {
			return AdminActionResult::invalidStatus();
		}

		$this->repository->beginTransaction();

		try {
			$this->repository->markCancelled( $id );
			$this->repository->deleteAccommodationFor( $id );
			$this->repository->commit();
		} catch ( \Throwable $e ) {
			$this->repository->rollback();
			throw $e;
		}

		return AdminActionResult::cancelled();
	}

	/**
	 * Awansuje zgłoszenie z listy rezerwowej na pending, jeśli jest miejsce.
	 *
	 * Powtarza inwariant lock→count z reserve(): lockEvent PRZED occupancy. Przy wolnym
	 * miejscu: waitlist→pending + nowy expires_at, emituje evreg_registration_reserved
	 * (mail opt-in z 4A). Brak miejsc: rejected. Hook emitowany po COMMIT.
	 * Companion-aware jak reserve()/editAnswers(): flaga companion czytana z wiersza,
	 * decide() dostaje ją jako 5. arg, a przyznany booking jest przebudowywany z
	 * seats=2/cena×2 (rozjazd z bookingiem zapisanym wcześniej przez editAnswers na
	 * liście rezerwowej — np. inne companion_counts_event — jest naprawiany przy promocji).
	 * Gdy nocleg NIE zostanie przyznany na promocji (accommodation_full — companion czyni
	 * to prawdopodobnym, bo żąda 2 miejsc), istniejący booking sprzed listy rezerwowej jest
	 * kasowany PRZED markPending() — inaczej zgłoszenie wchodzi w status zajmujący miejsce
	 * z "widmowym" bookingiem i slot zostaje przesprzedany (jak reserve(), które po prostu
	 * nie wstawia bookingu przy braku grantu). price_total jest przeliczane na promocji tak
	 * samo jak w reserve(): typ + (nocleg przyznany ? cena pozycji×seats : 0).
	 *
	 * Gdy bookingu NIE MA — a zgłoszenie z listy rezerwowej go nie ma, bo waitlista nie
	 * zajmuje miejsca — życzenie czytamy z odpowiedzi (`accommodationWish()`), jak robi to
	 * ścieżka dobowa (`nightWish()`). Takie życzenie NIGDY nie blokuje samego awansu: brak
	 * miejsca, slot zniknięty z inwentarza albo pokój oznaczony w międzyczasie jako dwuosobowy
	 * dają promoted_no_accommodation, bo odmowa zostawiłaby człowieka na liście rezerwowej
	 * mimo wolnego miejsca na wydarzeniu. Odmowa (occupancyMismatch) zostaje wyłącznie dla
	 * wyboru odtworzonego z ZAPISANEGO bookingu — patrz komentarz przy tej bramce. Wycena
	 * idzie po BIEŻĄCYM cenniku — ta sama reguła, co w trybie „doby”.
	 *
	 * @param int $id ID zgłoszenia.
	 *
	 * @throws \Throwable Gdy operacja w transakcji się nie powiedzie (ROLLBACK przed ponownym rzuceniem).
	 */
	public function promoteFromWaitlist( int $id ): AdminActionResult {
		$row = $this->repository->findById( $id );

		if ( null === $row ) {
			return AdminActionResult::notFound();
		}

		if ( RegistrationStatus::Waitlist->value !== $row['status'] ) {
			return AdminActionResult::invalidStatus();
		}

		$event_id          = (int) $row['event_id'];
		$config            = $this->config->get( $event_id );
		$types             = RegistrationTypeCollection::fromArray( is_array( $config['types'] ) ? $config['types'] : array() );
		$accommodation_raw = is_array( $config['accommodation'] ) ? $config['accommodation'] : array();

		// Rozgałęzienie trybu stoi PRZED AccommodationConfig::fromArray (jak w reserve()):
		// ta rzuca SchemaException na niespójnym inwentarzu, a event w trybie „doby” może
		// nieść zapomniane dane klasyczne. Gałąź dobowa nie buduje jej nigdy.
		$nights_config = NightsConfig::fromArray( $accommodation_raw );

		if ( $nights_config->isNights() ) {
			return $this->promoteFromWaitlistNights( $id, $row, $nights_config, $config, $types );
		}

		$accommodation = AccommodationConfig::fromArray( $accommodation_raw );
		$settings      = is_array( $config['settings'] ) ? $config['settings'] : array();

		// Typ usunięty z configu po zawaitlistowaniu (config-drift) → nie promuj (jak editAnswers).
		// Bez tego decide() bez limitu typu przepuściłby zgłoszenie, a wycena wyzerowałaby price_total.
		if ( null === $types->get( (string) $row['type_key'] ) ) {
			return AdminActionResult::invalidStatus();
		}

		// Odczyt bookingu stoi PRZED transakcją — inaczej niż w editAnswers(), gdzie siedzi
		// POD lockiem, i to celowo: tam rozstrzyga, czy zgłoszenie „się przesuwa”, więc
		// nieaktualny slot kazałby pominąć bramkę i wpisać z powrotem starą liczbę miejsc.
		// Tutaj nie rozstrzyga niczego poza tym, CZEGO spróbować: cokolwiek odczyt zwróci,
		// o przyznaniu decyduje dopiero decide() pod lockiem, a wiersz bookingu zapisuje się
		// w tej samej transakcji. Nieaktualność jest więc nieszkodliwa — a przeniesienie
		// odczytu pod lock nie dałoby nic ponad dłuższy lock. NIE „naprawiaj” jednego
		// z tych dwóch miejsc po to, żeby wyglądały tak samo: różnią się z powodu.
		$selection = $this->storedSelection( $this->repository->findAccommodationBooking( $id ) );
		$from_wish = false;

		// Bez bookingu życzenie mieszka tylko w odpowiedziach (B12) — i tak wygląda KAŻDE
		// zwykłe zgłoszenie z listy rezerwowej, bo waitlista nie zajmuje miejsca, więc
		// reserve() nie zapisuje mu bookingu. Bez tego odczytu awans nie miałby czego
		// przyznać: człowiek wracał do obiegu bez łóżka, mimo wolnych miejsc, a kod
		// promoted_no_accommodation nie mógł odpalić. Odczyt kolumny `data` z już pobranego
		// wiersza nie dotyka bazy, więc stoi tu, obok odczytu bookingu i przed transakcją —
		// niczego nie wkłada między lockEvent i pierwszy odczyt zajętości (jak nightWish()).
		if ( null === $selection ) {
			$selection = $this->accommodationWish( $config, (string) $row['data'] );
			$from_wish = null !== $selection;
		}

		$companion    = (bool) ( $row['companion'] ?? false );
		$wish_dropped = false;

		// Pokój mógł zmienić liczbę osób po zawaitlistowaniu. Przyznanie go takiemu
		// zgłoszeniu dałoby seats/cenę dla innej liczby osób, niż w nim zapisano (dwa łóżka
		// i podwójny rachunek dla jednej osoby), więc nie wolno go przyznać ŻADNĄ ścieżką.
		//
		// Rozstrzygnięcie rozchodzi się jednak wg POCHODZENIA wyboru, bo życzenie i przyznane
		// łóżko to różne fakty. BOOKING to łóżko, które system realnie przyznał i zapisał:
		// ciche zdjęcie niszczyłoby przydział, więc awans odmawia i rozstrzyga admin (edycją
		// odpowiedzi). ŻYCZENIE z odpowiedzi nie było nigdy przyznane: zdejmujemy je i mówimy
		// o utracie kodem promoted_no_accommodation — dokładnie jak przy pełnym slocie i jak
		// robi to ścieżka dobowa z dobą zniknięciem z konfiguracji. Odmowa zamieniłaby JEDNĄ
		// zmianę konfiguracji (oznaczenie pokoju jako dwuosobowego) w N ręcznych edycji
		// i trzymała ludzi na liście rezerwowej mimo wolnych miejsc na wydarzeniu.
		if ( null !== $selection ) {
			$room = $accommodation->room( $selection->roomKey );
			if ( null !== $room && null !== $room->companionMismatch( $companion ) ) {
				if ( ! $from_wish ) {
					return AdminActionResult::occupancyMismatch();
				}

				// Bookingu do skasowania nie ma: wybór pochodzi z życzenia WŁAŚNIE dlatego,
				// że findAccommodationBooking() nic nie zwrócił.
				$selection    = null;
				$wish_dropped = true;
			}
		}

		$accommodation_lost = $wish_dropped;

		$this->repository->beginTransaction();

		try {
			// KRYTYCZNA KOLEJNOŚĆ: lockEvent PRZED occupancy (jak reserve). NIE ZMIENIAJ.
			$this->repository->lockEvent( $event_id );

			$limits = $this->classicLimits( $settings, $types, $accommodation );

			$decision = $this->decideUnderLock( $limits, $event_id, null, (string) $row['type_key'], $selection, $companion );

			if ( Outcome::Accepted !== $decision->outcome ) {
				$this->repository->rollback();
				return AdminActionResult::rejected( null === $decision->reason ? null : (string) $decision->reason );
			}

			if ( null !== $selection ) {
				if ( $decision->accommodationGranted ) {
					$item      = $accommodation->item( $selection->packageKey, $selection->roomKey );
					$seats     = $accommodation->seatsFor( $selection->roomKey, $companion );
					$acc_price = ( null === $item ? 0.0 : $item->price ) * $seats;
					$this->repository->deleteAccommodationBooking( $id );
					$this->repository->insertAccommodationBooking(
						$id,
						$selection,
						$acc_price,
						$seats,
						$accommodation->packageLabel( $selection->packageKey ),
						$accommodation->roomLabel( $selection->roomKey )
					);
				} else {
					// Brak grantu na promocji (np. companion żąda 2 miejsc, zostało 1) —
					// skasuj nieaktualny booking sprzed listy rezerwowej. Bez tego markPending()
					// poniżej przeniesie zgłoszenie w status zajmujący miejsce z widmowym
					// bookingiem i slot zostanie przesprzedany.
					$this->repository->deleteAccommodationBooking( $id );
					$accommodation_lost = true;
				}
			}

			// Typ gwarantowany niepusty przez guard na wejściu promoteFromWaitlist.
			$type  = $types->get( (string) $row['type_key'] );
			$price = $this->pricing->total( $type, $accommodation, $decision->accommodationGranted ? $selection : null, $companion );
			$this->repository->updatePrice( $id, $price );

			$expires_at = gmdate( 'Y-m-d H:i:s', strtotime( self::PENDING_TTL, time() ) );
			$this->repository->markPending( $id, $expires_at );

			$this->repository->commit();
		} catch ( \Throwable $e ) {
			$this->repository->rollback();
			throw $e;
		}

		do_action( 'evreg_registration_reserved', $id, $event_id, (string) $row['token'] );

		return $accommodation_lost
			? AdminActionResult::promotedWithoutAccommodation()
			: AdminActionResult::promoted();
	}

	/**
	 * Awansuje zgłoszenie z listy rezerwowej w trybie „doby”.
	 *
	 * Kolejność jak w promoteFromWaitlist() i reserveNights(): START → lockEvent → zajętość
	 * zgłoszeń → limit wydarzenia i typu → bramka dób na zajętości dób z wykluczeniem samego
	 * zgłoszenia → zapis dób → cena → markPending → COMMIT → hook POZA try/catch.
	 *
	 * Życzenie dób czyta z odpowiedzi (JSON), bo zgłoszenie z listy rezerwowej NIE ma wierszy
	 * dób — nie zajmuje miejsc. Ścieżka klasyczna czyta swoje życzenie tak samo
	 * (`accommodationWish()`), odkąd B12 jest zamknięte.
	 *
	 * Nocleg NIGDY nie blokuje samego awansu: gdy doba nie ma już miejsca albo zniknęła
	 * z konfiguracji, promujemy i mówimy o utracie kodem promoted_no_accommodation — odmowa
	 * zostawiłaby człowieka na liście rezerwowej mimo wolnego miejsca na wydarzeniu. Odmowa
	 * dób jest CAŁOŚCIOWA jak w reserveNights(): jedna ciasna doba przepada cały wybór,
	 * żeby nie przyznać po cichu połowy życzenia.
	 *
	 * Świadomie nie buduje AccommodationConfig — wspólne flagi czyta z NightsConfig.
	 *
	 * @param int                        $id            ID zgłoszenia.
	 * @param array<string,mixed>        $row           Wiersz zgłoszenia.
	 * @param NightsConfig               $nights_config Konfiguracja trybu „doby”.
	 * @param array<string,array<mixed>> $config        Konfiguracja eventu (settings + schema z kluczem pola noclegu).
	 * @param RegistrationTypeCollection $types         Typy zgłoszeń eventu.
	 *
	 * @throws \Throwable Gdy operacja w transakcji się nie powiedzie (ROLLBACK przed ponownym
	 *                    rzuceniem), w tym \RuntimeException z writeNightRows() przy liczbie
	 *                    zapisanych dób innej niż żądana (niedoliczona doba = niedoliczone
	 *                    miejsca), albo gdy nasłuch rzuci po COMMIT.
	 */
	private function promoteFromWaitlistNights(
		int $id,
		array $row,
		NightsConfig $nights_config,
		array $config,
		RegistrationTypeCollection $types
	): AdminActionResult {
		$event_id = (int) $row['event_id'];
		$settings = is_array( $config['settings'] ) ? $config['settings'] : array();
		$type     = $types->get( (string) $row['type_key'] );

		// Typ usunięty z configu po zawaitlistowaniu (config-drift) → nie promuj, jak na
		// ścieżce klasycznej: decide() bez limitu typu przepuściłby zgłoszenie, a wycena
		// wyzerowałaby price_total.
		if ( null === $type ) {
			return AdminActionResult::invalidStatus();
		}

		$companion = (bool) ( $row['companion'] ?? false );
		$seats     = $companion ? 2 : 1;
		$empty     = NightSelection::fromKeys( array(), $nights_config );
		$wish      = $this->nightWish( $config, (string) $row['data'] );
		$selection = NightSelection::fromKeys( $wish, $nights_config );

		// NightSelection odfiltrowuje doby nieznane konfiguracji, więc krótszy wynik znaczy
		// „życzenie wskazywało dobę, której już nie ma". Traktujemy to jak dobę bez miejsc:
		// cały wybór przepada i awans mówi o utracie, zamiast po cichu przyznać resztę.
		$vanished = count( $wish ) !== count( $selection->keys() );
		$wished   = array() !== $wish;

		$granted            = false;
		$accommodation_lost = false;

		$this->repository->beginTransaction();

		try {
			// KRYTYCZNA KOLEJNOŚĆ: lockEvent PRZED pierwszym odczytem zajętości — zgłoszeń
			// ORAZ dób (inwariant 3A, uzasadnienie w reserve()). NIE ZMIENIAJ KOLEJNOŚCI.
			$this->repository->lockEvent( $event_id );

			// Limit wydarzenia i typu jak w reserveNights(), łącznie z regułą
			// companion_counts_event (patrz nightsLimits()).
			$limits = $this->nightsLimits( $settings, $types, $nights_config );

			$decision = $this->decideUnderLock( $limits, $event_id, null, (string) $row['type_key'], null, $companion );

			if ( Outcome::Accepted !== $decision->outcome ) {
				$this->repository->rollback();
				return AdminActionResult::rejected( null === $decision->reason ? null : (string) $decision->reason );
			}

			$rows = array();

			if ( $wished && ! $vanished ) {
				// Doba nieznana konfiguracji nie może tu wystąpić: $selection powstał
				// z tej samej konfiguracji, a nightRows() pomija ją tak jak dawna pętla.
				$rows = $this->nightRows( $nights_config, $selection->keys(), $seats );

				$granted = null === $this->blockingNightUnderLock( $nights_config, $event_id, $id, $selection, $companion );
			}

			$accommodation_lost = $wished && ! $granted;

			if ( $granted ) {
				$this->writeNightRows( $id, $rows, count( $selection->keys() ) );
			} else {
				// Bez przyznania noclegu zgłoszenie nie może wejść w status zajmujący miejsce
				// z wierszami dób sprzed awansu: markPending() poniżej uczyniłby je realną
				// zajętością i doba zostałaby przesprzedana. Ścieżka klasyczna kasuje w tym
				// miejscu widmowy booking z dokładnie tego powodu.
				$this->repository->deleteBookingNights( $id );
			}

			$this->repository->updatePrice(
				$id,
				$this->pricing->totalForNights( $type, $nights_config, $granted ? $selection : $empty, $seats )
			);

			$this->repository->markPending( $id, gmdate( 'Y-m-d H:i:s', strtotime( self::PENDING_TTL, time() ) ) );

			$this->repository->commit();
		} catch ( \Throwable $e ) {
			$this->repository->rollback();
			throw $e;
		}

		// Hook POZA try/catch, po COMMIT — nasłuch nigdy nie działa w transakcji (patrz reserve()).
		do_action( 'evreg_registration_reserved', $id, $event_id, (string) $row['token'] );

		return $accommodation_lost
			? AdminActionResult::promotedWithoutAccommodation()
			: AdminActionResult::promoted();
	}

	/**
	 * Czyta życzenie dób zapisane w odpowiedziach zgłoszenia (JSON).
	 *
	 * @param array<string,array<mixed>> $config    Konfiguracja eventu (schema niesie klucz pola noclegu).
	 * @param string                     $data_json Odpowiedzi zgłoszenia jako JSON.
	 *
	 * @return string[] Unikalne klucze dób, w kolejności zapisu.
	 */
	private function nightWish( array $config, string $data_json ): array {
		$answers = json_decode( $data_json, true );
		$key     = $this->accommodationFieldKey( is_array( $config['schema'] ) ? $config['schema'] : array() );

		if ( ! is_array( $answers ) || null === $key || ! isset( $answers[ $key ] ) ) {
			return array();
		}

		// Goły klucz traktujemy jak jednoelementowe życzenie. Zapis przez reserveNights()
		// jest zawsze listą, ale wartość zapisana inaczej (inna powierzchnia, ręczna
		// poprawka) nie może zniknąć po cichu: odczytana jako „brak życzenia” dałaby czyste
		// `promoted`, więc operator nie dowiedziałby się o utracie noclegu.
		$wish = is_array( $answers[ $key ] ) ? $answers[ $key ] : array( $answers[ $key ] );
		$keys = array();

		foreach ( $wish as $value ) {
			// Pusty ciąg to „bez noclegu”, nie klucz doby — inaczej zgłoszenie, które
			// o nocleg nie prosiło, raportowałoby utratę noclegu na awansie.
			if ( is_scalar( $value ) && '' !== (string) $value ) {
				$keys[] = (string) $value;
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Klucz pierwszego pola typu „accommodation” w SUROWEJ schemie eventu.
	 *
	 * Czyta surowe meta, nie złożoną FormSchema: SchemaAssembler waliduje każde pole
	 * i wstrzykuje opcje pola __type z typów AKTYWNYCH dziś, więc dla eventu z nieaktywnym
	 * typem rzuciłby SchemaException — a awans potrzebuje jednego klucza i nie może zależeć
	 * od tego, czy cały formularz da się dziś wyrenderować. Klucze pól są niezależne od
	 * języka (overlay i18n tłumaczy wyłącznie etykiety), więc nie ma tu czego lokalizować.
	 *
	 * @param array<mixed> $schema Surowy _evreg_schema.
	 */
	private function accommodationFieldKey( array $schema ): ?string {
		$sections = $schema['sections'] ?? array();

		if ( ! is_array( $sections ) ) {
			return null;
		}

		foreach ( $sections as $section ) {
			$fields = is_array( $section ) ? ( $section['fields'] ?? array() ) : array();

			if ( ! is_array( $fields ) ) {
				continue;
			}

			foreach ( $fields as $field ) {
				if ( ! is_array( $field ) || FieldType::Accommodation->value !== ( $field['type'] ?? '' ) ) {
					continue;
				}

				$key = $field['key'] ?? '';

				if ( is_string( $key ) && '' !== $key ) {
					return $key;
				}
			}
		}

		return null;
	}

	/**
	 * Odpowiedzi do zapisu z PRZENIESIONĄ wartością pola noclegu, gdy edycja jej nie podała.
	 *
	 * Validator zwraca wartości wyłącznie pól WIDOCZNYCH, więc pole noclegu ukryte warunkiem
	 * wypada z `data` i zapis odpowiedzi skasowałby zapisaną odpowiedź na nie. Dla zgłoszenia
	 * zajmującego miejsce księgą jest booking/wiersze dób, ale zgłoszenie z LISTY REZERWOWEJ
	 * wierszy nie ma — jego życzenie mieszka TYLKO tutaj i przepadłoby bezpowrotnie, a awans
	 * (`nightWish`, a na ścieżce klasycznej `accommodationWish`) nie miałby już czego przyznać.
	 *
	 * Przeniesiona wartość idzie VERBATIM: czytający rozumieją każdy zapisany kształt (lista
	 * kluczy, goły klucz, obiekt selekcji), a normalizacja przy okazji niezwiązanej edycji
	 * przepisywałaby historię, której ta edycja nie dotyczy.
	 *
	 * Klucz pola bierze `accommodationFieldKey()` z SUROWEJ schemy — ten sam rozstrzygacz,
	 * z którego korzysta `nightWish()`, i z tego samego powodu (patrz jego docblock).
	 *
	 * @param array<string,mixed>        $data      Odpowiedzi z żądania (bez pól niewidocznych).
	 * @param array<string,array<mixed>> $config    Konfiguracja eventu (schema niesie klucz pola noclegu).
	 * @param string                     $data_json Odpowiedzi ZAPISANE przy zgłoszeniu, jako JSON.
	 * @param bool                       $supplied  Czy edycja podała odpowiedź na pole noclegu. Obie ścieżki
	 *                                              dostają ją z `ReservationRequest::accommodationAnswered()`,
	 *                                              który trzyma rozstrzygnięcie per tryb przy polach, których
	 *                                              dotyczy (flaga w klasycznym, `nights` w dobowym).
	 *
	 * @return array<string,mixed>
	 */
	private function answersKeepingAccommodation( array $data, array $config, string $data_json, bool $supplied ): array {
		if ( $supplied ) {
			return $data;
		}

		$key = $this->accommodationFieldKey( is_array( $config['schema'] ) ? $config['schema'] : array() );

		if ( null === $key ) {
			return $data;
		}

		$stored = json_decode( $data_json, true );

		if ( ! is_array( $stored ) || ! array_key_exists( $key, $stored ) ) {
			return $data;
		}

		$data[ $key ] = $stored[ $key ];

		return $data;
	}

	/**
	 * Odtwarza wybór zakwaterowania z ZAPISANEGO bookingu (null, gdy zgłoszenie go nie ma).
	 *
	 * Odczytany wiersz jest jedynym świadectwem wyboru, którego edycja nie podała — razem
	 * z preferencją współlokatora, żeby przepisanie wiersza jej nie zgubiło.
	 *
	 * @param array<string,mixed>|null $booking Wiersz bookingu albo null.
	 */
	private function storedSelection( ?array $booking ): ?AccommodationSelection {
		if ( null === $booking ) {
			return null;
		}

		return new AccommodationSelection(
			(string) $booking['package_key'],
			(string) $booking['room_type_key'],
			(string) ( $booking['roommate_pref'] ?? '' )
		);
	}

	/**
	 * Czyta życzenie noclegu zapisane w odpowiedziach zgłoszenia (JSON), tryb klasyczny.
	 *
	 * Bliźniak `nightWish()` dla pakietów i pokoi, z tego samego powodu: zgłoszenie z LISTY
	 * REZERWOWEJ nie ma bookingu, bo waitlista nie zajmuje miejsca, więc wybór dokonany przy
	 * rezerwacji przeżywa wyłącznie tutaj. Klucz pola bierze `accommodationFieldKey()`
	 * z SUROWEJ schemy — ten sam rozstrzygacz i to samo uzasadnienie (patrz jego docblock).
	 *
	 * Oba zapisane kształty rozumie `AccommodationSelection::normalizeArray()`: żywe
	 * instalacje niosą zarówno klucze kanoniczne, jak i starsze nazwy właściwości obiektu,
	 * a czytelnik rozumiejący tylko jedne po cichu nie znalazłby życzenia w drugich.
	 *
	 * Wartość nie będąca tablicą nie jest wyborem — klasyczny wybór to zawsze para
	 * pakiet+pokój. W szczególności pusty ciąg znaczy „bez noclegu”: wzięty za życzenie
	 * kazałby awansowi raportować utratę łóżka, o które nikt nie prosił. `fromArray()`
	 * odrzuca tak samo parę niepełną.
	 *
	 * @param array<string,array<mixed>> $config    Konfiguracja eventu (schema niesie klucz pola noclegu).
	 * @param string                     $data_json Odpowiedzi zgłoszenia jako JSON.
	 */
	private function accommodationWish( array $config, string $data_json ): ?AccommodationSelection {
		$answers = json_decode( $data_json, true );
		$key     = $this->accommodationFieldKey( is_array( $config['schema'] ) ? $config['schema'] : array() );

		if ( ! is_array( $answers ) || null === $key || ! isset( $answers[ $key ] ) || ! is_array( $answers[ $key ] ) ) {
			return null;
		}

		return AccommodationSelection::fromArray( AccommodationSelection::normalizeArray( $answers[ $key ] ) );
	}

	/**
	 * Edytuje odpowiedzi zgłoszenia z re-walidacją pojemności przy zmianie typu/noclegu.
	 *
	 * Statusy zajmujące miejsce (pending/confirmed): transakcja lock→occupancyExcluding→decide,
	 * twardy blok na pełny typ/nocleg (Accepted to jedyny wynik dopuszczający edycję —
	 * Waitlisted przy pełnym typie z włączoną listą rezerwową też blokuje, tak jak Rejected).
	 * Waitlist: bez bramki, status zostaje waitlist. Nieznany typeKey → invalidStatus() przed
	 * transakcją. Nie emituje hooków cyklu życia → brak maila. Cancelled nieedytowalne.
	 *
	 * Edycja, która odpowiedzi na pole noclegu NIE PODAŁA (`accommodationSupplied === false`,
	 * czyli pole ukryte warunkiem widoczności), noclegu nie rusza: obowiązuje wybór zapisany,
	 * więc booking, jego miejsca i cena zostają. Patrz komentarz przy $selection niżej.
	 *
	 * @param int                $id      ID zgłoszenia.
	 * @param ReservationRequest $request Nowe dane zgłoszenia.
	 *
	 * @throws \Throwable ROLLBACK i ponowne rzucenie przy błędzie w transakcji.
	 */
	public function editAnswers( int $id, ReservationRequest $request ): AdminActionResult {
		$row = $this->repository->findById( $id );
		if ( null === $row ) {
			return AdminActionResult::notFound();
		}
		$status = RegistrationStatus::from( (string) $row['status'] );
		if ( RegistrationStatus::Cancelled === $status ) {
			return AdminActionResult::invalidStatus();
		}

		$event_id          = (int) $row['event_id'];
		$config            = $this->config->get( $event_id );
		$types             = RegistrationTypeCollection::fromArray( is_array( $config['types'] ) ? $config['types'] : array() );
		$accommodation_raw = is_array( $config['accommodation'] ) ? $config['accommodation'] : array();

		// Rozgałęzienie trybu stoi PRZED AccommodationConfig::fromArray (jak w reserve()):
		// ta rzuca SchemaException na niespójnym inwentarzu, a event w trybie „doby” może
		// nieść zapomniane dane klasyczne. Gałąź dobowa nie buduje jej nigdy.
		$nights_config = NightsConfig::fromArray( $accommodation_raw );
		$settings      = is_array( $config['settings'] ) ? $config['settings'] : array();

		if ( $nights_config->isNights() ) {
			return $this->editAnswersNights( $id, $row, $status, $request, $nights_config, $config, $types );
		}

		$accommodation = AccommodationConfig::fromArray( $accommodation_raw );
		$type          = $types->get( $request->typeKey );

		if ( null === $type ) {
			return AdminActionResult::invalidStatus();
		}

		$this->repository->beginTransaction();

		try {
			$occupies = in_array( $status, array( RegistrationStatus::Pending, RegistrationStatus::Confirmed ), true );

			if ( $occupies ) {
				// KRYTYCZNA KOLEJNOŚĆ: lockEvent przed occupancyExcluding (inwariant lock→count 3A)
				// ORAZ przed odczytem bookingu niżej — ten odczyt rozstrzyga, czy zgłoszenie "się
				// przesuwa", więc nieaktualny slot kazałby pominąć slotową część bramki i wpisać
				// z powrotem starą liczbę miejsc, czyli przesprzedać slot.
				$this->repository->lockEvent( $event_id );
			}

			// Zgłoszenie, które nie zmienia ani slotu, ani flagi towarzyszącej, nie "się przesuwa":
			// zostaje przy zapisanej liczbie miejsc i cenie. Inaczej późniejsze ustawienie liczby
			// osób w pokoju przeliczyłoby (i zdublowało) stare zgłoszenie przy niezwiązanej edycji.
			// Odczyt siedzi POD lockiem (gdy status zajmuje miejsce) — patrz komentarz wyżej.
			$stored_booking = $this->repository->findAccommodationBooking( $id );
			$stored_slot    = null === $stored_booking
				? ''
				: (string) $stored_booking['package_key'] . '|' . (string) $stored_booking['room_type_key'];

			// Wybór PODANY tą edycją rozstrzyga. Gdy odpowiedzi na pole noclegu NIE podano
			// (warunkowa widoczność ukryła pole, więc Validator jego wartości nie zwrócił),
			// obowiązuje wybór ZAPISANY — inaczej `selection === null` czytałoby się jak
			// świadoma rezygnacja i niszczyło nocleg pod komunikatem „zapisano” (B21).
			// To ten sam skrót, który tryb „doby” robi na `nights === null`, i dokładnie tak
			// samo NIE obejmuje flagi osoby towarzyszącej: ta zmienia zapotrzebowanie na
			// miejsca, więc nadal musi przejść bramką i przepisać wiersz — tylko już na
			// zapisanym slocie, a nie w miejsce skasowanego bookingu.
			$supplied       = $request->accommodationAnswered( nights_mode: false );
			$selection      = $supplied
				? $request->selection
				: $this->storedSelection( $stored_booking );
			$new_slot       = null === $selection ? '' : $selection->slotKey();
			$unchanged      = $stored_slot === $new_slot && (bool) ( $row['companion'] ?? false ) === $request->companion;
			$gate_selection = $unchanged ? null : $selection;

			if ( $occupies ) {
				$limits = $this->classicLimits( $settings, $types, $accommodation );

				$decision = $this->decideUnderLock( $limits, $event_id, $id, $request->typeKey, $gate_selection, $request->companion );

				if ( Outcome::Accepted !== $decision->outcome ) {
					$this->repository->rollback();
					return AdminActionResult::capacityFull();
				}
				if ( null !== $gate_selection && ! $decision->accommodationGranted ) {
					$this->repository->rollback();
					return AdminActionResult::accommodationFull();
				}
			}

			$stored_seats = null === $stored_booking ? 1 : max( 1, (int) $stored_booking['seats'] );
			$price        = $this->pricing->total( $type, $accommodation, $selection, $request->companion, $unchanged ? $stored_seats : null );

			$this->repository->updateRegistration(
				$id,
				$request->typeKey,
				$request->email,
				$request->name,
				(string) wp_json_encode(
					$this->answersKeepingAccommodation( $request->data, $config, (string) $row['data'], $supplied )
				),
				$price,
				$request->companion ? 1 : 0,
				$request->companion ? $request->companionName : ''
			);

			$this->repository->deleteAccommodationBooking( $id );
			if ( null !== $selection ) {
				$item      = $accommodation->item( $selection->packageKey, $selection->roomKey );
				$seats     = $unchanged ? $stored_seats : $accommodation->seatsFor( $selection->roomKey, $request->companion );
				$acc_price = ( null === $item ? 0.0 : $item->price ) * $seats;
				$this->repository->insertAccommodationBooking(
					$id,
					$selection,
					$acc_price,
					$seats,
					$accommodation->packageLabel( $selection->packageKey ),
					$accommodation->roomLabel( $selection->roomKey )
				);
			}

			$this->repository->commit();
		} catch ( \Throwable $e ) {
			$this->repository->rollback();
			throw $e;
		}

		// Świadomie BEZ do_action — edycja nie wysyła maila (Subscriber 4A nie kolejkuje).
		return AdminActionResult::edited();
	}

	/**
	 * Edytuje odpowiedzi zgłoszenia w trybie „doby”.
	 *
	 * Kolejność jak w editAnswers() i reserveNights(): START → (gdy status zajmuje miejsce)
	 * lockEvent → odczyt zapisanych dób POD lockiem → brama dób znikniętych z konfiguracji →
	 * limit wydarzenia i typu → bramka dób na zajętości dób z wykluczeniem samego zgłoszenia
	 * → zapis → COMMIT. BEZ hooków cyklu życia, czyli bez maila (jak confirmManually()).
	 *
	 * Doba ZAPISANA przy zgłoszeniu, której konfiguracja wydarzenia już nie zna, odrzuca
	 * edycję kodem accommodationGone() z jej kluczem — w KAŻDYM wariancie: gdy zniknęła
	 * część dób i gdy wszystkie, przy wyborze podanym i niepodanym, przy wyborze pustym
	 * i niepustym. Jedyny wyjątek to edycja nieruszająca ani zbioru dób, ani flagi
	 * towarzyszącej: ta i tak nie tyka wierszy ani ceny, więc nie ma tam czego chronić.
	 * Świadoma rezygnacja z noclegu (wybór PODANY i pusty) zostaje dozwolona, bo zostawia
	 * każdy zapisany klucz rozstrzygalnym — to inny fakt niż zniknięta doba.
	 *
	 * Edycja, która nie zmienia ani ZBIORU dób, ani flagi towarzyszącej, nie „się przesuwa”:
	 * wiersze dób, ich seats i cena dób zostają nietknięte, a bramka dób jest pomijana. Ta
	 * sama reguła, którą B-occupancy wprowadziło dla slotu, i z tego samego powodu: poprawka
	 * numeru telefonu nie może przeliczyć ceny według dzisiejszego cennika ani odbić się od
	 * bramki, która dziś by jej odmówiła. Zbiory porównujemy jako ZBIORY — kolejność wierszy
	 * (kolejność zapisu) i kolejność dób w konfiguracji mogą się rozejść bez żadnej zmiany
	 * wyboru, a przepisanie wierszy po samej zmianie kolejności skasowałoby snapshoty cen.
	 *
	 * Zgłoszenie z listy rezerwowej NIE dostaje wierszy dób (nie zajmuje miejsc) — jego
	 * życzenie mieszka w odpowiedziach i rozstrzyga je dopiero awans.
	 *
	 * Świadomie nie buduje AccommodationConfig — wspólne flagi czyta z NightsConfig.
	 *
	 * @param int                        $id            ID zgłoszenia.
	 * @param array<string,mixed>        $row           Wiersz zgłoszenia.
	 * @param RegistrationStatus         $status        Status zgłoszenia przed edycją.
	 * @param ReservationRequest         $request       Nowe dane zgłoszenia.
	 * @param NightsConfig               $nights_config Konfiguracja trybu „doby”.
	 * @param array<string,array<mixed>> $config        Konfiguracja eventu (settings + schema z kluczem pola
	 *                                                  noclegu) — jak w promoteFromWaitlistNights().
	 * @param RegistrationTypeCollection $types         Typy zgłoszeń eventu.
	 *
	 * @throws \Throwable Gdy operacja w transakcji się nie powiedzie (ROLLBACK przed ponownym
	 *                    rzuceniem), w tym \RuntimeException z writeNightRows() przy liczbie
	 *                    zapisanych dób innej niż żądana (niedoliczona doba = niedoliczone
	 *                    miejsca).
	 */
	private function editAnswersNights(
		int $id,
		array $row,
		RegistrationStatus $status,
		ReservationRequest $request,
		NightsConfig $nights_config,
		array $config,
		RegistrationTypeCollection $types
	): AdminActionResult {
		$event_id = (int) $row['event_id'];
		$settings = is_array( $config['settings'] ) ? $config['settings'] : array();
		$type     = $types->get( $request->typeKey );

		if ( null === $type ) {
			return AdminActionResult::invalidStatus();
		}

		// UWAGA: tutaj `nights === null` znaczy „wybór dób NIE został podany”, a NIE
		// „zrezygnowano z noclegu” — i to celowo INNE czytanie niż w reserveNights()
		// (patrz komentarz tam; nie ujednolicaj tego, asymetria jest tu sensem).
		// Edycja składana z formularza, który pola noclegu nie renderuje, nie niesie
		// wyboru; potraktowana jak pusty zbiór skasowałaby wszystkie wiersze dób,
		// zwolniła miejsca i obniżyła cenę, meldując „zapisano”. Świadoma rezygnacja
		// przychodzi jako PODANY, pusty NightSelection — te dwa przypadki muszą dać
		// się rozróżnić, więc zapamiętujemy, czy wybór w ogóle przyszedł.
		//
		// Ten skrót dotyczy WYŁĄCZNIE zbioru dób. Flaga osoby towarzyszącej stoi poza nim
		// (patrz $unchanged niżej) i nigdy nie przechodzi skrótem: zmienia zapotrzebowanie
		// na miejsca, więc „nocleg bez zmian” byłoby o niej kłamstwem. Flaga przychodzi
		// z checkboxa evreg_companion także wtedy, gdy formularz pola dób nie renderuje
		// (SubmissionAssembler czyta companion_enabled niezależnie od trybu), a para
		// z wierszami po jednym miejscu to doba sprzedana dwa razy.
		$supplied = $request->accommodationAnswered( nights_mode: true );
		$seats    = $request->companion ? 2 : 1;

		$this->repository->beginTransaction();

		try {
			$occupies = in_array( $status, array( RegistrationStatus::Pending, RegistrationStatus::Confirmed ), true );

			if ( $occupies ) {
				// KRYTYCZNA KOLEJNOŚĆ: lockEvent przed occupancyExcluding (inwariant lock→count
				// 3A) ORAZ przed odczytem zapisanych dób niżej — ten odczyt rozstrzyga, czy
				// zgłoszenie „się przesuwa", więc nieaktualny zbiór kazałby pominąć bramkę dób
				// i zostawić miejsca policzone dla innego zbioru. NIE ZMIENIAJ KOLEJNOŚCI.
				$this->repository->lockEvent( $event_id );
			}

			$stored      = $this->repository->bookingNightsFor( array( $id ) )[ $id ] ?? array();
			$stored_keys = array_map( static fn ( array $night ): string => $night['key'], $stored );

			// Niepodany wybór znaczy „nie dotykamy ZBIORU dób”, więc zbiorem jest to, co
			// zgłoszenie już trzyma. Tylko tak zmiana flagi towarzyszącej może przeliczyć
			// miejsca na tych samych dobach, zamiast udawać rezygnację z noclegu.
			$selection = $request->nights ?? NightSelection::fromKeys( $stored_keys, $nights_config );

			// Doby ŻĄDANE tą edycją. Przy wyborze podanym bierzemy je z żądania — to jest
			// nowy zbiór, o który operator poprosił. Przy niepodanym bierzemy klucze
			// ZAPISANE, bo $selection zbudowaliśmy sami z bieżącej konfiguracji i zniknięta
			// doba wypadłaby z niego po cichu.
			//
			// UWAGA: ten zbiór NIE JEST zbiorem do rozstrzygnięcia wobec konfiguracji i nie
			// wolno go w tej roli użyć. Doba zniknięta z konfiguracji nie ma jak się w nim
			// znaleźć przy wyborze podanym (formularz jej nie renderuje, a NightSelection
			// normalizuje ją do niebytu), a przy pustym wyborze zbiór nie powie w ogóle nic.
			// O zniknięte doby pyta się ZAPISANYCH kluczy — robi to brama $vanished niżej.
			$requested_keys = $supplied ? $selection->keys() : array_values( array_unique( $stored_keys ) );

			$sorted_stored = $stored_keys;
			$sorted_new    = $requested_keys;
			sort( $sorted_stored );
			sort( $sorted_new );

			// Skrót „nic się nie zmieniło” wymaga ZGODNEJ flagi towarzyszącej — zawsze,
			// niezależnie od tego, czy wybór dób przyszedł. Zmiana flagi to zmiana liczby
			// miejsc, więc musi przejść bramką i przepisać wiersze; w kierunku „dodaj
			// towarzyszącą” bramka ma prawo odmówić i to jest poprawne zachowanie.
			// Zbiór porównujemy tylko wtedy, gdy wybór w ogóle podano.
			$unchanged = (bool) ( $row['companion'] ?? false ) === $request->companion
				&& ( ! $supplied || $sorted_stored === $sorted_new );

			// JEDYNA brama dla doby, która ZNIKŁA z konfiguracji — stoi tu, przed podziałem
			// na „przyznane" i „bez noclegu", właśnie dlatego, że harm istnieje po OBU jego
			// stronach. Wcześniej reguła siedziała w pętli budującej wiersze, a tamta pętla
			// wymaga $granted, czyli niepustego zbioru żądanego; zgłoszenie, któremu zniknęła
			// JEDYNA doba, dostaje z wyrenderowanego formularza pusty zbiór, mijało pętlę
			// bokiem i kończyło na deleteBookingNights — wiersz, miejsce i cena ginęły pod
			// komunikatem „zaktualizowane".
			//
			// Świadoma rezygnacja i zniknięta doba to RÓŻNE FAKTY i tylko pierwszy ma prawo
			// coś zniszczyć. Rozróżnia je właśnie to, czy zapisane klucze dają się jeszcze
			// rozstrzygnąć: świadome odznaczenie dób, które nadal istnieją, zostawia każdy
			// zapisany klucz rozstrzygalnym, więc $vanished jest nullem i rezygnacja działa
			// dokładnie tak, jak ją przypina jej test.
			//
			// Warunek `! $unchanged` nie jest ozdobą: edycja, która nie rusza ani zbioru dób,
			// ani flagi towarzyszącej, NIE DOTYKA wierszy ani ceny (niżej: cena ze snapshotów,
			// zapis wierszy pominięty), więc zniknięta doba nic tam nie traci i odmowa byłaby
			// odmową fałszywą — zablokowałaby poprawkę numeru telefonu, nie chroniąc niczego.
			$vanished = $this->unknownNight( $nights_config, $stored_keys );

			if ( ! $unchanged && null !== $vanished ) {
				$this->repository->rollback();
				return AdminActionResult::accommodationGone( $vanished );
			}

			// Zgłoszenie z listy rezerwowej nie zajmuje miejsc, więc nie dostaje wierszy dób.
			//
			// Grant liczy się z dób ŻĄDANYCH, nie z tego, co przetrwało odczyt konfiguracji:
			// pusty jest tylko wybór PODANY i pusty, czyli świadoma rezygnacja. Zniknięta
			// doba nie dochodzi już tutaj w żadnym wariancie — ani częściowym, ani całkowitym
			// — bo brama wyżej odmówiła edycji po nazwie, zanim ten podział nastąpił.
			$granted = $occupies && array() !== $requested_keys;
			$rows    = array();

			if ( $occupies ) {
				$limits = $this->nightsLimits( $settings, $types, $nights_config );

				$decision = $this->decideUnderLock( $limits, $event_id, $id, $request->typeKey, null, $request->companion );

				// Accepted to jedyny wynik dopuszczający edycję: przy pełnym typie z włączoną
				// listą rezerwową decide() zwraca Waitlisted, co też musi blokować (inaczej
				// edycja przepełniłaby pełny typ).
				if ( Outcome::Accepted !== $decision->outcome ) {
					$this->repository->rollback();
					return AdminActionResult::capacityFull();
				}

				if ( ! $unchanged && $granted ) {
					// Budowa wierszy ze snapshotem etykiety i ceny, z konfiguracji odczytanej
					// TUTAJ, pod blokadą.
					//
					// Nieznana doba jest tu już NIEOSIĄGALNA: przy wyborze podanym klucze
					// pochodzą ze znormalizowanego NightSelection, a przy niepodanym są to
					// klucze zapisane, które brama $vanished wyżej właśnie rozstrzygnęła.
					// Pytanie zostaje jako ostatni bezpiecznik — nigdy jako dom reguły,
					// żeby nie było dwóch odpowiedzi na jeden fakt.
					$unknown = $this->unknownNight( $nights_config, $requested_keys );

					if ( null !== $unknown ) {
						$this->repository->rollback();
						return AdminActionResult::accommodationGone( $unknown );
					}

					$rows = $this->nightRows( $nights_config, $requested_keys, $seats );

					// Bramka dób z wykluczeniem samego zgłoszenia — inaczej jego własne miejsca
					// blokowałyby jego własną edycję. Odmowa jest całościowa.
					$blocking = $this->blockingNightUnderLock( $nights_config, $event_id, $id, $selection, $request->companion );

					if ( null !== $blocking ) {
						$this->repository->rollback();
						return AdminActionResult::accommodationFull( $blocking );
					}
				}
			}

			// Przy braku zmiany zbioru i flagi część noclegowa ceny pochodzi ze SNAPSHOTÓW
			// w wierszach dób, nie z dzisiejszego cennika (po to kolumna price w wierszu
			// istnieje: zmiana ceny doby nie przepisuje historii). Część za typ przeliczamy
			// zawsze, bo edycja mogła zmienić typ zgłoszenia. Obie formuły mieszkają
			// w PriceCalculator, żeby nie mogły się rozejść.
			$price = $unchanged
				? $this->pricing->totalFromStoredNights( $type, $stored )
				: $this->pricing->totalForNights(
					$type,
					$nights_config,
					$granted ? $selection : NightSelection::fromKeys( array(), $nights_config ),
					$seats
				);

			$this->repository->updateRegistration(
				$id,
				$request->typeKey,
				$request->email,
				$request->name,
				(string) wp_json_encode(
					$this->nightAnswers(
						$this->answersKeepingAccommodation( $request->data, $config, (string) $row['data'], $supplied )
					)
				),
				$price,
				$request->companion ? 1 : 0,
				$request->companion ? $request->companionName : ''
			);

			if ( ! $unchanged ) {
				if ( $granted ) {
					$this->writeNightRows( $id, $rows, count( $requested_keys ) );
				} else {
					// Rezygnacja z noclegu albo powrót na listę rezerwową: wiersze dób giną
					// razem z roszczeniem do miejsc.
					$this->repository->deleteBookingNights( $id );
				}
			}

			$this->repository->commit();
		} catch ( \Throwable $e ) {
			$this->repository->rollback();
			throw $e;
		}

		// Świadomie BEZ do_action — edycja nie wysyła maila (jak ścieżka klasyczna).
		return AdminActionResult::edited();
	}

	/**
	 * Trwale usuwa anulowane zgłoszenie wraz z noclegiem i osieroconymi wierszami kolejki.
	 *
	 * Nocleg kasuje ta sama metoda repozytorium co przy anulowaniu (booking klasyczny
	 * + wiersze dób). Anulowanie zwalnia je już wcześniej, więc to drugi zamek, nie
	 * jedyny: wiersze dób osierocone przez przerwane anulowanie nie przesprzedają
	 * miejsc (zapytanie liczące dołącza zgłoszenia i filtruje statusy), ale przeżyłyby
	 * zgłoszenie razem ze snapshotem `night_label`.
	 *
	 * @param int $id ID zgłoszenia.
	 *
	 * @throws \Throwable Gdy operacja w transakcji się nie powiedzie (ROLLBACK przed ponownym rzuceniem).
	 */
	public function deleteRegistration( int $id ): AdminActionResult {
		$row = $this->repository->findById( $id );

		if ( null === $row ) {
			return AdminActionResult::notFound();
		}

		if ( RegistrationStatus::Cancelled->value !== $row['status'] ) {
			return AdminActionResult::invalidStatus();
		}

		$this->repository->beginTransaction();

		try {
			$this->repository->deleteAccommodationFor( $id );
			$this->repository->deleteMailQueueByRegistration( $id );
			$this->repository->hardDelete( $id );
			$this->repository->commit();
		} catch ( \Throwable $e ) {
			$this->repository->rollback();
			throw $e;
		}

		return AdminActionResult::deleted();
	}
}
