<?php
/**
 * Transakcyjna rezerwacja miejsc i potwierdzanie zgłoszeń.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Services;

use EvReg\Domain\Accommodation\AccommodationConfig;
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
	 * (ROLLBACK przy rejected) → insert (+booking gdy przyznano zakwaterowanie) → COMMIT.
	 * Przy dowolnym wyjątku: ROLLBACK i ponowne rzucenie.
	 *
	 * @param int                $event_id ID eventu.
	 * @param ReservationRequest $request  Dane zgłoszenia.
	 *
	 * @throws \Throwable Gdy operacja w transakcji się nie powiedzie; transakcja jest wycofywana przed ponownym rzuceniem.
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
				(bool) ( $settings['waitlist_enabled'] ?? true )
			);

			$decision = $this->calculator->decide(
				$limits,
				$this->repository->occupancy( $event_id ),
				$request->typeKey,
				$request->selection
			);

			if ( Outcome::Rejected === $decision->outcome ) {
				$wpdb->query( 'ROLLBACK' );
				return ReservationResult::rejected( (string) $decision->reason );
			}

			$is_waitlist = Outcome::Waitlisted === $decision->outcome;
			$status      = $is_waitlist ? RegistrationStatus::Waitlist : RegistrationStatus::Pending;
			$expires_at  = $is_waitlist ? null : gmdate( 'Y-m-d H:i:s', strtotime( self::PENDING_TTL, time() ) );

			$type  = $types->get( $request->typeKey );
			$price = null === $type ? 0.0 : $this->pricing->total( $type, $accommodation, $request->selection );
			$token = bin2hex( random_bytes( 16 ) );

			$id = $this->repository->insertRegistration(
				array(
					'event_id'    => $event_id,
					'type_key'    => $request->typeKey,
					'status'      => $status->value,
					'email'       => $request->email,
					'name'        => $request->name,
					'token'       => $token,
					'data'        => (string) wp_json_encode( $request->data ),
					'price_total' => $price,
					'expires_at'  => $expires_at,
				)
			);

			if ( $decision->accommodationGranted && null !== $request->selection ) {
				$item      = $accommodation->item( $request->selection->packageKey, $request->selection->roomKey );
				$acc_price = null === $item ? 0.0 : $item->price;
				$this->repository->insertAccommodationBooking( $id, $request->selection, $acc_price );
			}

			$wpdb->query( 'COMMIT' );

			if ( $is_waitlist ) {
				do_action( 'evreg_registration_waitlisted', $id, $event_id );

				return ReservationResult::waitlisted( $id, $token, (string) $decision->reason );
			}

			do_action( 'evreg_registration_reserved', $id, $event_id, $token );

			return ReservationResult::reserved( $id, $token, $decision->accommodationGranted, $decision->reason );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}
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
}
