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

			if ( $granted ) {
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

			$type  = $types->get( $request->typeKey );
			$empty = NightSelection::fromKeys( array(), $nights_config );
			// Doby liczą się do ceny tylko wtedy, gdy faktycznie przyznane — zgłoszenie
			// z listy rezerwowej nie dostaje wierszy dób, więc nie może być nimi obciążone.
			$price = null === $type
				? 0.0
				: $this->pricing->totalForNights( $type, $nights_config, $granted ? $selection : $empty, $seats );
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
				$rows = array();

				foreach ( $selection->keys() as $key ) {
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

				$written = $this->repository->replaceBookingNights( $id, $rows );

				// Nieudany INSERT nie rzuca — replaceBookingNights zwraca liczbę zapisanych
				// wierszy właśnie po to, by cichy zapis częściowy nie przeszedł niezauważony.
				// Niedoliczona doba to niedoliczone miejsca, czyli dokładnie ta awaria, której
				// ta funkcja ma nie dopuścić. Rzut trafia do catch → ROLLBACK → nic nie zostaje.
				if ( count( $rows ) !== $written ) {
					throw new \RuntimeException(
						sprintf( 'Zapisano %d z %d dób zgłoszenia %d.', $written, count( $rows ), $id )
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
			$this->repository->deleteAccommodationBooking( $id );
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

		$event_id      = (int) $row['event_id'];
		$config        = $this->config->get( $event_id );
		$types         = RegistrationTypeCollection::fromArray( is_array( $config['types'] ) ? $config['types'] : array() );
		$accommodation = AccommodationConfig::fromArray( is_array( $config['accommodation'] ) ? $config['accommodation'] : array() );
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

		$event_id      = (int) $row['event_id'];
		$config        = $this->config->get( $event_id );
		$types         = RegistrationTypeCollection::fromArray( is_array( $config['types'] ) ? $config['types'] : array() );
		$accommodation = AccommodationConfig::fromArray( is_array( $config['accommodation'] ) ? $config['accommodation'] : array() );
		$settings      = is_array( $config['settings'] ) ? $config['settings'] : array();
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
	 * Trwale usuwa anulowane zgłoszenie wraz z noclegiem i osieroconymi wierszami kolejki.
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
			$this->repository->deleteAccommodationBooking( $id );
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
