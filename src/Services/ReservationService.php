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
use EvReg\Domain\Capacity\CapacityCalculator;
use EvReg\Domain\Capacity\CapacityLimits;
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

		$config        = $this->config->get( $event_id );
		$types         = RegistrationTypeCollection::fromArray( is_array( $config['types'] ) ? $config['types'] : array() );
		$accommodation = AccommodationConfig::fromArray( is_array( $config['accommodation'] ) ? $config['accommodation'] : array() );
		$settings      = is_array( $config['settings'] ) ? $config['settings'] : array();

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
				$accommodation->companionCountsEvent()
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

			$is_waitlist = Outcome::Waitlisted === $decision->outcome;
			$status      = $is_waitlist ? RegistrationStatus::Waitlist : RegistrationStatus::Pending;
			$expires_at  = $is_waitlist ? null : gmdate( 'Y-m-d H:i:s', strtotime( self::PENDING_TTL, time() ) );

			$type  = $types->get( $request->typeKey );
			$price = null === $type ? 0.0 : $this->pricing->total( $type, $accommodation, $request->selection, $request->companion );
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
				$seats     = $request->companion ? 2 : 1;
				$acc_price = ( null === $item ? 0.0 : $item->price ) * $seats;
				$this->repository->insertAccommodationBooking( $id, $request->selection, $acc_price, $seats );
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

		$booking   = $this->repository->findAccommodationBooking( $id );
		$selection = null === $booking
			? null
			: new AccommodationSelection( (string) $booking['package_key'], (string) $booking['room_type_key'], (string) ( $booking['roommate_pref'] ?? '' ) );
		$companion = (bool) ( $row['companion'] ?? false );

		$wpdb->query( 'START TRANSACTION' );

		try {
			// KRYTYCZNA KOLEJNOŚĆ: lockEvent PRZED occupancy (jak reserve). NIE ZMIENIAJ.
			$this->repository->lockEvent( $event_id );

			$limits = new CapacityLimits(
				isset( $settings['global_cap'] ) && null !== $settings['global_cap'] ? (int) $settings['global_cap'] : null,
				$types->capacities(),
				$accommodation->capacities(),
				(bool) ( $settings['waitlist_enabled'] ?? true ),
				$accommodation->companionCountsEvent()
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

			if ( $decision->accommodationGranted && null !== $selection ) {
				$item      = $accommodation->item( $selection->packageKey, $selection->roomKey );
				$seats     = $companion ? 2 : 1;
				$acc_price = ( null === $item ? 0.0 : $item->price ) * $seats;
				$this->repository->deleteAccommodationBooking( $id );
				$this->repository->insertAccommodationBooking( $id, $selection, $acc_price, $seats );
			}

			$expires_at = gmdate( 'Y-m-d H:i:s', strtotime( self::PENDING_TTL, time() ) );
			$this->repository->markPending( $id, $expires_at );

			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}

		do_action( 'evreg_registration_reserved', $id, $event_id, (string) $row['token'] );

		return AdminActionResult::promoted();
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
				// KRYTYCZNA KOLEJNOŚĆ: lockEvent przed occupancyExcluding (inwariant lock→count 3A).
				$this->repository->lockEvent( $event_id );

				$limits = new CapacityLimits(
					isset( $settings['global_cap'] ) && null !== $settings['global_cap'] ? (int) $settings['global_cap'] : null,
					$types->capacities(),
					$accommodation->capacities(),
					(bool) ( $settings['waitlist_enabled'] ?? true ),
					$accommodation->companionCountsEvent()
				);

				$decision = $this->calculator->decide(
					$limits,
					$this->repository->occupancyExcluding( $event_id, $id ),
					$request->typeKey,
					$request->selection,
					$request->companion
				);

				if ( Outcome::Accepted !== $decision->outcome ) {
					$wpdb->query( 'ROLLBACK' );
					return AdminActionResult::capacityFull();
				}
				if ( null !== $request->selection && ! $decision->accommodationGranted ) {
					$wpdb->query( 'ROLLBACK' );
					return AdminActionResult::accommodationFull();
				}
			}

			$price = $this->pricing->total( $type, $accommodation, $request->selection, $request->companion );

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
				$seats     = $request->companion ? 2 : 1;
				$acc_price = ( null === $item ? 0.0 : $item->price ) * $seats;
				$this->repository->insertAccommodationBooking( $id, $request->selection, $acc_price, $seats );
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
