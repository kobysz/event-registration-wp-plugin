<?php
/**
 * Cron wygaszający niepotwierdzone rezerwacje po upływie okna potwierdzenia.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Cron;

use EvReg\Persistence\RegistrationRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Cron wygaszający niepotwierdzone rezerwacje po upływie okna potwierdzenia.
 */
final class ExpirePending {

	public const HOOK     = 'evreg_expire_pending';
	public const INTERVAL = 'evreg_15min';

	/**
	 * Podpina interwał i handler.
	 */
	public static function register(): void {
		add_filter( 'cron_schedules', array( self::class, 'add_interval' ) );
		add_action( self::HOOK, array( self::class, 'run' ) );
	}

	/**
	 * Planuje zadanie, jeśli jeszcze niezaplanowane.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), self::INTERVAL, self::HOOK );
		}
	}

	/**
	 * Usuwa zaplanowane zadanie (dezaktywacja).
	 */
	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::HOOK );

		if ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}

	/**
	 * Dodaje interwał 15-minutowy.
	 *
	 * @param array<string,array<string,mixed>> $schedules Zarejestrowane interwały.
	 * @return array<string,array<string,mixed>>
	 */
	public static function add_interval( array $schedules ): array {
		$schedules[ self::INTERVAL ] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Co 15 minut (Event Registration)', 'event-registration' ),
		);

		return $schedules;
	}

	/**
	 * Wygasza przeterminowane rezerwacje pending i ogłasza zdarzenia.
	 */
	public static function run(): void {
		$expired = ( new RegistrationRepository() )->expirePending( current_time( 'mysql', true ) );

		foreach ( $expired as $registration ) {
			do_action( 'evreg_registration_expired', $registration['id'], $registration['event_id'] );
		}

		if ( array() !== $expired ) {
			do_action( 'evreg_pending_expired', count( $expired ) );
		}
	}
}
