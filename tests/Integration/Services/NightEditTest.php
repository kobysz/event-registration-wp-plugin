<?php
/**
 * Cykl życia zgłoszenia w trybie „doby”: edycja, anulowanie, awans, twarde usunięcie.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Services;

use EvReg\Domain\Accommodation\NightSelection;
use EvReg\Domain\Accommodation\NightsConfig;
use EvReg\Domain\Capacity\NightOccupancy;
use EvReg\Domain\Registration\RegistrationStatus;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Services\ReservationRequest;
use EvReg\Services\ReservationService;
use WP_UnitTestCase;

/**
 * Edycja odpowiedzi, anulowanie, awans z listy rezerwowej i twarde usunięcie
 * w trybie „doby”: wiersze dób muszą ginąć razem z roszczeniem do miejsc.
 */
final class NightEditTest extends WP_UnitTestCase {

	private ReservationService $service;

	private RegistrationRepository $repository;

	private int $event_id;

	/**
	 * Zebrane zapytania SQL (testy kolejności lock→zajętość).
	 *
	 * @var array<int,string>
	 */
	private array $captured = array();

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'booking_nights', 'locks', 'mail_queue' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$this->repository = new RegistrationRepository();
		$this->service    = new ReservationService( $this->repository, new EventConfigRepository() );
		$this->event_id   = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
		$this->configure();
	}

	protected function tearDown(): void {
		foreach ( array( 'evreg_registration_reserved', 'evreg_registration_waitlisted', 'evreg_registration_confirmed', 'evreg_registration_expired' ) as $hook ) {
			remove_all_actions( $hook );
		}
		parent::tearDown();
	}

	/**
	 * Zapisuje konfigurację eventu w trybie „doby”.
	 *
	 * Schema niesie pole typu `accommodation` o kluczu `nocleg` — awans z listy
	 * rezerwowej czyta spod niego życzenie zapisane w odpowiedziach.
	 *
	 * @param array<int,array<string,mixed>>|null $nights   Doby; null = domyślne dwie.
	 * @param array<string,mixed>                 $settings Nadpisania ustawień.
	 */
	private function configure( ?array $nights = null, array $settings = array() ): void {
		$nights = null === $nights
			? array(
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
			)
			: $nights;

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
									'key'      => 'email',
									'type'     => 'email',
									'label'    => 'E-mail',
									'required' => true,
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
					'mode'                   => 'nights',
					'nights'                 => $nights,
					'companion_enabled'      => true,
					'companion_counts_event' => false,
				),
				'settings'      => array_merge( array( 'waitlist_enabled' => true ), $settings ),
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
	 * Buduje żądanie (rezerwacji albo edycji) w trybie „doby”.
	 *
	 * @param string        $email     Adres e-mail.
	 * @param array<string> $nights    Klucze wybranych dób.
	 * @param bool          $companion Czy z osobą towarzyszącą.
	 */
	private function request( string $email, array $nights = array(), bool $companion = false ): ReservationRequest {
		$selection = NightSelection::fromKeys( $nights, $this->nightsConfig() );

		return new ReservationRequest(
			$email,
			'Jan',
			'uczestnik',
			array(
				'__type' => 'uczestnik',
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
	 * Rezerwuje zgłoszenie i zwraca jego ID.
	 *
	 * @param string        $email     Adres e-mail.
	 * @param array<string> $nights    Klucze wybranych dób.
	 * @param bool          $companion Czy z osobą towarzyszącą.
	 */
	private function reserve( string $email, array $nights, bool $companion = false ): int {
		$result = $this->service->reserve( $this->event_id, $this->request( $email, $nights, $companion ) );
		$this->assertSame( 'reserved', $result->code, 'Rezerwacja fixture musi się udać.' );

		return (int) $result->registrationId;
	}

	/**
	 * Wstawia zgłoszenie na liście rezerwowej z życzeniem dób w odpowiedziach.
	 *
	 * @param string        $email Adres e-mail.
	 * @param array<string> $wish  Klucze wymarzonych dób.
	 */
	private function seedWaitlist( string $email, array $wish ): int {
		static $counter = 0;
		++$counter;

		return $this->repository->insertRegistration(
			array(
				'event_id'    => $this->event_id,
				'type_key'    => 'uczestnik',
				'status'      => RegistrationStatus::Waitlist->value,
				'email'       => $email,
				'name'        => 'Jan',
				'token'       => str_pad( (string) $counter, 32, 'w', STR_PAD_LEFT ),
				'data'        => (string) wp_json_encode(
					array(
						'__type' => 'uczestnik',
						'email'  => $email,
						'nocleg' => $wish,
					)
				),
				'price_total' => 100.0,
				'expires_at'  => null,
			)
		);
	}

	/**
	 * Zajętość dób policzona dla statusów zajmujących miejsce.
	 */
	private function occupancy(): NightOccupancy {
		return $this->repository->nightOccupancy( $this->event_id, RegistrationStatus::occupyingValues() );
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

	public function test_edit_that_moves_the_set_rewrites_the_rows_and_the_price(): void {
		$id = $this->reserve( 'mover@example.com', array( 'n1819' ) );
		$this->assertSame( '180.00', $this->repository->findById( $id )['price_total'] );

		$result = $this->service->editAnswers( $id, $this->request( 'mover@example.com', array( 'n1920' ) ) );

		$this->assertSame( 'edited', $result->code );

		$rows = $this->nightsOf( $id );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'n1920', $rows[0]['key'] );
		$this->assertSame( 1, $rows[0]['seats'] );
		$this->assertSame( 90.0, $rows[0]['price'] );
		$this->assertSame( '190.00', $this->repository->findById( $id )['price_total'] );

		$occupancy = $this->occupancy();
		$this->assertSame( 0, $occupancy->seatsFor( 'n1819' ) );
		$this->assertSame( 1, $occupancy->seatsFor( 'n1920' ) );
	}

	public function test_edit_that_changes_nothing_keeps_the_rows_and_the_price(): void {
		// Jak B-occupancy dla slotu: niezwiązana edycja nie może przeliczyć ceny
		// według bieżącego cennika ani skasować wierszy.
		$id = $this->reserve( 'stable@example.com', array( 'n1819', 'n1920' ) );
		$this->assertSame( '270.00', $this->repository->findById( $id )['price_total'] );
		$before = $this->nightsOf( $id );

		// Cennik rośnie i kolejność dób w konfiguracji się odwraca. Zbiór porównujemy
		// jako ZBIÓR (kolejność nic nie znaczy), a cena dób jest snapshotem w wierszach,
		// nie funkcją dzisiejszego cennika.
		$this->configure(
			array(
				array(
					'key'   => 'n1920',
					'label' => '19/20 XI',
					'price' => 600.0,
					'seats' => 4,
				),
				array(
					'key'   => 'n1819',
					'label' => '18/19 XI',
					'price' => 500.0,
					'seats' => 4,
				),
			)
		);

		$result = $this->service->editAnswers( $id, $this->request( 'zmiana@example.com', array( 'n1819', 'n1920' ) ) );

		$this->assertSame( 'edited', $result->code );
		// Edycja niezwiązana z noclegiem przeszła (zmieniony e-mail), cena została.
		$this->assertSame( 'zmiana@example.com', $this->repository->findById( $id )['email'] );
		$this->assertSame( '270.00', $this->repository->findById( $id )['price_total'] );

		$rows = $this->nightsOf( $id );
		$this->assertCount( 2, $rows );
		$this->assertSame( array( 'n1819', 'n1920' ), array( $rows[0]['key'], $rows[1]['key'] ) );
		$this->assertSame( 80.0, $rows[0]['price'] );
		$this->assertSame( 90.0, $rows[1]['price'] );
		$this->assertSame( $before, $rows );
		$this->assertSame( 1, $this->occupancy()->seatsFor( 'n1819' ) );
	}

	public function test_edit_into_a_full_night_is_refused_naming_it(): void {
		$this->configure(
			array(
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
					'seats' => 1,
				),
			)
		);

		$this->reserve( 'holder@example.com', array( 'n1920' ) );
		$mover = $this->reserve( 'mover@example.com', array( 'n1819' ) );

		$result = $this->service->editAnswers( $mover, $this->request( 'zmiana@example.com', array( 'n1920' ) ) );

		$this->assertSame( 'accommodation_full', $result->code );
		$this->assertSame( 'n1920', $result->reason );

		// Odmowa jest całościowa: ani odpowiedzi, ani wiersze, ani cena się nie ruszają.
		$row = $this->repository->findById( $mover );
		$this->assertSame( 'mover@example.com', $row['email'] );
		$this->assertSame( '180.00', $row['price_total'] );
		$rows = $this->nightsOf( $mover );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'n1819', $rows[0]['key'] );
		$this->assertSame( 1, $this->occupancy()->seatsFor( 'n1920' ) );
	}

	public function test_edit_can_drop_the_accommodation_entirely(): void {
		$id = $this->reserve( 'quitter@example.com', array( 'n1819', 'n1920' ), true );
		$this->assertSame( 2, $this->occupancy()->seatsFor( 'n1819' ) );

		$result = $this->service->editAnswers( $id, $this->request( 'quitter@example.com', array() ) );

		$this->assertSame( 'edited', $result->code );
		$this->assertSame( array(), $this->repository->bookingNightsFor( array( $id ) ) );
		$this->assertSame( '100.00', $this->repository->findById( $id )['price_total'] );
		$this->assertSame( 0, $this->occupancy()->seatsFor( 'n1819' ) );
		$this->assertSame( 0, $this->occupancy()->seatsFor( 'n1920' ) );
	}

	public function test_cancel_frees_the_seats(): void {
		$id = $this->reserve( 'leaver@example.com', array( 'n1819', 'n1920' ), true );
		$this->assertSame( 2, $this->occupancy()->seatsFor( 'n1819' ) );
		$this->assertSame( 2, $this->occupancy()->seatsFor( 'n1920' ) );

		$result = $this->service->cancel( $id );

		$this->assertSame( 'cancelled', $result->code );
		$this->assertSame( 'cancelled', $this->repository->findById( $id )['status'] );
		$this->assertSame( array(), $this->repository->bookingNightsFor( array( $id ) ) );
		$this->assertSame( 0, $this->occupancy()->seatsFor( 'n1819' ) );
		$this->assertSame( 0, $this->occupancy()->seatsFor( 'n1920' ) );
	}

	public function test_promotion_reserves_the_nights_the_waitlisted_wish_named(): void {
		// Lepiej niż classic (B12): życzenie jest w JSON-ie, więc awans ma co rozstrzygnąć.
		$id     = $this->seedWaitlist( 'late@example.com', array( 'n1819', 'n1920' ) );
		$before = did_action( 'evreg_registration_reserved' );
		$this->assertSame( array(), $this->repository->bookingNightsFor( array( $id ) ) );

		$result = $this->service->promoteFromWaitlist( $id );

		$this->assertSame( 'promoted', $result->code );
		$this->assertSame( 'pending', $this->repository->findById( $id )['status'] );
		$this->assertSame( $before + 1, did_action( 'evreg_registration_reserved' ) );

		$rows = $this->nightsOf( $id );
		$this->assertCount( 2, $rows );
		$this->assertSame( array( 'n1819', 'n1920' ), array( $rows[0]['key'], $rows[1]['key'] ) );
		$this->assertSame( 1, $rows[0]['seats'] );
		$this->assertSame( 80.0, $rows[0]['price'] );
		$this->assertSame( 90.0, $rows[1]['price'] );
		$this->assertSame( '270.00', $this->repository->findById( $id )['price_total'] );
		$this->assertSame( 1, $this->occupancy()->seatsFor( 'n1819' ) );
		$this->assertSame( 1, $this->occupancy()->seatsFor( 'n1920' ) );
	}

	public function test_promotion_into_a_full_night_promotes_without_accommodation(): void {
		$this->configure(
			array(
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
					'seats' => 1,
				),
			)
		);

		$this->reserve( 'holder@example.com', array( 'n1920' ) );
		$id = $this->seedWaitlist( 'late@example.com', array( 'n1819', 'n1920' ) );

		$result = $this->service->promoteFromWaitlist( $id );

		// Nigdy nie odmawiamy awansu z powodu noclegu — mówimy, że nocleg przepadł.
		$this->assertSame( 'promoted_no_accommodation', $result->code );
		$this->assertSame( 'pending', $this->repository->findById( $id )['status'] );
		// Odmowa dób jest całościowa: wolna doba też nie dostaje wiersza, cena = sam typ.
		$this->assertSame( array(), $this->repository->bookingNightsFor( array( $id ) ) );
		$this->assertSame( '100.00', $this->repository->findById( $id )['price_total'] );
		$this->assertSame( 0, $this->occupancy()->seatsFor( 'n1819' ) );
		$this->assertSame( 1, $this->occupancy()->seatsFor( 'n1920' ) );
	}

	public function test_promotion_of_a_vanished_night_promotes_without_accommodation(): void {
		$id = $this->seedWaitlist( 'drift@example.com', array( 'n1819', 'n1920' ) );

		// Admin usunął dobę n1920 po zawaitlistowaniu.
		$this->configure(
			array(
				array(
					'key'   => 'n1819',
					'label' => '18/19 XI',
					'price' => 80.0,
					'seats' => 4,
				),
			)
		);

		$result = $this->service->promoteFromWaitlist( $id );

		$this->assertSame( 'promoted_no_accommodation', $result->code );
		$this->assertSame( 'pending', $this->repository->findById( $id )['status'] );
		$this->assertSame( array(), $this->repository->bookingNightsFor( array( $id ) ) );
		$this->assertSame( '100.00', $this->repository->findById( $id )['price_total'] );
	}

	public function test_hard_delete_removes_the_night_rows(): void {
		// Stan nieosiągalny dzisiejszym anulowaniem (ono już kasuje doby), ale osiągalny
		// dla wiersza sprzed tej gałęzi albo po przerwanym anulowaniu: twarde usunięcie
		// to drugi zamek, nie jedyny. Osierocone wiersze nie przesprzedają miejsc (zapytanie
		// liczące dołącza zgłoszenia), ale przeżywają zgłoszenie razem z `night_label`.
		$id = $this->seedWaitlist( 'gone@example.com', array( 'n1819' ) );
		$this->repository->markCancelled( $id );
		$this->repository->replaceBookingNights(
			$id,
			array(
				array(
					'key'   => 'n1819',
					'label' => '18/19 XI',
					'seats' => 1,
					'price' => 80.0,
				),
			)
		);
		$this->assertCount( 1, $this->nightsOf( $id ) );

		$result = $this->service->deleteRegistration( $id );

		$this->assertSame( 'deleted', $result->code );
		$this->assertNull( $this->repository->findById( $id ) );
		$this->assertSame( array(), $this->repository->bookingNightsFor( array( $id ) ) );
	}

	public function test_partial_night_write_rolls_back_the_edit(): void {
		// Doba z kluczem dłuższym niż kolumna night_key (varchar(64)) jest odrzucana
		// przez $wpdb BEZ wyjątku. Bez porównania liczby zapisanych wierszy z liczbą
		// żądanych edycja weszłaby z jedną dobą zamiast dwóch — z niedoliczonymi miejscami.
		$long = str_repeat( 'n', 70 );
		$this->configure(
			array(
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
			)
		);

		$id = $this->reserve( 'partial@example.com', array( 'n1819' ) );

		global $wpdb;
		$wpdb->suppress_errors( true );
		$caught = null;
		try {
			$this->service->editAnswers( $id, $this->request( 'partial@example.com', array( 'n1819', $long ) ) );
		} catch ( \Throwable $e ) {
			$caught = $e;
		}
		$wpdb->suppress_errors( false );

		$this->assertInstanceOf( \RuntimeException::class, $caught );
		$rows = $this->nightsOf( $id );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'n1819', $rows[0]['key'] );
		$this->assertSame( '180.00', $this->repository->findById( $id )['price_total'] );
	}

	public function test_partial_night_write_rolls_back_the_promotion(): void {
		$long = str_repeat( 'n', 70 );
		$this->configure(
			array(
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
			)
		);

		$id = $this->seedWaitlist( 'partial@example.com', array( 'n1819', $long ) );

		global $wpdb;
		$wpdb->suppress_errors( true );
		$caught = null;
		try {
			$this->service->promoteFromWaitlist( $id );
		} catch ( \Throwable $e ) {
			$caught = $e;
		}
		$wpdb->suppress_errors( false );

		$this->assertInstanceOf( \RuntimeException::class, $caught );
		$row = $this->repository->findById( $id );
		$this->assertSame( 'waitlist', $row['status'] );
		$this->assertSame( '100.00', $row['price_total'] );
		$this->assertSame( array(), $this->repository->bookingNightsFor( array( $id ) ) );
	}

	public function test_edit_takes_the_lock_before_the_occupancy_reads(): void {
		$id = $this->reserve( 'order@example.com', array( 'n1819' ) );

		$this->captured = array();
		add_filter( 'query', array( $this, 'capture_query' ) );
		$result = $this->service->editAnswers( $id, $this->request( 'order@example.com', array( 'n1920' ) ) );
		remove_filter( 'query', array( $this, 'capture_query' ) );

		$this->assertSame( 'edited', $result->code );

		$lock   = $this->first_query_index( '/FROM\s+\S*evreg_locks\s+WHERE\s+event_id\s*=\s*\d+\s+FOR UPDATE/i' );
		$counts = $this->first_query_index( '/SELECT COUNT\(\*\) FROM \S*evreg_registrations/i' );
		// Wzorzec celuje w ODCZYT zajętości dób (agregat), nie w DELETE, który
		// replaceBookingNights wysyła do tej samej tabeli.
		$nights = $this->first_query_index( '/SELECT\s+n\.night_key,\s*SUM\(n\.seats\)/i' );

		$this->assertGreaterThan( -1, $lock, 'editAnswers() musi wziąć blokadę wiersza eventu.' );
		$this->assertGreaterThan( -1, $counts, 'editAnswers() musi policzyć zajętość zgłoszeń.' );
		$this->assertGreaterThan( -1, $nights, 'editAnswers() musi policzyć zajętość dób.' );
		$this->assertLessThan( $counts, $lock, 'lockEvent MUSI poprzedzać pierwszy COUNT zajętości.' );
		$this->assertLessThan( $nights, $lock, 'lockEvent MUSI poprzedzać odczyt zajętości dób.' );
	}

	public function test_promotion_takes_the_lock_before_the_occupancy_reads(): void {
		$id = $this->seedWaitlist( 'order@example.com', array( 'n1819' ) );

		$this->captured = array();
		add_filter( 'query', array( $this, 'capture_query' ) );
		$result = $this->service->promoteFromWaitlist( $id );
		remove_filter( 'query', array( $this, 'capture_query' ) );

		$this->assertSame( 'promoted', $result->code );

		$lock   = $this->first_query_index( '/FROM\s+\S*evreg_locks\s+WHERE\s+event_id\s*=\s*\d+\s+FOR UPDATE/i' );
		$counts = $this->first_query_index( '/SELECT COUNT\(\*\) FROM \S*evreg_registrations/i' );
		$nights = $this->first_query_index( '/SELECT\s+n\.night_key,\s*SUM\(n\.seats\)/i' );

		$this->assertGreaterThan( -1, $lock, 'promoteFromWaitlist() musi wziąć blokadę wiersza eventu.' );
		$this->assertGreaterThan( -1, $counts, 'promoteFromWaitlist() musi policzyć zajętość zgłoszeń.' );
		$this->assertGreaterThan( -1, $nights, 'promoteFromWaitlist() musi policzyć zajętość dób.' );
		$this->assertLessThan( $counts, $lock, 'lockEvent MUSI poprzedzać pierwszy COUNT zajętości.' );
		$this->assertLessThan( $nights, $lock, 'lockEvent MUSI poprzedzać odczyt zajętości dób.' );
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
