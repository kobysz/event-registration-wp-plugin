<?php
/**
 * Shortcode [evreg_form event="ID"] osadzający formularz rejestracji.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Frontend;

use EvReg\Persistence\EventConfigRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode [evreg_form event="ID"] osadzający formularz rejestracji.
 */
final class Shortcode {

	/**
	 * Rejestruje shortcode [evreg_form].
	 */
	public static function register(): void {
		add_shortcode( 'evreg_form', array( self::class, 'render' ) );
	}

	/**
	 * Renderuje shortcode dla podanych atrybutów.
	 *
	 * @param array<string,mixed>|string $atts Atrybuty shortcode'a.
	 */
	public static function render( $atts ): string {
		$atts     = shortcode_atts( array( 'event' => '0' ), is_array( $atts ) ? $atts : array(), 'evreg_form' );
		$event_id = (int) $atts['event'];

		return self::renderForm( $event_id );
	}

	/**
	 * Renderuje formularz (lub komunikat sukcesu/niedostępności) dla eventu. Wspólny helper dla shortcode'a i bloku.
	 *
	 * @param int $event_id ID posta eventu.
	 */
	public static function renderForm( int $event_id ): string {
		if ( $event_id <= 0 ) {
			return '';
		}

		// Powrót z linku potwierdzającego (double opt-in): ConfirmationController
		// robi PRG na ?evreg_confirmed=<kod>. Pokazujemy komunikat zamiast formularza.
		$confirmed = isset( $_GET['evreg_confirmed'] ) ? sanitize_key( (string) $_GET['evreg_confirmed'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $confirmed ) {
			self::enqueueStyles();
			return self::alert(
				ConfirmationController::confirmedMessage( $confirmed ),
				self::confirmVariant( $confirmed ),
				'evreg-confirmed'
			);
		}

		$success = isset( $_GET['evreg'] ) ? sanitize_key( (string) $_GET['evreg'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'reserved' === $success || 'waitlisted' === $success ) {
			self::enqueueStyles();
			return self::successMessage( $success );
		}

		$schema = ( new EventFormLoader( new EventConfigRepository() ) )->load( $event_id );
		if ( null === $schema ) {
			return '<p class="evreg-unavailable">' . esc_html__( 'Rejestracja jest niedostępna.', 'event-registration' ) . '</p>';
		}

		self::enqueueStyles();
		wp_enqueue_script( 'evreg-public' );

		$result = SubmitHandler::resultFor( $event_id );
		$notice = null === $result ? '' : self::resultNotice( $result );

		return $notice . ( new FormRenderer() )->render( $schema, $event_id, $result );
	}

	/**
	 * Buduje komunikat sukcesu po udanej rezerwacji lub zapisie na listę rezerwową.
	 *
	 * @param string $code Kod sukcesu ("reserved" lub "waitlisted").
	 */
	private static function successMessage( string $code ): string {
		$text = 'waitlisted' === $code
			? __( 'Jesteś na liście rezerwowej. Poinformujemy Cię, gdy zwolni się miejsce.', 'event-registration' )
			: __( 'Dziękujemy! Sprawdź e-mail i potwierdź zgłoszenie.', 'event-registration' );

		return self::alert( $text, 'waitlisted' === $code ? 'info' : 'success', 'evreg-success' );
	}

	/**
	 * Wariant alertu Bootstrap dla kodu potwierdzenia double opt-in.
	 *
	 * @param string $code Kod wyniku potwierdzenia.
	 */
	private static function confirmVariant( string $code ): string {
		$map = array(
			'confirmed'         => 'success',
			'already_confirmed' => 'info',
			'waitlist'          => 'info',
			'expired'           => 'warning',
			'not_found'         => 'danger',
		);

		return $map[ $code ] ?? 'warning';
	}

	/**
	 * Enqueue styli frontu: Bootstrap 5 (opcjonalnie) + baseline form.css.
	 * Wołane też na ścieżkach komunikatów (bez formularza), by alerty miały styl.
	 */
	private static function enqueueStyles(): void {
		if ( get_option( \EvReg\Admin\SettingsScreen::LOAD_BOOTSTRAP_OPTION, false ) ) {
			wp_enqueue_style( 'evreg-bootstrap' );
		}
		wp_enqueue_style( 'evreg-public' );
	}

	/**
	 * Owija komunikat w bootstrapowy alert z hookiem klasy evreg-* (baseline bez BS5).
	 *
	 * @param string $text    Treść komunikatu (nieescapowana).
	 * @param string $variant Wariant Bootstrap: success|info|warning|danger.
	 * @param string $hook    Klasa-hook evreg-* dla stylowania bez Bootstrapa.
	 */
	private static function alert( string $text, string $variant, string $hook ): string {
		return '<div class="' . esc_attr( $hook ) . ' alert alert-' . esc_attr( $variant ) . '" role="alert">' . esc_html( $text ) . '</div>';
	}

	/**
	 * Buduje komunikat błędu na podstawie wyniku poprzedniej próby submisji.
	 *
	 * @param SubmitResult $result Wynik poprzedniej próby submisji.
	 */
	private static function resultNotice( SubmitResult $result ): string {
		$map  = array(
			'duplicate'    => __( 'Jesteś już zapisany na to wydarzenie.', 'event-registration' ),
			'rejected'     => __( 'Brak wolnych miejsc.', 'event-registration' ),
			'spam'         => __( 'Nie udało się wysłać zgłoszenia. Spróbuj ponownie.', 'event-registration' ),
			'config_error' => __( 'Formularz jest nieprawidłowo skonfigurowany.', 'event-registration' ),
			'invalid'      => __( 'Popraw zaznaczone pola.', 'event-registration' ),
		);
		$text = $map[ $result->code() ] ?? '';

		return '' === $text ? '' : self::alert( $text, 'warning', 'evreg-notice' );
	}
}
