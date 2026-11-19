<?php
/**
 * Shortcode [evreg_form event="ID"] osadzający formularz rejestracji.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Frontend;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Accommodation\NightsConfig;
use EvReg\Domain\Accommodation\SlotAvailability;
use EvReg\Domain\Capacity\CapacityLimits;
use EvReg\Domain\Capacity\EventAvailability;
use EvReg\Domain\Capacity\EventAvailabilityState;
use EvReg\Domain\Registration\RegistrationTypeCollection;
use EvReg\Domain\Registration\RegistrationStatus;
use EvReg\Domain\Schema\SchemaException;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\RegistrationRepository;

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

		$loader = new EventFormLoader( new EventConfigRepository() );
		$schema = $loader->load( $event_id );
		if ( null === $schema ) {
			return '<p class="evreg-unavailable">' . esc_html__( 'Rejestracja jest niedostępna.', 'event-registration' ) . '</p>';
		}

		// Tryb „doby” rozstrzygamy RAZ, tu, u wejścia, i wstrzykujemy niżej. Niepoprawna
		// konfiguracja dób (np. zduplikowany klucz) nie renderuje noclegu — degradujemy do
		// istniejącego komunikatu niedostępności zamiast wywalać całą stronę.
		try {
			$nights = $loader->nights( $event_id );
		} catch ( SchemaException ) {
			return '<p class="evreg-unavailable">' . esc_html__( 'Rejestracja jest niedostępna.', 'event-registration' ) . '</p>';
		}

		// Wydarzenie, które nikogo już nie przyjmie, nie dostaje formularza ani skryptu —
		// sam komunikat. Wynik poprzedniej próby (np. `rejected` z wyścigu o ostatnie
		// miejsce) pomijamy, bo opowiadałby tę samą historię drugi raz, gorzej.
		$state = self::eventAvailability( $event_id, $nights );
		if ( EventAvailabilityState::Closed === $state ) {
			self::enqueueStyles();
			return self::fullNotice( $event_id, $state );
		}

		self::enqueueStyles();
		wp_enqueue_script( 'evreg-public' );

		$result = SubmitHandler::resultFor( $event_id );
		$notice = ( EventAvailabilityState::Waitlist === $state ? self::fullNotice( $event_id, $state ) : '' )
			. ( null === $result ? '' : self::resultNotice( $result ) );

		$renderer = null === $nights
			? new FormRenderer( self::availability( $event_id ) )
			: new FormRenderer( null, ( new RegistrationRepository() )->nightOccupancy( $event_id, RegistrationStatus::occupyingValues() ), $nights );

		return $notice . $renderer->render( $schema, $event_id, $result );
	}

	/**
	 * Ustala, czy wydarzenie może jeszcze kogokolwiek przyjąć. Limity składa z SUROWYCH
	 * ustawień (global_cap/waitlist_enabled nie podlegają tłumaczeniu), a obłożenie liczy
	 * świeżo. Tryb „doby” nigdy nie buduje AccommodationConfig; w klasycznym niepoprawny
	 * inwentarz oznacza Open — nie zamykamy rejestracji z powodu błędu konfiguracji.
	 *
	 * @param int               $event_id ID posta eventu.
	 * @param NightsConfig|null $nights   Konfiguracja dób (null = tryb klasyczny).
	 */
	private static function eventAvailability( int $event_id, ?NightsConfig $nights ): EventAvailabilityState {
		$config   = ( new EventConfigRepository() )->get( $event_id );
		$settings = is_array( $config['settings'] ) ? $config['settings'] : array();
		$types    = RegistrationTypeCollection::fromArray( is_array( $config['types'] ) ? $config['types'] : array() );

		if ( null !== $nights ) {
			$limits = CapacityLimits::forNights( $settings, $types, $nights );
		} else {
			try {
				$raw    = is_array( $config['accommodation'] ) ? $config['accommodation'] : array();
				$limits = CapacityLimits::forClassic( $settings, $types, AccommodationConfig::fromArray( $raw ) );
			} catch ( SchemaException ) {
				return EventAvailabilityState::Open;
			}
		}

		return EventAvailability::decide(
			$limits,
			( new RegistrationRepository() )->occupancy( $event_id ),
			array_map( static fn( $type ) => $type->key, $types->active() )
		);
	}

	/**
	 * Buduje alert „wydarzenie pełne”: tekst własny organizatora (po overlayu językowym),
	 * a gdy pusty — domyślny.
	 *
	 * @param int                    $event_id ID posta eventu.
	 * @param EventAvailabilityState $state    Stan Waitlist albo Closed.
	 */
	private static function fullNotice( int $event_id, EventAvailabilityState $state ): string {
		$waitlist = EventAvailabilityState::Waitlist === $state;
		$key      = $waitlist ? 'full_waitlist_notice' : 'full_closed_notice';
		$custom   = ( new EventFormLoader( new EventConfigRepository() ) )->settings( $event_id )[ $key ] ?? '';
		$custom   = is_string( $custom ) ? trim( $custom ) : '';

		if ( '' === $custom ) {
			$custom = $waitlist
				? __( 'Wszystkie miejsca są zajęte. Możesz się zapisać — trafisz na listę rezerwową i damy znać, gdy zwolni się miejsce.', 'event-registration' )
				: __( 'Rejestracja zamknięta — brak wolnych miejsc.', 'event-registration' );
		}

		return self::alert( $custom, $waitlist ? 'info' : 'warning', 'evreg-full' );
	}

	/**
	 * Składa dostępność miejsc w slotach noclegowych eventu. Zwraca null, gdy event nie
	 * ma inwentarza noclegów albo konfiguracja jest niepoprawna — renderer bez dostępności
	 * zachowuje się jak przed tą zmianą.
	 *
	 * @param int $event_id ID posta eventu.
	 */
	private static function availability( int $event_id ): ?SlotAvailability {
		$config = ( new EventConfigRepository() )->get( $event_id );
		$raw    = is_array( $config['accommodation'] ) ? $config['accommodation'] : array();

		if ( ! is_array( $raw['inventory'] ?? null ) || array() === $raw['inventory'] ) {
			return null;
		}

		try {
			$accommodation = AccommodationConfig::fromArray( $raw );
		} catch ( SchemaException ) {
			return null;
		}

		return SlotAvailability::fromSnapshot(
			$accommodation,
			( new RegistrationRepository() )->occupancy( $event_id )
		);
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
			'rate_limited' => __( 'Z tego urządzenia wysłano zbyt wiele prób. Odczekaj godzinę i spróbuj ponownie lub skontaktuj się z organizatorem.', 'event-registration' ),
			'config_error' => __( 'Formularz jest nieprawidłowo skonfigurowany.', 'event-registration' ),
			'invalid'      => __( 'Popraw zaznaczone pola.', 'event-registration' ),
			'error'        => __( 'Nie udało się zapisać zgłoszenia z powodu błędu po naszej stronie. Nic nie zostało zapisane — spróbuj ponownie za chwilę lub skontaktuj się z organizatorem.', 'event-registration' ),
		);
		$text = $map[ $result->code() ] ?? '';

		return '' === $text ? '' : self::alert( $text, 'warning', 'evreg-notice' );
	}
}
