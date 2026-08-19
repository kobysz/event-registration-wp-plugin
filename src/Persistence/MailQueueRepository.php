<?php
/**
 * Odczyt i zapis kolejki mailowej.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Persistence;

defined( 'ABSPATH' ) || exit;

/**
 * Odczyt i zapis kolejki mailowej.
 *
 * Jedyny adapter dotykający $wpdb dla tabeli evreg_mail_queue. Tylko czyta/pisze —
 * nie decyduje, co i kiedy wysłać.
 */
final class MailQueueRepository {

	public const STATUS_QUEUED  = 'queued';
	public const STATUS_SENDING = 'sending';
	public const STATUS_SENT    = 'sent';
	public const STATUS_FAILED  = 'failed';

	/**
	 * Zwraca pełną nazwę tabeli kolejki.
	 */
	private function table(): string {
		return Migrations::table( 'mail_queue' );
	}

	/**
	 * Wstawia wiersz w stanie queued. Duplikat (registration_id, template_key) jest pomijany.
	 *
	 * @param array{registration_id: int|null, event_id: int, template_key: string, recipient: string, subject: string, body: string, headers: string, scheduled_at: string} $row Dane wiersza.
	 *
	 * @return bool True, gdy wiersz powstał; false przy duplikacie lub błędzie zapisu.
	 */
	public function insert( array $row ): bool {
		global $wpdb;

		$columns = '(registration_id, event_id, template_key, recipient, subject, body, headers, status, attempts, scheduled_at)';

		if ( null === $row['registration_id'] ) {
			$sql = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"INSERT IGNORE INTO {$this->table()} {$columns} VALUES (NULL, %d, %s, %s, %s, %s, %s, %s, 0, %s)",
				$row['event_id'],
				$row['template_key'],
				$row['recipient'],
				$row['subject'],
				$row['body'],
				$row['headers'],
				self::STATUS_QUEUED,
				$row['scheduled_at']
			);
		} else {
			$sql = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"INSERT IGNORE INTO {$this->table()} {$columns} VALUES (%d, %d, %s, %s, %s, %s, %s, %s, 0, %s)",
				$row['registration_id'],
				$row['event_id'],
				$row['template_key'],
				$row['recipient'],
				$row['subject'],
				$row['body'],
				$row['headers'],
				self::STATUS_QUEUED,
				$row['scheduled_at']
			);
		}

		return 1 === (int) $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Zwraca wiersz kolejki albo null.
	 *
	 * @param int $id ID wiersza.
	 *
	 * @return array<string,mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Zwraca wiersze gotowe do wysyłki, najstarszym terminem naprzód.
	 *
	 * @param string $now   Moment odniesienia (Y-m-d H:i:s, UTC).
	 * @param int    $limit Maksymalna liczba wierszy.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function due( string $now, int $limit ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$this->table()} WHERE status = %s AND scheduled_at <= %s ORDER BY scheduled_at ASC, id ASC LIMIT %d",
				self::STATUS_QUEUED,
				$now,
				$limit
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Przejmuje wiersz do wysyłki. Powodzenie oznacza wyłączność na ten wiersz.
	 *
	 * @param int    $id  ID wiersza.
	 * @param string $now Moment przejęcia (Y-m-d H:i:s, UTC).
	 */
	public function claim( int $id, string $now ): bool {
		global $wpdb;

		$result = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$this->table()} SET status = %s, attempts = attempts + 1, scheduled_at = %s WHERE id = %d AND status = %s",
				self::STATUS_SENDING,
				$now,
				$id,
				self::STATUS_QUEUED
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

		return 1 === (int) $result;
	}

	/**
	 * Oznacza wiersz jako wysłany.
	 *
	 * @param int    $id  ID wiersza.
	 * @param string $now Moment wysyłki (Y-m-d H:i:s, UTC).
	 */
	public function markSent( int $id, string $now ): void {
		global $wpdb;

		$wpdb->update(
			$this->table(),
			array(
				'status'  => self::STATUS_SENT,
				'sent_at' => $now,
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Zwraca wiersz do kolejki z nowym terminem i zapisanym błędem.
	 *
	 * @param int    $id           ID wiersza.
	 * @param string $scheduled_at Termin następnej próby (Y-m-d H:i:s, UTC).
	 * @param string $error        Komunikat błędu.
	 */
	public function reschedule( int $id, string $scheduled_at, string $error ): void {
		global $wpdb;

		$wpdb->update(
			$this->table(),
			array(
				'status'       => self::STATUS_QUEUED,
				'scheduled_at' => $scheduled_at,
				'last_error'   => $error,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Oznacza wiersz jako nieudany na dobre.
	 *
	 * @param int    $id    ID wiersza.
	 * @param string $error Komunikat błędu.
	 */
	public function markFailed( int $id, string $error ): void {
		global $wpdb;

		$wpdb->update(
			$this->table(),
			array(
				'status'     => self::STATUS_FAILED,
				'last_error' => $error,
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Zwraca do kolejki wiersze porzucone w stanie sending.
	 *
	 * @param string $threshold Wiersze przejęte wcześniej niż ten moment (Y-m-d H:i:s, UTC).
	 *
	 * @return int Liczba odzyskanych wierszy.
	 */
	public function recoverStale( string $threshold ): int {
		global $wpdb;

		$result = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$this->table()} SET status = %s WHERE status = %s AND scheduled_at < %s",
				self::STATUS_QUEUED,
				self::STATUS_SENDING,
				$threshold
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Kasuje wysłane wiersze starsze niż podany moment.
	 *
	 * @param string $before Granica retencji (Y-m-d H:i:s, UTC).
	 *
	 * @return int Liczba skasowanych wierszy.
	 */
	public function purgeSent( string $before ): int {
		global $wpdb;

		$result = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"DELETE FROM {$this->table()} WHERE status = %s AND sent_at IS NOT NULL AND sent_at < %s",
				self::STATUS_SENT,
				$before
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

		return false === $result ? 0 : (int) $result;
	}
}
