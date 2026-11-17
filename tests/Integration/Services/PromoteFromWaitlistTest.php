<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Services;

use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Mail\Subscriber;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Services\ReservationService;
use WP_UnitTestCase;

final class PromoteFromWaitlistTest extends WP_UnitTestCase {

	private ReservationService $service;

	private RegistrationRepository $repository;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'locks', 'mail_queue' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$config           = new EventConfigRepository();
		$this->repository = new RegistrationRepository();
		$this->service    = new ReservationService( $this->repository, $config );
		$this->event_id   = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );

		// Typ z limitem 1 miejsca. Schema niesie pole typu `accommodation` o kluczu
		// `nocleg` — awans czyta spod niego życzenie zapisane w odpowiedziach zgłoszenia,
		// które na liście rezerwowej nie ma bookingu.
		$config->save(
			$this->event_id,
			array(
				'schema'   => array(
					'version'  => 1,
					'sections' => array(
						array(
							'key'    => 'dane',
							'title'  => 'Dane',
							'fields' => array(
								array( 'key' => '__type', 'type' => 'radio', 'label' => 'Typ' ),
								array( 'key' => 'nocleg', 'type' => 'accommodation', 'label' => 'Nocleg' ),
							),
						),
					),
				),
				'types'    => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 0.0, 'capacity' => 1 ) ),
				'settings' => array( 'waitlist_enabled' => true ),
			)
		);
	}

	protected function tearDown(): void {
		foreach ( array( 'evreg_registration_reserved', 'evreg_registration_waitlisted', 'evreg_registration_confirmed', 'evreg_registration_expired' ) as $hook ) {
			remove_all_actions( $hook );
		}
		parent::tearDown();
	}

	/**
	 * Zapisuje zgłoszenie o podanym statusie.
	 *
	 * @param string              $status  Status zgłoszenia.
	 * @param string              $token   Token (32 znaki) — służy też jako lokalna część e-maila.
	 * @param array<string,mixed> $answers Odpowiedzi do kolumny `data`; puste dają `{}`.
	 */
	private function seed( string $status, string $token, array $answers = array() ): int {
		return $this->repository->insertRegistration(
			array(
				'event_id'    => $this->event_id,
				'type_key'    => 'uczestnik',
				'status'      => $status,
				'email'       => $token . '@example.com',
				'name'        => 'Jan',
				'token'       => $token,
				// Rzutowanie na obiekt trzyma kształt `{}` dla pustych odpowiedzi.
				'data'        => (string) wp_json_encode( (object) $answers ),
				'price_total' => 0.0,
				'expires_at'  => null,
			)
		);
	}

	/**
	 * Konfiguruje event z jednym slotem noclegowym (typ bez limitu, żeby miejsce na
	 * wydarzeniu nigdy nie było tym, co blokuje awans).
	 *
	 * @param int   $capacity   Pojemność slotu w miejscach.
	 * @param float $bed_price  Cena łóżka w aktualnym cenniku.
	 * @param float $type_price Cena typu zgłoszenia.
	 * @param int   $occupancy  Liczba osób w pokoju (0 = nieustawione).
	 * @param bool  $companions Czy nocleg dopuszcza osobę towarzyszącą.
	 */
	private function configure_accommodation(
		int $capacity = 2,
		float $bed_price = 150.0,
		float $type_price = 100.0,
		int $occupancy = 0,
		bool $companions = false
	): void {
		// Pokój pyta o współlokatora, żeby zapisane życzenie mogło nieść tę preferencję
		// — dokładnie jak zgłoszenie wysłane przez formularz.
		$room = array( 'key' => 'single', 'label' => 'Jednoosobowy', 'roommate_field' => true );

		if ( 0 !== $occupancy ) {
			$room['occupancy'] = $occupancy;
		}

		( new EventConfigRepository() )->save(
			$this->event_id,
			array(
				'types'         => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => $type_price ) ),
				'accommodation' => array(
					'packages'          => array( array( 'key' => 'n18', 'label' => 'Noc 18/19' ) ),
					'rooms'             => array( $room ),
					'inventory'         => array( array( 'package' => 'n18', 'room' => 'single', 'capacity' => $capacity, 'price' => $bed_price ) ),
					'companion_enabled' => $companions,
				),
				'settings'      => array( 'waitlist_enabled' => true ),
			)
		);
	}

	/**
	 * Liczba wierszy bookingu zgłoszenia.
	 *
	 * @param int $id ID zgłoszenia.
	 */
	private function booking_count( int $id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::table( 'accommodation_bookings' ) . ' WHERE registration_id = %d', $id ) );
	}

	private function mail_count( string $template_key ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::table( 'mail_queue' ) . ' WHERE template_key = %s', $template_key ) );
	}

	public function test_promote_when_seat_free(): void {
		Subscriber::register();
		$id = $this->seed( 'waitlist', str_repeat( 'a', 32 ) );

		$result = $this->service->promoteFromWaitlist( $id );

		$this->assertSame( 'promoted', $result->code );
		$row = $this->repository->findById( $id );
		$this->assertSame( 'pending', $row['status'] );
		$this->assertNotNull( $row['expires_at'] );
		$this->assertSame( 1, $this->mail_count( 'optin' ) );
	}

	public function test_promote_rejected_when_full(): void {
		$this->seed( 'confirmed', str_repeat( 'a', 32 ) );      // zajmuje jedyne miejsce
		$waitlisted = $this->seed( 'waitlist', str_repeat( 'b', 32 ) );

		$result = $this->service->promoteFromWaitlist( $waitlisted );

		$this->assertSame( 'rejected', $result->code );
		$this->assertSame( 'waitlist', $this->repository->findById( $waitlisted )['status'] );
	}

	public function test_promote_rejects_non_waitlist(): void {
		$id = $this->seed( 'pending', str_repeat( 'a', 32 ) );

		$this->assertSame( 'invalid_status', $this->service->promoteFromWaitlist( $id )->code );
	}

	public function test_promote_rejects_unknown_type(): void {
		// Typ usunięty z configu po zawaitlistowaniu (config-drift) → nie promuj, nie zeruj ceny.
		$id = $this->repository->insertRegistration(
			array(
				'event_id'    => $this->event_id,
				'type_key'    => 'ghost',
				'status'      => 'waitlist',
				'email'       => 'ghost@example.com',
				'name'        => 'Jan',
				'token'       => str_repeat( 'c', 32 ),
				'data'        => '{}',
				'price_total' => 0.0,
				'expires_at'  => null,
			)
		);

		$this->assertSame( 'invalid_status', $this->service->promoteFromWaitlist( $id )->code );
		$this->assertSame( 'waitlist', $this->repository->findById( $id )['status'] );
	}

	public function test_delete_registration_removes_cancelled_and_orphans(): void {
		$id = $this->seed( 'cancelled', str_repeat( 'a', 32 ) );
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Migrations::table( 'mail_queue' ),
			array(
				'registration_id' => $id,
				'event_id'        => $this->event_id,
				'template_key'    => 'optin',
				'recipient'       => 'jan@example.com',
				'subject'         => 'S',
				'body'            => 'B',
				'headers'         => '',
				'status'          => 'sent',
				'attempts'        => 1,
				'scheduled_at'    => '2026-08-19 10:00:00',
			)
		);

		$result = $this->service->deleteRegistration( $id );

		$this->assertSame( 'deleted', $result->code );
		$this->assertNull( $this->repository->findById( $id ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::table( 'mail_queue' ) . ' WHERE registration_id = %d', $id ) ) );
	}

	public function test_delete_registration_rejects_active(): void {
		$id = $this->seed( 'pending', str_repeat( 'a', 32 ) );

		$this->assertSame( 'invalid_status', $this->service->deleteRegistration( $id )->code );
		$this->assertNotNull( $this->repository->findById( $id ) );
	}

	public function test_promote_without_accommodation_reports_the_loss(): void {
		// Typ bez limitu + slot noclegowy na 1 miejsce: miejsce na wydarzeniu jest,
		// ale łóżko zajął ktoś inny, gdy zgłoszenie czekało na liście rezerwowej.
		( new EventConfigRepository() )->save(
			$this->event_id,
			array(
				'types'         => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 0.0 ) ),
				'accommodation' => array(
					'packages'  => array( array( 'key' => 'n18', 'label' => 'Noc 18/19' ) ),
					'rooms'     => array( array( 'key' => 'single', 'label' => 'Jednoosobowy' ) ),
					'inventory' => array( array( 'package' => 'n18', 'room' => 'single', 'capacity' => 1, 'price' => 0.0 ) ),
				),
				'settings'      => array( 'waitlist_enabled' => true ),
			)
		);

		$selection = new AccommodationSelection( 'n18', 'single' );

		// Potwierdzone zgłoszenie trzyma jedyne łóżko (status zajmujący miejsce).
		$taken = $this->seed( 'confirmed', str_repeat( 'd', 32 ) );
		$this->repository->insertAccommodationBooking( $taken, $selection, 0.0, 1, 'Noc 18/19', 'Jednoosobowy' );

		// Zgłoszenie na liście rezerwowej ma booking na ten sam slot (waitlist nie zajmuje miejsca).
		$waitlisted = $this->seed( 'waitlist', str_repeat( 'e', 32 ) );
		$this->repository->insertAccommodationBooking( $waitlisted, $selection, 0.0, 1, 'Noc 18/19', 'Jednoosobowy' );

		$result = $this->service->promoteFromWaitlist( $waitlisted );

		$this->assertSame( 'promoted_no_accommodation', $result->code );
		$this->assertSame( 'pending', $this->repository->findById( $waitlisted )['status'] );

		global $wpdb;
		$bookings = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::table( 'accommodation_bookings' ) . ' WHERE registration_id = %d', $waitlisted )
		);

		$this->assertSame( 0, $bookings, 'Nieaktualny booking musi zniknąć, inaczej slot zostanie przesprzedany.' );
	}

	public function test_promote_refused_when_room_now_requires_companion(): void {
		// Organizator oznaczył pokój jako dwuosobowy PO tym, jak ktoś zapisał się solo
		// na listę rezerwową. Bez odmowy ten człowiek dostałby seats=2 i cenę ×2 przy
		// companion=0 — czyli dwa łóżka i podwójny rachunek dla jednej osoby.
		( new EventConfigRepository() )->save(
			$this->event_id,
			array(
				'types'         => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 0.0 ) ),
				'accommodation' => array(
					'packages'          => array( array( 'key' => 'n18', 'label' => 'Noc 18/19' ) ),
					'rooms'             => array( array( 'key' => 'double', 'label' => '2-os.', 'occupancy' => 2 ) ),
					'inventory'         => array( array( 'package' => 'n18', 'room' => 'double', 'capacity' => 10, 'price' => 0.0 ) ),
					'companion_enabled' => true,
				),
				'settings'      => array( 'waitlist_enabled' => true ),
			)
		);

		$waitlisted = $this->seed( 'waitlist', str_repeat( 'f', 32 ) );
		$this->repository->insertAccommodationBooking(
			$waitlisted,
			new AccommodationSelection( 'n18', 'double' ),
			0.0,
			1,
			'Noc 18/19',
			'2-os.'
		);

		$result = $this->service->promoteFromWaitlist( $waitlisted );

		$this->assertSame( 'occupancy_mismatch', $result->code );
		$this->assertSame( 'waitlist', $this->repository->findById( $waitlisted )['status'] );
		$this->assertNotNull( $this->repository->findAccommodationBooking( $waitlisted ) );
	}

	public function test_promotion_grants_the_bed_wished_on_the_waitlist(): void {
		// Zgłoszenie na liście rezerwowej NIE ma bookingu (waitlista nie zajmuje miejsca),
		// więc jego życzenie mieszka wyłącznie w odpowiedziach — awans musi je ponowić.
		$this->configure_accommodation( 2, 100.0 );

		$id = $this->seed(
			'waitlist',
			str_repeat( 'g', 32 ),
			array(
				'__type' => 'uczestnik',
				'nocleg' => array(
					'package'  => 'n18',
					'room'     => 'single',
					'roommate' => 'Ola',
				),
			)
		);

		// Cennik zmieniony, gdy zgłoszenie czekało: awans wycenia po BIEŻĄCYM cenniku,
		// jak ścieżka dobowa — nie po cenie sprzed tygodni.
		$this->configure_accommodation( 2, 150.0 );

		$result = $this->service->promoteFromWaitlist( $id );

		$this->assertSame( 'promoted', $result->code );
		$this->assertSame( 'pending', $this->repository->findById( $id )['status'] );

		$booking = $this->repository->findAccommodationBooking( $id );
		$this->assertNotNull( $booking, 'Życzenie z listy rezerwowej musi dostać booking.' );
		$this->assertSame( 'n18', $booking['package_key'] );
		$this->assertSame( 'single', $booking['room_type_key'] );
		$this->assertSame( 1, (int) $booking['seats'] );
		$this->assertSame( '150.00', $booking['price'] );
		$this->assertSame( 'Ola', $booking['roommate_pref'] );
		$this->assertSame( 'Noc 18/19', $booking['package_label'] );
		$this->assertSame( 'Jednoosobowy', $booking['room_label'] );
		$this->assertSame( '250.00', $this->repository->findById( $id )['price_total'] );
	}

	public function test_promotion_reads_the_legacy_answer_shape(): void {
		// Wiersze zapisane przed naprawą kształtu JSON trzymają nazwy właściwości obiektu.
		// Czytelnik rozumiejący tylko klucze kanoniczne nie znalazłby tu życzenia.
		$this->configure_accommodation( 2, 150.0 );

		$id = $this->seed(
			'waitlist',
			str_repeat( 'k', 32 ),
			array(
				'nocleg' => array(
					'packageKey'   => 'n18',
					'roomKey'      => 'single',
					'roommatePref' => 'Ola',
				),
			)
		);

		$result = $this->service->promoteFromWaitlist( $id );

		$this->assertSame( 'promoted', $result->code );

		$booking = $this->repository->findAccommodationBooking( $id );
		$this->assertNotNull( $booking, 'Starszy kształt zapisanej odpowiedzi też jest życzeniem.' );
		$this->assertSame( 'n18', $booking['package_key'] );
		$this->assertSame( 'single', $booking['room_type_key'] );
		$this->assertSame( 'Ola', $booking['roommate_pref'] );
		$this->assertSame( '250.00', $this->repository->findById( $id )['price_total'] );
	}

	public function test_promotion_without_a_free_bed_reports_the_loss(): void {
		$this->configure_accommodation( 1, 150.0 );

		// Jedyne łóżko zajęte przez potwierdzone zgłoszenie (status zajmujący miejsce).
		$taken = $this->seed( 'confirmed', str_repeat( 'h', 32 ) );
		$this->repository->insertAccommodationBooking(
			$taken,
			new AccommodationSelection( 'n18', 'single' ),
			150.0,
			1,
			'Noc 18/19',
			'Jednoosobowy'
		);

		$id = $this->seed(
			'waitlist',
			str_repeat( 'i', 32 ),
			array(
				'nocleg' => array(
					'package' => 'n18',
					'room'    => 'single',
				),
			)
		);

		$result = $this->service->promoteFromWaitlist( $id );

		// Nocleg NIGDY nie blokuje awansu: promujemy i mówimy o utracie łóżka.
		$this->assertSame( 'promoted_no_accommodation', $result->code );
		$this->assertSame( 'pending', $this->repository->findById( $id )['status'] );
		$this->assertSame( 0, $this->booking_count( $id ), 'Bez przyznania nie wolno zapisać bookingu — slot zostałby przesprzedany.' );
		$this->assertSame( '100.00', $this->repository->findById( $id )['price_total'] );
	}

	public function test_promotion_of_a_slot_gone_from_the_inventory_reports_the_loss(): void {
		$this->configure_accommodation( 2, 150.0 );

		$id = $this->seed(
			'waitlist',
			str_repeat( 'm', 32 ),
			array(
				'nocleg' => array(
					'package' => 'n18',
					'room'    => 'single',
				),
			)
		);

		// Organizator wycofał slot z inwentarza po zawaitlistowaniu (config-drift).
		( new EventConfigRepository() )->save(
			$this->event_id,
			array(
				'accommodation' => array(
					'packages'  => array( array( 'key' => 'n18', 'label' => 'Noc 18/19' ) ),
					'rooms'     => array( array( 'key' => 'single', 'label' => 'Jednoosobowy' ) ),
					'inventory' => array(),
				),
			)
		);

		$result = $this->service->promoteFromWaitlist( $id );

		$this->assertSame( 'promoted_no_accommodation', $result->code );
		$this->assertSame( 'pending', $this->repository->findById( $id )['status'] );
		$this->assertSame( 0, $this->booking_count( $id ) );
		$this->assertSame( '100.00', $this->repository->findById( $id )['price_total'] );
	}

	public function test_promotion_of_an_empty_accommodation_answer_is_a_plain_promotion(): void {
		// Pusty ciąg to „bez noclegu", nie życzenie — inaczej awans raportowałby utratę
		// łóżka, o które ta osoba nigdy nie prosiła.
		$this->configure_accommodation();

		$id = $this->seed(
			'waitlist',
			str_repeat( 'j', 32 ),
			array(
				'__type' => 'uczestnik',
				'nocleg' => '',
			)
		);

		$result = $this->service->promoteFromWaitlist( $id );

		$this->assertSame( 'promoted', $result->code );
		$this->assertSame( 0, $this->booking_count( $id ) );
		$this->assertSame( '100.00', $this->repository->findById( $id )['price_total'] );
	}

	public function test_promotion_does_not_revive_an_accommodation_dropped_on_purpose(): void {
		// „Bez noclegu" zapisuje się jako null (walidator zwraca pustą wartość), więc
		// odczyt życzenia musi to czytać jako brak wyboru — inaczej awans wskrzesiłby
		// nocleg, który admin albo zgłaszający się świadomie zdjął.
		$this->configure_accommodation();

		$id = $this->seed(
			'waitlist',
			str_repeat( 'o', 32 ),
			array(
				'__type' => 'uczestnik',
				'nocleg' => null,
			)
		);

		$result = $this->service->promoteFromWaitlist( $id );

		$this->assertSame( 'promoted', $result->code );
		$this->assertSame( 0, $this->booking_count( $id ) );
		$this->assertSame( '100.00', $this->repository->findById( $id )['price_total'] );
	}

	public function test_promotion_refuses_a_wished_room_that_now_requires_companion(): void {
		// Ta sama sprzeczność, co przy życzeniu z zapisanego bookingu, i ta sama szkoda:
		// seats=2 i cena ×2 przy companion=0. Pochodzenie życzenia (wiersz czy JSON)
		// nie może zmieniać wyroku — rozstrzyga admin, edytując odpowiedzi.
		$this->configure_accommodation( 10, 150.0, 100.0, 2, true );

		$id = $this->seed(
			'waitlist',
			str_repeat( 'n', 32 ),
			array(
				'nocleg' => array(
					'package' => 'n18',
					'room'    => 'single',
				),
			)
		);

		$result = $this->service->promoteFromWaitlist( $id );

		$this->assertSame( 'occupancy_mismatch', $result->code );
		$this->assertSame( 'waitlist', $this->repository->findById( $id )['status'] );
		$this->assertSame( 0, $this->booking_count( $id ) );
	}
}
