<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Services;

use EvReg\Domain\Registration\RegistrationStatus;
use EvReg\Mail\Subscriber;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Services\ReservationService;
use WP_UnitTestCase;

/**
 * Awans wpisu ręcznego z listy rezerwowej: wraca POTWIERDZONY, bez terminu i bez maila.
 *
 * Dzisiejsza promocja ustawia `pending` z 48-godzinnym terminem i ogłasza
 * evreg_registration_reserved, co kolejkuje mail „potwierdź rejestrację” z linkiem.
 * Wpisanemu z telefonu człowiekowi (adres bywa pusty) ten link nic nie daje, więc cron
 * anulowałby go po dwóch dniach — promocja odwracałaby cały sens trybu ręcznego.
 *
 * Obie gałęzie trybów noclegu są świadomie osobne, więc każda ma tu parę testów:
 * wiersz ze znacznikiem ORAZ wiersz publiczny, który musi zostać przy dzisiejszym wyniku.
 */
final class ManualPromotionTest extends WP_UnitTestCase {

	private ReservationService $service;

	private RegistrationRepository $repository;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'booking_nights', 'locks', 'mail_queue' ) as $table ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$this->repository = new RegistrationRepository();
		$this->service    = new ReservationService( $this->repository, new EventConfigRepository() );
		$this->event_id   = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );

		// Kolejka mailowa jest jedynym obserwowalnym skutkiem hooków cyklu życia, więc
		// „nie leci mail” asertujemy na jej wierszach, nie na wp_mail — tego woła dopiero
		// Dispatcher.
		Subscriber::register();
	}

	protected function tearDown(): void {
		foreach ( array( 'evreg_registration_reserved', 'evreg_registration_waitlisted', 'evreg_registration_confirmed', 'evreg_registration_expired' ) as $hook ) {
			remove_all_actions( $hook );
		}
		parent::tearDown();
	}

	/**
	 * Zapisuje konfigurację eventu w trybie klasycznym (bez inwentarza noclegowego).
	 */
	private function configureClassic(): void {
		( new EventConfigRepository() )->save(
			$this->event_id,
			array(
				'schema'        => array(
					'version'  => 1,
					'sections' => array(
						array(
							'key'    => 'dane',
							'title'  => 'Dane',
							'fields' => array(
								array(
									'key'   => '__type',
									'type'  => 'radio',
									'label' => 'Typ',
								),
							),
						),
					),
				),
				'types'         => array(
					array(
						'key'      => 'uczestnik',
						'label'    => 'Uczestnik',
						'price'    => 100.0,
						'capacity' => 5,
					),
				),
				'accommodation' => array(),
				'settings'      => array( 'waitlist_enabled' => true ),
			)
		);
	}

	/**
	 * Zapisuje konfigurację eventu w trybie „doby”.
	 *
	 * Schema niesie pole typu `accommodation` o kluczu `nocleg` — awans czyta spod niego
	 * życzenie dób zapisane w odpowiedziach, bo zgłoszenie z listy rezerwowej nie ma
	 * wierszy dób.
	 */
	private function configureNights(): void {
		( new EventConfigRepository() )->save(
			$this->event_id,
			array(
				'schema'        => array(
					'version'  => 1,
					'sections' => array(
						array(
							'key'    => 'dane',
							'title'  => 'Dane',
							'fields' => array(
								array(
									'key'   => '__type',
									'type'  => 'radio',
									'label' => 'Typ',
								),
								array(
									'key'   => 'nocleg',
									'type'  => 'accommodation',
									'label' => 'Nocleg',
								),
							),
						),
					),
				),
				'types'         => array(
					array(
						'key'   => 'uczestnik',
						'label' => 'Uczestnik',
						'price' => 100.0,
					),
				),
				'accommodation' => array(
					'mode'              => 'nights',
					'nights'            => array(
						array(
							'key'   => 'n1819',
							'label' => '18/19 XI',
							'price' => 80.0,
							'seats' => 4,
						),
					),
					'companion_enabled' => false,
				),
				'settings'      => array( 'waitlist_enabled' => true ),
			)
		);
	}

	/**
	 * Wstawia zgłoszenie na liście rezerwowej — dokładnie takie, jak zapisuje reserve():
	 * bez terminu, bez bookingu, z życzeniem noclegu wyłącznie w odpowiedziach.
	 *
	 * @param string        $email  Adres e-mail.
	 * @param bool          $manual Czy wiersz jest wpisem ręcznym organizatora.
	 * @param array<string> $wish   Klucze wymarzonych dób (tryb „doby”); puste = bez noclegu.
	 */
	private function seedWaitlist( string $email, bool $manual, array $wish = array() ): int {
		static $counter = 0;
		++$counter;

		$answers = array(
			'__type' => 'uczestnik',
			'email'  => $email,
		);

		if ( array() !== $wish ) {
			$answers['nocleg'] = $wish;
		}

		return $this->repository->insertRegistration(
			array(
				'event_id'    => $this->event_id,
				'type_key'    => 'uczestnik',
				'status'      => RegistrationStatus::Waitlist->value,
				'email'       => $email,
				'name'        => 'Jan Kowalski',
				'token'       => str_pad( (string) $counter, 32, 'w', STR_PAD_LEFT ),
				'data'        => (string) wp_json_encode( $answers ),
				'price_total' => 100.0,
				'expires_at'  => null,
				'manual'      => $manual ? 1 : 0,
			)
		);
	}

	/**
	 * Wiersz zgłoszenia z bazy.
	 *
	 * @param int $id ID zgłoszenia.
	 *
	 * @return array<string,mixed>
	 */
	private function row( int $id ): array {
		return (array) $this->repository->findById( $id );
	}

	/**
	 * Klucze szablonów zakolejkowanych maili do uczestnika, w kolejności zapisu.
	 *
	 * Powiadomienia organizatora (`admin_new:<hash>`) są odfiltrowane — ich obecność
	 * zależy od adresów w ustawieniach eventu, a te testy pytają o mail zgłaszającego się.
	 *
	 * @return array<int,string>
	 */
	private function queuedParticipantTemplates(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$keys = (array) $wpdb->get_col( 'SELECT template_key FROM ' . Migrations::table( 'mail_queue' ) . " WHERE template_key NOT LIKE 'admin_new%' ORDER BY id ASC" );

		return array_map( 'strval', $keys );
	}

	/**
	 * Liczba wszystkich wierszy kolejki mailowej (łącznie z powiadomieniami organizatora).
	 */
	private function queuedRowCount(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Migrations::table( 'mail_queue' ) );
	}

	/**
	 * Wiersze dób zgłoszenia (w kolejności zapisu).
	 *
	 * @param int $id ID zgłoszenia.
	 *
	 * @return array<int,array{key:string,label:string,price:float,seats:int}>
	 */
	private function nightsOf( int $id ): array {
		return $this->repository->bookingNightsFor( array( $id ) )[ $id ] ?? array();
	}

	public function test_promoted_manual_entry_is_confirmed_without_expiry_or_mail(): void {
		$this->configureClassic();
		$id = $this->seedWaitlist( 'telefon@example.com', true );

		$result = $this->service->promoteFromWaitlist( $id );

		$this->assertSame( 'promoted', $result->code );

		$row = $this->row( $id );
		$this->assertSame( 'confirmed', $row['status'], 'Wpis ręczny wraca z listy rezerwowej potwierdzony.' );
		$this->assertNotEmpty( $row['confirmed_at'], 'Potwierdzony wiersz musi mieć datę potwierdzenia.' );
		$this->assertNull( $row['expires_at'], 'Termin 48 h oddałby wpis ręczny cronowi anulującemu.' );
		$this->assertSame( '1', (string) $row['manual'], 'Awans nie może zetrzeć pochodzenia wpisu.' );

		// Ani mail opt-in z linkiem, którego nikt nie kliknie, ani powiadomienie dla
		// organizatora, który właśnie patrzy na ten ekran.
		$this->assertSame( 0, $this->queuedRowCount() );
	}

	public function test_promoted_public_entry_is_unchanged(): void {
		$this->configureClassic();
		$id = $this->seedWaitlist( 'formularz@example.com', false );

		$result = $this->service->promoteFromWaitlist( $id );

		$this->assertSame( 'promoted', $result->code );

		$row = $this->row( $id );
		$this->assertSame( 'pending', $row['status'] );
		$this->assertNotNull( $row['expires_at'], 'Zgłoszenie publiczne dalej ma 48 h na potwierdzenie.' );
		$this->assertNull( $row['confirmed_at'] );
		$this->assertSame( array( 'optin' ), $this->queuedParticipantTemplates(), 'Mail opt-in dalej musi lecieć.' );
	}

	public function test_promoted_manual_entry_in_nights_mode_is_confirmed_without_expiry_or_mail(): void {
		$this->configureNights();
		$id = $this->seedWaitlist( 'doby-telefon@example.com', true, array( 'n1819' ) );

		$result = $this->service->promoteFromWaitlist( $id );

		$this->assertSame( 'promoted', $result->code );

		$row = $this->row( $id );
		$this->assertSame( 'confirmed', $row['status'] );
		$this->assertNotEmpty( $row['confirmed_at'] );
		$this->assertNull( $row['expires_at'] );
		$this->assertSame( '1', (string) $row['manual'] );
		$this->assertSame( 0, $this->queuedRowCount() );

		// Dowód, że rozgałęzienie dotknęło statusu i hooka, a NIE arytmetyki miejsc:
		// doba z życzenia jest przyznana i wyceniona jak na ścieżce publicznej.
		$rows = $this->nightsOf( $id );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'n1819', $rows[0]['key'] );
		$this->assertSame( 1, $rows[0]['seats'] );
		$this->assertSame( '180.00', $row['price_total'] );
	}

	public function test_promoted_public_entry_in_nights_mode_is_unchanged(): void {
		$this->configureNights();
		$id = $this->seedWaitlist( 'doby-formularz@example.com', false, array( 'n1819' ) );

		$result = $this->service->promoteFromWaitlist( $id );

		$this->assertSame( 'promoted', $result->code );

		$row = $this->row( $id );
		$this->assertSame( 'pending', $row['status'] );
		$this->assertNotNull( $row['expires_at'] );
		$this->assertNull( $row['confirmed_at'] );
		$this->assertSame( array( 'optin' ), $this->queuedParticipantTemplates() );
		$this->assertCount( 1, $this->nightsOf( $id ) );
		$this->assertSame( '180.00', $row['price_total'] );
	}
}
