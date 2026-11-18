<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Admin;

use EvReg\Admin\Capabilities;
use EvReg\Admin\RegistrationsScreen;
use EvReg\Mail\Subscriber;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use WP_UnitTestCase;

/**
 * Wyjątek przenoszący adres redirectu, by przerwać przed exit handlera.
 */
final class ManualAddRedirectException extends \Exception {

	public string $location;

	public function __construct( string $location ) {
		parent::__construct( 'redirect' );
		$this->location = $location;
	}
}

/**
 * Trasa, handler i komunikaty ekranu ręcznego dodawania zgłoszenia.
 *
 * Handler jest ostatnim ogniwem wpisu ręcznego: straże (nonce, uprawnienie, wydarzenie,
 * typ, przełącznik wysyłki, spójność pary pokój/osoba towarzysząca), wywołanie rezerwacji
 * ze znacznikiem i zamiana wyniku na komunikat. Testy pytają o SKUTEK: czy wiersz powstał,
 * gdzie poszło przekierowanie i co napis mówi organizatorowi.
 *
 * Dołożone są tu także dwa testy promocji — ta sama powierzchnia komunikatów: awans wpisu
 * ręcznego nie wysyła maila, więc nie może mówić „wysłano prośbę o potwierdzenie”.
 */
final class ManualAddHandlerTest extends WP_UnitTestCase {

	private RegistrationRepository $repository;

	private int $admin_id;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'booking_nights', 'locks', 'mail_queue' ) as $table ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$this->repository = new RegistrationRepository();
		Capabilities::grant();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->event_id = self::factory()->post->create(
			array(
				'post_type'  => 'evreg_event',
				'post_title' => 'Zjazd wiosenny',
			)
		);

		$this->saveConfig();

		// Kolejka mailowa jest jedynym obserwowalnym skutkiem hooków cyklu życia, więc
		// „nie leci mail” asertujemy na jej wierszach, nie na wp_mail.
		Subscriber::register();

		wp_set_current_user( $this->admin_id );
	}

	protected function tearDown(): void {
		remove_all_filters( 'wp_redirect' );
		foreach ( array( 'evreg_registration_reserved', 'evreg_registration_waitlisted', 'evreg_registration_confirmed', 'evreg_registration_expired' ) as $hook ) {
			remove_all_actions( $hook );
		}
		unset(
			$_POST['event_id'],
			$_POST['evreg_field'],
			$_POST['evreg_notify'],
			$_POST['evreg_companion'],
			$_POST['evreg_companion_name'],
			$_POST['id'],
			$_REQUEST['_wpnonce'],
			$_GET['action'],
			$_GET['id'],
			$_GET['event_id'],
			$_GET['evreg_msg'],
			$_GET['evreg_dup']
		);
		parent::tearDown();
	}

	/**
	 * Zapisuje konfigurację eventu bez noclegu. Pole e-mail jest WYMAGANE w schemacie —
	 * to jedyny sposób, żeby test pustego adresu dowodził zwolnienia z `required`
	 * (emailOptional), a nie tego, że autor formularza adresu nie wymagał.
	 *
	 * @param int  $capacity Limit miejsc typu „uczestnik”.
	 * @param bool $waitlist Czy lista rezerwowa jest włączona.
	 */
	private function saveConfig( int $capacity = 10, bool $waitlist = true ): void {
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
								array( 'key' => '__type', 'type' => 'radio', 'label' => 'Typ' ),
								array( 'key' => 'imie', 'type' => 'text', 'label' => 'Imię', 'required' => true ),
								array( 'key' => 'email', 'type' => 'email', 'label' => 'E-mail', 'required' => true ),
							),
						),
					),
				),
				'types'         => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 100.0, 'capacity' => $capacity ) ),
				'accommodation' => array(),
				'settings'      => array(
					'global_cap'       => null,
					'waitlist_enabled' => $waitlist,
				),
			)
		);
	}

	/**
	 * Dopisuje do eventu pole noclegu z jednym slotem.
	 *
	 * @param int $capacity  Pojemność slotu n18|double.
	 * @param int $occupancy Liczba osób pokoju „double” (0 = nieustawiona).
	 */
	private function saveAccommodationConfig( int $capacity, int $occupancy = 0 ): void {
		$room = array( 'key' => 'double', 'label' => 'Dwuosobowy' );
		if ( 0 !== $occupancy ) {
			$room['occupancy'] = $occupancy;
		}

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
								array( 'key' => '__type', 'type' => 'radio', 'label' => 'Typ' ),
								array( 'key' => 'imie', 'type' => 'text', 'label' => 'Imię', 'required' => true ),
								array( 'key' => 'email', 'type' => 'email', 'label' => 'E-mail', 'required' => true ),
								array( 'key' => 'nocleg', 'type' => 'accommodation', 'label' => 'Nocleg' ),
							),
						),
					),
				),
				'accommodation' => array(
					'packages'          => array( array( 'key' => 'n18', 'label' => 'Noc 18/19' ) ),
					'rooms'             => array( $room ),
					'inventory'         => array( array( 'package' => 'n18', 'room' => 'double', 'capacity' => $capacity, 'price' => 180.0 ) ),
					'allow_none'        => true,
					'companion_enabled' => true,
				),
			)
		);
	}

	/**
	 * Przestawia event w tryb „doby” z jedną otwartą dobą.
	 */
	private function saveNightsConfig(): void {
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
								array( 'key' => '__type', 'type' => 'radio', 'label' => 'Typ' ),
								array( 'key' => 'imie', 'type' => 'text', 'label' => 'Imię', 'required' => true ),
								array( 'key' => 'email', 'type' => 'email', 'label' => 'E-mail', 'required' => true ),
								array( 'key' => 'nocleg', 'type' => 'accommodation', 'label' => 'Nocleg' ),
							),
						),
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
			)
		);
	}

	/**
	 * Wypełnia POST formularza dodawania.
	 *
	 * @param array<string,mixed> $fields Wartości pól schematu (evreg_field[...]).
	 * @param bool                $notify Czy przełącznik wysyłki jest włączony.
	 * @param int|null            $event  ID wydarzenia w ukrytym polu (domyślnie event testu).
	 */
	private function postAdd( array $fields, bool $notify = false, ?int $event = null ): void {
		$event_id = null === $event ? $this->event_id : $event;

		$_POST['event_id']    = $event_id;
		$_POST['evreg_field'] = $fields;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'evreg_add_' . $event_id );

		if ( $notify ) {
			$_POST['evreg_notify'] = '1';
		}
	}

	private function catchRedirect( callable $fn ): ?ManualAddRedirectException {
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new ManualAddRedirectException( (string) $location );
			}
		);

		try {
			$fn();
		} catch ( ManualAddRedirectException $e ) {
			return $e;
		}

		return null;
	}

	/**
	 * Woła handler łapiąc jednocześnie redirect i wypisany HTML (inline re-render).
	 *
	 * @return array{0:?ManualAddRedirectException,1:string}
	 */
	private function runHandler(): array {
		ob_start();
		try {
			$redirect = $this->catchRedirect( array( RegistrationsScreen::class, 'handle_add' ) );
		} finally {
			$html = (string) ob_get_clean();
		}

		return array( $redirect, $html );
	}

	/**
	 * Wiersze zgłoszeń tego eventu, najnowsze pierwsze.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function rows(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Migrations::table( 'registrations' ) . ' WHERE event_id = %d ORDER BY id DESC', $this->event_id ), ARRAY_A );
	}

	private function registrationCount(): int {
		return count( $this->rows() );
	}

	private function queuedRowCount(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Migrations::table( 'mail_queue' ) );
	}

	/**
	 * Zasiewa istniejące zgłoszenie (do testu duplikatu adresu i zapełniania limitu).
	 *
	 * @param string $email  Adres e-mail.
	 * @param string $status Status zgłoszenia.
	 * @param bool   $manual Czy wiersz jest wpisem ręcznym.
	 */
	private function seed( string $email, string $status = 'confirmed', bool $manual = false ): int {
		static $counter = 0;
		++$counter;

		return $this->repository->insertRegistration(
			array(
				'event_id'    => $this->event_id,
				'type_key'    => 'uczestnik',
				'status'      => $status,
				'email'       => $email,
				'name'        => 'Jan',
				'token'       => str_pad( (string) $counter, 32, 'a', STR_PAD_LEFT ),
				'data'        => '{}',
				'price_total' => 0.0,
				'expires_at'  => null,
				'manual'      => $manual ? 1 : 0,
			)
		);
	}

	private function renderScreen(): string {
		set_current_screen( 'evreg_event_page_' . RegistrationsScreen::SLUG );

		ob_start();
		RegistrationsScreen::render();

		return (string) ob_get_clean();
	}

	/**
	 * Renderuje ekran, na który handler faktycznie przekierował — razem z komunikatem.
	 *
	 * Komunikat czytamy z adresu przekierowania, nie z wpisanych ręcznie parametrów:
	 * inaczej test potwierdzałby mapę komunikatów, a nie to, co organizator zobaczy.
	 *
	 * @param string $location Adres z przechwyconego redirectu.
	 */
	private function renderRedirectTarget( string $location ): string {
		$query = array();
		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );

		foreach ( array( 'action', 'id', 'event_id', 'evreg_msg', 'evreg_dup' ) as $key ) {
			$_GET[ $key ] = isset( $query[ $key ] ) ? (string) $query[ $key ] : null;

			if ( null === $_GET[ $key ] ) {
				unset( $_GET[ $key ] );
			}
		}

		return $this->renderScreen();
	}

	public function test_handle_add_bad_nonce_dies(): void {
		$this->postAdd( array( '__type' => 'uczestnik', 'imie' => 'Jan', 'email' => 'jan@example.com' ) );
		$_REQUEST['_wpnonce'] = 'zły';

		$this->expectException( \WPDieException::class );
		RegistrationsScreen::handle_add();
	}

	public function test_handle_add_without_cap_dies(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->postAdd( array( '__type' => 'uczestnik', 'imie' => 'Jan', 'email' => 'jan@example.com' ) );

		$this->expectException( \WPDieException::class );
		RegistrationsScreen::handle_add();
	}

	public function test_handle_add_creates_confirmed_registration_and_redirects_to_its_detail(): void {
		$this->postAdd( array( '__type' => 'uczestnik', 'imie' => 'Zofia', 'email' => 'zofia@example.com' ) );

		[ $redirect, $html ] = $this->runHandler();

		$this->assertNotNull( $redirect, 'Przyjęty wpis ręczny powinien zakończyć się przekierowaniem (PRG).' );
		$this->assertSame( '', $html, 'Na sukcesie handler nic nie wypisuje.' );

		$rows = $this->rows();
		$this->assertCount( 1, $rows );
		$row = $rows[0];

		$this->assertStringContainsString( 'evreg_msg=added', $redirect->location );
		$this->assertStringContainsString( 'action=view', $redirect->location );
		$this->assertStringContainsString( 'id=' . $row['id'], $redirect->location );

		$this->assertSame( 'confirmed', $row['status'], 'Wpis ręczny wchodzi potwierdzony.' );
		$this->assertSame( '1', (string) $row['manual'], 'Znacznik pochodzenia musi być trwały.' );
		$this->assertNull( $row['expires_at'], 'Termin 48 h oddałby wpis ręczny cronowi anulującemu.' );
		$this->assertNotEmpty( $row['confirmed_at'] );
		$this->assertSame( 'zofia@example.com', $row['email'] );
		$this->assertSame( 'Zofia', $row['name'] );
		$this->assertSame( 0, $this->queuedRowCount(), 'Bez przełącznika nie leci żaden mail.' );
	}

	public function test_handle_add_with_notify_mails_the_participant(): void {
		$this->postAdd( array( '__type' => 'uczestnik', 'imie' => 'Zofia', 'email' => 'zofia@example.com' ), true );

		[ $redirect ] = $this->runHandler();

		$this->assertNotNull( $redirect );
		$this->assertStringContainsString( 'evreg_msg=added', $redirect->location );
		$this->assertSame( 1, $this->queuedRowCount(), 'Z przełącznikiem leci jeden mail — do uczestnika.' );
	}

	public function test_handle_add_with_empty_type_saves_nothing_and_errors_on_the_type_field(): void {
		$this->postAdd( array( '__type' => '', 'imie' => 'Jan', 'email' => 'jan@example.com' ) );

		[ $redirect, $html ] = $this->runHandler();

		$this->assertNull( $redirect, 'Pusty typ nie przekierowuje — formularz wraca z błędem.' );
		$this->assertSame( 0, $this->registrationCount(), 'Zgłoszenie bez typu nie może powstać.' );
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'Typ', $html );
		$this->assertStringContainsString( 'name="action" value="' . RegistrationsScreen::ACTION_ADD . '"', $html );
		$this->assertStringContainsString( 'value="jan@example.com"', $html, 'Wpisane wartości muszą wrócić.' );
	}

	public function test_handle_add_with_notify_and_empty_address_saves_nothing(): void {
		$this->postAdd( array( '__type' => 'uczestnik', 'imie' => 'Jan', 'email' => '' ), true );

		[ $redirect, $html ] = $this->runHandler();

		$this->assertNull( $redirect, 'Włączona wysyłka bez adresu nie przekierowuje — formularz wraca z błędem.' );
		$this->assertSame( 0, $this->registrationCount() );
		$this->assertSame( 0, $this->queuedRowCount() );
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'E-mail', $html );
		$this->assertMatchesRegularExpression( '/name="evreg_notify"[^>]*checked/', $html, 'Stan przełącznika musi wrócić.' );
	}

	public function test_handle_add_with_empty_address_and_notify_off_saves(): void {
		$this->postAdd( array( '__type' => 'uczestnik', 'imie' => 'Babcia Jadwiga', 'email' => '' ) );

		[ $redirect ] = $this->runHandler();

		$this->assertNotNull( $redirect, 'Bez adresu i bez wysyłki wpis ręczny musi przejść.' );
		$this->assertStringContainsString( 'evreg_msg=added', $redirect->location );

		$rows = $this->rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( '', $rows[0]['email'] );
		$this->assertSame( 'Babcia Jadwiga', $rows[0]['name'] );
		$this->assertSame( 0, $this->queuedRowCount() );
	}

	public function test_handle_add_with_missing_required_field_rerenders_without_saving(): void {
		$this->postAdd( array( '__type' => 'uczestnik', 'imie' => '', 'email' => 'jan@example.com' ) );

		[ $redirect, $html ] = $this->runHandler();

		$this->assertNull( $redirect, 'Brak wymaganego pola nie przekierowuje — formularz wraca z błędem.' );
		$this->assertSame( 0, $this->registrationCount() );
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'To pole jest wymagane.', $html );
		$this->assertStringContainsString( 'name="event_id" value="' . $this->event_id . '"', $html );
		$this->assertDoesNotMatchRegularExpression( '/name="evreg_notify"[^>]*checked/', $html, 'Odtworzenie stanu nie może włączać wysyłki, o którą nie proszono.' );
	}

	public function test_handle_add_on_address_with_active_registration_saves_and_says_so(): void {
		$this->seed( 'rodzina@example.com' );

		$this->postAdd( array( '__type' => 'uczestnik', 'imie' => 'Druga Osoba', 'email' => 'rodzina@example.com' ) );

		[ $redirect ] = $this->runHandler();

		$this->assertNotNull( $redirect, 'Duplikat adresu to informacja, nie blokada.' );
		$this->assertStringContainsString( 'evreg_msg=added', $redirect->location );
		$this->assertStringContainsString( 'evreg_dup=1', $redirect->location );
		$this->assertSame( 2, $this->registrationCount(), 'Oba zgłoszenia na ten adres istnieją.' );

		$html = $this->renderRedirectTarget( $redirect->location );

		$this->assertStringContainsString( 'notice-success', $html );
		$this->assertStringContainsString( 'inne aktywne zgłoszenie', $html );
		$this->assertStringContainsString( 'Druga Osoba', $html, 'Ekran docelowy to detal nowego zgłoszenia.' );
	}

	public function test_handle_add_without_duplicate_does_not_mention_one(): void {
		$this->postAdd( array( '__type' => 'uczestnik', 'imie' => 'Jan', 'email' => 'jan@example.com' ) );

		[ $redirect ] = $this->runHandler();

		$this->assertNotNull( $redirect );
		$this->assertStringNotContainsString( 'evreg_dup', $redirect->location );

		$html = $this->renderRedirectTarget( $redirect->location );

		$this->assertStringContainsString( 'Zgłoszenie dodane ręcznie', $html );
		$this->assertStringNotContainsString( 'inne aktywne zgłoszenie', $html );
	}

	public function test_handle_add_lands_on_waitlist_when_the_type_is_full(): void {
		$this->saveConfig( 1, true );
		$this->seed( 'pierwszy@example.com' );

		$this->postAdd( array( '__type' => 'uczestnik', 'imie' => 'Drugi', 'email' => 'drugi@example.com' ) );

		[ $redirect ] = $this->runHandler();

		$this->assertNotNull( $redirect );
		$this->assertStringContainsString( 'evreg_msg=added_waitlist', $redirect->location );

		$rows = $this->rows();
		$this->assertSame( 'waitlist', $rows[0]['status'] );
		$this->assertSame( '1', (string) $rows[0]['manual'] );

		$this->assertStringContainsString( 'listę rezerwową', $this->renderRedirectTarget( $redirect->location ) );
	}

	public function test_handle_add_reports_no_seats_when_the_type_is_full_without_waitlist(): void {
		$this->saveConfig( 1, false );
		$this->seed( 'pierwszy@example.com' );

		$this->postAdd( array( '__type' => 'uczestnik', 'imie' => 'Drugi', 'email' => 'drugi@example.com' ) );

		[ $redirect ] = $this->runHandler();

		$this->assertNotNull( $redirect );
		$this->assertStringContainsString( 'evreg_msg=capacity_full', $redirect->location );
		$this->assertSame( 1, $this->registrationCount(), 'Odrzucone zgłoszenie nie powstaje.' );
	}

	public function test_handle_add_with_full_accommodation_rerenders_at_the_accommodation_field(): void {
		$this->saveAccommodationConfig( 0 );

		$this->postAdd(
			array(
				'__type' => 'uczestnik',
				'imie'   => 'Jan',
				'email'  => 'jan@example.com',
				'nocleg' => array( 'slot' => 'n18|double' ),
			)
		);

		[ $redirect, $html ] = $this->runHandler();

		$this->assertNull( $redirect, 'Wyczerpany nocleg wraca na formularz, nie przez PRG.' );
		$this->assertSame( 0, $this->registrationCount(), 'Bez łóżka zgłoszenie nie jest zapisywane po cichu.' );
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'Nocleg', $html );
		$this->assertStringContainsString( 'nie ma już wolnych miejsc', $html );
		$this->assertStringNotContainsString( 'Nieprawidłowa wartość.', $html );
	}

	public function test_handle_add_rejects_a_two_person_room_without_a_companion(): void {
		$this->saveAccommodationConfig( 10, 2 );

		$this->postAdd(
			array(
				'__type' => 'uczestnik',
				'imie'   => 'Jan',
				'email'  => 'jan@example.com',
				'nocleg' => array( 'slot' => 'n18|double' ),
			)
		);

		[ $redirect, $html ] = $this->runHandler();

		$this->assertNull( $redirect, 'Niespójna para pokój/osoba towarzysząca wraca na formularz.' );
		$this->assertSame( 0, $this->registrationCount() );
		$this->assertStringContainsString( 'Ten pokój jest dwuosobowy.', $html );
	}

	public function test_handle_add_without_event_id_reports_a_missing_event(): void {
		$this->postAdd( array( '__type' => 'uczestnik', 'imie' => 'Jan', 'email' => 'jan@example.com' ), false, 0 );

		[ $redirect ] = $this->runHandler();

		$this->assertNotNull( $redirect );
		$this->assertStringContainsString( 'evreg_msg=add_no_event', $redirect->location );
		$this->assertSame( 0, $this->registrationCount() );
	}

	public function test_handle_add_with_a_post_of_another_type_reports_a_missing_event(): void {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->postAdd( array( '__type' => 'uczestnik', 'imie' => 'Jan', 'email' => 'jan@example.com' ), false, $page_id );

		[ $redirect ] = $this->runHandler();

		$this->assertNotNull( $redirect );
		$this->assertStringContainsString( 'evreg_msg=add_no_event', $redirect->location );

		$html = $this->renderRedirectTarget( $redirect->location );

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'Nie znaleziono wydarzenia', $html );
	}

	public function test_add_route_renders_the_form_for_the_chosen_event(): void {
		$_GET['action']   = 'add';
		$_GET['event_id'] = $this->event_id;

		$html = $this->renderScreen();

		$this->assertStringContainsString( 'name="action" value="' . RegistrationsScreen::ACTION_ADD . '"', $html );
		$this->assertStringContainsString( 'name="event_id" value="' . $this->event_id . '"', $html );
		$this->assertStringContainsString( 'name="evreg_field[email]"', $html );
	}

	public function test_add_route_without_event_renders_the_picker(): void {
		$_GET['action'] = 'add';

		$html = $this->renderScreen();

		$this->assertStringContainsString( 'Wybierz wydarzenie', $html );
		$this->assertStringContainsString( 'Zjazd wiosenny', $html );
		$this->assertStringNotContainsString( 'name="evreg_notify"', $html );
	}

	public function test_list_has_an_add_button_carrying_the_event_filter(): void {
		$_GET['event_id'] = $this->event_id;

		$html = $this->renderScreen();

		$this->assertStringContainsString( 'Dodaj zgłoszenie', $html );
		$this->assertStringContainsString( 'action=add', $html );
		$this->assertStringContainsString( 'event_id=' . $this->event_id, $html );
	}

	public function test_promote_of_a_manual_row_does_not_promise_a_confirmation_mail(): void {
		$id = $this->seed( 'telefon@example.com', 'waitlist', true );

		$_POST['id']          = $id;
		$_REQUEST['_wpnonce'] = wp_create_nonce( RegistrationsScreen::ACTION_PROMOTE . '_' . $id );

		$redirect = $this->catchRedirect( array( RegistrationsScreen::class, 'handle_promote' ) );

		$this->assertNotNull( $redirect );
		$this->assertStringContainsString( 'evreg_msg=promoted_manual', $redirect->location );
		$this->assertSame( 'confirmed', (string) $this->repository->findById( $id )['status'] );
		$this->assertSame( 0, $this->queuedRowCount() );

		$html = $this->renderRedirectTarget( $redirect->location );

		$this->assertStringContainsString( 'Awansowano', $html );
		$this->assertStringNotContainsString( 'wysłano prośbę o potwierdzenie', $html );
	}

	public function test_promote_of_a_public_row_keeps_todays_message(): void {
		$id = $this->seed( 'formularz@example.com', 'waitlist' );

		$_POST['id']          = $id;
		$_REQUEST['_wpnonce'] = wp_create_nonce( RegistrationsScreen::ACTION_PROMOTE . '_' . $id );

		$redirect = $this->catchRedirect( array( RegistrationsScreen::class, 'handle_promote' ) );

		$this->assertNotNull( $redirect );
		$this->assertStringContainsString( 'evreg_msg=promoted', $redirect->location );
		$this->assertStringNotContainsString( 'promoted_manual', $redirect->location );
		$this->assertSame( 'pending', (string) $this->repository->findById( $id )['status'] );

		$this->assertStringContainsString( 'wysłano prośbę o potwierdzenie', $this->renderRedirectTarget( $redirect->location ) );
	}

	/**
	 * Awaria zapisu wierszy dób nie może skończyć się fatalem.
	 *
	 * Serwis rzuca \RuntimeException, gdy zapisze inną liczbę dób niż żądaną — transakcja
	 * jest wtedy wycofana, więc nic nie powstaje, ale bez degradacji organizator dostaje
	 * białą stronę i nie wie, czy zgłoszenie jest w bazie. Awarię wstrzykujemy w ZAPIS,
	 * nie w wybór: INSERT wierszy dób podmieniamy na DELETE, który nie trafia w nic.
	 */
	public function test_handle_add_degrades_a_failed_night_write_into_a_notice(): void {
		$this->saveNightsConfig();

		$breaker = static function ( $sql ) {
			if ( is_string( $sql ) && str_contains( $sql, 'INSERT INTO' ) && str_contains( $sql, 'booking_nights' ) ) {
				return 'DELETE FROM ' . Migrations::table( 'booking_nights' ) . ' WHERE registration_id = 0 AND 1 = 0';
			}

			return $sql;
		};

		// Log awarii jest dla operatora JEDYNYM śladem przyczyny, więc sprawdzamy go
		// zamiast puszczać na stderr testów.
		$log      = tempnam( sys_get_temp_dir(), 'evreg-add-failure' );
		$previous = ini_set( 'error_log', (string) $log );

		add_filter( 'query', $breaker );

		$this->postAdd(
			array(
				'__type' => 'uczestnik',
				'imie'   => 'Jan',
				'email'  => 'jan@example.com',
				'nocleg' => array( 'nights' => array( 'n1819' ) ),
			)
		);

		try {
			[ $redirect, $html ] = $this->runHandler();
		} finally {
			remove_filter( 'query', $breaker );

			if ( false !== $previous ) {
				ini_set( 'error_log', $previous );
			}
		}

		$this->assertNotNull( $redirect, 'Awaria zapisu musi skończyć się komunikatem (PRG), nie fatalem.' );
		$this->assertStringContainsString( 'evreg_msg=add_failed', $redirect->location );
		$this->assertSame( '', $html );
		$this->assertSame( 0, $this->registrationCount(), 'Transakcja wycofana — nic nie zostało zapisane.' );

		$logged = (string) file_get_contents( (string) $log );
		unlink( (string) $log );
		$this->assertStringContainsString( 'evreg manual add failed: Zapisano 0 z 1', $logged, 'Operator musi mieć w logu przyczynę.' );

		$notice = $this->renderRedirectTarget( $redirect->location );

		$this->assertStringContainsString( 'notice-error', $notice );
		$this->assertStringContainsString( 'Nic nie zostało zapisane', $notice );
	}
}
