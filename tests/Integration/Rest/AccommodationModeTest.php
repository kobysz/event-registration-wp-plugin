<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Rest;

use EvReg\Admin\Capabilities;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Rest\EventConfigController;
use WP_REST_Request;
use WP_UnitTestCase;
use wpdb;

final class AccommodationModeTest extends WP_UnitTestCase {

	private int $event_id;

	private int $seq = 0;

	private RegistrationRepository $repository;

	protected function setUp(): void {
		parent::setUp();

		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'booking_nights', 'locks' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		do_action( 'rest_api_init' );
		Capabilities::grant();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->event_id   = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
		$this->repository = new RegistrationRepository();
	}

	/**
	 * @param array<string,mixed> $accommodation Config noclegów.
	 * @return \WP_REST_Response
	 */
	private function put( array $accommodation ) {
		$request = new WP_REST_Request( 'PUT', "/evreg/v1/events/{$this->event_id}/config" );
		$request->set_body_params( array( 'accommodation' => $accommodation ) );
		return rest_do_request( $request );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function get_payload(): array {
		$response = rest_do_request( new WP_REST_Request( 'GET', "/evreg/v1/events/{$this->event_id}/config" ) );
		return $response->get_data();
	}

	/**
	 * @param array<int,array{key:string,seats:int}> $nights Doby zgłoszenia.
	 */
	private function register( string $status, array $nights ): int {
		++$this->seq;
		$id = $this->repository->insertRegistration(
			array(
				'event_id'    => $this->event_id,
				'type_key'    => 'uczestnik',
				'status'      => $status,
				'email'       => 'p' . $this->seq . '@example.com',
				'name'        => 'P' . $this->seq,
				'token'       => str_pad( (string) $this->seq, 32, '0', STR_PAD_LEFT ),
				'data'        => '{}',
				'price_total' => 0.0,
				'expires_at'  => '2099-01-01 00:00:00',
			)
		);
		$rows = array();
		foreach ( $nights as $night ) {
			$rows[] = array(
				'key'   => $night['key'],
				'label' => $night['key'],
				'seats' => $night['seats'],
				'price' => 100.0,
			);
		}
		$this->repository->replaceBookingNights( $id, $rows );
		return $id;
	}

	/**
	 * Zatwierdza wiersz-zamek eventu, żeby sonda z drugiego połączenia mierzyła
	 * konflikt na istniejącym wierszu, a nie na niezatwierdzonym INSERT-cie.
	 */
	private function seed_lock_row(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . Migrations::table( 'locks' ) . ' (event_id) VALUES (%d)', $this->event_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'COMMIT' );
	}

	/**
	 * Czy wiersz-zamek eventu jest wolny WIDZIANY Z INNEGO POŁĄCZENIA. Osobne
	 * $wpdb z sekundowym innodb_lock_wait_timeout: brak konfliktu = zamek wolny,
	 * timeout = ktoś go trzyma. To ten sam chwyt, co w ReservationConcurrencyTest.
	 */
	private function lock_is_free(): bool {
		$second = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$second->suppress_errors( true );
		$second->query( 'SET SESSION innodb_lock_wait_timeout = 1' );
		$second->query( 'START TRANSACTION' );
		$acquired = $second->query( $second->prepare( 'SELECT event_id FROM ' . Migrations::table( 'locks' ) . ' WHERE event_id = %d FOR UPDATE', $this->event_id ) );
		$second->query( 'ROLLBACK' );
		$second->close();

		return false !== $acquired;
	}

	/**
	 * Identyfikator transakcji TEGO połączenia, czyli tego, na którym biegnie żądanie.
	 * Zmienia się przy każdym COMMIT/ROLLBACK, więc ta sama wartość w dwóch punktach
	 * żądania znaczy: to JEDNA transakcja, nie dwie pod rząd.
	 */
	private function current_trx_id(): ?string {
		global $wpdb;

		$trx = $wpdb->get_var( 'SELECT trx_id FROM information_schema.innodb_trx WHERE trx_mysql_thread_id = CONNECTION_ID()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return null === $trx ? null : (string) $trx;
	}

	public function test_the_mode_check_and_the_save_run_in_one_locked_transaction(): void {
		$this->seed_lock_row();

		// Dwie sondy w dwóch punktach jednego żądania: pierwszy odczyt
		// _evreg_accommodation to sprawdzenie trybu, pierwszy zapis meta to save().
		// Każda mierzy DWIE rzeczy: czy zamek eventu jest zajęty (z innego połączenia)
		// ORAZ identyfikator transakcji tego połączenia. Sam zamek w obu punktach nie
		// wystarcza — kształt „begin; lock; sprawdź; commit; begin; lock; zapisz; commit”
		// też trzyma go w obu punktach i NADAL ma wyścig B17 w szczelinie między
		// transakcjami. Dopiero ten sam trx_id dowodzi jednej transakcji.
		$free_during_check = null;
		$free_during_save  = null;
		$trx_during_check  = null;
		$trx_during_save   = null;

		$check_probe = function ( $short_circuit, $object_id, $meta_key ) use ( &$free_during_check, &$trx_during_check ) {
			if ( null === $free_during_check && '_evreg_accommodation' === $meta_key && $this->event_id === $object_id ) {
				$trx_during_check  = $this->current_trx_id();
				$free_during_check = $this->lock_is_free();
			}

			return $short_circuit;
		};

		$save_probe = function ( $short_circuit ) use ( &$free_during_save, &$trx_during_save ) {
			if ( null === $free_during_save ) {
				$trx_during_save  = $this->current_trx_id();
				$free_during_save = $this->lock_is_free();
			}

			return $short_circuit;
		};

		add_filter( 'get_post_metadata', $check_probe, 10, 3 );
		add_filter( 'update_post_metadata', $save_probe, 10, 1 );
		$response = $this->put( array( 'mode' => 'nights', 'nights' => array() ) );
		remove_filter( 'get_post_metadata', $check_probe, 10 );
		remove_filter( 'update_post_metadata', $save_probe, 10 );

		$this->assertSame( 200, $response->get_status() );
		// Kontrola dodatnia obu sond: zamek MUSI dać się wziąć, gdy nikt go nie trzyma,
		// a trx_id MUSI być czymś odczytanym — inaczej „zajęty”/„równe” przechodzi
		// pusto przy zepsutej sondzie.
		$this->assertNotNull( $trx_during_check, 'Sonda nie odczytała identyfikatora transakcji — bez tego porównanie trx_id nic nie dowodzi.' );
		$this->assertNotNull( $trx_during_save, 'Sonda nie odczytała identyfikatora transakcji — bez tego porównanie trx_id nic nie dowodzi.' );
		$this->assertSame( false, $free_during_check, 'Sprawdzenie trybu musi biec pod zamkiem eventu — sonda z innego połączenia nie może go wziąć.' );
		$this->assertSame( false, $free_during_save, 'Zapis konfiguracji musi biec pod zamkiem eventu — sonda z innego połączenia nie może go wziąć.' );
		$this->assertSame( $trx_during_check, $trx_during_save, 'Sprawdzenie i zapis muszą leżeć w JEDNEJ transakcji — dwie kolejne transakcje zostawiają szczelinę, w którą wciśnie się rezerwacja.' );
		$this->assertTrue( $this->lock_is_free(), 'Po zakończeniu żądania zamek eventu musi być wolny (kontrola dodatnia sondy).' );
	}

	public function test_the_event_lock_is_released_when_the_save_throws(): void {
		$this->seed_lock_row();

		$boom = static function (): void {
			throw new \RuntimeException( 'boom' );
		};

		$request = new WP_REST_Request( 'PUT', "/evreg/v1/events/{$this->event_id}/config" );
		$request->set_url_params( array( 'id' => (string) $this->event_id ) );
		$request->set_body_params( array( 'accommodation' => array( 'mode' => 'classic' ) ) );

		add_filter( 'update_post_metadata', $boom, 10, 1 );
		$thrown = null;
		try {
			( new EventConfigController() )->update_config( $request );
		} catch ( \Throwable $e ) {
			$thrown = $e;
		}
		remove_filter( 'update_post_metadata', $boom, 10 );

		$this->assertInstanceOf( \RuntimeException::class, $thrown );
		$this->assertTrue( $this->lock_is_free(), 'Rzut z zapisu musi zostawić zamek eventu zwolniony, inaczej kolejna rezerwacja zawiśnie.' );
	}

	public function test_changing_the_mode_is_refused_when_the_event_has_accommodation(): void {
		$this->put( array( 'mode' => 'classic', 'packages' => array() ) );
		$this->register( 'confirmed', array( array( 'key' => 'n1', 'seats' => 1 ) ) );

		$response = $this->put( array( 'mode' => 'nights', 'nights' => array( array( 'key' => 'n1', 'seats' => 5 ) ) ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'evreg_mode_locked', $response->get_data()['code'] );
		// Blokada przed zapisem: meta zostaje, jak było.
		$saved = $this->get_payload()['accommodation'];
		$this->assertSame( 'classic', $saved['mode'] );
		$this->assertArrayNotHasKey( 'nights', $saved );
	}

	public function test_changing_the_mode_is_allowed_when_only_cancelled_registrations_exist(): void {
		$this->put( array( 'mode' => 'classic' ) );
		$this->register( 'cancelled', array( array( 'key' => 'n1', 'seats' => 1 ) ) );

		$response = $this->put( array( 'mode' => 'nights', 'nights' => array() ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'nights', $this->get_payload()['accommodation']['mode'] );
	}

	public function test_saving_the_same_mode_is_not_refused(): void {
		$this->put( array( 'mode' => 'nights', 'nights' => array( array( 'key' => 'n1', 'seats' => 5 ) ) ) );
		$this->register( 'confirmed', array( array( 'key' => 'n1', 'seats' => 1 ) ) );

		$same = $this->put( array( 'mode' => 'nights', 'nights' => array( array( 'key' => 'n1', 'seats' => 9 ) ) ) );

		$this->assertSame( 200, $same->get_status() );
		$this->assertSame( 9, $this->get_payload()['accommodation']['nights'][0]['seats'] );
	}

	public function test_a_missing_mode_key_counts_as_classic_and_is_not_a_change(): void {
		$this->put( array( 'packages' => array() ) );
		$this->register( 'confirmed', array( array( 'key' => 'n1', 'seats' => 1 ) ) );

		$response = $this->put( array( 'mode' => 'classic', 'packages' => array() ) );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_payload_reports_the_lock_and_the_per_night_counts(): void {
		$this->put( array( 'mode' => 'nights', 'nights' => array( array( 'key' => 'n1', 'seats' => 5 ), array( 'key' => 'n2', 'seats' => 5 ) ) ) );
		$this->register( 'confirmed', array( array( 'key' => 'n1', 'seats' => 2 ), array( 'key' => 'n2', 'seats' => 2 ) ) );
		$this->register( 'pending', array( array( 'key' => 'n1', 'seats' => 1 ) ) );
		$this->register( 'cancelled', array( array( 'key' => 'n1', 'seats' => 1 ) ) );

		$stats = $this->get_payload()['stats'];

		$this->assertTrue( $stats['mode_locked'] );
		$this->assertSame( array( 'n1' => 3, 'n2' => 2 ), $stats['night_seats'] );
	}

	public function test_payload_stats_are_unlocked_for_an_event_without_bookings(): void {
		$stats = $this->get_payload()['stats'];

		$this->assertFalse( $stats['mode_locked'] );
		$this->assertSame( array(), $stats['night_seats'] );
	}

	public function test_sanitize_passes_the_new_keys_through(): void {
		$this->put(
			array(
				'mode'   => 'nights',
				'nights' => array(
					array( 'key' => 'n1', 'label' => '<b>Piątek</b>', 'price' => 120.5, 'seats' => 30, 'companion_cap' => 4 ),
					array( 'key' => 'n2', 'label' => 'Sobota', 'price' => 0, 'seats' => 0, 'companion_cap' => '' ),
					// Trzecia konwencja pustki, którą łatwo pomylić z dwiema wyżej:
					// companion_cap === 0 to doba ZAMKNIĘTA dla par, nie „bez limitu”.
					// Gdyby sanitize zaczęło przycinać wartości falsy, limit 0 zamieniłby
					// się po cichu w brak limitu i nikt by tego nie zauważył.
					array( 'key' => 'n3', 'label' => 'Niedziela', 'price' => 10, 'seats' => 5, 'companion_cap' => 0 ),
				),
			)
		);

		$saved = $this->get_payload()['accommodation'];

		$this->assertSame( 'nights', $saved['mode'] );
		$this->assertSame(
			array(
				array( 'key' => 'n1', 'label' => 'Piątek', 'price' => 120.5, 'seats' => 30, 'companion_cap' => 4 ),
				array( 'key' => 'n2', 'label' => 'Sobota', 'price' => 0, 'seats' => 0, 'companion_cap' => '' ),
				array( 'key' => 'n3', 'label' => 'Niedziela', 'price' => 10, 'seats' => 5, 'companion_cap' => 0 ),
			),
			$saved['nights']
		);
	}
}
