<?php
/**
 * Budowa wartości placeholderów dla maila o zgłoszeniu.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Mail;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Conditions\ConditionEngine;
use EvReg\Domain\Mail\Placeholders;
use EvReg\Domain\Mail\SummaryBuilder;
use EvReg\Domain\Registration\RegistrationTypeCollection;
use EvReg\Domain\Schema\VisibilityResolver;
use EvReg\Frontend\EventFormLoader;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\RegistrationRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Budowa wartości placeholderów dla maila o zgłoszeniu.
 */
final class PlaceholderFactory {

	/**
	 * Tworzy fabrykę.
	 *
	 * @param EventConfigRepository  $config        Repozytorium konfiguracji eventu.
	 * @param RegistrationRepository $registrations Repozytorium zgłoszeń.
	 * @param EventFormLoader        $loader        Loader złożonej schemy.
	 */
	public function __construct(
		private readonly EventConfigRepository $config,
		private readonly RegistrationRepository $registrations,
		private readonly EventFormLoader $loader
	) {
	}

	/**
	 * Buduje zbiór placeholderów dla wiersza zgłoszenia.
	 *
	 * @param array<string,mixed> $registration Wiersz tabeli zgłoszeń.
	 */
	public function build( array $registration ): Placeholders {
		$event_id      = (int) ( $registration['event_id'] ?? 0 );
		$config        = $this->config->get( $event_id );
		$accommodation = $this->accommodationLabel( $config, (int) ( $registration['id'] ?? 0 ) );

		return new Placeholders(
			array(
				'imie'               => (string) ( $registration['name'] ?? '' ),
				'email'              => (string) ( $registration['email'] ?? '' ),
				'event'              => (string) get_the_title( $event_id ),
				'typ'                => $this->typeLabel( $config, (string) ( $registration['type_key'] ?? '' ) ),
				'nocleg'             => $accommodation,
				'link_potwierdzenia' => $this->confirmationUrl( $config, $event_id, (string) ( $registration['token'] ?? '' ) ),
				'podsumowanie'       => $this->summary( $event_id, $registration, $accommodation ),
			)
		);
	}

	/**
	 * Zwraca etykietę typu zgłoszenia, a przy nieznanym typie sam klucz.
	 *
	 * @param array<string,mixed> $config   Konfiguracja eventu.
	 * @param string              $type_key Klucz typu zgłoszenia.
	 */
	private function typeLabel( array $config, string $type_key ): string {
		$types = RegistrationTypeCollection::fromArray( is_array( $config['types'] ) ? $config['types'] : array() );
		$type  = $types->get( $type_key );

		return null === $type ? $type_key : $type->label;
	}

	/**
	 * Zwraca opis rezerwacji noclegowej albo pusty łańcuch.
	 *
	 * @param array<string,mixed> $config          Konfiguracja eventu.
	 * @param int                 $registration_id ID zgłoszenia.
	 */
	private function accommodationLabel( array $config, int $registration_id ): string {
		$booking = $this->registrations->findAccommodationBooking( $registration_id );

		if ( null === $booking ) {
			return '';
		}

		$accommodation = AccommodationConfig::fromArray( is_array( $config['accommodation'] ) ? $config['accommodation'] : array() );
		$package_key   = (string) ( $booking['package_key'] ?? '' );
		$room_key      = (string) ( $booking['room_type_key'] ?? '' );
		$package_label = $package_key;

		foreach ( $accommodation->packages() as $package ) {
			if ( $package->key === $package_key ) {
				$package_label = $package->label;
				break;
			}
		}

		$room       = $accommodation->room( $room_key );
		$room_label = null === $room ? $room_key : $room->label;
		$label      = trim( $package_label . ' / ' . $room_label, ' /' );
		$roommate   = trim( (string) ( $booking['roommate_pref'] ?? '' ) );

		if ( '' === $roommate ) {
			return $label;
		}

		/* translators: 1: opis noclegu, 2: preferowany współlokator. */
		return sprintf( __( '%1$s (współlokator: %2$s)', 'event-registration' ), $label, $roommate );
	}

	/**
	 * Buduje adres potwierdzenia zgłoszenia.
	 *
	 * @param array<string,mixed> $config   Konfiguracja eventu.
	 * @param int                 $event_id ID eventu.
	 * @param string              $token    Token zgłoszenia.
	 */
	private function confirmationUrl( array $config, int $event_id, string $token ): string {
		if ( '' === $token ) {
			return '';
		}

		$settings = is_array( $config['settings'] ) ? $config['settings'] : array();
		$page_id  = isset( $settings['form_page_id'] ) ? (int) $settings['form_page_id'] : 0;
		$base     = $page_id > 0 ? get_permalink( $page_id ) : get_permalink( $event_id );

		if ( ! is_string( $base ) || '' === $base ) {
			$base = home_url( '/' );
		}

		return add_query_arg( 'evreg_confirm', $token, $base );
	}

	/**
	 * Buduje podsumowanie odpowiedzi, dopisując linię noclegu.
	 *
	 * @param int                 $event_id      ID eventu.
	 * @param array<string,mixed> $registration  Wiersz zgłoszenia.
	 * @param string              $accommodation Opis noclegu albo pusty łańcuch.
	 */
	private function summary( int $event_id, array $registration, string $accommodation ): string {
		$schema = $this->loader->load( $event_id );

		if ( null === $schema ) {
			return '';
		}

		$decoded = json_decode( (string) ( $registration['data'] ?? '' ), true );
		$answers = is_array( $decoded ) ? $decoded : array();
		$builder = new SummaryBuilder(
			new VisibilityResolver( new ConditionEngine() ),
			__( 'Tak', 'event-registration' )
		);

		$summary = $builder->build( $schema, $answers );

		if ( '' === $accommodation ) {
			return $summary;
		}

		/* translators: %s: opis rezerwacji noclegowej. */
		$line = sprintf( __( 'Nocleg: %s', 'event-registration' ), $accommodation );

		return '' === $summary ? $line : $summary . "\n" . $line;
	}
}
