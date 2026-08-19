<?php
/**
 * Endpoint potwierdzenia double opt-in.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Frontend;

use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Services\ReservationService;

defined( 'ABSPATH' ) || exit;

/**
 * Endpoint potwierdzenia double opt-in.
 */
final class ConfirmationController {

	/**
	 * Tworzy kontroler z serwisem rezerwacji obsługującym potwierdzanie tokenów.
	 *
	 * @param ReservationService $reservations Serwis rezerwacji obsługujący potwierdzanie tokenów.
	 */
	public function __construct( private readonly ReservationService $reservations ) {
	}

	/**
	 * Rejestruje obsługę potwierdzenia na hooku `template_redirect`.
	 */
	public static function register(): void {
		add_action(
			'template_redirect',
			static function (): void {
				( new self( new ReservationService( new RegistrationRepository(), new EventConfigRepository() ) ) )->handle();
			}
		);
	}

	/**
	 * Wykrywa `?evreg_confirm=token`, potwierdza zgłoszenie i wykonuje PRG redirect na `?evreg_confirmed=<kod>`.
	 */
	public function handle(): void {
		if ( ! isset( $_GET['evreg_confirm'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$token = sanitize_text_field( wp_unslash( (string) $_GET['evreg_confirm'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code  = $this->resolve( $token );

		wp_safe_redirect( add_query_arg( 'evreg_confirmed', $code, remove_query_arg( array( 'evreg_confirm' ) ) ) );
		exit;
	}

	/**
	 * Potwierdza token i zwraca kod wyniku (bez redirectu) — testowalny seam dla `handle()`.
	 *
	 * @param string $token Token potwierdzający z linku e-mail.
	 */
	public function resolve( string $token ): string {
		return $this->reservations->confirm( $token )->code;
	}

	/**
	 * Zwraca komunikat do wyświetlenia na stronie na podstawie kodu potwierdzenia.
	 *
	 * @param string $code Kod wyniku potwierdzenia.
	 */
	public static function confirmedMessage( string $code ): string {
		$map = array(
			'confirmed'         => __( 'Zgłoszenie potwierdzone. Do zobaczenia!', 'event-registration' ),
			'already_confirmed' => __( 'To zgłoszenie było już potwierdzone.', 'event-registration' ),
			'expired'           => __( 'Link potwierdzający wygasł.', 'event-registration' ),
			'waitlist'          => __( 'Jesteś na liście rezerwowej.', 'event-registration' ),
			'not_found'         => __( 'Nie znaleziono zgłoszenia dla tego linku.', 'event-registration' ),
		);

		return $map[ $code ] ?? __( 'Nieznany status potwierdzenia.', 'event-registration' );
	}
}
