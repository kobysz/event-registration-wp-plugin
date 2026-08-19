<?php
/**
 * Wysyłka kolejki mailowej z ponawianiem.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Mail;

use EvReg\Domain\Mail\RetryPolicy;
use EvReg\Persistence\MailQueueRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Wysyłka kolejki mailowej z ponawianiem.
 */
final class Dispatcher {

	/**
	 * Po tylu sekundach wiersz w stanie sending uznajemy za porzucony.
	 */
	public const STALE_AFTER = 300;

	/**
	 * Domyślna liczba wierszy obsłużonych w jednym przebiegu.
	 */
	public const DEFAULT_BATCH = 20;

	/**
	 * Tworzy dispatchera.
	 *
	 * @param MailQueueRepository $queue  Repozytorium kolejki.
	 * @param RetryPolicy         $policy Polityka ponawiania.
	 */
	public function __construct(
		private readonly MailQueueRepository $queue,
		private readonly RetryPolicy $policy
	) {
	}

	/**
	 * Odzyskuje porzucone wiersze i wysyła jedną paczkę wymagalnych maili.
	 */
	public function run(): void {
		$this->queue->recoverStale( gmdate( 'Y-m-d H:i:s', time() - self::STALE_AFTER ) );

		foreach ( $this->queue->due( current_time( 'mysql', true ), $this->batchSize() ) as $row ) {
			$id = (int) $row['id'];

			if ( ! $this->queue->claim( $id, current_time( 'mysql', true ) ) ) {
				continue;
			}

			$error = '';

			if ( $this->send( $row, $error ) ) {
				$this->queue->markSent( $id, current_time( 'mysql', true ) );
				continue;
			}

			$delay = $this->policy->next( (int) $row['attempts'] + 1 );

			if ( null === $delay ) {
				$this->queue->markFailed( $id, $error );
				continue;
			}

			$this->queue->reschedule( $id, gmdate( 'Y-m-d H:i:s', time() + $delay ), $error );
		}
	}

	/**
	 * Zwraca rozmiar batcha, z filtrem i zabezpieczeniem przed wartością bezsensowną.
	 */
	private function batchSize(): int {
		$size = (int) apply_filters( 'evreg_mail_batch_size', self::DEFAULT_BATCH );

		return $size > 0 ? $size : self::DEFAULT_BATCH;
	}

	/**
	 * Wysyła pojedynczy wiersz. Powód porażki ląduje w $error.
	 *
	 * @param array<string,mixed> $row   Wiersz kolejki.
	 * @param string              $error Referencja na komunikat błędu.
	 */
	private function send( array $row, string &$error ): bool {
		$captured = '';
		$listener = static function ( $failure ) use ( &$captured ): void {
			if ( $failure instanceof WP_Error ) {
				$captured = $failure->get_error_message();
			}
		};

		add_action( 'wp_mail_failed', $listener );

		try {
			$sent = wp_mail(
				(string) $row['recipient'],
				(string) $row['subject'],
				(string) $row['body'],
				$this->headers( $row )
			);
		} catch ( \Throwable $e ) {
			$sent     = false;
			$captured = $e->getMessage();
		} finally {
			remove_action( 'wp_mail_failed', $listener );
		}

		if ( ! $sent && '' === $captured ) {
			$captured = __( 'wp_mail zwróciło false bez podania przyczyny.', 'event-registration' );
		}

		$error = $captured;

		return (bool) $sent;
	}

	/**
	 * Dekoduje nagłówki maila z wiersza kolejki.
	 *
	 * @param array<string,mixed> $row Wiersz kolejki.
	 *
	 * @return array<int,string>
	 */
	private function headers( array $row ): array {
		$raw = (string) ( $row['headers'] ?? '' );

		if ( '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$headers = array();

		foreach ( $decoded as $header ) {
			if ( is_string( $header ) ) {
				$headers[] = $header;
			}
		}

		return $headers;
	}
}
