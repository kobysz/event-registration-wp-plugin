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
		$slot_rows = $wpdb->get_results( $wpdb->prepare( "SELECT CONCAT(b.package_key, '|', b.room_type_key) AS slot, SUM(b.seats) AS c FROM {$this->bookings()} b INNER JOIN {$this->registrations()} r ON b.registration_id = r.id WHERE r.event_id = %d AND r.status IN ($in) GROUP BY slot", $args ), ARRAY_A );
		foreach ( (array) $slot_rows as $row ) {
			$per_slot[ (string) $row['slot'] ] = (int) $row['c'];
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$companions = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(companion),0) FROM {$this->registrations()} WHERE event_id = %d AND status IN ($in)", $args ) );

		return new OccupancySnapshot( $global, $per_type, $per_slot, $companions );
	}

	/**
	 * Zajętość eventu policzona tak, jakby wiersz $exclude_id nie istniał (self-exclusion przy edycji).
	 *
	 * @param int $event_id   ID eventu.
	 * @param int $exclude_id ID zgłoszenia pomijanego w liczeniu (edytowany wiersz).
	 */
	public function occupancyExcluding( int $event_id, int $exclude_id ): OccupancySnapshot {
		global $wpdb;

		$statuses = RegistrationStatus::occupyingValues();
		$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$args     = array_merge( array( $event_id ), $statuses, array( $exclude_id ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in oraz $args mają zmienną, ale dopasowaną liczbę elementów.
		$global = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->registrations()} WHERE event_id = %d AND status IN ($in) AND id != %d", $args ) );

		$per_type = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in oraz $args mają zmienną, ale dopasowaną liczbę elementów.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT type_key, COUNT(*) AS c FROM {$this->registrations()} WHERE event_id = %d AND status IN ($in) AND id != %d GROUP BY type_key", $args ), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$per_type[ (string) $row['type_key'] ] = (int) $row['c'];
		}

		$per_slot = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in oraz $args mają zmienną, ale dopasowaną liczbę elementów.
		$slot_rows = $wpdb->get_results( $wpdb->prepare( "SELECT CONCAT(b.package_key, '|', b.room_type_key) AS slot, SUM(b.seats) AS c FROM {$this->bookings()} b INNER JOIN {$this->registrations()} r ON b.registration_id = r.id WHERE r.event_id = %d AND r.status IN ($in) AND r.id != %d GROUP BY slot", $args ), ARRAY_A );
		foreach ( (array) $slot_rows as $row ) {
			$per_slot[ (string) $row['slot'] ] = (int) $row['c'];
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in oraz $args mają zmienną, ale dopasowaną liczbę elementów.
		$companions = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(companion),0) FROM {$this->registrations()} WHERE event_id = %d AND status IN ($in) AND id != %d", $args ) );

		return new OccupancySnapshot( $global, $per_type, $per_slot, $companions );
	}

	/**
	 * Wstawia nowe zgłoszenie i zwraca jego ID.
	 *
	 * @param array<string,mixed> $row Dane zgłoszenia: event_id, type_key, status, email,
	 *                                 name, token, data (JSON), price_total, expires_at, lang,
	 *                                 companion, companion_name.
	 */
	public function insertRegistration( array $row ): int {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$wpdb->insert(
			$this->registrations(),
			array(
				'event_id'       => (int) $row['event_id'],
				'type_key'       => (string) $row['type_key'],
				'status'         => (string) $row['status'],
				'email'          => (string) $row['email'],
				'name'           => (string) $row['name'],
				'token'          => (string) $row['token'],
				'data'           => (string) $row['data'],
				'price_total'    => (float) $row['price_total'],
				'created_at'     => $now,
				'updated_at'     => $now,
				'expires_at'     => $row['expires_at'],
				'lang'           => (string) ( $row['lang'] ?? '' ),
				'companion'      => (int) ( $row['companion'] ?? 0 ),
				'companion_name' => (string) ( $row['companion_name'] ?? '' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Zwraca slug języka zgłoszenia ('' gdy brak).
	 *
	 * @param int $registration_id ID zgłoszenia.
	 */
	public function langOf( int $registration_id ): string {
		$row = $this->findById( $registration_id );
		return is_array( $row ) ? (string) ( $row['lang'] ?? '' ) : '';
	}

	/**
	 * Wstawia rezerwację noclegową powiązaną ze zgłoszeniem.
	 *
	 * @param int                    $registration_id ID zgłoszenia.
	 * @param AccommodationSelection $selection       Wybór pakietu/pokoju.
	 * @param float                  $price           Cena rezerwacji.
	 * @param int                    $seats           Liczba zajmowanych miejsc w slocie (1 + towarzysz).
	 */
	public function insertAccommodationBooking( int $registration_id, AccommodationSelection $selection, float $price, int $seats = 1 ): void {
		global $wpdb;

		$wpdb->insert(
			$this->bookings(),
			array(
				'registration_id' => $registration_id,
				'package_key'     => $selection->packageKey,
				'room_type_key'   => $selection->roomKey,
				'roommate_pref'   => '' === $selection->roommatePref ? null : $selection->roommatePref,
				'price'           => $price,
				'seats'           => $seats,
			),
			array( '%d', '%s', '%s', '%s', '%f', '%d' )
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
	 * Zwraca WSZYSTKIE zgłoszenia spełniające filtry (bez paginacji), chronologicznie — do eksportu.
	 *
	 * @param array{status?: string, type_key?: string, event_id?: int} $filters Filtry.
	 * @return array<int,array<string,mixed>>
	 */
	public function exportRegistrations( array $filters ): array {
		global $wpdb;

		list( $where, $args ) = $this->registrationWhere( $filters );

		if ( array() === $args ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results( "SELECT * FROM {$this->registrations()} ORDER BY id ASC", ARRAY_A );
		} else {
			$rows = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $args ma zmienną, ale dopasowaną liczbę elementów.
				$wpdb->prepare( "SELECT * FROM {$this->registrations()}{$where} ORDER BY id ASC", $args ),
				ARRAY_A
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

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
	 * Sumuje kwoty zgłoszeń zajmujących miejsce (pending+confirmed) w evencie.
	 *
	 * @param int $event_id ID eventu.
	 */
	public function sumPriceTotal( int $event_id ): float {
		global $wpdb;

		$statuses = RegistrationStatus::occupyingValues();
		$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$args     = array_merge( array( $event_id ), $statuses );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(price_total),0) FROM {$this->registrations()} WHERE event_id = %d AND status IN ($in)", $args ) );
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
	 * Bulk-pobiera bookingi noclegu dla wielu zgłoszeń (unika N+1 przy eksporcie).
	 *
	 * @param array<int,int> $ids ID zgłoszeń.
	 * @return array<int,array<string,mixed>> Mapa registration_id => wiersz bookingu.
	 */
	public function accommodationBookingsFor( array $ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		if ( array() === $ids ) {
			return array();
		}

		$in   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in ma zmienną, ale dopasowaną liczbę placeholderów.
			$wpdb->prepare( "SELECT * FROM {$this->bookings()} WHERE registration_id IN ($in) ORDER BY id ASC", $ids ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$map = array();
		foreach ( (array) $rows as $row ) {
			$rid = (int) $row['registration_id'];
			if ( ! isset( $map[ $rid ] ) ) {
				$map[ $rid ] = $row; // pierwszy wygrywa gdy >1 booking.
			}
		}
		return $map;
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
	 * Zapisuje przeliczoną cenę łączną zgłoszenia (bez zmiany innych pól).
	 *
	 * @param int   $id    ID zgłoszenia.
	 * @param float $price Nowa cena łączna.
	 */
	public function updatePrice( int $id, float $price ): void {
		global $wpdb;

		$wpdb->update(
			$this->registrations(),
			array(
				'price_total' => $price,
				'updated_at'  => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%f', '%s' ),
			array( '%d' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Nadpisuje edytowalne pola zgłoszenia (bez zmiany statusu/tokenu/dat cyklu życia).
	 *
	 * @param int    $id             ID zgłoszenia.
	 * @param string $type_key       Klucz typu zgłoszenia.
	 * @param string $email          Adres e-mail.
	 * @param string $name           Imię/nazwa.
	 * @param string $data_json      Odpowiedzi formularza (JSON).
	 * @param float  $price          Cena łączna.
	 * @param int    $companion      Czy zgłoszenie ma osobę towarzyszącą (0/1).
	 * @param string $companion_name Imię/nazwa osoby towarzyszącej.
	 */
	public function updateRegistration( int $id, string $type_key, string $email, string $name, string $data_json, float $price, int $companion = 0, string $companion_name = '' ): void {
		global $wpdb;

		$wpdb->update(
			$this->registrations(),
			array(
				'type_key'       => $type_key,
				'email'          => $email,
				'name'           => $name,
				'data'           => $data_json,
				'price_total'    => $price,
				'companion'      => $companion,
				'companion_name' => $companion_name,
				'updated_at'     => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s', '%f', '%d', '%s', '%s' ),
			array( '%d' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Zwraca zgłoszenia danego e-maila (wszystkie eventy), stronicowane — dla WP Privacy API.
	 *
	 * @param string $email  Adres e-mail zgłaszającego się.
	 * @param int    $limit  Maksymalna liczba wierszy.
	 * @param int    $offset Przesunięcie.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function findByEmailPaged( string $email, int $limit, int $offset ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$this->registrations()} WHERE email = %s ORDER BY id ASC LIMIT %d OFFSET %d",
				$email,
				$limit,
				$offset
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Anonimizuje pola PII zgłoszenia (bez zmiany statusu/typu/ceny/dat) — WP Privacy eraser.
	 * Czyści też imię osoby towarzyszącej; flaga companion ZOSTAJE (zajętość/liczniki spójne).
	 *
	 * @param int $id ID zgłoszenia.
	 */
	public function anonymizeById( int $id ): void {
		global $wpdb;
		$wpdb->update(
			$this->registrations(),
			array(
				'email'          => 'deleted-' . $id . '@example.invalid',
				'name'           => '',
				'data'           => '{}',
				'note'           => '',
				'token'          => '',
				'companion_name' => '',
				'updated_at'     => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Czyści preferencję współlokatora w rezerwacji noclegu zgłoszenia.
	 *
	 * @param int $id ID zgłoszenia.
	 */
	public function anonymizeBookingByRegistration( int $id ): void {
		global $wpdb;
		$wpdb->update(
			$this->bookings(),
			array( 'roommate_pref' => '' ),
			array( 'registration_id' => $id ),
			array( '%s' ),
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
