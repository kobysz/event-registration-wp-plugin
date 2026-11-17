<?php
/**
 * Powierzchnie admina w trybie „doby”: edycja, detal, panel obłożenia.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Admin;

use EvReg\Admin\Capabilities;
use EvReg\Admin\RegistrationsOverview;
use EvReg\Admin\RegistrationsScreen;
use EvReg\Domain\Accommodation\NightSelection;
use EvReg\Domain\Accommodation\NightsConfig;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Services\ReservationRequest;
use EvReg\Services\ReservationService;
use WP_UnitTestCase;

/**
 * Wyjątek przenoszący adres redirectu, by przerwać przed exit handlera.
 */
final class NightAdminRedirectException extends \Exception {

	public string $location;

	public function __construct( string $location ) {
		parent::__construct( 'redirect' );
		$this->location = $location;
	}
}

/**
 * Trzy ekrany, które organizator czyta i poprawia w trybie „doby”.
 */
final class NightAdminScreensTest extends WP_UnitTestCase {

	private RegistrationRepository $repository;

	private ReservationService $service;

	private int $event_id;

	private int $admin_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'booking_nights', 'locks', 'mail_queue' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		Capabilities::grant();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		$this->repository = new RegistrationRepository();
		$this->service    = new ReservationService( $this->repository, new EventConfigRepository() );
		$this->event_id   = self::factory()->post->create(
			array(
				'post_type'  => 'evreg_event',
				'post_title' => 'Zjazd 2026',
			)
		);

		$this->configure();
	}

	protected function tearDown(): void {
		remove_all_filters( 'wp_redirect' );
		unset(
			$_GET['action'],
			$_GET['id'],
			$_GET['evreg_msg'],
			$_GET['evreg_night'],
			$_POST['registration'],
			$_POST['evreg_field'],
			$_POST['evreg_companion'],
			$_POST['evreg_companion_name'],
			$_REQUEST['_wpnonce']
		);
		foreach ( array( 'evreg_registration_reserved', 'evreg_registration_waitlisted' ) as $hook ) {
			remove_all_actions( $hook );
		}
		parent::tearDown();
	}

	/**
	 * Zapisuje konfigurację eventu w trybie „doby”.
	 *
	 * @param array<int,array<string,mixed>>|null $nights        Doby; null = domyślne dwie.
	 * @param array<string,mixed>                 $accommodation Nadpisania bloku noclegu.
	 * @param array<string,mixed>                 $settings      Nadpisania ustawień eventu.
	 */
	private function configure( ?array $nights = null, array $accommodation = array(), array $settings = array() ): void {
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
				'accommodation' => array_merge(
					array(
						'mode'                   => 'nights',
						'nights'                 => $nights,
						'companion_enabled'      => true,
						'companion_counts_event' => false,
					),
					$accommodation
				),
				'settings'      => array_merge( array( 'waitlist_enabled' => false ), $settings ),
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
	 * Rezerwuje zgłoszenie w trybie „doby” i zwraca jego ID.
	 *
	 * @param string        $email     Adres e-mail.
	 * @param array<string> $nights    Klucze wybranych dób.
	 * @param bool          $companion Czy z osobą towarzyszącą.
	 * @param string        $expected  Oczekiwany kod wyniku (`reserved` albo `waitlisted`).
	 */
	private function reserve( string $email, array $nights, bool $companion = false, string $expected = 'reserved' ): int {
		$selection = NightSelection::fromKeys( $nights, $this->nightsConfig() );

		$result = $this->service->reserve(
			$this->event_id,
			new ReservationRequest(
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
			)
		);

		$this->assertSame( $expected, $result->code, 'Rezerwacja na potrzeby testu musi dać oczekiwany wynik.' );

		return (int) $result->registrationId;
	}

	/**
	 * Renderuje ekran panelu zgłoszeń dla danej akcji i ID.
	 *
	 * @param string $action Akcja widoku (view|edit).
	 * @param int    $id     ID zgłoszenia.
	 */
	private function renderScreen( string $action, int $id ): string {
		$_GET['action'] = $action;
		$_GET['id']     = $id;

		ob_start();
		RegistrationsScreen::render();

		return (string) ob_get_clean();
	}

	/**
	 * Łapie redirect handlera (przerywa przed exit).
	 *
	 * @param callable $fn Handler do wywołania.
	 */
	private function catchRedirect( callable $fn ): ?NightAdminRedirectException {
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new NightAdminRedirectException( (string) $location );
			}
		);

		try {
			$fn();
		} catch ( NightAdminRedirectException $e ) {
			return $e;
		}

		return null;
	}

	/**
	 * Odczytuje z wyrenderowanego formularza wartości kontrolek `evreg_field[...]` —
	 * dokładnie to, co przeglądarka wysłałaby po kliknięciu „Zapisz" bez zmian.
	 *
	 * @param string $html Wyrenderowany formularz edycji.
	 * @return array<string,mixed>
	 */
	private function postFromForm( string $html ): array {
		$post = array();

		preg_match_all( '/<(?:input|textarea|select)\b([^>]*)>/i', $html, $tags );

		foreach ( $tags[1] as $attrs ) {
			if ( 1 !== preg_match( '/name="evreg_field\[([^\]"]+)\]((?:\[[^\]"]*\])*)"/', (string) $attrs, $m ) ) {
				continue;
			}

			$key     = $m[1];
			$suffix  = $m[2];
			$checked = false !== strpos( (string) $attrs, ' checked' );
			$value   = 1 === preg_match( '/value="([^"]*)"/', (string) $attrs, $v ) ? $v[1] : '';
			$is_box  = 1 === preg_match( '/type="(?:checkbox|radio)"/', (string) $attrs );

			if ( '[nights][]' === $suffix ) {
				if ( $checked ) {
					$post[ $key ]['nights'][] = $value;
				}
				continue;
			}

			if ( $is_box && ! $checked ) {
				continue;
			}

			$post[ $key ] = $value;
		}

		return $post;
	}

	public function test_edit_screen_ticks_the_nights_the_registration_holds(): void {
		$id = $this->reserve( 'jan@example.com', array( 'n1819', 'n1920' ) );

		$html = $this->renderScreen( 'edit', $id );

		$this->assertStringContainsString( 'name="evreg_field[nocleg][nights][]"', $html );

		foreach ( array( 'n1819', 'n1920' ) as $key ) {
			$this->assertMatchesRegularExpression(
				'/name="evreg_field\[nocleg\]\[nights\]\[\]" value="' . $key . '"[^>]*checked/',
				$html,
				'Doba ' . $key . ' zapisana przy zgłoszeniu musi być zaznaczona.'
			);
		}
	}

	public function test_edit_screen_leaves_a_night_the_registration_does_not_hold_unticked(): void {
		$id = $this->reserve( 'jan@example.com', array( 'n1819' ) );

		$html = $this->renderScreen( 'edit', $id );

		$this->assertMatchesRegularExpression(
			'/name="evreg_field\[nocleg\]\[nights\]\[\]" value="n1819"[^>]*checked/',
			$html
		);
		$this->assertDoesNotMatchRegularExpression(
			'/name="evreg_field\[nocleg\]\[nights\]\[\]" value="n1920"[^>]*checked/',
			$html
		);
	}

	public function test_submitting_the_rendered_edit_form_unchanged_keeps_rows_seats_and_price(): void {
		$id = $this->reserve( 'jan@example.com', array( 'n1819', 'n1920' ), true );

		$before       = $this->repository->bookingNightsFor( array( $id ) )[ $id ];
		$price_before = (float) $this->repository->findById( $id )['price_total'];

		$this->assertSame( array( 'n1819', 'n1920' ), array_column( $before, 'key' ) );
		$this->assertSame( array( 2, 2 ), array_column( $before, 'seats' ) );
		$this->assertSame( 440.0, $price_before, '100 + 2×80 + 2×90.' );

		$html = $this->renderScreen( 'edit', $id );
		$post = $this->postFromForm( $html );

		$this->assertSame( array( 'n1819', 'n1920' ), $post['nocleg']['nights'], 'Formularz musi oddać doby, które wyrenderował.' );

		$_POST['registration']         = $id;
		$_REQUEST['_wpnonce']          = wp_create_nonce( 'evreg_edit_' . $id );
		$_POST['evreg_field']          = $post;
		$_POST['evreg_companion']      = '1';
		$_POST['evreg_companion_name'] = 'Jan T.';

		$redirect = $this->catchRedirect( array( RegistrationsScreen::class, 'handle_edit' ) );

		$this->assertNotNull( $redirect, 'Zapis bez zmian musi przejść (PRG).' );
		$this->assertStringContainsString( 'evreg_msg=edited', $redirect->location );

		$after = $this->repository->bookingNightsFor( array( $id ) )[ $id ] ?? array();

		$this->assertSame( array( 'n1819', 'n1920' ), array_column( $after, 'key' ) );
		$this->assertSame( array( 2, 2 ), array_column( $after, 'seats' ) );
		$this->assertSame( array( 160.0, 180.0 ), array_column( $after, 'price' ) );
		$this->assertSame( $price_before, (float) $this->repository->findById( $id )['price_total'] );
	}

	/**
	 * Zgłoszenie z listy rezerwowej NIE MA wierszy dób (nie zajmuje miejsc), a jego wybór
	 * żyje wyłącznie w JSON-ie odpowiedzi. Prefill musi go pokazać, a zapis bez zmian
	 * zachować: bezwarunkowe nadpisanie prefilla wierszami odznaczyłoby wszystko i zapis
	 * skasowałby życzenie po cichu, meldując „zapisano" (ta sama asymetria co B15).
	 */
	public function test_edit_screen_ticks_the_wish_of_a_rowless_waitlisted_registration_and_the_save_keeps_it(): void {
		$this->configure(
			null,
			array(),
			array(
				'waitlist_enabled' => true,
				'global_cap'       => 1,
			)
		);

		$this->reserve( 'pierwszy@example.com', array( 'n1819' ) );
		$id = $this->reserve( 'jan@example.com', array( 'n1819', 'n1920' ), false, 'waitlisted' );

		$this->assertSame( array(), $this->repository->bookingNightsFor( array( $id ) ), 'Lista rezerwowa nie ma wierszy dób.' );

		$before = json_decode( (string) $this->repository->findById( $id )['data'], true );
		$this->assertSame( array( 'n1819', 'n1920' ), $before['nocleg'], 'Życzenie siedzi w JSON-ie odpowiedzi.' );

		$html = $this->renderScreen( 'edit', $id );

		foreach ( array( 'n1819', 'n1920' ) as $key ) {
			$this->assertMatchesRegularExpression(
				'/name="evreg_field\[nocleg\]\[nights\]\[\]" value="' . $key . '"[^>]*checked/',
				$html,
				'Doba z życzenia ' . $key . ' musi być zaznaczona, choć nie ma wiersza.'
			);
		}

		$this->assertStringContainsString(
			'Nocleg nie jest przyznany',
			$html,
			'Operator musi widzieć, że ptaszki są życzeniem, nie zajętymi miejscami.'
		);

		$post = $this->postFromForm( $html );
		$this->assertSame( array( 'n1819', 'n1920' ), $post['nocleg']['nights'] );

		$_POST['registration'] = $id;
		$_REQUEST['_wpnonce']  = wp_create_nonce( 'evreg_edit_' . $id );
		$_POST['evreg_field']  = $post;

		$redirect = $this->catchRedirect( array( RegistrationsScreen::class, 'handle_edit' ) );

		$this->assertNotNull( $redirect );
		$this->assertStringContainsString( 'evreg_msg=edited', $redirect->location );

		$row   = $this->repository->findById( $id );
		$after = json_decode( (string) $row['data'], true );

		$this->assertSame( array( 'n1819', 'n1920' ), $after['nocleg'], 'Zapis bez zmian nie może skasować życzenia.' );
		$this->assertSame( 'waitlist', (string) $row['status'] );
		$this->assertSame( array(), $this->repository->bookingNightsFor( array( $id ) ), 'Edycja nie przyznaje noclegu listy rezerwowej.' );
	}

	/**
	 * Prefill bierze się z WIERSZY, nie z JSON-a — gdy się rozchodzą, wygrywają wiersze.
	 * Bez tego testu usunięcie całego bloku prefilla (upadek do JSON-a) przechodziłoby
	 * niezauważone, bo w pozostałych fixture'ach oba źródła są identyczne.
	 */
	public function test_edit_screen_ticks_only_the_night_the_rows_hold_when_they_diverge_from_the_json(): void {
		$id = $this->reserve( 'jan@example.com', array( 'n1819', 'n1920' ) );

		// Wiersze stają się WŁAŚCIWYM podzbiorem życzenia z JSON-a (jak po odmowie jednej doby).
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

		$json = json_decode( (string) $this->repository->findById( $id )['data'], true );
		$this->assertSame( array( 'n1819', 'n1920' ), $json['nocleg'], 'JSON nadal niesie oba życzenia.' );

		$html = $this->renderScreen( 'edit', $id );

		$this->assertMatchesRegularExpression(
			'/name="evreg_field\[nocleg\]\[nights\]\[\]" value="n1819"[^>]*checked/',
			$html,
			'Doba trzymana przez zgłoszenie musi być zaznaczona.'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/name="evreg_field\[nocleg\]\[nights\]\[\]" value="n1920"[^>]*checked/',
			$html,
			'Doba, której wiersze już nie trzymają, NIE może być zaznaczona — prefill czyta stan, nie życzenie.'
		);
		$this->assertStringNotContainsString(
			'Nocleg nie jest przyznany',
			$html,
			'Zgłoszenie z wierszami ma przyznany nocleg — bez zastrzeżenia.'
		);
	}

	public function test_edit_can_drop_a_single_night(): void {
		$id = $this->reserve( 'jan@example.com', array( 'n1819', 'n1920' ) );

		$_POST['registration'] = $id;
		$_REQUEST['_wpnonce']  = wp_create_nonce( 'evreg_edit_' . $id );
		$_POST['evreg_field']  = array(
			'__type' => 'uczestnik',
			'email'  => 'jan@example.com',
			'nocleg' => array( 'nights' => array( 'n1819' ) ),
		);

		$redirect = $this->catchRedirect( array( RegistrationsScreen::class, 'handle_edit' ) );

		$this->assertNotNull( $redirect );
		$this->assertStringContainsString( 'evreg_msg=edited', $redirect->location );

		$after = $this->repository->bookingNightsFor( array( $id ) )[ $id ] ?? array();

		$this->assertSame( array( 'n1819' ), array_column( $after, 'key' ) );
		$this->assertSame( 180.0, (float) $this->repository->findById( $id )['price_total'], '100 + 80.' );
	}

	public function test_detail_shows_the_label_and_price_snapshotted_in_the_rows(): void {
		$id = $this->reserve( 'jan@example.com', array( 'n1819' ) );

		// Konfiguracja zmienia nazwę i cenę PO rezerwacji — detal ma pokazać historię.
		$this->configure(
			array(
				array(
					'key'   => 'n1819',
					'label' => 'Noc przemianowana',
					'price' => 500.0,
					'seats' => 4,
				),
			)
		);

		$html = $this->renderScreen( 'view', $id );

		// Asercja na KOMÓRKACH wiersza tabeli dób, nie na całym dokumencie: „80" samo
		// w sobie trafiałoby też w „180.00" (kwota zgłoszenia) gdzie indziej na stronie.
		$this->assertMatchesRegularExpression(
			'#<td>18/19 XI</td><td>1</td><td>80[.,]00</td>#',
			$html,
			'Wiersz tabeli dób musi nieść etykietę, miejsca i cenę ze snapshotu.'
		);
		$this->assertStringNotContainsString( 'Noc przemianowana', $html, 'Detal nie może czytać dzisiejszej konfiguracji.' );
		$this->assertDoesNotMatchRegularExpression( '#<td>500[.,]00</td>#', $html, 'Detal nie może pokazać dzisiejszej ceny doby.' );
	}

	public function test_edit_refusal_notice_names_the_blocking_night_not_its_key(): void {
		// Doba n1920 ma 4 miejsca; cztery inne zgłoszenia je wypełniają.
		foreach ( range( 1, 4 ) as $i ) {
			$this->reserve( 'inny' . $i . '@example.com', array( 'n1920' ) );
		}

		$id = $this->reserve( 'jan@example.com', array( 'n1819' ) );

		$_POST['registration'] = $id;
		$_REQUEST['_wpnonce']  = wp_create_nonce( 'evreg_edit_' . $id );
		$_POST['evreg_field']  = array(
			'__type' => 'uczestnik',
			'email'  => 'jan@example.com',
			'nocleg' => array( 'nights' => array( 'n1819', 'n1920' ) ),
		);

		$redirect = $this->catchRedirect( array( RegistrationsScreen::class, 'handle_edit' ) );

		$this->assertNotNull( $redirect );
		$this->assertStringContainsString( 'evreg_msg=accommodation_full', $redirect->location );
		$this->assertStringContainsString( 'evreg_night=n1920', $redirect->location );

		$this->assertSame(
			array( 'n1819' ),
			array_column( $this->repository->bookingNightsFor( array( $id ) )[ $id ], 'key' ),
			'Odmowa nie może ruszyć zapisanych dób.'
		);

		$_GET['evreg_msg']   = 'accommodation_full';
		$_GET['evreg_night'] = 'n1920';

		$html = $this->renderScreen( 'edit', $id );

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertMatchesRegularExpression( '/notice-error.*19\/20 XI/s', $html, 'Komunikat musi nazwać dobę etykietą.' );
	}

	public function test_edit_refusal_for_a_removed_night_tells_the_operator_what_to_do(): void {
		// Druga przyczyna odmowy, z inną radą. Doba nie jest pełna — jej NIE MA. Rada
		// „odznacz ją" byłaby ślepym zaułkiem: formularz iteruje bieżącą konfigurację, więc
		// nie renderuje dla niej pola i nie da się odznaczyć czegoś, czego nie widać.
		// Etykiety też nie ma (nightLabel degraduje do klucza), więc komunikat podaje klucz
		// i mówi, co zrobić: przywrócić dobę albo świadomie porzucić nocleg.
		$id = $this->reserve( 'jan@example.com', array( 'n1920' ) );

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

		// Formularz edycji nie renderuje n1920, więc POST niesie zbiór PUSTY.
		$_POST['registration'] = $id;
		$_REQUEST['_wpnonce']  = wp_create_nonce( 'evreg_edit_' . $id );
		$_POST['evreg_field']  = array(
			'__type' => 'uczestnik',
			'email'  => 'jan@example.com',
			'nocleg' => array( 'nights' => array() ),
		);

		$redirect = $this->catchRedirect( array( RegistrationsScreen::class, 'handle_edit' ) );

		$this->assertNotNull( $redirect );
		$this->assertStringContainsString( 'evreg_msg=accommodation_gone', $redirect->location );
		$this->assertStringContainsString( 'evreg_night=n1920', $redirect->location );

		$this->assertSame(
			array( 'n1920' ),
			array_column( $this->repository->bookingNightsFor( array( $id ) )[ $id ], 'key' ),
			'Odmowa nie może ruszyć zapisanych dób.'
		);
		$this->assertSame( 190.0, (float) $this->repository->findById( $id )['price_total'], '100 + 90, bez zmian.' );

		$_GET['evreg_msg']   = 'accommodation_gone';
		$_GET['evreg_night'] = 'n1920';

		$html = $this->renderScreen( 'edit', $id );

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertMatchesRegularExpression( '/notice-error.*n1920/s', $html, 'Komunikat musi wskazać dobę kluczem.' );
		$this->assertMatchesRegularExpression(
			'/notice-error.*nie ma jej już w konfiguracji wydarzenia/su',
			$html,
			'Komunikat musi powiedzieć, CO się stało.'
		);
		$this->assertMatchesRegularExpression(
			'/notice-error.*Przywróć tę dobę/su',
			$html,
			'Komunikat musi powiedzieć, co operator ma zrobić.'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/notice-error.*Odznacz ją/su',
			$html,
			'Rada z pełnej doby jest tu ślepym zaułkiem — doby nie ma w formularzu.'
		);
	}

	public function test_overview_panel_counts_seats_and_pairs_per_night(): void {
		$this->reserve( 'para@example.com', array( 'n1819' ), true );
		$this->reserve( 'solo@example.com', array( 'n1819', 'n1920' ) );

		ob_start();
		RegistrationsOverview::render( $this->event_id );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '18/19 XI', $html );
		$this->assertStringContainsString( '19/20 XI', $html );
		// n1819: 2 (para) + 1 (solo) z 4, wolne 1, jedna para; n1920: 1 z 4, wolne 3, zero par.
		$this->assertMatchesRegularExpression( '#18/19 XI.*?3 z 4 \(wolne: 1\).*?pary: 1 \(bez limitu\)#s', $html );
		$this->assertMatchesRegularExpression( '#19/20 XI.*?1 z 4 \(wolne: 3\).*?pary: 0 \(bez limitu\)#s', $html );
	}

	public function test_overview_panel_shows_the_pair_cap_when_a_night_has_one(): void {
		$this->configure(
			array(
				array(
					'key'           => 'n1819',
					'label'         => '18/19 XI',
					'price'         => 80.0,
					'seats'         => 6,
					'companion_cap' => 2,
				),
			)
		);

		$this->reserve( 'para@example.com', array( 'n1819' ), true );

		ob_start();
		RegistrationsOverview::render( $this->event_id );
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '#18/19 XI.*?2 z 6 \(wolne: 4\).*?pary: 1 z 2#s', $html );
	}

	/**
	 * Niepoprawna konfiguracja dób nie może zabić CAŁEGO ekranu listy zgłoszeń — panel
	 * degraduje się do komunikatu. Fixture z duplikatem klucza, nie z niespójnym
	 * inwentarzem klasycznym: ten drugi NightsConfig::fromArray przechodzi bez błędu.
	 */
	public function test_overview_panel_degrades_with_a_notice_on_a_broken_nights_config(): void {
		$this->reserve( 'jan@example.com', array( 'n1819' ) );

		$this->configure(
			array(
				array(
					'key'   => 'n1819',
					'label' => '18/19 XI',
					'price' => 80.0,
					'seats' => 4,
				),
				array(
					'key'   => 'n1819',
					'label' => 'Duplikat',
					'price' => 80.0,
					'seats' => 4,
				),
			)
		);

		ob_start();
		RegistrationsOverview::render( $this->event_id );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'Zduplikowana doba', $html, 'Komunikat ma powiedzieć, co poprawić.' );
		$this->assertStringNotContainsString( 'Doby (miejsca)', $html, 'Bez konfiguracji nie ma czego tabelaryzować.' );
	}

	/**
	 * Etykieta doby to tekst od operatora — każda z trzech powierzchni musi ją escapować.
	 * Jeden fixture pina wszystkie trzy `esc_html()` naraz.
	 */
	public function test_a_night_label_is_escaped_on_the_edit_detail_and_panel_screens(): void {
		$this->configure(
			array(
				array(
					'key'   => 'n1819',
					'label' => '18/19 <b>XI</b>',
					'price' => 80.0,
					'seats' => 4,
				),
			)
		);

		$id = $this->reserve( 'jan@example.com', array( 'n1819' ) );

		ob_start();
		RegistrationsOverview::render( $this->event_id );
		$panel = (string) ob_get_clean();

		foreach ( array(
			'panel'  => $panel,
			'edycja' => $this->renderScreen( 'edit', $id ),
			'detal'  => $this->renderScreen( 'view', $id ),
		) as $surface => $html ) {
			$this->assertStringNotContainsString( '<b>XI</b>', $html, 'Ekran „' . $surface . '" nie może wypuścić surowego HTML-a z etykiety.' );
			$this->assertStringContainsString( '18/19 &lt;b&gt;XI&lt;/b&gt;', $html, 'Ekran „' . $surface . '" musi pokazać etykietę escapowaną.' );
		}
	}

	public function test_overview_panel_survives_leftover_classic_inventory(): void {
		// Event w trybie „doby” z zapomnianym, niespójnym inwentarzem klasycznym:
		// AccommodationConfig::fromArray rzuciłaby, więc panel nie może jej budować.
		$this->configure(
			null,
			array(
				'packages'  => array(),
				'rooms'     => array(),
				'inventory' => array(
					array(
						'package'  => 'widmo',
						'room'     => 'widmo',
						'capacity' => 3,
						'price'    => 10.0,
					),
				),
			)
		);

		$this->reserve( 'jan@example.com', array( 'n1819' ) );

		ob_start();
		RegistrationsOverview::render( $this->event_id );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '18/19 XI', $html );
		$this->assertMatchesRegularExpression( '#18/19 XI.*?1 z 4 \(wolne: 3\)#s', $html );
	}

	public function test_edit_screen_survives_leftover_classic_inventory(): void {
		$id = $this->reserve( 'jan@example.com', array( 'n1819' ) );

		$this->configure(
			null,
			array(
				'packages'  => array(),
				'rooms'     => array(),
				'inventory' => array(
					array(
						'package'  => 'widmo',
						'room'     => 'widmo',
						'capacity' => 3,
						'price'    => 10.0,
					),
				),
			)
		);

		$html = $this->renderScreen( 'edit', $id );

		$this->assertStringContainsString( 'name="action" value="evreg_edit_registration"', $html );
		$this->assertMatchesRegularExpression(
			'/name="evreg_field\[nocleg\]\[nights\]\[\]" value="n1819"[^>]*checked/',
			$html
		);
	}

	public function test_edit_screen_degrades_with_a_notice_on_a_broken_nights_config(): void {
		$id = $this->reserve( 'jan@example.com', array( 'n1819' ) );

		// Zduplikowany klucz doby — NightsConfig::fromArray rzuca SchemaException.
		$this->configure(
			array(
				array(
					'key'   => 'n1819',
					'label' => '18/19 XI',
					'price' => 80.0,
					'seats' => 4,
				),
				array(
					'key'   => 'n1819',
					'label' => 'Duplikat',
					'price' => 80.0,
					'seats' => 4,
				),
			)
		);

		$html = $this->renderScreen( 'edit', $id );

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringNotContainsString( 'name="action" value="evreg_edit_registration"', $html );
	}

	public function test_handle_edit_degrades_with_a_message_on_a_broken_nights_config(): void {
		$id = $this->reserve( 'jan@example.com', array( 'n1819' ) );

		$this->configure(
			array(
				array(
					'key'   => 'n1819',
					'label' => '18/19 XI',
					'price' => 80.0,
					'seats' => 4,
				),
				array(
					'key'   => 'n1819',
					'label' => 'Duplikat',
					'price' => 80.0,
					'seats' => 4,
				),
			)
		);

		$_POST['registration'] = $id;
		$_REQUEST['_wpnonce']  = wp_create_nonce( 'evreg_edit_' . $id );
		$_POST['evreg_field']  = array(
			'__type' => 'uczestnik',
			'email'  => 'jan@example.com',
		);

		$redirect = $this->catchRedirect( array( RegistrationsScreen::class, 'handle_edit' ) );

		$this->assertNotNull( $redirect, 'Niepoprawna konfiguracja nie może zabić żądania.' );
		$this->assertStringContainsString( 'evreg_msg=config_error', $redirect->location );

		$this->assertSame(
			array( 'n1819' ),
			array_column( $this->repository->bookingNightsFor( array( $id ) )[ $id ], 'key' ),
			'Odmowa z powodu konfiguracji nie może ruszyć zapisanych dób.'
		);
	}

	public function test_detail_degrades_with_a_notice_on_a_broken_nights_config(): void {
		$id = $this->reserve( 'jan@example.com', array( 'n1819' ) );

		// Zduplikowany klucz doby ORAZ zapomniany, niespójny inwentarz klasyczny: ani
		// NightsConfig, ani AccommodationConfig nie dają się zbudować.
		$this->configure(
			array(
				array(
					'key'   => 'n1819',
					'label' => '18/19 XI',
					'price' => 80.0,
					'seats' => 4,
				),
				array(
					'key'   => 'n1819',
					'label' => 'Duplikat',
					'price' => 80.0,
					'seats' => 4,
				),
			),
			array(
				'packages'  => array(),
				'rooms'     => array(),
				'inventory' => array(
					array(
						'package'  => 'widmo',
						'room'     => 'widmo',
						'capacity' => 3,
						'price'    => 10.0,
					),
				),
			)
		);

		$html = $this->renderScreen( 'view', $id );

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'Szczegóły zgłoszenia', $html );
		$this->assertStringContainsString( 'jan@example.com', $html, 'Detal musi dać się odczytać mimo zepsutej konfiguracji.' );

		// Wiersze dób niosą WŁASNE snapshoty etykiety i ceny, więc nie potrzebują
		// konfiguracji — tabela ma wyjść poprawnie także nad zepsutym configiem.
		$this->assertStringContainsString( 'Nocleg (doby)', $html );
		$this->assertMatchesRegularExpression( '#<td>18/19 XI</td><td>1</td><td>80[.,]00</td>#', $html );

		// Bez wierszy nie ma skąd wziąć etykiet — zostają surowe klucze życzenia, nie pustka.
		$this->repository->deleteBookingNights( $id );
		$rowless = $this->renderScreen( 'view', $id );

		$this->assertStringNotContainsString( 'Nocleg (doby)', $rowless );
		$this->assertStringContainsString( 'n1819', $rowless );
	}

	public function test_detail_shows_the_wish_of_a_waitlisted_registration_without_rows(): void {
		$id = $this->reserve( 'jan@example.com', array( 'n1819' ) );
		$this->repository->deleteBookingNights( $id );

		$html = $this->renderScreen( 'view', $id );

		$this->assertStringContainsString( '18/19 XI', $html, 'Bez wierszy detal pokazuje życzenie z odpowiedzi.' );
	}

	/**
	 * Usuwa pole noclegu ze schematu, zostawiając tryb „doby” w konfiguracji EVENTU.
	 *
	 * Organizator ma do tego UI (budowniczy formularza), a zgłoszenia dalej trzymają
	 * wiersze dób — więc to konfiguracja osiągalna, nie hipotetyczna. Schemat nie niesie
	 * wtedy ŻADNEGO śladu trybu: ekran, który pytałby o tryb schemat, uznałby event
	 * za klasyczny i zgubił doby.
	 */
	private function dropAccommodationField(): void {
		$repo   = new EventConfigRepository();
		$config = $repo->get( $this->event_id );

		$config['schema']['sections'][0]['fields'] = array_values(
			array_filter(
				$config['schema']['sections'][0]['fields'],
				static fn ( array $field ): bool => 'accommodation' !== ( $field['type'] ?? '' )
			)
		);

		$repo->save( $this->event_id, $config );
	}

	public function test_detail_shows_the_rows_when_the_schema_has_no_accommodation_field(): void {
		$id = $this->reserve( 'jan@example.com', array( 'n1819' ) );
		$this->dropAccommodationField();

		$html = $this->renderScreen( 'view', $id );

		$this->assertStringContainsString( 'Nocleg (doby)', $html, 'Tryb jest własnością eventu — brak pola nie czyni go klasycznym.' );
		$this->assertMatchesRegularExpression( '#<td>18/19 XI</td><td>1</td><td>80[.,]00</td>#', $html );
	}

	public function test_edit_shows_the_rows_when_the_schema_has_no_accommodation_field(): void {
		$id = $this->reserve( 'jan@example.com', array( 'n1819' ) );
		$this->dropAccommodationField();

		$html = $this->renderScreen( 'edit', $id );

		$this->assertStringContainsString( 'name="action" value="evreg_edit_registration"', $html, 'Pozostałe odpowiedzi muszą dać się edytować.' );
		$this->assertStringNotContainsString( 'evreg_field[nocleg]', $html, 'Usuniętego pola nie ma czym renderować.' );
		$this->assertStringContainsString( 'Nocleg (doby)', $html, 'Doby, których formularz nie umie pokazać, muszą być widoczne obok niego.' );
		$this->assertMatchesRegularExpression( '#<td>18/19 XI</td><td>1</td><td>80[.,]00</td>#', $html );
	}

	public function test_edit_does_not_repeat_the_rows_when_the_form_renders_the_nights_itself(): void {
		$id = $this->reserve( 'jan@example.com', array( 'n1819' ) );

		$html = $this->renderScreen( 'edit', $id );

		$this->assertMatchesRegularExpression(
			'/name="evreg_field\[nocleg\]\[nights\]\[\]" value="n1819"[^>]*checked/',
			$html
		);
		$this->assertStringNotContainsString( 'Nocleg (doby)', $html, 'Kontrolka już je pokazuje — tabela byłaby szumem.' );
	}
}
