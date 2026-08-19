<?php
/**
 * Cron wysyłający kolejkę mailową.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Cron;

use EvReg\Domain\Mail\RetryPolicy;
use EvReg\Mail\Dispatcher;
use EvReg\Mail\MailQueue;
use EvReg\Persistence\MailQueueRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Cron wysyłający kolejkę mailową.
 *
 * Zadanie cykliczne (HOOK) i strzał jednorazowy planowany przy kolejkowaniu
 * (IMMEDIATE_HOOK) mają osobne nazwy zdarzeń i wspólny handler. Wspólna nazwa
 * sprawiłaby, że wp_next_scheduled() widziałby strzał jednorazowy i uznał
 * zadanie cykliczne za już zaplanowane.
 */
final class DispatchMail {

	public const HOOK           = 'evreg_dispatch_mail';
	public const IMMEDIATE_HOOK = MailQueue::DISPATCH_HOOK;
	public const INTERVAL       = 'evreg_1min';

	/**
	 * Podpina interwał i oba handlery.
	 */
	public static function register(): void {
		add_filter( 'cron_schedules', array( self::class, 'add_interval' ) );
		add_action( self::HOOK, array( self::class, 'run' ) );
		add_action( self::IMMEDIATE_HOOK, array( self::class, 'run' ) );
	}

	/**
	 * Planuje zadanie cykliczne, jeśli jeszcze niezaplanowane.
	 */
	public static function schedule(): void {
		if ( false === wp_get_schedule( self::HOOK ) ) {
			wp_schedule_event( time(), self::INTERVAL, self::HOOK );
		}
	}

	/**
	 * Usuwa zaplanowane zadania (dezaktywacja).
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::IMMEDIATE_HOOK );
	}

	/**
	 * Dodaje interwał minutowy.
	 *
	 * @param array<string,array<string,mixed>> $schedules Zarejestrowane interwały.
	 * @return array<string,array<string,mixed>>
	 */
	public static function add_interval( array $schedules ): array {
		$schedules[ self::INTERVAL ] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Co minutę (Event Registration)', 'event-registration' ),
		);

		return $schedules;
	}

	/**
	 * Wysyła jedną paczkę wymagalnych maili.
	 */
	public static function run(): void {
		( new Dispatcher( new MailQueueRepository(), new RetryPolicy() ) )->run();
	}
}
