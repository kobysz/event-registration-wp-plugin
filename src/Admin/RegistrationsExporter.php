<?php
/**
 * Budowanie tekstu CSV eksportu zgłoszeń.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Admin;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Export\RegistrationExportMapper;
use EvReg\Domain\Registration\RegistrationTypeCollection;
use EvReg\Domain\Schema\FormSchema;

defined( 'ABSPATH' ) || exit;

/**
 * Składa pełny tekst CSV (BOM + nagłówek + wiersze) dla eksportu zgłoszeń jednego eventu.
 */
final class RegistrationsExporter {

	/**
	 * Buduje pełny tekst CSV (BOM + nagłówek + wiersze) dla eksportu zgłoszeń jednego eventu.
	 *
	 * @param FormSchema                                     $schema        Schemat formularza (odpowiedzi).
	 * @param AccommodationConfig|null                       $accommodation Konfiguracja noclegu (etykiety pakietów/pokoi); null w trybie „doby”.
	 * @param RegistrationTypeCollection                     $types         Typy zgłoszeń eventu (etykiety).
	 * @param array<int,array<string,mixed>>                 $rows          Wiersze evreg_registrations (ARRAY_A).
	 * @param array<int,array<string,mixed>>                 $bookingsById  Mapa registration_id => wiersz bookingu.
	 * @param array<int,array<int,array<string,mixed>>>|null $nightsById Mapa registration_id => wiersze dób; null = tryb klasyczny,
	 *                                                       tablica (także pusta) = tryb „doby” (jedna kolumna „Doby” zamiast trzech klasycznych).
	 */
	public function buildCsv(
		FormSchema $schema,
		?AccommodationConfig $accommodation,
		RegistrationTypeCollection $types,
		array $rows,
		array $bookingsById,
		?array $nightsById = null
	): string {
		$mapper = new RegistrationExportMapper();

		$identity_headers = array(
			__( 'ID', 'event-registration' ),
			__( 'Status', 'event-registration' ),
			__( 'Typ', 'event-registration' ),
			__( 'E-mail', 'event-registration' ),
			__( 'Imię i nazwisko', 'event-registration' ),
			__( 'Cena', 'event-registration' ),
			__( 'Utworzono', 'event-registration' ),
			__( 'Potwierdzono', 'event-registration' ),
			__( 'Notatka', 'event-registration' ),
			__( 'Osoba towarzysząca', 'event-registration' ),
		);

		$answer_headers = array();
		foreach ( $mapper->answerColumns( $schema ) as $col ) {
			$answer_headers[] = $col['label'];
		}

		$nights_mode = null !== $nightsById || null === $accommodation;

		$accommodation_headers = $nights_mode
			? array( __( 'Doby', 'event-registration' ) )
			: array(
				__( 'Nocleg – pakiet', 'event-registration' ),
				__( 'Nocleg – pokój', 'event-registration' ),
				__( 'Nocleg – współlokator', 'event-registration' ),
			);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- bufor w pamięci (php://temp), nie operacja na systemie plików.
		$handle = fopen( 'php://temp', 'r+' );

		// Nagłówek — bez neutralizacji (labele autora / i18n, nie dane użytkownika).
		fputcsv( $handle, array_merge( $identity_headers, $answer_headers, $accommodation_headers ) );

		foreach ( $rows as $row ) {
			$id      = (int) $row['id'];
			$type    = $types->get( (string) $row['type_key'] );
			$data    = json_decode( (string) $row['data'], true );
			$data    = is_array( $data ) ? $data : array();
			$booking = $bookingsById[ $id ] ?? null;

			if ( $nights_mode ) {
				$accommodation_cells = array( $mapper->nightsCell( $nightsById[ $id ] ?? array() ) );
			} else {
				$acc                 = $mapper->accommodationCells( $booking, $accommodation );
				$accommodation_cells = array( $acc['package'], $acc['room'], $acc['roommate'] );
			}

			$identity = array(
				(string) $row['id'],
				RegistrationsListTable::status_label( (string) $row['status'] ),
				null === $type ? (string) $row['type_key'] : $type->label,
				(string) $row['email'],
				(string) $row['name'],
				number_format( (float) $row['price_total'], 2, '.', '' ),
				(string) $row['created_at'],
				(string) ( $row['confirmed_at'] ?? '' ),
				(string) ( $row['note'] ?? '' ),
				(string) ( $row['companion_name'] ?? '' ),
			);

			$cells = array_merge(
				$identity,
				$mapper->answerCells( $data, $schema ),
				$accommodation_cells
			);

			fputcsv( $handle, array_map( array( $this, 'neutralize' ), $cells ) );
		}

		rewind( $handle );
		$csv = (string) stream_get_contents( $handle );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- zamyka bufor w pamięci otwarty powyżej, nie plik na dysku.
		fclose( $handle );

		return "\xEF\xBB\xBF" . $csv;
	}

	/**
	 * Neutralizuje CSV injection: komórka danych zaczynająca się od = + - @ \t \r → poprzedzona apostrofem.
	 *
	 * @param string $value Wartość komórki.
	 */
	private function neutralize( string $value ): string {
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}

		return $value;
	}
}
