<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Services;

use EvReg\Domain\Accommodation\NightSelection;
use EvReg\Domain\Accommodation\NightsConfig;
use EvReg\Domain\Registration\RegistrationStatus;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Services\ReservationRequest;
use EvReg\Services\ReservationService;
use WP_UnitTestCase;

/**
 * Rezerwacja w trybie „doby”: doba jest jedynym wymiarem pojemności.
 */
final class NightReservationTest extends WP_UnitTestCase {

	private ReservationService $service;

	private RegistrationRepository $repository;

	private int $event_id;

	/**
	 * Zebrane zapytania SQL (test kolejności lock→zajętość).
	 *
	 * @var array<int,string>
	 */
	private array $captured = array();

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'booking_nights', 'locks' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$this->repository = new RegistrationRepository();
		$this->service    = new ReservationService( $this->repository, new EventConfigRepository() );
		$this->event_id   = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
	}

	/**
	 * Zapisuje konfigurację eventu w trybie „doby”.
	 *
	 * @param array<string,mixed> $overrides Nadpisania kluczy types/accommodation/settings.
	 */
	private function configure( array $overrides = array() ): void {
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
						'mode'                   => 'nights',
						'nights'                 => array(
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
						'companion_enabled'      => true,
						'companion_counts_event' => false,
					),
					'settings'      => array( 'waitlist_enabled' => true ),
				),
				$overrides
			)
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
	 * Buduje żądanie rezerwacji w trybie „doby”.
	 *
	 * @param string        $email     Adres e-mail.
	 * @param array<string> $nights    Klucze wybranych dób.
	 * @param bool          $companion Czy ze osobą towarzyszącą.
	 */
	private function request( string $email, array $nights = array(), bool $companion = false ): ReservationRequest {
		$selection = NightSelection::fromKeys( $nights, $this->nightsConfig() );

		return new ReservationRequest(
			$email,
			'Jan',
			'uczestnik',
			array(
				'email'  => $email,
				'nocleg' => $selection,
			),
			null,
			'',
			$companion,
			$companion ? 'Jan T.' : '',
			$selection
		);
	}

	/**
	 * Zajętość dób policzona dla statusów zajmujących miejsce.
	 */
	private function occupancy(): \EvReg\Domain\Capacity\NightOccupancy {
		return $this->repository->nightOccupancy( $this->event_id, RegistrationStatus::occupyingValues() );
	}

	public function test_pair_on_two_nights_takes_two_seats_from_each(): void {
		$this->configure();

		$result = $this->service->reserve( $this->event_id, $this->request( 'pair@example.com', array( 'n1819', 'n1920' ), true ) );

		$this->assertSame( 'reserved', $result->code );
		$this->assertTrue( $result->accommodationGranted );

		$nights = $this->repository->bookingNightsFor( array( (int) $result->registrationId ) );
		$this->assertCount( 2, $nights[ (int) $result->registrationId ] );
		$this->assertSame( 2, $nights[ (int) $result->registrationId ][0]['seats'] );
		$this->assertSame( 2, $nights[ (int) $result->registrationId ][1]['seats'] );

		$occupancy = $this->occupancy();
		$this->assertSame( 2, $occupancy->seatsFor( 'n1819' ) );
		$this->assertSame( 2, $occupancy->seatsFor( 'n1920' ) );
		$this->assertSame( 1, $occupancy->pairsFor( 'n1819' ) );
		$this->assertSame( 1, $occupancy->pairsFor( 'n1920' ) );
	}

	public function test_second_night_full_refuses_the_whole_selection(): void {
		// Cel 5: nie przyjmujemy części po cichu — ani wiersza dla wolnej doby.
		$this->configure(
			array(
				'accommodation' => array(
					'mode'              => 'nights',
					'nights'            => array(
						array(
							'key'   => 'n1819',
							'label' => '18/19 XI',
							'price' => 80.0,
							'seats' => 5,
						),
						array(
							'key'   => 'n1920',
							'label' => '19/20 XI',
							'price' => 90.0,
							'seats' => 1,
						),
					),
					'companion_enabled' => false,
				),
			)
		);

		$first = $this->service->reserve( $this->event_id, $this->request( 'first@example.com', array( 'n1920' ) ) );
		$this->assertSame( 'reserved', $first->code );

		$result = $this->service->reserve( $this->event_id, $this->request( 'both@example.com', array( 'n1819', 'n1920' ) ) );

		$this->assertSame( 'accommodation_full', $result->code );
		$this->assertNull( $result->registrationId );
		$this->assertSame( array(), $this->repository->findByEmailPaged( 'both@example.com', 10, 0 ) );

		// Wolna doba NIE dostaje wiersza — tylko miejsce zajęte przez first@.
		$occupancy = $this->occupancy();
		$this->assertSame( 0, $occupancy->seatsFor( 'n1819' ) );
		$this->assertSame( 1, $occupancy->seatsFor( 'n1920' ) );
	}

	public function test_refusal_names_the_blocking_night(): void {
		$this->configure(
			array(
				'accommodation' => array(
					'mode'              => 'nights',
					'nights'            => array(
						array(
							'key'   => 'n1819',
							'label' => '18/19 XI',
							'price' => 80.0,
							'seats' => 5,
						),
						array(
							'key'   => 'n1920',
							'label' => '19/20 XI',
							'price' => 90.0,
							'seats' => 0,
						),
					),
					'companion_enabled' => false,
				),
			)
		);

		$result = $this->service->reserve( $this->event_id, $this->request( 'named@example.com', array( 'n1819', 'n1920' ) ) );

		$this->assertSame( 'accommodation_full', $result->code );
		$this->assertSame( 'n1920', $result->blockingNight );
	}

	public function test_companion_cap_refuses_a_pair_while_singles_still_fit(): void {
		$this->configure(
			array(
				'accommodation' => array(
					'mode'                   => 'nights',
					'nights'                 => array(
						array(
							'key'           => 'n1819',
							'label'         => '18/19 XI',
							'price'         => 80.0,
							'seats'         => 10,
							'companion_cap' => 1,
						),
					),
					'companion_enabled'      => true,
					'companion_counts_event' => false,
				),
			)
		);

		$first = $this->service->reserve( $this->event_id, $this->request( 'pair1@example.com', array( 'n1819' ), true ) );
		$this->assertSame( 'reserved', $first->code );

		$second = $this->service->reserve( $this->event_id, $this->request( 'pair2@example.com', array( 'n1819' ), true ) );
		$this->assertSame( 'accommodation_full', $second->code );
		$this->assertSame( 'n1819', $second->blockingNight );

		// Limit dotyczy par, nie miejsc — pojedyncza osoba wciąż się mieści (2 z 10 zajęte).
		$single = $this->service->reserve( $this->event_id, $this->request( 'single@example.com', array( 'n1819' ) ) );
		$this->assertSame( 'reserved', $single->code );

		$occupancy = $this->occupancy();
		$this->assertSame( 3, $occupancy->seatsFor( 'n1819' ) );
		$this->assertSame( 1, $occupancy->pairsFor( 'n1819' ) );
	}

	public function test_price_is_the_type_plus_the_nights(): void {
		$this->configure();

		$single = $this->service->reserve( $this->event_id, $this->request( 'solo@example.com', array( 'n1819', 'n1920' ) ) );
		// 100 (typ) + 80 + 90.
		$this->assertSame( '270.00', $this->repository->findById( (int) $single->registrationId )['price_total'] );

		$pair = $this->service->reserve( $this->event_id, $this->request( 'duo@example.com', array( 'n1819', 'n1920' ), true ) );
		// 100 (typ raz) + 2×80 + 2×90.
		$this->assertSame( '440.00', $this->repository->findById( (int) $pair->registrationId )['price_total'] );

		$nights = $this->repository->bookingNightsFor( array( (int) $pair->registrationId ) );
		$this->assertSame( 160.0, $nights[ (int) $pair->registrationId ][0]['price'] );
		$this->assertSame( 180.0, $nights[ (int) $pair->registrationId ][1]['price'] );
	}

	public function test_answers_json_stores_the_night_keys_as_a_plain_list(): void {
		// B15: lista kluczy, nigdy obiekt. Ścieżka klasyczna wkłada do `data` OBIEKT
		// selekcji i ekrany admina czytały inne klucze, niż zapisano — tryb „doby”
		// tego kształtu nie dziedziczy.
		$this->configure();

		$result  = $this->service->reserve( $this->event_id, $this->request( 'shape@example.com', array( 'n1819', 'n1920' ) ) );
		$answers = json_decode( (string) $this->repository->findById( (int) $result->registrationId )['data'], true );

		$this->assertIsArray( $answers );
		$this->assertSame( array( 'n1819', 'n1920' ), $answers['nocleg'] );
	}

	public function test_waitlisted_registration_holds_no_night_rows_but_keeps_its_wish(): void {
		$this->configure( array( 'settings' => array( 'global_cap' => 1, 'waitlist_enabled' => true ) ) );

		$this->service->reserve( $this->event_id, $this->request( 'taken@example.com' ) );

		$result = $this->service->reserve( $this->event_id, $this->request( 'late@example.com', array( 'n1819', 'n1920' ) ) );

		$this->assertSame( 'waitlisted', $result->code );
		$this->assertSame( 'event_full', $result->reason );
		$this->assertFalse( $result->accommodationGranted );

		$id = (int) $result->registrationId;
		$this->assertSame( array(), $this->repository->bookingNightsFor( array( $id ) ) );
		$this->assertSame( 0, $this->occupancy()->seatsFor( 'n1819' ) );

		$row = $this->repository->findById( $id );
		$this->assertSame( 'waitlist', $row['status'] );
		// Życzenie zostaje w odpowiedziach, ale nie jest obciążone ceną dób.
		$this->assertSame( array( 'n1819', 'n1920' ), json_decode( (string) $row['data'], true )['nocleg'] );
		$this->assertSame( '100.00', $row['price_total'] );
	}

	public function test_event_cap_and_type_cap_still_decide_first(): void {
		$this->configure(
			array(
				'settings' => array(
					'global_cap'       => 1,
					'waitlist_enabled' => false,
				),
			)
		);

		$this->assertSame( 'reserved', $this->service->reserve( $this->event_id, $this->request( 'a@example.com' ) )->code );

		$rejected = $this->service->reserve( $this->event_id, $this->request( 'b@example.com', array( 'n1819' ) ) );
		$this->assertSame( 'rejected', $rejected->code );
		$this->assertSame( 'event_full', $rejected->reason );
		$this->assertSame( 0, $this->occupancy()->seatsFor( 'n1819' ) );

		// Ten sam układ, ale limit per typ: decyduje przed bramką dób.
		$this->configure(
			array(
				'types'    => array(
					array(
						'key'      => 'uczestnik',
						'label'    => 'Uczestnik',
						'price'    => 100.0,
						'capacity' => 1,
					),
				),
				'settings' => array( 'waitlist_enabled' => true ),
			)
		);

		$waitlisted = $this->service->reserve( $this->event_id, $this->request( 'c@example.com', array( 'n1819' ) ) );
		$this->assertSame( 'waitlisted', $waitlisted->code );
		$this->assertSame( 'type_full', $waitlisted->reason );
		$this->assertSame( 0, $this->occupancy()->seatsFor( 'n1819' ) );
	}

	public function test_lock_is_taken_before_the_first_occupancy_read(): void {
		$this->configure();

		$this->captured = array();
		add_filter( 'query', array( $this, 'capture_query' ) );
		$result = $this->service->reserve( $this->event_id, $this->request( 'order@example.com', array( 'n1819' ) ) );
		remove_filter( 'query', array( $this, 'capture_query' ) );

		$this->assertSame( 'reserved', $result->code );

		$lock   = $this->first_query_index( '/FROM\s+\S*evreg_locks\s+WHERE\s+event_id\s*=\s*\d+\s+FOR UPDATE/i' );
		$counts = $this->first_query_index( '/SELECT COUNT\(\*\) FROM \S*evreg_registrations/i' );
		$nights = $this->first_query_index( '/FROM\s+\S*evreg_booking_nights/i' );

		$this->assertGreaterThan( -1, $lock, 'reserve() musi wziąć blokadę wiersza eventu.' );
		$this->assertGreaterThan( -1, $counts, 'reserve() musi policzyć zajętość zgłoszeń.' );
		$this->assertGreaterThan( -1, $nights, 'reserve() musi policzyć zajętość dób.' );
		$this->assertLessThan( $counts, $lock, 'lockEvent MUSI poprzedzać pierwszy COUNT zajętości.' );
		$this->assertLessThan( $nights, $lock, 'lockEvent MUSI poprzedzać odczyt zajętości dób.' );
	}

	public function test_partial_night_write_rolls_back_the_whole_reservation(): void {
		// Doba z kluczem dłuższym niż kolumna night_key (varchar(64)) jest odrzucana
		// przez $wpdb BEZ wyjątku — insert zwraca false. Gdyby reserve() nie porównywał
		// liczby zapisanych wierszy z liczbą żądanych, zgłoszenie weszłoby z jedną dobą
		// zamiast dwóch, czyli z niedoliczonymi miejscami.
		$long = str_repeat( 'n', 70 );
		$this->configure(
			array(
				'accommodation' => array(
					'mode'              => 'nights',
					'nights'            => array(
						array(
							'key'   => 'n1819',
							'label' => '18/19 XI',
							'price' => 80.0,
							'seats' => 5,
						),
						array(
							'key'   => $long,
							'label' => 'Doba o zbyt długim kluczu',
							'price' => 90.0,
							'seats' => 5,
						),
					),
					'companion_enabled' => false,
				),
			)
		);

		global $wpdb;
		$wpdb->suppress_errors( true );
		$caught = null;
		try {
			$this->service->reserve( $this->event_id, $this->request( 'partial@example.com', array( 'n1819', $long ) ) );
		} catch ( \Throwable $e ) {
			$caught = $e;
		}
		$wpdb->suppress_errors( false );

		$this->assertInstanceOf( \RuntimeException::class, $caught );
		$this->assertSame( array(), $this->repository->findByEmailPaged( 'partial@example.com', 10, 0 ) );
		$this->assertSame( 0, $this->occupancy()->seatsFor( 'n1819' ) );
	}

	/**
	 * Zapamiętuje zapytanie bez zmiany treści (filtr `query`).
	 *
	 * @param string $query Zapytanie SQL.
	 */
	public function capture_query( $query ) {
		$this->captured[] = (string) $query;

		return $query;
	}

	/**
	 * Indeks pierwszego zebranego zapytania pasującego do wzorca (-1 gdy brak).
	 *
	 * @param string $pattern Wyrażenie regularne.
	 */
	private function first_query_index( string $pattern ): int {
		foreach ( $this->captured as $index => $query ) {
			if ( 1 === preg_match( $pattern, $query ) ) {
				return $index;
			}
		}

		return -1;
	}
}
