<?php
/**
 * Panel przeglądu stanu rejestracji na wydarzenie.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Admin;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Capacity\CapacityLimits;
use EvReg\Domain\Capacity\OccupancyReport;
use EvReg\Domain\Registration\RegistrationStatus;
use EvReg\Domain\Registration\RegistrationTypeCollection;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\RegistrationRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Renderuje zwięzły przegląd obłożenia wybranego eventu nad listą zgłoszeń.
 *
 * Tylko prezentacja: liczby bierze z repozytorium, arytmetykę miejsc
 * z czystego `OccupancyReport`.
 */
final class RegistrationsOverview {

	/**
	 * Renderuje panel dla eventu; bez wybranego eventu tylko podpowiedź.
	 *
	 * @param int $event_id ID eventu z filtra listy (0 = brak wyboru).
	 */
	public static function render( int $event_id ): void {
		if ( $event_id <= 0 ) {
			echo '<p class="description">' . esc_html__( 'Wybierz wydarzenie w filtrze poniżej, aby zobaczyć przegląd stanu rejestracji.', 'event-registration' ) . '</p>';
			return;
		}

		$report = self::report( $event_id );

		printf(
			'<h2>%s</h2>',
			esc_html(
				sprintf(
					/* translators: %s: nazwa wydarzenia. */
					__( 'Przegląd: %s', 'event-registration' ),
					(string) get_the_title( $event_id )
				)
			)
		);

		self::render_summary( $report );
		self::render_types( $report );
		self::render_slots( $report );
	}

	/**
	 * Składa zestawienie obłożenia dla eventu.
	 *
	 * @param int $event_id ID eventu.
	 */
	private static function report( int $event_id ): OccupancyReport {
		$config        = ( new EventConfigRepository() )->get( $event_id );
		$types         = RegistrationTypeCollection::fromArray( is_array( $config['types'] ) ? $config['types'] : array() );
		$accommodation = AccommodationConfig::fromArray( is_array( $config['accommodation'] ) ? $config['accommodation'] : array() );
		$settings      = is_array( $config['settings'] ) ? $config['settings'] : array();
		$global_cap    = isset( $settings['global_cap'] ) && null !== $settings['global_cap'] ? (int) $settings['global_cap'] : null;

		$repository = new RegistrationRepository();
		$counts     = array();

		foreach ( RegistrationStatus::cases() as $case ) {
			$counts[ $case->value ] = $repository->countRegistrations(
				array(
					'event_id' => $event_id,
					'status'   => $case->value,
				)
			);
		}

		return new OccupancyReport(
			$repository->occupancy( $event_id ),
			new CapacityLimits( $global_cap, $types->capacities(), $accommodation->capacities() ),
			$types,
			$accommodation,
			$counts,
			$repository->sumPriceTotal( $event_id )
		);
	}

	/**
	 * Renderuje tabelę „Zgłoszenia i limit".
	 *
	 * @param OccupancyReport $report Zestawienie obłożenia.
	 */
	private static function render_summary( OccupancyReport $report ): void {
		$rows = array(
			array( __( 'Zajmują miejsce', 'event-registration' ), self::occupancy_text( $report->overall() ) ),
			array( RegistrationsListTable::status_label( RegistrationStatus::Confirmed->value ), number_format_i18n( $report->statusCount( RegistrationStatus::Confirmed->value ) ) ),
			array( RegistrationsListTable::status_label( RegistrationStatus::Pending->value ), number_format_i18n( $report->statusCount( RegistrationStatus::Pending->value ) ) ),
			array( RegistrationsListTable::status_label( RegistrationStatus::Waitlist->value ), number_format_i18n( $report->statusCount( RegistrationStatus::Waitlist->value ) ) ),
			array( RegistrationsListTable::status_label( RegistrationStatus::Cancelled->value ), number_format_i18n( $report->statusCount( RegistrationStatus::Cancelled->value ) ) ),
			array( __( 'Osoby towarzyszące', 'event-registration' ), number_format_i18n( $report->companions() ) ),
			array( __( 'Suma kwot', 'event-registration' ), number_format_i18n( $report->priceTotal(), 2 ) ),
		);

		self::open_table( __( 'Zgłoszenia i limit', 'event-registration' ), __( 'Wartość', 'event-registration' ) );

		foreach ( $rows as $row ) {
			printf( '<tr><td>%s</td><td>%s</td></tr>', esc_html( $row[0] ), esc_html( $row[1] ) );
		}

		self::close_table();
	}

	/**
	 * Renderuje tabelę obłożenia per typ zgłoszenia.
	 *
	 * @param OccupancyReport $report Zestawienie obłożenia.
	 */
	private static function render_types( OccupancyReport $report ): void {
		$types = $report->types();

		if ( array() === $types ) {
			return;
		}

		self::open_table( __( 'Typy zgłoszenia', 'event-registration' ), __( 'Obłożenie', 'event-registration' ) );

		foreach ( $types as $type ) {
			printf( '<tr><td>%s</td><td>%s</td></tr>', esc_html( $type['label'] ), esc_html( self::occupancy_text( $type ) ) );
		}

		self::close_table();
	}

	/**
	 * Renderuje tabelę obłożenia noclegów (miejsca, nie rezerwacje).
	 *
	 * @param OccupancyReport $report Zestawienie obłożenia.
	 */
	private static function render_slots( OccupancyReport $report ): void {
		$slots = $report->slots();

		if ( array() === $slots ) {
			return;
		}

		self::open_table( __( 'Noclegi (miejsca)', 'event-registration' ), __( 'Obłożenie', 'event-registration' ) );

		foreach ( $slots as $slot ) {
			printf(
				'<tr><td>%s</td><td>%s</td></tr>',
				esc_html( $slot['packageLabel'] . ' — ' . $slot['roomLabel'] ),
				esc_html(
					self::occupancy_text(
						array(
							'taken' => $slot['taken'],
							'limit' => $slot['capacity'],
							'free'  => $slot['free'],
						)
					)
				)
			);
		}

		self::close_table();
	}

	/**
	 * Formatuje „zajęte z limitu (wolne)" albo „zajęte (bez limitu)".
	 *
	 * @param array{taken:int,limit:int|null,free:int|null} $row Wiersz obłożenia.
	 */
	private static function occupancy_text( array $row ): string {
		if ( null === $row['limit'] ) {
			/* translators: %s: liczba zajętych miejsc. */
			return sprintf( __( '%s (bez limitu)', 'event-registration' ), number_format_i18n( $row['taken'] ) );
		}

		return sprintf(
			/* translators: 1: zajęte miejsca, 2: limit miejsc, 3: wolne miejsca. */
			__( '%1$s z %2$s (wolne: %3$s)', 'event-registration' ),
			number_format_i18n( $row['taken'] ),
			number_format_i18n( $row['limit'] ),
			number_format_i18n( (int) $row['free'] )
		);
	}

	/**
	 * Otwiera tabelę przeglądu z dwiema kolumnami.
	 *
	 * @param string $heading Nagłówek pierwszej kolumny.
	 * @param string $value   Nagłówek drugiej kolumny.
	 */
	private static function open_table( string $heading, string $value ): void {
		printf(
			'<table class="widefat striped evreg-overview"><thead><tr><th scope="col">%s</th><th scope="col">%s</th></tr></thead><tbody>',
			esc_html( $heading ),
			esc_html( $value )
		);
	}

	/**
	 * Zamyka tabelę przeglądu.
	 */
	private static function close_table(): void {
		echo '</tbody></table><br />';
	}
}
