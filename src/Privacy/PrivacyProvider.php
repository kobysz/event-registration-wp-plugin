<?php
/**
 * Integracja z WP Privacy API: eksporter i anonimizator danych zgłoszeń.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Privacy;

use EvReg\Admin\RegistrationsListTable;
use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Export\RegistrationExportMapper;
use EvReg\Domain\Registration\RegistrationTypeCollection;
use EvReg\Frontend\EventFormLoader;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\MailQueueRepository;
use EvReg\Persistence\RegistrationRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Rejestruje eksporter i anonimizator danych osobowych zgłoszeń w WP Privacy API
 * oraz dokłada treść do polityki prywatności.
 */
final class PrivacyProvider {

	private const PER_PAGE = 50;

	/**
	 * Podpina filtry/akcje WP Privacy API.
	 */
	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'register_eraser' ) );
		add_action( 'admin_init', array( self::class, 'add_policy_content' ) );
	}

	/**
	 * Rejestruje eksporter danych osobowych.
	 *
	 * @param array<string,mixed> $exporters Zarejestrowani eksporterzy.
	 * @return array<string,mixed>
	 */
	public static function register_exporter( array $exporters ): array {
		$exporters['event-registration'] = array(
			'exporter_friendly_name' => __( 'Zgłoszenia (Event Registration)', 'event-registration' ),
			'callback'               => array( new self(), 'export' ),
		);
		return $exporters;
	}

	/**
	 * Rejestruje anonimizator danych osobowych.
	 *
	 * @param array<string,mixed> $erasers Zarejestrowani anonimizatorzy.
	 * @return array<string,mixed>
	 */
	public static function register_eraser( array $erasers ): array {
		$erasers['event-registration'] = array(
			'eraser_friendly_name' => __( 'Zgłoszenia (Event Registration)', 'event-registration' ),
			'callback'             => array( new self(), 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Dokłada treść o przechowywanych danych do polityki prywatności.
	 */
	public static function add_policy_content(): void {
		$content = __( 'Wtyczka Event Registration przechowuje dane zgłoszeń na wydarzenia: adres e-mail, imię i nazwisko, odpowiedzi z formularza, wybór noclegu oraz treść wysłanych potwierdzeń. Dane są przechowywane do czasu usunięcia zgłoszenia lub odinstalowania wtyczki.', 'event-registration' );
		wp_add_privacy_policy_content( __( 'Event Registration', 'event-registration' ), wp_kses_post( wpautop( $content ) ) );
	}

	/**
	 * Eksporter WP Privacy API: zwraca zgłoszenia powiązane z adresem e-mail.
	 *
	 * @param string $email Adres e-mail, dla którego zbierane są dane.
	 * @param int    $page  Numer strony (1-indeksowany).
	 * @return array{data:array<int,array<string,mixed>>,done:bool}
	 */
	public function export( string $email, int $page = 1 ): array {
		$repo        = new RegistrationRepository();
		$mails       = new MailQueueRepository();
		$offset      = ( max( 1, $page ) - 1 ) * self::PER_PAGE;
		$rows        = $repo->findByEmailPaged( $email, self::PER_PAGE, $offset );
		$mapper      = new RegistrationExportMapper();
		$config_repo = new EventConfigRepository();
		$loader      = new EventFormLoader( $config_repo );

		$export = array();
		foreach ( $rows as $row ) {
			$id       = (int) $row['id'];
			$event_id = (int) $row['event_id'];
			$schema   = $loader->load( $event_id );
			$config   = $config_repo->get( $event_id );
			$types    = RegistrationTypeCollection::fromArray( is_array( $config['types'] ) ? $config['types'] : array() );
			$acc      = AccommodationConfig::fromArray( is_array( $config['accommodation'] ) ? $config['accommodation'] : array() );

			$data = array(
				array(
					'name'  => __( 'Status', 'event-registration' ),
					'value' => RegistrationsListTable::status_label( (string) $row['status'] ),
				),
				array(
					'name'  => __( 'Typ', 'event-registration' ),
					'value' => ( $types->get( (string) $row['type_key'] )?->label ) ?? (string) $row['type_key'],
				),
				array(
					'name'  => __( 'E-mail', 'event-registration' ),
					'value' => (string) $row['email'],
				),
				array(
					'name'  => __( 'Imię i nazwisko', 'event-registration' ),
					'value' => (string) $row['name'],
				),
				array(
					'name'  => __( 'Cena', 'event-registration' ),
					'value' => (string) $row['price_total'],
				),
				array(
					'name'  => __( 'Utworzono', 'event-registration' ),
					'value' => (string) $row['created_at'],
				),
				array(
					'name'  => __( 'Potwierdzono', 'event-registration' ),
					'value' => (string) ( $row['confirmed_at'] ?? '' ),
				),
				array(
					'name'  => __( 'Notatka', 'event-registration' ),
					'value' => (string) ( $row['note'] ?? '' ),
				),
			);

			// Odpowiedzi (reuse mapper 5C).
			if ( null !== $schema ) {
				$answers = json_decode( (string) $row['data'], true );
				$answers = is_array( $answers ) ? $answers : array();
				$cells   = $mapper->answerCells( $answers, $schema );
				foreach ( $mapper->answerColumns( $schema ) as $i => $col ) {
					$data[] = array(
						'name'  => $col['label'],
						'value' => $cells[ $i ] ?? '',
					);
				}
				$acc_cells = $mapper->accommodationCells( $repo->findAccommodationBooking( $id ), $acc );
				$data[]    = array(
					'name'  => __( 'Nocleg', 'event-registration' ),
					'value' => trim( $acc_cells['package'] . ' ' . $acc_cells['room'] . ' ' . $acc_cells['roommate'] ),
				);
			}

			// Historia maili (spec §6: recipient/subject/dane wysyłki).
			foreach ( $mails->findByRegistration( $id ) as $mail_row ) {
				$data[] = array(
					'name'  => __( 'E-mail: adresat', 'event-registration' ),
					'value' => (string) $mail_row['recipient'],
				);
				$data[] = array(
					'name'  => __( 'E-mail: temat', 'event-registration' ),
					'value' => (string) $mail_row['subject'],
				);
				$data[] = array(
					'name'  => __( 'E-mail: zaplanowano', 'event-registration' ),
					'value' => (string) $mail_row['scheduled_at'],
				);
				$data[] = array(
					'name'  => __( 'E-mail: wysłano', 'event-registration' ),
					'value' => (string) ( $mail_row['sent_at'] ?? '' ),
				);
				$data[] = array(
					'name'  => __( 'E-mail: status', 'event-registration' ),
					'value' => (string) $mail_row['status'],
				);
			}

			$export[] = array(
				'group_id'    => 'evreg_registration',
				'group_label' => __( 'Zgłoszenia (Event Registration)', 'event-registration' ),
				'item_id'     => 'evreg-registration-' . $id,
				'data'        => $data,
			);
		}

		return array(
			'data' => $export,
			'done' => count( $rows ) < self::PER_PAGE,
		);
	}

	/**
	 * Anonimizator WP Privacy API: anonimizuje zgłoszenia powiązane z adresem e-mail.
	 *
	 * Zawsze pobiera pierwszą stronę POZOSTAŁYCH dopasowań (offset=0), nie offset z $page:
	 * anonimizacja zmienia e-mail, więc zanonimizowane wiersze wypadają z dopasowania między
	 * kolejnymi wywołaniami WP. Przesuwanie offsetu o self::PER_PAGE pomijałoby resztę wierszy
	 * dla adresów z >PER_PAGE zgłoszeń (WP wywołuje erase() w pętli aż done=true).
	 *
	 * @param string $email Adres e-mail, dla którego anonimizowane są dane.
	 * @param int    $page  Numer strony (1-indeksowany); przyjmowany bo WP go przekazuje, nieużywany do offsetu.
	 * @return array{items_removed:bool,items_retained:bool,messages:array<int,string>,done:bool}
	 */
	public function erase( string $email, int $page = 1 ): array {
		$repo  = new RegistrationRepository();
		$mails = new MailQueueRepository();
		$rows  = $repo->findByEmailPaged( $email, self::PER_PAGE, 0 );

		foreach ( $rows as $row ) {
			$id = (int) $row['id'];
			$repo->anonymizeById( $id );
			$repo->anonymizeBookingByRegistration( $id );
			$mails->anonymizeByRegistration( $id );
		}

		$count    = count( $rows );
		$messages = $count > 0
			? array(
				sprintf(
					/* translators: %d: liczba zgłoszeń */
					__( 'Zanonimizowano dane %d zgłoszeń.', 'event-registration' ),
					$count
				),
			)
			: array();

		return array(
			'items_removed'  => false,
			'items_retained' => $count > 0,
			'messages'       => $messages,
			'done'           => $count < self::PER_PAGE,
		);
	}
}
