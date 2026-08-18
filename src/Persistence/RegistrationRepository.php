<?php
/**
 * Odczyt i zapis zgłoszeń, rezerwacji noclegowych oraz zajętości.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Persistence;

use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Domain\Capacity\OccupancySnapshot;
use EvReg\Domain\Registration\RegistrationStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Odczyt i zapis zgłoszeń, rezerwacji noclegowych oraz zajętości.
 *
 * Jedyny adapter dotykający $wpdb dla zgłoszeń. Tylko czyta/pisze —
 * nie decyduje o limitach.
 */
final class RegistrationRepository {

	/**
	 * Zwraca pełną nazwę tabeli zgłoszeń.
	 */
	private function registrations(): string {
		return Migrations::table( 'registrations' );
	}

	/**
	 * Zwraca pełną nazwę tabeli rezerwacji noclegowych.
	 */
	private function bookings(): string {
		return Migrations::table( 'accommodation_bookings' );
	}

	/**
	 * Zwraca pełną nazwę tabeli zamków.
	 */
	private function locks(): string {
		return Migrations::table( 'locks' );
	}

	/**
	 * Zapewnia i blokuje wiersz-zamek eventu. Wywoływać tylko w transakcji.
	 *
	 * @param int $event_id ID eventu.
	 */
	public function lockEvent( int $event_id ): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$this->locks()} (event_id) VALUES (%d)", $event_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "SELECT event_id FROM {$this->locks()} WHERE event_id = %d FOR UPDATE", $event_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Czy istnieje aktywne (niebędące anulowanym) zgłoszenie na ten e-mail w evencie.
	 *
	 * @param int    $event_id ID eventu.
	 * @param string $email    Adres e-mail zgłaszającego się.
	 */
	public function activeRegistrationExists( int $event_id, string $email ): bool {
		global $wpdb;

		$statuses = array_merge( RegistrationStatus::occupyingValues(), array( RegistrationStatus::Waitlist->value ) );
		$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$args     = array_merge( array( $event_id, $email ), $statuses );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in oraz $args mają zmienną, ale dopasowaną liczbę elementów.
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->registrations()} WHERE event_id = %d AND email = %s AND status IN ($in)", $args ) );

		return $count > 0;
	}

	/**
	 * Buduje migawkę zajętości (globalnie, per typ, per slot zakwaterowania) dla eventu.
	 *
	 * Liczy tylko statusy blokujące miejsce z RegistrationStatus::occupyingValues().
	 *
	 * @param int $event_id ID eventu.
	 */
	public function occupancy( int $event_id ): OccupancySnapshot {
		global $wpdb;

		$statuses = RegistrationStatus::occupyingValues();
		$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$args     = array_merge( array( $event_id ), $statuses );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$global = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->registrations()} WHERE event_id = %d AND status IN ($in)", $args ) );

		$per_type = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT type_key, COUNT(*) AS c FROM {$this->registrations()} WHERE event_id = %d AND status IN ($in) GROUP BY type_key", $args ), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$per_type[ (string) $row['type_key'] ] = (int) $row['c'];
		}

		$per_slot = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$slot_rows = $wpdb->get_results( $wpdb->prepare( "SELECT CONCAT(b.package_key, '|', b.room_type_key) AS slot, COUNT(*) AS c FROM {$this->bookings()} b INNER JOIN {$this->registrations()} r ON b.registration_id = r.id WHERE r.event_id = %d AND r.status IN ($in) GROUP BY slot", $args ), ARRAY_A );
		foreach ( (array) $slot_rows as $row ) {
			$per_slot[ (string) $row['slot'] ] = (int) $row['c'];
		}

		return new OccupancySnapshot( $global, $per_type, $per_slot );
	}

	/**
	 * Wstawia nowe zgłoszenie i zwraca jego ID.
	 *
	 * @param array<string,mixed> $row Dane zgłoszenia: event_id, type_key, status, email,
	 *                                 name, token, data (JSON), price_total, expires_at.
	 */
	public function insertRegistration( array $row ): int {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$wpdb->insert(
			$this->registrations(),
			array(
				'event_id'    => (int) $row['event_id'],
				'type_key'    => (string) $row['type_key'],
				'status'      => (string) $row['status'],
				'email'       => (string) $row['email'],
				'name'        => (string) $row['name'],
				'token'       => (string) $row['token'],
				'data'        => (string) $row['data'],
				'price_total' => (float) $row['price_total'],
				'created_at'  => $now,
				'updated_at'  => $now,
				'expires_at'  => $row['expires_at'],
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Wstawia rezerwację noclegową powiązaną ze zgłoszeniem.
	 *
	 * @param int                    $registration_id ID zgłoszenia.
	 * @param AccommodationSelection $selection       Wybór pakietu/pokoju.
	 * @param float                  $price           Cena rezerwacji.
	 */
	public function insertAccommodationBooking( int $registration_id, AccommodationSelection $selection, float $price ): void {
		global $wpdb;

		$wpdb->insert(
			$this->bookings(),
			array(
				'registration_id' => $registration_id,
				'package_key'     => $selection->packageKey,
				'room_type_key'   => $selection->roomKey,
				'roommate_pref'   => '' === $selection->roommatePref ? null : $selection->roommatePref,
				'price'           => $price,
			),
			array( '%d', '%s', '%s', '%s', '%f' )
		);
	}

	/**
	 * Znajduje zgłoszenie po tokenie.
	 *
	 * @param string $token Token zgłoszenia.
	 *
	 * @return array<string,mixed>|null
	 */
	public function findByToken( string $token ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->registrations()} WHERE token = %s", $token ), ARRAY_A );

		return null === $row ? null : $row;
	}

	/**
	 * Oznacza zgłoszenie jako potwierdzone i zapisuje znacznik czasu.
	 *
	 * @param int $registration_id ID zgłoszenia.
	 */
	public function markConfirmed( int $registration_id ): void {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$wpdb->update(
			$this->registrations(),
			array(
				'status'       => RegistrationStatus::Confirmed->value,
				'confirmed_at' => $now,
				'updated_at'   => $now,
			),
			array( 'id' => $registration_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Anuluje zgłoszenia oczekujące, których termin wygasł przed podanym momentem.
	 *
	 * @param string $now Aktualny moment (Y-m-d H:i:s) do porównania z expires_at.
	 *
	 * @return int Liczba anulowanych zgłoszeń.
	 */
	public function expirePending( string $now ): int {
		global $wpdb;

		$result = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$this->registrations()} SET status = %s, updated_at = %s WHERE status = %s AND expires_at IS NOT NULL AND expires_at < %s",
				RegistrationStatus::Cancelled->value,
				current_time( 'mysql', true ),
				RegistrationStatus::Pending->value,
				$now
			)
		);

		if ( false === $result ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'evreg expirePending failed: ' . $wpdb->last_error );
			return 0;
		}

		return (int) $result;
	}
}
