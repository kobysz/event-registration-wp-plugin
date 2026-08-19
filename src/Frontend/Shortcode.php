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

		$success = isset( $_GET['evreg'] ) ? sanitize_key( (string) $_GET['evreg'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'reserved' === $success || 'waitlisted' === $success ) {
			return self::successMessage( $success );
		}

		$schema = ( new EventFormLoader( new EventConfigRepository() ) )->load( $event_id );
		if ( null === $schema ) {
			return '<p class="evreg-unavailable">' . esc_html__( 'Rejestracja jest niedostępna.', 'event-registration' ) . '</p>';
		}

		wp_enqueue_style( 'evreg-public' );
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

		return '<div class="evreg-success">' . esc_html( $text ) . '</div>';
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

		return '' === $text ? '' : '<div class="evreg-notice">' . esc_html( $text ) . '</div>';
	}
}
