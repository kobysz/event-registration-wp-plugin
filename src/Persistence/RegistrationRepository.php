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
	 * Zwraca zgłoszenie po ID.
	 *
	 * @param int $id ID zgłoszenia.
	 *
	 * @return array<string,mixed>|null
	 */
	public function findById( int $id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->registrations()} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Buduje klauzulę WHERE i argumenty z filtrów listy zgłoszeń.
	 *
	 * @param array{status?: string, type_key?: string, event_id?: int} $filters Filtry.
	 *
	 * @return array{0: string, 1: array<int,mixed>}
	 */
	private function registrationWhere( array $filters ): array {
		$clauses  = array();
		$args     = array();
		$statuses = array(
			RegistrationStatus::Pending->value,
			RegistrationStatus::Confirmed->value,
			RegistrationStatus::Waitlist->value,
			RegistrationStatus::Cancelled->value,
		);

		if ( isset( $filters['status'] ) && in_array( $filters['status'], $statuses, true ) ) {
			$clauses[] = 'status = %s';
			$args[]    = $filters['status'];
		}

		if ( isset( $filters['type_key'] ) && '' !== (string) $filters['type_key'] ) {
			$clauses[] = 'type_key = %s';
			$args[]    = (string) $filters['type_key'];
		}

		if ( isset( $filters['event_id'] ) && (int) $filters['event_id'] > 0 ) {
			$clauses[] = 'event_id = %d';
			$args[]    = (int) $filters['event_id'];
		}

		$where = array() === $clauses ? '' : ' WHERE ' . implode( ' AND ', $clauses );

		return array( $where, $args );
	}

	/**
	 * Zwraca stronę zgłoszeń wg filtrów, najnowsze naprzód.
	 *
	 * @param array{status?: string, type_key?: string, event_id?: int} $filters  Filtry.
	 * @param int                                                       $per_page Wiersze na stronę.
	 * @param int                                                       $offset   Przesunięcie.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function paginateRegistrations( array $filters, int $per_page, int $offset ): array {
		global $wpdb;

		list( $where, $args ) = $this->registrationWhere( $filters );
		$args[]               = $per_page;
		$args[]               = $offset;

		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $args ma zmienną, ale dopasowaną do placeholderów liczbę elementów.
			$wpdb->prepare( "SELECT * FROM {$this->registrations()}{$where} ORDER BY id DESC LIMIT %d OFFSET %d", $args ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Liczy zgłoszenia spełniające filtry.
	 *
	 * @param array{status?: string, type_key?: string, event_id?: int} $filters Filtry.
	 */
	public function countRegistrations( array $filters ): int {
		global $wpdb;

		list( $where, $args ) = $this->registrationWhere( $filters );

		if ( array() === $args ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->registrations()}" );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->registrations()}{$where}", $args ) );
	}

	/**
	 * Zwraca unikalne klucze typów zgłoszeń obecne w evencie.
	 *
	 * @param int $event_id ID eventu.
	 *
	 * @return array<int,string>
	 */
	public function distinctTypeKeys( int $event_id ): array {
		global $wpdb;

		$keys = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT DISTINCT type_key FROM {$this->registrations()} WHERE event_id = %d ORDER BY type_key ASC", $event_id )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return array_map( 'strval', is_array( $keys ) ? $keys : array() );
	}

	/**
	 * Zwraca unikalne ID eventów obecnych w zgłoszeniach.
	 *
	 * @return array<int,int>
	 */
	public function distinctEventIds(): array {
		global $wpdb;

		$ids = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT DISTINCT event_id FROM {$this->registrations()} ORDER BY event_id ASC"
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Zwraca rezerwację noclegową zgłoszenia albo null.
	 *
	 * @param int $registration_id ID zgłoszenia.
	 *
	 * @return array<string,mixed>|null
	 */
	public function findAccommodationBooking( int $registration_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$this->bookings()} WHERE registration_id = %d ORDER BY id ASC LIMIT 1",
				$registration_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
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
	 * Ustawia status zgłoszenia na anulowany.
	 *
	 * @param int $id ID zgłoszenia.
	 */
	public function markCancelled( int $id ): void {
		global $wpdb;

		$wpdb->update(
			$this->registrations(),
			array(
				'status'     => RegistrationStatus::Cancelled->value,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Ustawia status na pending i nowy termin wygaśnięcia (promocja z waitlisty).
	 *
	 * @param int    $id         ID zgłoszenia.
	 * @param string $expires_at Termin wygaśnięcia (Y-m-d H:i:s, UTC).
	 */
	public function markPending( int $id, string $expires_at ): void {
		global $wpdb;

		$wpdb->update(
			$this->registrations(),
			array(
				'status'     => RegistrationStatus::Pending->value,
				'expires_at' => $expires_at,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Kasuje rezerwacje noclegowe zgłoszenia.
	 *
	 * @param int $id ID zgłoszenia.
	 */
	public function deleteAccommodationBooking( int $id ): void {
		global $wpdb;

		$wpdb->delete( $this->bookings(), array( 'registration_id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Kasuje wiersze kolejki maili powiązane ze zgłoszeniem (sprzątanie osieroconych).
	 *
	 * @param int $id ID zgłoszenia.
	 */
	public function deleteMailQueueByRegistration( int $id ): void {
		global $wpdb;

		$wpdb->delete( Migrations::table( 'mail_queue' ), array( 'registration_id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Trwale kasuje wiersz zgłoszenia.
	 *
	 * @param int $id ID zgłoszenia.
	 */
	public function hardDelete( int $id ): void {
		global $wpdb;

		$wpdb->delete( $this->registrations(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Zapisuje notatkę organizatora.
	 *
	 * @param int    $id   ID zgłoszenia.
	 * @param string $note Treść notatki.
	 */
	public function updateNote( int $id, string $note ): void {
		global $wpdb;

		$wpdb->update(
			$this->registrations(),
			array(
				'note'       => $note,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Anuluje zgłoszenia oczekujące, których termin wygasł przed podanym momentem.
	 *
	 * @param string $now Aktualny moment (Y-m-d H:i:s) do porównania z expires_at.
	 *
	 * @return array<int,array{id: int, event_id: int}> Wygaszone zgłoszenia.
	 */
	public function expirePending( string $now ): array {
		global $wpdb;

		$candidates = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id, event_id FROM {$this->registrations()} WHERE status = %s AND expires_at IS NOT NULL AND expires_at < %s",
				RegistrationStatus::Pending->value,
				$now
			),
			ARRAY_A
		);

		if ( ! is_array( $candidates ) || array() === $candidates ) {
			return array();
		}

		$ids          = array_map( static fn ( array $row ): int => (int) $row['id'], $candidates );
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		$result = $wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders ma zmienną, ale dopasowaną do $ids liczbę elementów.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$this->registrations()} SET status = %s, updated_at = %s WHERE id IN ({$placeholders}) AND status = %s",
				array_merge(
					array( RegistrationStatus::Cancelled->value, current_time( 'mysql', true ) ),
					$ids,
					array( RegistrationStatus::Pending->value )
				)
			)
		);

		if ( false === $result ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'evreg expirePending failed: ' . $wpdb->last_error );
			return array();
		}

		// Zwracane wiersze to kandydaci z SELECT sprzed UPDATE, nie tylko te, które TA rozmowa
		// przestawiła na Cancelled — przy nakładających się przebiegach crona nasłuch może więc
		// odpalić się też dla wiersza już anulowanego przez inny przebieg; dla maila nieszkodliwe
		// (unikalny indeks kolejki dedupuje), ale dla przyszłego nie-mailowego nasłuchu to nieszczelny kontrakt.
		return array_map(
			static fn ( array $row ): array => array(
				'id'       => (int) $row['id'],
				'event_id' => (int) $row['event_id'],
			),
			$candidates
		);
	}
}
