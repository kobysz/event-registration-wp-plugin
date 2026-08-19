<?php
/**
 * Zamiana zdarzeń cyklu życia zgłoszenia na maile w kolejce.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Mail;

use EvReg\Domain\Mail\TemplateRenderer;
use EvReg\Frontend\EventFormLoader;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\MailQueueRepository;
use EvReg\Persistence\MailTemplateRepository;
use EvReg\Persistence\RegistrationRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Zamiana zdarzeń cyklu życia zgłoszenia na maile w kolejce.
 */
final class Subscriber {

	/**
	 * Podpina nasłuch zdarzeń cyklu życia zgłoszenia.
	 */
	public static function register(): void {
		add_action( 'evreg_registration_reserved', array( self::class, 'on_reserved' ), 10, 2 );
		add_action( 'evreg_registration_waitlisted', array( self::class, 'on_waitlisted' ), 10, 2 );
		add_action( 'evreg_registration_confirmed', array( self::class, 'on_confirmed' ), 10, 2 );
		add_action( 'evreg_registration_expired', array( self::class, 'on_expired' ), 10, 2 );
	}

	/**
	 * Zgłoszenie przyjęte: opt-in do uczestnika, powiadomienie do organizatora.
	 *
	 * Hooki cyklu życia odpalają się wewnątrz try/catch ReservationService::reserve()
	 * PO COMMIT — rzucony stąd wyjątek trafiłby do (no-op) ROLLBACK i wypłynąłby jako
	 * fałszywa porażka rezerwacji, mimo że wpis jest już trwale zapisany. Dlatego cała
	 * treść handlera jest osłonięta: awaria kolejkowania maila nigdy nie ma wyglądać
	 * jak awaria rezerwacji.
	 *
	 * @param int $registration_id ID zgłoszenia.
	 * @param int $event_id        ID eventu.
	 */
	public static function on_reserved( int $registration_id, int $event_id ): void {
		try {
			self::queue( $registration_id, $event_id, DefaultTemplates::KEY_OPTIN, true );
			self::queue_admin( $registration_id, $event_id );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'evreg mail subscriber failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Zgłoszenie na liście rezerwowej.
	 *
	 * Patrz komentarz przy on_reserved(): handler musi być exception-safe.
	 *
	 * @param int $registration_id ID zgłoszenia.
	 * @param int $event_id        ID eventu.
	 */
	public static function on_waitlisted( int $registration_id, int $event_id ): void {
		try {
			self::queue( $registration_id, $event_id, DefaultTemplates::KEY_WAITLIST, true );
			self::queue_admin( $registration_id, $event_id );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'evreg mail subscriber failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Zgłoszenie potwierdzone.
	 *
	 * Patrz komentarz przy on_reserved(): handler musi być exception-safe.
	 *
	 * @param int $registration_id ID zgłoszenia.
	 * @param int $event_id        ID eventu.
	 */
	public static function on_confirmed( int $registration_id, int $event_id ): void {
		try {
			self::queue( $registration_id, $event_id, DefaultTemplates::KEY_CONFIRMED, true );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'evreg mail subscriber failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Rezerwacja wygasła.
	 *
	 * Patrz komentarz przy on_reserved(): handler musi być exception-safe.
	 *
	 * @param int $registration_id ID zgłoszenia.
	 * @param int $event_id        ID eventu.
	 */
	public static function on_expired( int $registration_id, int $event_id ): void {
		try {
			self::queue( $registration_id, $event_id, DefaultTemplates::KEY_EXPIRED, true );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'evreg mail subscriber failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Kolejkuje mail do uczestnika.
	 *
	 * @param int    $registration_id ID zgłoszenia.
	 * @param int    $event_id        ID eventu.
	 * @param string $template_key    Klucz szablonu.
	 * @param bool   $immediate       Czy wymusić natychmiastowy przebieg dispatchera.
	 */
	private static function queue( int $registration_id, int $event_id, string $template_key, bool $immediate ): void {
		$row = ( new RegistrationRepository() )->findById( $registration_id );

		if ( null === $row ) {
			return;
		}

		self::mailQueue()->enqueue(
			$template_key,
			$event_id,
			$registration_id,
			(string) $row['email'],
			self::placeholders()->build( $row ),
			array(),
			$immediate
		);
	}

	/**
	 * Kolejkuje powiadomienia dla organizatorów — po jednym wierszu na adres.
	 *
	 * @param int $registration_id ID zgłoszenia.
	 * @param int $event_id        ID eventu.
	 */
	private static function queue_admin( int $registration_id, int $event_id ): void {
		$row = ( new RegistrationRepository() )->findById( $registration_id );

		if ( null === $row ) {
			return;
		}

		$values  = self::placeholders()->build( $row );
		$queue   = self::mailQueue();
		$headers = array( 'Reply-To: ' . (string) $row['email'] );

		foreach ( self::recipients( $event_id ) as $recipient ) {
			$queue->enqueue(
				DefaultTemplates::KEY_ADMIN_NEW . ':' . md5( $recipient ),
				$event_id,
				$registration_id,
				$recipient,
				$values,
				$headers
			);
		}
	}

	/**
	 * Zwraca adresy organizatorów: z ustawień eventu, a gdy pusto — adres administratora strony.
	 *
	 * @param int $event_id ID eventu.
	 *
	 * @return array<int,string>
	 */
	private static function recipients( int $event_id ): array {
		$settings = ( new EventConfigRepository() )->get( $event_id )['settings'];
		$raw      = array();

		if ( is_array( $settings ) && isset( $settings['notify_emails'] ) ) {
			$configured = $settings['notify_emails'];
			$raw        = is_array( $configured ) ? $configured : explode( ',', (string) $configured );
		}

		$emails = array();

		foreach ( $raw as $candidate ) {
			$email = sanitize_email( trim( (string) $candidate ) );

			if ( '' !== $email ) {
				$emails[] = $email;
			}
		}

		if ( array() === $emails ) {
			$fallback = sanitize_email( (string) get_option( 'admin_email' ) );

			if ( '' !== $fallback ) {
				$emails[] = $fallback;
			}
		}

		return array_values( array_unique( $emails ) );
	}

	/**
	 * Składa kolejkę mailową.
	 */
	private static function mailQueue(): MailQueue {
		return new MailQueue(
			new MailQueueRepository(),
			new TemplateResolver( new MailTemplateRepository() ),
			new TemplateRenderer()
		);
	}

	/**
	 * Składa fabrykę placeholderów.
	 */
	private static function placeholders(): PlaceholderFactory {
		$config = new EventConfigRepository();

		return new PlaceholderFactory( $config, new RegistrationRepository(), new EventFormLoader( $config ) );
	}
}
