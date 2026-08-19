<?php
/**
 * Kolejkowanie maili z renderowaniem treści w chwili zapisu.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Mail;

use EvReg\Domain\Mail\Placeholders;
use EvReg\Domain\Mail\TemplateRenderer;
use EvReg\Persistence\MailQueueRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Kolejkowanie maili z renderowaniem treści w chwili zapisu.
 *
 * Treść jest snapshotem: późniejsza edycja szablonu nie zmienia tego,
 * co już czeka w kolejce, a dispatcher nie potrzebuje eventu ani configu.
 */
final class MailQueue {

	/**
	 * Zdarzenie jednorazowe wymuszające natychmiastowy przebieg dispatchera.
	 *
	 * Osobne od cyklicznego evreg_dispatch_mail: wp_next_scheduled() nie odróżnia
	 * zdarzenia jednorazowego od cyklicznego, więc wspólna nazwa blokowałaby
	 * zaplanowanie zadania cyklicznego. Kolejka zna samą nazwę, nie klasę crona.
	 */
	public const DISPATCH_HOOK = 'evreg_dispatch_mail_now';

	/**
	 * Tworzy kolejkę.
	 *
	 * @param MailQueueRepository $queue     Repozytorium kolejki.
	 * @param TemplateResolver    $templates Rozstrzyganie szablonów.
	 * @param TemplateRenderer    $renderer  Podstawianie placeholderów.
	 */
	public function __construct(
		private readonly MailQueueRepository $queue,
		private readonly TemplateResolver $templates,
		private readonly TemplateRenderer $renderer
	) {
	}

	/**
	 * Wstawia mail do kolejki. Duplikat dla tego samego zgłoszenia i szablonu jest pomijany.
	 *
	 * @param string            $template_key    Klucz szablonu (może zawierać wariant po dwukropku).
	 * @param int               $event_id        ID eventu.
	 * @param int|null          $registration_id ID zgłoszenia albo null.
	 * @param string            $recipient       Adres odbiorcy.
	 * @param Placeholders      $values          Wartości placeholderów.
	 * @param array<int,string> $headers        Nagłówki maila (np. Reply-To).
	 * @param bool              $immediate       Czy zaplanować natychmiastowy przebieg dispatchera.
	 *
	 * @return bool True, gdy wiersz powstał.
	 */
	public function enqueue(
		string $template_key,
		int $event_id,
		?int $registration_id,
		string $recipient,
		Placeholders $values,
		array $headers = array(),
		bool $immediate = false
	): bool {
		$address = sanitize_email( $recipient );

		if ( '' === $address ) {
			return false;
		}

		$template = $this->templates->resolve( $event_id, $template_key );

		if ( '' === $template['subject'] && '' === $template['body'] ) {
			return false;
		}

		$inserted = $this->queue->insert(
			array(
				'registration_id' => $registration_id,
				'event_id'        => $event_id,
				'template_key'    => $template_key,
				'recipient'       => $address,
				'subject'         => $this->renderer->render( $template['subject'], $values ),
				'body'            => $this->renderer->render( $template['body'], $values ),
				'headers'         => array() === $headers ? '' : (string) wp_json_encode( array_values( $headers ) ),
				'scheduled_at'    => current_time( 'mysql', true ),
			)
		);

		if ( $inserted && $immediate ) {
			wp_schedule_single_event( time(), self::DISPATCH_HOOK );
		}

		return $inserted;
	}
}
