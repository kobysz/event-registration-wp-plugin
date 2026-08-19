<?php
/**
 * Cron kasujący stare wysłane maile z kolejki.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Cron;

use EvReg\Persistence\MailQueueRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Cron kasujący stare wysłane maile z kolejki.
 *
 * Wiersze failed i queued zostają — decyzja o nich należy do organizatora.
 */
final class PurgeMailQueue {

	public const HOOK           = 'evreg_purge_mail_queue';
	public const RETENTION_DAYS = 30;

	/**
	 * Podpina handler.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );
	}

	/**
	 * Planuje zadanie dzienne, jeśli jeszcze niezaplanowane.
	 */
	public static function schedule(): void {
		if ( false === wp_get_schedule( self::HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::HOOK );
		}
	}

	/**
	 * Usuwa zaplanowane zadanie (dezaktywacja).
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Kasuje wysłane wiersze starsze niż okno retencji.
	 */
	public static function run(): void {
		( new MailQueueRepository() )->purgeSent( gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS ) );
	}
}
