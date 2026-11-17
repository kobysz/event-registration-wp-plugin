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
use EvReg\Domain\Capacity\CapacityLimits;
use EvReg\Domain\Capacity\NightCapacity;
use EvReg\Domain\Capacity\Outcome;
use EvReg\Domain\Pricing\PriceCalculator;
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
	 * Rezerwuje miejsce dla zgłoszenia w ramach jednej transakcji z blokadą per event.
	 *
	 * Kolejność: START → lockEvent → guard duplikatu → zajętość → decyzja →
	 * (ROLLBACK przy rejected) → insert (+booking gdy przyznano zakwaterowanie) → COMMIT →
	 * (poza try/catch) do_action cyklu życia. Przy dowolnym wyjątku wewnątrz transakcji:
	 * ROLLBACK i ponowne rzucenie. Hook odpala się strukturalnie po zamknięciu try/catch,
	 * więc rzut z nasłuchu propaguje się do wywołującego bez wpływu na już zatwierdzony wiersz.
	 *
	 * @param int                $event_id ID eventu.
	 * @param ReservationRequest $request  Dane zgłoszenia.
	 *
	 * @throws \Throwable Gdy operacja w transakcji się nie powiedzie (ROLLBACK przed ponownym rzuceniem)
	 *                    albo gdy nasłuch evreg_registration_reserved/evreg_registration_waitlisted
	 *                    rzuci po COMMIT (wiersz pozostaje zatwierdzony).
	 */
	public function reserve( int $event_id, ReservationRequest $request ): ReservationResult {
		global $wpdb;

		$config            = $this->config->get( $event_id );
		$types             = RegistrationTypeCollection::fromArray( is_array( $config['types'] ) ? $config['types'] : array() );
		$settings          = is_array( $config['settings'] ) ? $config['settings'] : array();
		$accommodation_raw = is_array( $config['accommodation'] ) ? $config['accommodation'] : array();

		// Rozgałęzienie trybu stoi na WEJŚCIU, przed AccommodationConfig::fromArray —
		// ta rzuca SchemaException na niespójnym inwentarzu, a event w trybie „doby”
		// może nieść zapomniane dane klasyczne. Gałąź dobowa nie buduje jej nigdy.
		$nights_config = NightsConfig::fromArray( $accommodation_raw );

		if ( $nights_config->isNights() ) {
			return $this->reserveNights( $event_id, $request, $nights_config, $settings, $types );
		}

		$accommodation = AccommodationConfig::fromArray( $accommodation_raw );

		$wpdb->query( 'START TRANSACTION' );

		try {
			// KRYTYCZNA KOLEJNOŚĆ: lockEvent() (SELECT ... FOR UPDATE) MUSI wykonać się
			// przed pierwszym odczytem COUNT (duplikat/zajętość). Pod InnoDB REPEATABLE
			// READ migawka odczytu transakcji ustala się przy pierwszym spójnym odczycie —
			// blokada jako pierwsza wymusza, że zablokowany rywal ustali migawkę dopiero
			// po commicie poprzednika (widzi jego zatwierdzony wiersz). To jedyna rzecz
			// zapobiegająca zajęciu ostatniego miejsca przez dwóch rywali. NIE ZMIENIAJ KOLEJNOŚCI.
			$this->repository->lockEvent( $event_id );

			if ( $this->repository->activeRegistrationExists( $event_id, $request->email ) ) {
				$wpdb->query( 'ROLLBACK' );
				return ReservationResult::duplicate();
			}

			$limits = new CapacityLimits(
				isset( $settings['global_cap'] ) && null !== $settings['global_cap'] ? (int) $settings['global_cap'] : null,
				$types->capacities(),
				$accommodation->capacities(),
				(bool) ( $settings['waitlist_enabled'] ?? true ),
				$accommodation->companionCountsEvent(),
				$accommodation->slotOccupancies()
			);

			$decision = $this->calculator->decide(
				$limits,
				$this->repository->occupancy( $event_id ),
				$request->typeKey,
				$request->selection,
				$request->companion
			);

			if ( Outcome::Rejected === $decision->outcome ) {
				$wpdb->query( 'ROLLBACK' );
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
				$wpdb->query( 'ROLLBACK' );
				return ReservationResult::accommodationFull();
			}

			$is_waitlist = Outcome::Waitlisted === $decision->outcome;
			$status      = $is_waitlist ? RegistrationStatus::Waitlist : RegistrationStatus::Pending;
			$expires_at  = $is_waitlist ? null : gmdate( 'Y-m-d H:i:s', strtotime( self::PENDING_TTL, time() ) );

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
				)
			);

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

			$wpdb->query( 'COMMIT' );

			$result = $is_waitlist
				? ReservationResult::waitlisted( $id, $token, (string) $decision->reason )
				: ReservationResult::reserved( $id, $token, $decision->accommodationGranted, $decision->reason );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}

		// Hooki cyklu życia odpalają się celowo POZA try/catch, już po $result
		// zbudowanym wewnątrz udanej transakcji: gdyby leżały wewnątrz try, rzut
		// z nasłuchu trafiłby do (no-op) ROLLBACK powyżej i wypłynąłby jako fałszywa
		// porażka rezerwacji mimo trwale zatwierdzonego wiersza. Tutaj rzut nasłuchu
		// propaguje się do wywołującego bez cofania COMMIT — wiersz zostaje zapisany.
		if ( $is_waitlist ) {
			do_action( 'evreg_registration_waitlisted', $id, $event_id );
		} else {
			do_action( 'evreg_registration_reserved', $id, $event_id, $token );
		}

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
	 * @param int                        $event_id      ID eventu.
	 * @param ReservationRequest         $request       Dane zgłoszenia.
	 * @param NightsConfig               $nights_config Konfiguracja trybu „doby”.
	 * @param array<mixed>               $settings      Ustawienia eventu.
	 * @param RegistrationTypeCollection $types         Typy zgłoszeń eventu.
	 *
	 * @throws \RuntimeException Gdy liczba zapisanych dób nie zgadza się z liczbą żądanych
	 *                           (ROLLBACK w catch; niedoliczona doba = niedoliczone miejsca).
	 * @throws \Throwable        Gdy operacja w transakcji się nie powiedzie (ROLLBACK przed ponownym
	 *                           rzuceniem) albo gdy nasłuch rzuci po COMMIT.
	 */
	private function reserveNights(
		int $event_id,
		ReservationRequest $request,
		NightsConfig $nights_config,
		array $settings,
		RegistrationTypeCollection $types
	): ReservationResult {
		global $wpdb;

		// UWAGA: tutaj `nights === null` znaczy „nie poproszono o nocleg” i pusty zbiór jest
		// tym samym co brak wyboru — nowe zgłoszenie nie ma żadnego stanu, który mógłby na
		// tym stracić. W editAnswersNights() ten sam null czyta się INACZEJ („wybór nie
		// został podany”, więc zapisane doby zostają), bo tam pusty zbiór skasowałby wiersze
		// istniejącej rezerwacji. Asymetria jest zamierzona — nie ujednolicaj jej.
		$selection = $request->nights ?? NightSelection::fromKeys( array(), $nights_config );
		$seats     = $request->companion ? 2 : 1;

		$wpdb->query( 'START TRANSACTION' );

		try {
			// KRYTYCZNA KOLEJNOŚĆ (jak w reserve()): lockEvent() (SELECT ... FOR UPDATE) MUSI
			// wykonać się przed pierwszym odczytem zajętości — zgłoszeń ORAZ dób. Pod InnoDB
			// REPEATABLE READ migawka odczytu ustala się przy pierwszym spójnym odczycie, więc
			// blokada jako pierwsza wymusza, że zablokowany rywal zobaczy zatwierdzone miejsca
			// poprzednika. To jedyna rzecz zapobiegająca sprzedaniu ostatniego łóżka dwa razy.
			// NIE ZMIENIAJ KOLEJNOŚCI.
			$this->repository->lockEvent( $event_id );

			if ( $this->repository->activeRegistrationExists( $event_id, $request->email ) ) {
				$wpdb->query( 'ROLLBACK' );
				return ReservationResult::duplicate();
			}

			// Limit wydarzenia i limit typu rozstrzygają PRZED bramką dób, dokładnie jak dziś —
			// łącznie z regułą companion_counts_event. Doba nie jest slotem klasycznym, więc
			// limity slotów są puste, a decide() dostaje selection=null i wraca po limicie typu.
			$limits = new CapacityLimits(
				isset( $settings['global_cap'] ) && null !== $settings['global_cap'] ? (int) $settings['global_cap'] : null,
				$types->capacities(),
				array(),
				(bool) ( $settings['waitlist_enabled'] ?? true ),
				$nights_config->companionCountsEvent(),
				array()
			);

			$decision = $this->calculator->decide(
				$limits,
				$this->repository->occupancy( $event_id ),
				$request->typeKey,
				null,
				$request->companion
			);

			if ( Outcome::Rejected === $decision->outcome ) {
				$wpdb->query( 'ROLLBACK' );
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
				foreach ( $selection->keys() as $key ) {
					$night = $nights_config->night( $key );

					if ( null === $night ) {
						$wpdb->query( 'ROLLBACK' );
						return ReservationResult::accommodationFull( $key );
					}

					$rows[] = array(
						'key'   => $night->key,
						'label' => $night->label,
						'seats' => $seats,
						'price' => $night->price * $seats,
					);
				}

				// Bramka dób — po blokadzie, na świeżo policzonej zajętości dób.
				// Odmowa jest całościowa: jedna ciasna doba przepada cały wybór i NIE
				// zapisujemy ani wiersza dla doby wolnej (żadnego cichego przyjęcia części).
				$blocking = NightCapacity::blockingNight(
					$nights_config,
					$this->repository->nightOccupancy( $event_id, RegistrationStatus::occupyingValues() ),
					$selection,
					$request->companion
				);

				if ( null !== $blocking ) {
					$wpdb->query( 'ROLLBACK' );
					return ReservationResult::accommodationFull( $blocking );
				}
			}

			$status     = $is_waitlist ? RegistrationStatus::Waitlist : RegistrationStatus::Pending;
			$expires_at = $is_waitlist ? null : gmdate( 'Y-m-d H:i:s', strtotime( self::PENDING_TTL, time() ) );

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
				)
			);

			if ( $granted ) {
				// Punktem odniesienia jest liczba dób ŻĄDANYCH, nie liczba zbudowanych
				// wierszy: po odmowie nieznanej doby wyżej są równe, ale baseline z
				// $selection nie da się ściszyć pominięciem przy budowie wierszy.
				$written   = $this->repository->replaceBookingNights( $id, $rows );
				$requested = count( $selection->keys() );

				// Nieudany INSERT nie rzuca — replaceBookingNights zwraca liczbę zapisanych
				// wierszy właśnie po to, by cichy zapis częściowy nie przeszedł niezauważony.
				// Niedoliczona doba to niedoliczone miejsca, czyli dokładnie ta awaria, której
				// ta funkcja ma nie dopuścić. Rzut trafia do catch → ROLLBACK → nic nie zostaje.
				if ( $requested !== $written ) {
					throw new \RuntimeException(
						sprintf( 'Zapisano %d z %d dób zgłoszenia %d.', $written, $requested, $id )
					);
				}
			}

			$wpdb->query( 'COMMIT' );

			$result = $is_waitlist
				? ReservationResult::waitlisted( $id, $token, (string) $decision->reason )
				: ReservationResult::reserved( $id, $token, $granted, $decision->reason );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}

		// Hooki cyklu życia POZA try/catch, po COMMIT — nasłuch nigdy nie działa
		// w transakcji rezerwacji (patrz komentarz w reserve()).
		if ( $is_waitlist ) {
			do_action( 'evreg_registration_waitlisted', $id, $event_id );
		} else {
			do_action( 'evreg_registration_reserved', $id, $event_id, $token );
		}

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
		global $wpdb;

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

		$wpdb->query( 'START TRANSACTION' );

		try {
			$this->repository->markCancelled( $id );
			$this->repository->deleteAccommodationFor( $id );
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
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
	 * @param int $id ID zgłoszenia.
	 *
	 * @throws \Throwable Gdy operacja w transakcji się nie powiedzie (ROLLBACK przed ponownym rzuceniem).
	 */
	public function promoteFromWaitlist( int $id ): AdminActionResult {
		global $wpdb;

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

		$booking   = $this->repository->findAccommodationBooking( $id );
		$selection = null === $booking
			? null
			: new AccommodationSelection( (string) $booking['package_key'], (string) $booking['room_type_key'], (string) ( $booking['roommate_pref'] ?? '' ) );
		$companion = (bool) ( $row['companion'] ?? false );

		// Pokój mógł zmienić liczbę osób po zawaitlistowaniu. Promowanie zgłoszenia
		// niespójnego z nową konfiguracją dałoby seats/cenę dla innej liczby osób,
		// niż zapisano w zgłoszeniu — admin musi to rozstrzygnąć edycją odpowiedzi.
		if ( null !== $selection ) {
			$room = $accommodation->room( $selection->roomKey );
			if ( null !== $room && null !== $room->companionMismatch( $companion ) ) {
				return AdminActionResult::occupancyMismatch();
			}
		}

		$accommodation_lost = false;

		$wpdb->query( 'START TRANSACTION' );

		try {
			// KRYTYCZNA KOLEJNOŚĆ: lockEvent PRZED occupancy (jak reserve). NIE ZMIENIAJ.
			$this->repository->lockEvent( $event_id );

			$limits = new CapacityLimits(
				isset( $settings['global_cap'] ) && null !== $settings['global_cap'] ? (int) $settings['global_cap'] : null,
				$types->capacities(),
				$accommodation->capacities(),
				(bool) ( $settings['waitlist_enabled'] ?? true ),
				$accommodation->companionCountsEvent(),
				$accommodation->slotOccupancies()
			);

			$decision = $this->calculator->decide(
				$limits,
				$this->repository->occupancy( $event_id ),
				(string) $row['type_key'],
				$selection,
				$companion
			);

			if ( Outcome::Accepted !== $decision->outcome ) {
				$wpdb->query( 'ROLLBACK' );
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

			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
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
	 * dób — nie zajmuje miejsc. To przewaga nad trybem klasycznym, w którym waitlista gubi
	 * wybrany nocleg (znana luka B12): tutaj awans ma co ponownie rozstrzygnąć.
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
	 * @throws \RuntimeException Gdy liczba zapisanych dób nie zgadza się z liczbą żądanych
	 *                           (ROLLBACK w catch; niedoliczona doba = niedoliczone miejsca).
	 * @throws \Throwable        Gdy operacja w transakcji się nie powiedzie (ROLLBACK przed ponownym
	 *                           rzuceniem) albo gdy nasłuch rzuci po COMMIT.
	 */
	private function promoteFromWaitlistNights(
		int $id,
		array $row,
		NightsConfig $nights_config,
		array $config,
		RegistrationTypeCollection $types
	): AdminActionResult {
		global $wpdb;

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

		$wpdb->query( 'START TRANSACTION' );

		try {
			// KRYTYCZNA KOLEJNOŚĆ: lockEvent PRZED pierwszym odczytem zajętości — zgłoszeń
			// ORAZ dób (inwariant 3A, uzasadnienie w reserve()). NIE ZMIENIAJ KOLEJNOŚCI.
			$this->repository->lockEvent( $event_id );

			// Doba nie jest slotem klasycznym, więc limity slotów są puste, a decide() dostaje
			// selection=null i rozstrzyga po limicie wydarzenia i typu — jak reserveNights(),
			// łącznie z regułą companion_counts_event.
			$limits = new CapacityLimits(
				isset( $settings['global_cap'] ) && null !== $settings['global_cap'] ? (int) $settings['global_cap'] : null,
				$types->capacities(),
				array(),
				(bool) ( $settings['waitlist_enabled'] ?? true ),
				$nights_config->companionCountsEvent(),
				array()
			);

			$decision = $this->calculator->decide(
				$limits,
				$this->repository->occupancy( $event_id ),
				(string) $row['type_key'],
				null,
				$companion
			);

			if ( Outcome::Accepted !== $decision->outcome ) {
				$wpdb->query( 'ROLLBACK' );
				return AdminActionResult::rejected( null === $decision->reason ? null : (string) $decision->reason );
			}

			$rows = array();

			if ( $wished && ! $vanished ) {
				foreach ( $selection->keys() as $key ) {
					$night = $nights_config->night( $key );

					// Nie może być null: $selection powstał z tej samej konfiguracji.
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

				$granted = null === NightCapacity::blockingNight(
					$nights_config,
					$this->repository->nightOccupancy( $event_id, RegistrationStatus::occupyingValues(), $id ),
					$selection,
					$companion
				);
			}

			$accommodation_lost = $wished && ! $granted;

			if ( $granted ) {
				$written   = $this->repository->replaceBookingNights( $id, $rows );
				$requested = count( $selection->keys() );

				// Nieudany INSERT nie rzuca — porównanie liczby wierszy to jedyna obrona przed
				// cichym zapisem częściowym, czyli przed niedoliczonymi miejscami.
				if ( $requested !== $written ) {
					throw new \RuntimeException(
						sprintf( 'Zapisano %d z %d dób zgłoszenia %d.', $written, $requested, $id )
					);
				}
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

			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
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
	 * Edytuje odpowiedzi zgłoszenia z re-walidacją pojemności przy zmianie typu/noclegu.
	 *
	 * Statusy zajmujące miejsce (pending/confirmed): transakcja lock→occupancyExcluding→decide,
	 * twardy blok na pełny typ/nocleg (Accepted to jedyny wynik dopuszczający edycję —
	 * Waitlisted przy pełnym typie z włączoną listą rezerwową też blokuje, tak jak Rejected).
	 * Waitlist: bez bramki, status zostaje waitlist. Nieznany typeKey → invalidStatus() przed
	 * transakcją. Nie emituje hooków cyklu życia → brak maila. Cancelled nieedytowalne.
	 *
	 * @param int                $id      ID zgłoszenia.
	 * @param ReservationRequest $request Nowe dane zgłoszenia.
	 *
	 * @throws \Throwable ROLLBACK i ponowne rzucenie przy błędzie w transakcji.
	 */
	public function editAnswers( int $id, ReservationRequest $request ): AdminActionResult {
		global $wpdb;

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
			return $this->editAnswersNights( $id, $row, $status, $request, $nights_config, $settings, $types );
		}

		$accommodation = AccommodationConfig::fromArray( $accommodation_raw );
		$type          = $types->get( $request->typeKey );

		if ( null === $type ) {
			return AdminActionResult::invalidStatus();
		}

		$wpdb->query( 'START TRANSACTION' );

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
			$new_slot       = null === $request->selection ? '' : $request->selection->slotKey();
			$unchanged      = $stored_slot === $new_slot && (bool) ( $row['companion'] ?? false ) === $request->companion;
			$gate_selection = $unchanged ? null : $request->selection;

			if ( $occupies ) {
				$limits = new CapacityLimits(
					isset( $settings['global_cap'] ) && null !== $settings['global_cap'] ? (int) $settings['global_cap'] : null,
					$types->capacities(),
					$accommodation->capacities(),
					(bool) ( $settings['waitlist_enabled'] ?? true ),
					$accommodation->companionCountsEvent(),
					$accommodation->slotOccupancies()
				);

				$decision = $this->calculator->decide(
					$limits,
					$this->repository->occupancyExcluding( $event_id, $id ),
					$request->typeKey,
					$gate_selection,
					$request->companion
				);

				if ( Outcome::Accepted !== $decision->outcome ) {
					$wpdb->query( 'ROLLBACK' );
					return AdminActionResult::capacityFull();
				}
				if ( null !== $gate_selection && ! $decision->accommodationGranted ) {
					$wpdb->query( 'ROLLBACK' );
					return AdminActionResult::accommodationFull();
				}
			}

			$stored_seats = null === $stored_booking ? 1 : max( 1, (int) $stored_booking['seats'] );
			$price        = $this->pricing->total( $type, $accommodation, $request->selection, $request->companion, $unchanged ? $stored_seats : null );

			$this->repository->updateRegistration(
				$id,
				$request->typeKey,
				$request->email,
				$request->name,
				(string) wp_json_encode( $request->data ),
				$price,
				$request->companion ? 1 : 0,
				$request->companion ? $request->companionName : ''
			);

			$this->repository->deleteAccommodationBooking( $id );
			if ( null !== $request->selection ) {
				$item      = $accommodation->item( $request->selection->packageKey, $request->selection->roomKey );
				$seats     = $unchanged ? $stored_seats : $accommodation->seatsFor( $request->selection->roomKey, $request->companion );
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

			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
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
	 * @param array<mixed>               $settings      Ustawienia eventu.
	 * @param RegistrationTypeCollection $types         Typy zgłoszeń eventu.
	 *
	 * @throws \RuntimeException Gdy liczba zapisanych dób nie zgadza się z liczbą żądanych
	 *                           (ROLLBACK w catch; niedoliczona doba = niedoliczone miejsca).
	 * @throws \Throwable        Gdy operacja w transakcji się nie powiedzie (ROLLBACK przed
	 *                           ponownym rzuceniem).
	 */
	private function editAnswersNights(
		int $id,
		array $row,
		RegistrationStatus $status,
		ReservationRequest $request,
		NightsConfig $nights_config,
		array $settings,
		RegistrationTypeCollection $types
	): AdminActionResult {
		global $wpdb;

		$event_id = (int) $row['event_id'];
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
		$supplied = null !== $request->nights;
		$seats    = $request->companion ? 2 : 1;

		$wpdb->query( 'START TRANSACTION' );

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
			// zapisany klucz rozstrzygalnym, więc $vanished jest puste i rezygnacja działa
			// dokładnie tak, jak ją przypina jej test.
			//
			// Warunek `! $unchanged` nie jest ozdobą: edycja, która nie rusza ani zbioru dób,
			// ani flagi towarzyszącej, NIE DOTYKA wierszy ani ceny (niżej: cena ze snapshotów,
			// zapis wierszy pominięty), więc zniknięta doba nic tam nie traci i odmowa byłaby
			// odmową fałszywą — zablokowałaby poprawkę numeru telefonu, nie chroniąc niczego.
			$vanished = array();

			foreach ( $stored_keys as $stored_key ) {
				if ( null === $nights_config->night( $stored_key ) && ! in_array( $stored_key, $vanished, true ) ) {
					$vanished[] = $stored_key;
				}
			}

			if ( ! $unchanged && array() !== $vanished ) {
				$wpdb->query( 'ROLLBACK' );
				return AdminActionResult::accommodationGone( $vanished[0] );
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
				$limits = new CapacityLimits(
					isset( $settings['global_cap'] ) && null !== $settings['global_cap'] ? (int) $settings['global_cap'] : null,
					$types->capacities(),
					array(),
					(bool) ( $settings['waitlist_enabled'] ?? true ),
					$nights_config->companionCountsEvent(),
					array()
				);

				$decision = $this->calculator->decide(
					$limits,
					$this->repository->occupancyExcluding( $event_id, $id ),
					$request->typeKey,
					null,
					$request->companion
				);

				// Accepted to jedyny wynik dopuszczający edycję: przy pełnym typie z włączoną
				// listą rezerwową decide() zwraca Waitlisted, co też musi blokować (inaczej
				// edycja przepełniłaby pełny typ).
				if ( Outcome::Accepted !== $decision->outcome ) {
					$wpdb->query( 'ROLLBACK' );
					return AdminActionResult::capacityFull();
				}

				if ( ! $unchanged && $granted ) {
					// Budowa wierszy ze snapshotem etykiety i ceny, z konfiguracji odczytanej
					// TUTAJ, pod blokadą.
					//
					// Zwrot null jest tu już NIEOSIĄGALNY: przy wyborze podanym klucze
					// pochodzą ze znormalizowanego NightSelection, a przy niepodanym są to
					// klucze zapisane, które brama $vanished wyżej właśnie rozstrzygnęła.
					// Zostaje jako zawężenie typu (night() zwraca ?Night) i ostatni bezpiecznik
					// — nigdy jako dom reguły, żeby nie było dwóch odpowiedzi na jeden fakt.
					foreach ( $requested_keys as $key ) {
						$night = $nights_config->night( $key );

						if ( null === $night ) {
							$wpdb->query( 'ROLLBACK' );
							return AdminActionResult::accommodationGone( $key );
						}

						$rows[] = array(
							'key'   => $night->key,
							'label' => $night->label,
							'seats' => $seats,
							'price' => $night->price * $seats,
						);
					}

					// Bramka dób z wykluczeniem samego zgłoszenia — inaczej jego własne miejsca
					// blokowałyby jego własną edycję. Odmowa jest całościowa.
					$blocking = NightCapacity::blockingNight(
						$nights_config,
						$this->repository->nightOccupancy( $event_id, RegistrationStatus::occupyingValues(), $id ),
						$selection,
						$request->companion
					);

					if ( null !== $blocking ) {
						$wpdb->query( 'ROLLBACK' );
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
				(string) wp_json_encode( $this->nightAnswers( $request->data ) ),
				$price,
				$request->companion ? 1 : 0,
				$request->companion ? $request->companionName : ''
			);

			if ( ! $unchanged ) {
				if ( $granted ) {
					$written   = $this->repository->replaceBookingNights( $id, $rows );
					$requested = count( $requested_keys );

					// Nieudany INSERT nie rzuca — porównanie liczby wierszy to jedyna obrona
					// przed cichym zapisem częściowym, czyli przed niedoliczonymi miejscami.
					// Punktem odniesienia jest liczba dób ŻĄDANYCH: baseline z $rows dałby się
					// ściszyć pominięciem przy ich budowie.
					if ( $requested !== $written ) {
						throw new \RuntimeException(
							sprintf( 'Zapisano %d z %d dób zgłoszenia %d.', $written, $requested, $id )
						);
					}
				} else {
					// Rezygnacja z noclegu albo powrót na listę rezerwową: wiersze dób giną
					// razem z roszczeniem do miejsc.
					$this->repository->deleteBookingNights( $id );
				}
			}

			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
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
		global $wpdb;

		$row = $this->repository->findById( $id );

		if ( null === $row ) {
			return AdminActionResult::notFound();
		}

		if ( RegistrationStatus::Cancelled->value !== $row['status'] ) {
			return AdminActionResult::invalidStatus();
		}

		$wpdb->query( 'START TRANSACTION' );

		try {
			$this->repository->deleteAccommodationFor( $id );
			$this->repository->deleteMailQueueByRegistration( $id );
			$this->repository->hardDelete( $id );
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}

		return AdminActionResult::deleted();
	}
}
