<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Services;

use EvReg\Domain\Accommodation\NightSelection;
use EvReg\Domain\Accommodation\NightsConfig;
use EvReg\Domain\Registration\ManualEntry;
use EvReg\Mail\Subscriber;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Services\ReservationRequest;
use EvReg\Services\ReservationService;
use WP_UnitTestCase;

/**
 * Wpis ręczny organizatora: zgłoszenie wchodzi POTWIERDZONE i bez terminu wygaśnięcia.
 *
 * Ścieżką publiczną taki wpis wylądowałby jako `pending` z 48-godzinnym terminem i mailem
 * „potwierdź rejestrację”, którego nikt nie kliknie — po dwóch dniach cron by go anulował.
 * Tryb dotyczy TYLKO wołań z ManualEntry, więc obie gałęzie mają obok test ścieżki
 * publicznej (bez trzeciego argumentu), który musi zostać przy dzisiejszym wyniku.
 */
final class ManualReservationTest extends WP_UnitTestCase {

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
		// „nie leci mail” asertujemy na jej wierszach, nie na wp_mail — ten odpala dopiero
		// Dispatcher, a idempotencję gwarantuje unikalny indeks kolejki, nie wysyłka.
		Subscriber::register();
	}

	protected function tearDown(): void {
		foreach ( array( 'evreg_registration_reserved', 'evreg_registration_waitlisted', 'evreg_registration_confirmed', 'evreg_registration_expired' ) as $hook ) {
			remove_all_actions( $hook );
		}
		parent::tearDown();
	}

	/**
	 * Zapisuje konfigurację eventu w trybie klasycznym.
	 *
	 * @param array<string,mixed> $overrides Nadpisania kluczy types/accommodation/settings.
	 */
	private function configureClassic( array $overrides = array() ): void {
		( new EventConfigRepository() )->save(
			$this->event_id,
			array_merge(
				array(
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
				),
				$overrides
			)
		);
	}

	/**
	 * Zapisuje konfigurację eventu w trybie „doby”.
	 *
	 * @param array<string,mixed> $overrides Nadpisania kluczy types/accommodation/settings.
	 */
	private function configureNights( array $overrides = array() ): void {
		( new EventConfigRepository() )->save(
			$this->event_id,
			array_merge(
				array(
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
							array(
								'key'   => 'n1920',
								'label' => '19/20 XI',
								'price' => 90.0,
								'seats' => 4,
							),
						),
						'companion_enabled' => false,
					),
					'settings'      => array( 'waitlist_enabled' => true ),
				),
				$overrides
			)
		);
	}

	/**
	 * Limit typu zawężony do jednego miejsca — do testów listy rezerwowej.
	 *
	 * @return array<string,mixed>
	 */
	private function singleSeatType(): array {
		return array(
			'types' => array(
				array(
					'key'      => 'uczestnik',
					'label'    => 'Uczestnik',
					'price'    => 100.0,
					'capacity' => 1,
				),
			),
		);
	}

	/**
	 * Konfiguracja trybu „doby” odczytana z zapisanych meta eventu.
	 */
	private function nightsConfig(): NightsConfig {
		$config = ( new EventConfigRepository() )->get( $this->event_id );

		return NightsConfig::fromArray( is_array( $config['accommodation'] ) ? $config['accommodation'] : array() );
	}

	/**
	 * Buduje żądanie rezerwacji w trybie klasycznym (bez noclegu).
	 *
	 * @param string $email Adres e-mail.
	 */
	private function classicRequest( string $email ): ReservationRequest {
		return new ReservationRequest(
			$email,
			'Jan Kowalski',
			'uczestnik',
			array(
				'__type' => 'uczestnik',
				'email'  => $email,
			)
		);
	}

	/**
	 * Buduje żądanie rezerwacji w trybie „doby”.
	 *
	 * @param string        $email  Adres e-mail.
	 * @param array<string> $nights Klucze wybranych dób.
	 */
	private function nightsRequest( string $email, array $nights = array( 'n1819', 'n1920' ) ): ReservationRequest {
		$selection = NightSelection::fromKeys( $nights, $this->nightsConfig() );

		return new ReservationRequest(
			$email,
			'Jan Kowalski',
			'uczestnik',
			array(
				'__type' => 'uczestnik',
				'email'  => $email,
				'nocleg' => $selection,
			),
			null,
			'',
			false,
			'',
			$selection
		);
	}

	/**
	 * Wiersz zgłoszenia z bazy.
	 *
	 * @param int|null $id ID zgłoszenia.
	 *
	 * @return array<string,mixed>
	 */
	private function row( ?int $id ): array {
		$this->assertNotNull( $id, 'Zgłoszenie miało zostać zapisane.' );

		return (array) $this->repository->findById( (int) $id );
	}

	/**
	 * Klucze szablonów zakolejkowanych maili do uczestnika, w kolejności zapisu.
	 *
	 * Wiersze powiadomień dla organizatora (`admin_new:<hash>`) są celowo odfiltrowane:
	 * ich obecność zależy od ustawień adresów eventu, a ten test pyta o maile, które
	 * dostaje (albo nie dostaje) zgłaszający się.
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

	public function test_manual_entry_is_confirmed_without_expiry(): void {
		$this->configureClassic();

		$result = $this->service->reserve( $this->event_id, $this->classicRequest( 'telefon@example.com' ), new ManualEntry() );

		$this->assertSame( 'reserved', $result->code );

		$row = $this->row( $result->registrationId );
		$this->assertSame( 'confirmed', $row['status'] );
		$this->assertNotEmpty( $row['confirmed_at'] );
		$this->assertNull( $row['expires_at'] );
		$this->assertSame( '1', (string) $row['manual'] );
	}

	public function test_manual_entry_without_notify_queues_no_mail(): void {
		$this->configureClassic();

		$this->service->reserve( $this->event_id, $this->classicRequest( 'cicho@example.com' ), new ManualEntry( false ) );

		// Żaden wiersz, także powiadomienie organizatora: przy notify === false nie leci nic.
		$this->assertSame( 0, $this->queuedRowCount() );
	}

	public function test_manual_entry_with_notify_queues_the_confirmation_mail_not_the_optin(): void {
		// Szablon opt-in niesie link potwierdzający, a ten wiersz jest już potwierdzony —
		// link prowadziłby w nikąd. Dlatego wpis ręczny emituje evreg_registration_confirmed.
		$this->configureClassic();

		$this->service->reserve( $this->event_id, $this->classicRequest( 'glosno@example.com' ), new ManualEntry( true ) );

		$this->assertSame( array( 'confirmed' ), $this->queuedParticipantTemplates() );
	}

	public function test_manual_entry_skips_the_duplicate_email_guard(): void {
		// Organizator zna swoje zgłoszenia i ma powody wpisać ten sam adres dwa razy
		// (rodzina na jednej skrzynce) — blokada duplikatu broni formularza publicznego.
		$this->configureClassic();

		$first  = $this->service->reserve( $this->event_id, $this->classicRequest( 'dup@example.com' ), new ManualEntry() );
		$second = $this->service->reserve( $this->event_id, $this->classicRequest( 'dup@example.com' ), new ManualEntry() );

		$this->assertSame( 'reserved', $first->code );
		$this->assertSame( 'reserved', $second->code );
		$this->assertNotSame( (int) $first->registrationId, (int) $second->registrationId );
		$this->assertCount( 2, $this->repository->findByEmailPaged( 'dup@example.com', 10, 0 ) );

		// Ten sam adres przez formularz publiczny nadal odbija się od blokady.
		$public = $this->service->reserve( $this->event_id, $this->classicRequest( 'dup@example.com' ) );
		$this->assertSame( 'duplicate', $public->code );
	}

	public function test_manual_entry_lands_on_the_waitlist_when_the_type_is_full(): void {
		$this->configureClassic( $this->singleSeatType() );

		$this->assertSame( 'reserved', $this->service->reserve( $this->event_id, $this->classicRequest( 'pierwszy@example.com' ) )->code );

		$result = $this->service->reserve( $this->event_id, $this->classicRequest( 'spozniony@example.com' ), new ManualEntry() );

		$this->assertSame( 'waitlisted', $result->code );
		$this->assertSame( 'type_full', $result->reason );

		$row = $this->row( $result->registrationId );
		$this->assertSame( 'waitlist', $row['status'] );
		$this->assertNull( $row['expires_at'] );
		$this->assertNull( $row['confirmed_at'] );
		$this->assertSame( '1', (string) $row['manual'] );
	}

	public function test_manual_waitlist_entry_honours_the_notify_flag(): void {
		$this->configureClassic( $this->singleSeatType() );
		$this->service->reserve( $this->event_id, $this->classicRequest( 'pierwszy@example.com' ), new ManualEntry() );

		$silent = $this->service->reserve( $this->event_id, $this->classicRequest( 'cicha-rezerwa@example.com' ), new ManualEntry( false ) );
		$this->assertSame( 'waitlisted', $silent->code );
		$this->assertSame( 0, $this->queuedRowCount() );

		$loud = $this->service->reserve( $this->event_id, $this->classicRequest( 'glosna-rezerwa@example.com' ), new ManualEntry( true ) );
		$this->assertSame( 'waitlisted', $loud->code );
		// Lista rezerwowa zachowuje dzisiejszy szablon — to opt-in jest tym, czego nie wysyłamy.
		$this->assertSame( array( 'waitlist' ), $this->queuedParticipantTemplates() );
	}

	public function test_public_path_is_unchanged_without_the_manual_argument(): void {
		$this->configureClassic();

		$result = $this->service->reserve( $this->event_id, $this->classicRequest( 'formularz@example.com' ) );

		$this->assertSame( 'reserved', $result->code );

		$row = $this->row( $result->registrationId );
		$this->assertSame( 'pending', $row['status'] );
		$this->assertNotEmpty( $row['expires_at'] );
		$this->assertNull( $row['confirmed_at'] );
		$this->assertSame( '0', (string) $row['manual'] );
		$this->assertSame( array( 'optin' ), $this->queuedParticipantTemplates() );
	}

	public function test_manual_entry_in_nights_mode_is_confirmed_with_night_rows(): void {
		// Tryb „doby” to świadomie osobna gałąź reserve() — pominięcie jej dałoby wpis
		// ręczny, który mimo znacznika wygasa po 48 godzinach.
		$this->configureNights();

		$result = $this->service->reserve( $this->event_id, $this->nightsRequest( 'doby@example.com' ), new ManualEntry() );

		$this->assertSame( 'reserved', $result->code );
		$this->assertTrue( $result->accommodationGranted );

		$row = $this->row( $result->registrationId );
		$this->assertSame( 'confirmed', $row['status'] );
		$this->assertNotEmpty( $row['confirmed_at'] );
		$this->assertNull( $row['expires_at'] );
		$this->assertSame( '1', (string) $row['manual'] );
		// 100 (typ) + 80 + 90 — wycena trybu „doby” nietknięta.
		$this->assertSame( '270.00', (string) $row['price_total'] );

		$id     = (int) $result->registrationId;
		$nights = $this->repository->bookingNightsFor( array( $id ) );
		$this->assertCount( 2, $nights[ $id ] );
		$this->assertSame( array( 'n1819', 'n1920' ), array_column( $nights[ $id ], 'key' ) );
	}

	public function test_manual_entry_in_nights_mode_honours_the_notify_flag(): void {
		$this->configureNights();

		$this->service->reserve( $this->event_id, $this->nightsRequest( 'cicho-doby@example.com' ), new ManualEntry( false ) );
		$this->assertSame( 0, $this->queuedRowCount() );

		$this->service->reserve( $this->event_id, $this->nightsRequest( 'glosno-doby@example.com' ), new ManualEntry( true ) );
		$this->assertSame( array( 'confirmed' ), $this->queuedParticipantTemplates() );
	}

	public function test_manual_entry_in_nights_mode_skips_the_duplicate_email_guard(): void {
		$this->configureNights();

		$first  = $this->service->reserve( $this->event_id, $this->nightsRequest( 'dup-doby@example.com', array( 'n1819' ) ), new ManualEntry() );
		$second = $this->service->reserve( $this->event_id, $this->nightsRequest( 'dup-doby@example.com', array( 'n1920' ) ), new ManualEntry() );

		$this->assertSame( 'reserved', $first->code );
		$this->assertSame( 'reserved', $second->code );
		$this->assertCount( 2, $this->repository->findByEmailPaged( 'dup-doby@example.com', 10, 0 ) );
	}

	public function test_manual_entry_in_nights_mode_lands_on_the_waitlist_when_the_event_is_full(): void {
		$this->configureNights(
			array(
				'settings' => array(
					'global_cap'       => 1,
					'waitlist_enabled' => true,
				),
			)
		);

		$this->assertSame( 'reserved', $this->service->reserve( $this->event_id, $this->nightsRequest( 'pierwszy@example.com', array( 'n1819' ) ) )->code );

		$result = $this->service->reserve( $this->event_id, $this->nightsRequest( 'spozniony@example.com' ), new ManualEntry() );

		$this->assertSame( 'waitlisted', $result->code );

		$row = $this->row( $result->registrationId );
		$this->assertSame( 'waitlist', $row['status'] );
		$this->assertNull( $row['expires_at'] );
		$this->assertNull( $row['confirmed_at'] );
		$this->assertSame( '1', (string) $row['manual'] );
		// Lista rezerwowa nie zajmuje łóżka — wierszy dób nie ma, życzenie zostaje w `data`.
		$this->assertSame( array(), $this->repository->bookingNightsFor( array( (int) $result->registrationId ) ) );
	}

	public function test_public_path_in_nights_mode_is_unchanged_without_the_manual_argument(): void {
		$this->configureNights();

		$result = $this->service->reserve( $this->event_id, $this->nightsRequest( 'formularz-doby@example.com' ) );

		$this->assertSame( 'reserved', $result->code );

		$row = $this->row( $result->registrationId );
		$this->assertSame( 'pending', $row['status'] );
		$this->assertNotEmpty( $row['expires_at'] );
		$this->assertNull( $row['confirmed_at'] );
		$this->assertSame( '0', (string) $row['manual'] );
		$this->assertSame( array( 'optin' ), $this->queuedParticipantTemplates() );

		$id = (int) $result->registrationId;
		$this->assertCount( 2, $this->repository->bookingNightsFor( array( $id ) )[ $id ] );
	}
}
