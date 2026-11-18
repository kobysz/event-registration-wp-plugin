<?php
/**
 * Widoki jednego zgłoszenia w panelu: ekran szczegółów i ekran edycji odpowiedzi.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Admin;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Domain\Accommodation\NightsConfig;
use EvReg\Domain\Export\RegistrationExportMapper;
use EvReg\Domain\Registration\RegistrationTypeCollection;
use EvReg\Domain\Schema\FieldType;
use EvReg\Domain\Schema\FormSchema;
use EvReg\Domain\Schema\SchemaException;
use EvReg\Frontend\EventFormLoader;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\RegistrationRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Widoki jednego zgłoszenia w panelu zgłoszeń.
 *
 * Renderuje dwa ekrany, na które routuje {@see RegistrationsScreen::render()}: szczegóły
 * (read-only, notatka, przyciski akcji) i edycję odpowiedzi (prefill + formularz). Sam
 * formularz edycji składa {@see RegistrationEditForm} — tu stoi strona wokół niego.
 *
 * Handlery `admin-post` (nonce → cap → akcja → PRG) zostają w {@see RegistrationsScreen};
 * ta klasa nic nie zapisuje. Dostaje repozytoria z zewnątrz, żeby dała się złożyć na
 * dowolnym zestawie danych i żeby nie była drugim źródłem prawdy o tym, co czytać.
 */
final class RegistrationView {

	/**
	 * Loader składający kompletną schemę eventu (i rozstrzygający tryb „doby”).
	 *
	 * @var EventFormLoader
	 */
	private readonly EventFormLoader $loader;

	/**
	 * Tworzy widok z wstrzykniętymi repozytoriami.
	 *
	 * @param RegistrationRepository $registrations Repozytorium zgłoszeń.
	 * @param EventConfigRepository  $config        Repozytorium konfiguracji eventu.
	 */
	public function __construct(
		private readonly RegistrationRepository $registrations,
		private readonly EventConfigRepository $config
	) {
		$this->loader = new EventFormLoader( $config );
	}

	/**
	 * Renderuje ekran szczegółów zgłoszenia (read-only + notatka).
	 *
	 * Wypisuje dane osobowe zgłaszającego się (imię, e-mail, notatkę, cenę) i NIE sprawdza
	 * uprawnień sama — wołający MUSI mieć sprawdzone `current_user_can( Capabilities::CAP )`
	 * (robi to router w `RegistrationsScreen::render()`).
	 *
	 * @param int $id ID zgłoszenia.
	 */
	public function render_detail( int $id ): void {
		$row  = $this->registrations->findById( $id );
		$back = add_query_arg(
			array(
				'post_type' => EventPostType::POST_TYPE,
				'page'      => RegistrationsScreen::SLUG,
			),
			admin_url( 'edit.php' )
		);

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Szczegóły zgłoszenia', 'event-registration' ) . '</h1>';
		printf( '<p><a href="%s">%s</a></p>', esc_url( $back ), esc_html__( '← wróć do listy', 'event-registration' ) );

		if ( null === $row ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Nie znaleziono zgłoszenia.', 'event-registration' ) . '</p></div></div>';
			return;
		}

		$event_id = (int) $row['event_id'];
		$schema   = $this->loader->load( $event_id );

		// Tryb „doby” rozstrzygamy RAZ, u wejścia ekranu, i wstrzykujemy niżej. Pytamy
		// o niego KONFIGURACJĘ EVENTU, nie schemat: event dobowy bez pola noclegu
		// w formularzu (organizator je usunął) nie ma w schemacie śladu trybu, a jego
		// zgłoszenia dalej trzymają wiersze dób. Niepoprawna konfiguracja degraduje się
		// do komunikatu — detal zgłoszenia musi dać się odczytać także wtedy, gdy
		// konfiguracja eventu jest zepsuta (schemat też się wtedy nie składa).
		$nights       = null;
		$config_error = null;

		try {
			$nights = $this->loader->nights( $event_id );
		} catch ( SchemaException $e ) {
			$config_error = $e->getMessage();
		}

		RegistrationNotice::render( $nights );

		if ( null !== $config_error ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: komunikat błędu konfiguracji. */
						__( 'Konfiguracja noclegów tego wydarzenia jest niepoprawna (%s) — nocleg nie jest pokazany.', 'event-registration' ),
						$config_error
					)
				)
			);
		}

		echo '<table class="widefat striped"><tbody>';
		$this->detail_row( __( 'Status', 'event-registration' ), RegistrationsListTable::status_label( (string) $row['status'] ) );
		$this->detail_row( __( 'Imię i nazwisko', 'event-registration' ), (string) $row['name'] );
		$this->detail_row( __( 'E-mail', 'event-registration' ), (string) $row['email'] );
		$this->detail_row( __( 'Typ', 'event-registration' ), $this->type_label( $event_id, (string) $row['type_key'] ) );
		$this->detail_row( __( 'Kwota', 'event-registration' ), (string) $row['price_total'] );
		$this->detail_row( __( 'Zgłoszono', 'event-registration' ), (string) $row['created_at'] );
		$this->detail_row( __( 'Potwierdzono', 'event-registration' ), (string) ( $row['confirmed_at'] ?? '' ) );
		$this->detail_row( __( 'Wygasa', 'event-registration' ), (string) ( $row['expires_at'] ?? '' ) );
		if ( ! empty( $row['companion'] ) ) {
			$this->detail_row( __( 'Osoba towarzysząca', 'event-registration' ), (string) ( $row['companion_name'] ?? '' ) );
		}
		// Kolumna wraca z bazy jako string ('0'/'1') — rzutowanie, nie empty() na surowym ciągu.
		if ( (bool) (int) ( $row['manual'] ?? 0 ) ) {
			$this->detail_row( __( 'Pochodzenie', 'event-registration' ), __( 'Wpis ręczny (dodany przez organizatora)', 'event-registration' ) );
		}
		echo '</tbody></table>';

		// Czy event jest w trybie „doby”, rozstrzyga się NIEZALEŻNIE od tego, czy konfiguracja
		// dała się odczytać: NightsConfig::fromArray rzuca wyłącznie dla mode === 'nights'
		// (poza tym trybem listy dób nie czyta wcale), więc nieudany odczyt też znaczy „doby”.
		$is_nights = null !== $nights || null !== $config_error;

		$booking = $is_nights ? null : $this->registrations->findAccommodationBooking( $id );
		// Wiersze dób to PRAWDA o tym, co zgłoszenie trzyma, i niosą WŁASNE snapshoty
		// etykiety i ceny — detal czyta je, nie dzisiejszą konfigurację (przemianowana albo
		// przeceniona doba nie przepisuje historii). Dlatego nie potrzebują konfiguracji
		// WCALE i renderują się także wtedy, gdy jest zepsuta: zepsuty config to żaden powód,
		// by schować tabelę, która i tak wyszłaby poprawnie. Jeden odczyt bulk, nie N+1.
		$night_rows = $is_nights ? ( $this->registrations->bookingNightsFor( array( $id ) )[ $id ] ?? array() ) : array();

		$this->render_answers( $schema, (string) $row['data'], $booking, $nights, $night_rows, $is_nights );
		$this->render_booking( $booking, $event_id );
		$this->render_night_rows( $night_rows );
		$this->render_note_form( $id, (string) ( $row['note'] ?? '' ) );
		$this->render_actions( $id, (string) $row['status'] );

		echo '</div>';
	}

	/**
	 * Renderuje formularz edycji odpowiedzi zgłoszenia (albo notice dla braku/anulowanego/niepoprawnego eventu).
	 *
	 * Prefilluje dane osobowe zgłaszającego się i NIE sprawdza uprawnień sama — wołający
	 * MUSI mieć sprawdzone `current_user_can( Capabilities::CAP )` (robi to router
	 * w `RegistrationsScreen::render()`).
	 *
	 * @param int $id ID zgłoszenia.
	 */
	public function render_edit( int $id ): void {
		$row = $this->registrations->findById( $id );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Edytuj zgłoszenie', 'event-registration' ) . '</h1>';

		if ( null === $row ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Nie znaleziono zgłoszenia.', 'event-registration' ) . '</p></div></div>';
			return;
		}

		if ( 'cancelled' === (string) $row['status'] ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Nie można edytować anulowanego zgłoszenia.', 'event-registration' ) . '</p></div></div>';
			return;
		}

		$event_id = (int) $row['event_id'];
		$schema   = $this->loader->load( $event_id );

		if ( null === $schema ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Nie udało się załadować formularza tego wydarzenia.', 'event-registration' ) . '</p></div></div>';
			return;
		}

		// Tryb „doby” rozstrzygamy RAZ, u wejścia ekranu, z KONFIGURACJI EVENTU (nie ze
		// schematu — ten nie niesie śladu trybu, gdy organizator usunął pole noclegu),
		// i wstrzykujemy do renderera: inaczej formularz wyrenderowałby kontrolkę klasyczną
		// dla eventu dobowego, a zapis skasowałby doby zgłoszenia. Niepoprawna konfiguracja
		// degraduje się do komunikatu: formularz, który nie umie oddać tego, co pokazuje,
		// lepiej się nie pokazywać wcale.
		try {
			$nights = $this->loader->nights( $event_id );
		} catch ( SchemaException $e ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div></div>',
				esc_html(
					sprintf(
						/* translators: %s: komunikat błędu konfiguracji. */
						__( 'Konfiguracja noclegów tego wydarzenia jest niepoprawna (%s) — popraw ją, zanim zmienisz odpowiedzi.', 'event-registration' ),
						$e->getMessage()
					)
				)
			);
			return;
		}

		RegistrationNotice::render( $nights );

		wp_enqueue_script( 'evreg-public' );

		$answers = json_decode( (string) $row['data'], true );
		$answers = is_array( $answers ) ? $answers : array();
		// Companion NIE jest w JSON odpowiedzi (osobne kolumny DB) — dokładamy pod tymi samymi
		// kluczami evreg_companion/evreg_companion_name, których RegistrationEditForm/form.js
		// (evreg-public) oczekują (spójnie z SubmissionAssembler przy re-renderze błędu).
		$answers['evreg_companion']      = (bool) ( $row['companion'] ?? false );
		$answers['evreg_companion_name'] = (string) ( $row['companion_name'] ?? '' );

		// Tryb „doby”: prefill z ZAPISANYCH WIERSZY, a gdy ich nie ma — z JSON-a odpowiedzi.
		// Wiersze są prawdą o tym, co zgłoszenie TRZYMA, i tam, gdzie są, biją JSON (ten
		// niesie życzenie i może się z nimi rozejść: awans bez przyznanego noclegu, odmowa
		// pojedynczej doby). Ale NIGDY nie nadpisujemy nimi życzenia na puste: zgłoszenie
		// z listy rezerwowej nie ma wierszy NIGDY (nie zajmuje miejsc), a jego wybór żyje
		// wyłącznie w JSON-ie. Bezwarunkowe nadpisanie odznaczyłoby wszystko, a zapis — bo
		// kontrolka domyka się w obie strony — skasowałby życzenie po cichu, meldując
		// „zapisano”, i późniejsza „Promuj” nie miałaby już czego przyznać. To ta sama
		// asymetria zapisu i odczytu co B15, o jeden ekran dalej: detal POKAZUJE życzenie
		// (patrz night_text()), więc edycja nie może go kasować. Rows first, JSON fallback,
		// nigdy clobber. Oba kształty czyta RegistrationEditForm::nightKeys().
		$accommodation_key = $schema->accommodationFieldKey();

		if ( null !== $nights && null !== $accommodation_key ) {
			$rows = $this->registrations->bookingNightsFor( array( $id ) )[ $id ] ?? array();

			if ( array() !== $rows ) {
				$answers[ $accommodation_key ] = array_column( $rows, 'key' );
			}

			// Podpowiedź renderu (NIE kontrolka): bez wierszy ptaszki są życzeniem, nie
			// zajętymi miejscami, i formularz ma to powiedzieć — tak jak detal.
			$answers['evreg_nights_granted'] = array() !== $rows;
		}

		// Event dobowy, któremu organizator usunął pole noclegu z formularza: kontrolki dób
		// nie ma czym wyrenderować (schemat ich nie niesie), a zgłoszenie dalej trzyma
		// wiersze. Pokazujemy je obok formularza — read-only, ze snapshotów, tak jak detal —
		// żeby operator nie edytował zgłoszenia na ślepo. Tam, gdzie kontrolka jest, tabela
		// byłaby szumem: ptaszki pokazują to samo i dają się zmienić.
		if ( null !== $nights && null === $accommodation_key ) {
			$this->render_night_rows( $this->registrations->bookingNightsFor( array( $id ) )[ $id ] ?? array() );
		}

		echo RegistrationEditForm::renderEdit( $schema, $answers, $id, null, null, $nights ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- już escapowane w rendererze.
		echo '</div>';
	}

	/**
	 * Renderuje ekran edycji od nowa, z błędami i wartościami z odrzuconego POST.
	 *
	 * Wołane przez {@see RegistrationsScreen::handle_edit()} INLINE, zamiast PRG: tylko tak
	 * organizator zachowuje to, co wpisał. Dlatego nie czyta zgłoszenia z bazy — schemat,
	 * błędy i wartości przychodzą z handlera, który je właśnie zbudował; prefill z bazy
	 * nadpisałby odrzucone odpowiedzi tymi zapisanymi.
	 *
	 * @param FormSchema           $schema    Złożony schemat formularza eventu.
	 * @param int                  $id        ID zgłoszenia.
	 * @param array<string,string> $errors    Błędy walidacji: klucz pola → kod błędu.
	 * @param array<string,mixed>  $submitted Wartości z odrzuconego POST (prefill).
	 * @param NightsConfig|null    $nights    Konfiguracja trybu „doby” rozstrzygnięta u wejścia
	 *                                        handlera; null = tryb klasyczny.
	 */
	public function render_edit_errors( FormSchema $schema, int $id, array $errors, array $submitted, ?NightsConfig $nights ): void {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Edytuj zgłoszenie', 'event-registration' ) . '</h1>';
		echo RegistrationEditForm::renderEdit( $schema, array(), $id, $errors, $submitted, $nights ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- już escapowane w rendererze.
		echo '</div>';
	}

	/**
	 * Renderuje ekran ręcznego dodania zgłoszenia: wybór wydarzenia albo pusty formularz.
	 *
	 * Bez wydarzenia (`0`) nie ma schematu, więc jedyne, co da się pokazać, to wybór — to
	 * ten sam ekran w stanie „jeszcze nie wybrano”, nie osobna strona. NIE sprawdza uprawnień
	 * sama — wołający MUSI mieć sprawdzone `current_user_can( Capabilities::CAP )`.
	 *
	 * @param int $event_id ID wydarzenia albo 0 (wybór wydarzenia).
	 */
	public function render_add( int $event_id ): void {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Dodaj zgłoszenie ręcznie', 'event-registration' ) . '</h1>';

		if ( $event_id <= 0 ) {
			$this->render_event_picker();
			echo '</div>';
			return;
		}

		$schema = $this->loader->load( $event_id );

		if ( null === $schema ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Nie udało się załadować formularza tego wydarzenia.', 'event-registration' ) . '</p></div></div>';
			return;
		}

		// Tryb „doby” rozstrzygany RAZ, u wejścia, jak w render_edit(): inaczej formularz
		// wyrenderowałby kontrolkę klasyczną dla eventu dobowego.
		try {
			$nights = $this->loader->nights( $event_id );
		} catch ( SchemaException $e ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div></div>',
				esc_html(
					sprintf(
						/* translators: %s: komunikat błędu konfiguracji. */
						__( 'Konfiguracja noclegów tego wydarzenia jest niepoprawna (%s) — popraw ją, zanim dodasz zgłoszenie.', 'event-registration' ),
						$e->getMessage()
					)
				)
			);
			return;
		}

		RegistrationNotice::render( $nights );

		wp_enqueue_script( 'evreg-public' );

		echo RegistrationEditForm::renderAdd( $schema, $event_id, null, null, $nights ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- już escapowane w rendererze.
		echo '</div>';
	}

	/**
	 * Renderuje ekran dodawania od nowa, z błędami i wartościami z odrzuconego POST.
	 *
	 * Wołane przez handler dodawania INLINE, zamiast PRG, żeby organizator zachował to, co
	 * wpisał. Nie czyta nic z bazy — schemat, błędy i wartości przychodzą z handlera.
	 *
	 * @param FormSchema           $schema    Złożony schemat formularza eventu.
	 * @param int                  $event_id  ID wydarzenia.
	 * @param array<string,string> $errors    Błędy walidacji: klucz pola → kod błędu.
	 * @param array<string,mixed>  $submitted Wartości z odrzuconego POST (prefill).
	 * @param NightsConfig|null    $nights    Konfiguracja trybu „doby” rozstrzygnięta u wejścia
	 *                                        handlera; null = tryb klasyczny.
	 */
	public function render_add_errors( FormSchema $schema, int $event_id, array $errors, array $submitted, ?NightsConfig $nights ): void {
		wp_enqueue_script( 'evreg-public' );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Dodaj zgłoszenie ręcznie', 'event-registration' ) . '</h1>';
		echo RegistrationEditForm::renderAdd( $schema, $event_id, $errors, $submitted, $nights ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- już escapowane w rendererze.
		echo '</div>';
	}

	/**
	 * Renderuje wybór wydarzenia (GET z powrotem na ten sam ekran, już z `event_id`).
	 */
	private function render_event_picker(): void {
		$events = get_posts(
			array(
				'post_type'   => EventPostType::POST_TYPE,
				'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'numberposts' => -1,
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);

		if ( array() === $events ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Nie ma jeszcze żadnego wydarzenia, do którego można dodać zgłoszenie.', 'event-registration' ) . '</p></div>';
			return;
		}

		echo '<p>' . esc_html__( 'Wybierz wydarzenie, do którego dodajesz zgłoszenie.', 'event-registration' ) . '</p>';
		echo '<form method="get" action="' . esc_url( admin_url( 'edit.php' ) ) . '">';
		printf( '<input type="hidden" name="post_type" value="%s" />', esc_attr( EventPostType::POST_TYPE ) );
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( RegistrationsScreen::SLUG ) );
		echo '<input type="hidden" name="action" value="add" />';
		echo '<p><label for="evreg-add-event">' . esc_html__( 'Wydarzenie', 'event-registration' ) . '</label> ';
		echo '<select id="evreg-add-event" name="event_id">';
		foreach ( $events as $event ) {
			$title = '' !== $event->post_title ? $event->post_title : sprintf( /* translators: %d: ID wydarzenia. */ __( '(bez tytułu, #%d)', 'event-registration' ), $event->ID );
			printf( '<option value="%d">%s</option>', (int) $event->ID, esc_html( $title ) );
		}
		echo '</select></p>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Dalej', 'event-registration' ) . '</button></p>';
		echo '</form>';
	}

	/**
	 * Zwraca etykietę typu z configu eventu, fallback: sam klucz.
	 *
	 * @param int    $event_id ID eventu.
	 * @param string $type_key Klucz typu.
	 */
	private function type_label( int $event_id, string $type_key ): string {
		if ( 0 === $event_id || '' === $type_key ) {
			return $type_key;
		}

		$config = $this->config->get( $event_id );
		$types  = RegistrationTypeCollection::fromArray( is_array( $config['types'] ) ? $config['types'] : array() );
		$type   = $types->get( $type_key );

		return null === $type ? $type_key : $type->label;
	}

	/**
	 * Renderuje odpowiedzi uczestnika ze złożonej schemy.
	 *
	 * @param FormSchema|null                                                 $schema     Złożony schemat formularza albo null.
	 * @param string                                                          $data       JSON odpowiedzi.
	 * @param array<string,mixed>|null                                        $booking    Rezerwacja noclegu (nośnik snapshotu etykiet) albo null.
	 * @param NightsConfig|null                                               $nights     Konfiguracja trybu „doby” albo null (klasyczna
	 *                                                                                    ALBO dobowa, której nie dało się odczytać).
	 * @param array<int,array{key:string,label:string,seats:int,price:float}> $night_rows Zapisane doby zgłoszenia.
	 * @param bool                                                            $is_nights  Czy event jest w trybie „doby” — także wtedy, gdy
	 *                                                                                    konfiguracja nie dała się odczytać ($nights === null,
	 *                                                                                    notice stoi wyżej). Rozstrzyga o gałęzi pola noclegu,
	 *                                                                                    więc gałąź klasyczna (jedyna, która buduje
	 *                                                                                    AccommodationConfig — ta rzuca na niespójnym
	 *                                                                                    inwentarzu) nie jest osiągalna dla eventu dobowego.
	 */
	private function render_answers( ?FormSchema $schema, string $data, ?array $booking = null, ?NightsConfig $nights = null, array $night_rows = array(), bool $is_nights = false ): void {
		if ( null === $schema ) {
			return;
		}

		$answers = json_decode( $data, true );
		$answers = is_array( $answers ) ? $answers : array();

		echo '<h2>' . esc_html__( 'Odpowiedzi', 'event-registration' ) . '</h2>';
		echo '<table class="widefat striped"><tbody>';

		foreach ( $schema->allFields() as $field ) {
			if ( ! $field->type->isInput() ) {
				continue;
			}

			$value = $answers[ $field->key ] ?? '';

			if ( FieldType::Accommodation === $field->type && $is_nights ) {
				// Tryb „doby”: etykiety ze SNAPSHOTU wierszy. Bez wierszy (zgłoszenie
				// z listy rezerwowej albo bez przyznanego noclegu) zostaje życzenie
				// z odpowiedzi — nazwane dzisiejszą konfiguracją, a gdy ta się nie
				// odczytała, surowymi kluczami. Bez fatala i bez pustki.
				$text = $this->night_text( $nights, $value, $night_rows );
			} elseif ( FieldType::Accommodation === $field->type && is_array( $value ) ) {
				// Surowa odpowiedź to klucze (package/room) — bez etykiet jest nieczytelna.
				// Rezerwacja niesie snapshot etykiet, więc jest lepszym źródłem niż same klucze.
				$stored = AccommodationSelection::normalizeArray( $value );
				$text   = $this->accommodation_text(
					AccommodationConfig::fromArray( $field->config ),
					$booking ?? array(
						'package_key'   => $stored['package'],
						'room_type_key' => $stored['room'],
						'roommate_pref' => $stored['roommate'],
					)
				);
			} else {
				$text = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
			}

			$this->detail_row( $field->label, $text );
		}

		echo '</tbody></table>';
	}

	/**
	 * Renderuje rezerwację noclegową, jeśli istnieje.
	 *
	 * @param array<string,mixed>|null $booking  Wiersz bookingu albo null.
	 * @param int                      $event_id ID eventu (źródło etykiet pakietu/pokoju).
	 */
	private function render_booking( ?array $booking, int $event_id ): void {
		if ( null === $booking ) {
			return;
		}

		$config = $this->config->get( $event_id );
		$cells  = ( new RegistrationExportMapper() )->accommodationCells(
			$booking,
			AccommodationConfig::fromArray( is_array( $config['accommodation'] ) ? $config['accommodation'] : array() )
		);

		echo '<h2>' . esc_html__( 'Nocleg', 'event-registration' ) . '</h2>';
		echo '<table class="widefat striped"><tbody>';
		$this->detail_row( __( 'Pakiet', 'event-registration' ), $cells['package'] );
		$this->detail_row( __( 'Pokój', 'event-registration' ), $cells['room'] );
		$this->detail_row( __( 'Współlokator', 'event-registration' ), $cells['roommate'] );
		echo '</tbody></table>';
	}

	/**
	 * Składa czytelny opis wybranych dób.
	 *
	 * Źródłem pierwszym są ZAPISANE wiersze — niosą snapshot etykiety z chwili rezerwacji,
	 * więc przemianowana doba nie przepisuje historii. Brak wierszy znaczy „nocleg nie jest
	 * przyznany" (lista rezerwowa, odmowa przy awansie): wtedy pokazujemy życzenie
	 * z odpowiedzi, nazwane dzisiejszą konfiguracją, bo innego źródła etykiet nie ma.
	 *
	 * @param NightsConfig|null                                               $config Konfiguracja trybu „doby” albo null, gdy się nie
	 *                                                                                odczytała (wtedy życzenie idzie surowymi kluczami —
	 *                                                                                wiersze i tak niosą własne etykiety).
	 * @param mixed                                                           $value  Odpowiedź pola (płaska lista kluczy dób).
	 * @param array<int,array{key:string,label:string,seats:int,price:float}> $rows   Zapisane doby zgłoszenia.
	 */
	private function night_text( ?NightsConfig $config, $value, array $rows ): string {
		if ( array() !== $rows ) {
			return implode( ', ', array_column( $rows, 'label' ) );
		}

		$keys = is_array( $value ) ? array_filter( $value, 'is_scalar' ) : array();

		if ( array() === $keys ) {
			return '';
		}

		$labels = array();
		foreach ( $keys as $key ) {
			$labels[] = null === $config ? (string) $key : $config->nightLabel( (string) $key );
		}

		/* translators: %s: lista etykiet dób. */
		return sprintf( __( '%s (nocleg nieprzyznany)', 'event-registration' ), implode( ', ', $labels ) );
	}

	/**
	 * Renderuje tabelę zapisanych dób zgłoszenia (etykieta, miejsca, cena ze snapshotu).
	 *
	 * @param array<int,array{key:string,label:string,seats:int,price:float}> $rows Zapisane doby.
	 */
	private function render_night_rows( array $rows ): void {
		if ( array() === $rows ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Nocleg (doby)', 'event-registration' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		printf( '<th scope="col">%s</th>', esc_html__( 'Doba', 'event-registration' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Miejsca', 'event-registration' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Kwota', 'event-registration' ) );
		echo '</tr></thead><tbody>';

		$total = 0.0;
		foreach ( $rows as $row ) {
			$total += $row['price'];

			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $row['label'] ),
				esc_html( number_format_i18n( $row['seats'] ) ),
				esc_html( number_format_i18n( $row['price'], 2 ) )
			);
		}

		printf(
			'<tr><td><strong>%s</strong></td><td></td><td><strong>%s</strong></td></tr>',
			esc_html__( 'Razem za noclegi', 'event-registration' ),
			esc_html( number_format_i18n( $total, 2 ) )
		);

		echo '</tbody></table>';
	}

	/**
	 * Składa czytelny opis noclegu: „Pakiet — Pokój" plus współlokator, gdy podany.
	 * Nieznany klucz zostaje pokazany surowy (lepsze niż pustka), tak jak w eksporcie.
	 *
	 * @param AccommodationConfig $config  Konfiguracja noclegów eventu.
	 * @param array<string,mixed> $booking Wiersz rezerwacji albo jego odpowiednik z odpowiedzi.
	 */
	private function accommodation_text( AccommodationConfig $config, array $booking ): string {
		$cells = ( new RegistrationExportMapper() )->accommodationCells( $booking, $config );

		$label = trim( $cells['package'] . ' — ' . $cells['room'], " \t\n\r\0\x0B—" );

		if ( '' === $cells['roommate'] ) {
			return $label;
		}

		/* translators: 1: opis noclegu, 2: preferowany współlokator. */
		return sprintf( __( '%1$s (współlokator: %2$s)', 'event-registration' ), $label, $cells['roommate'] );
	}

	/**
	 * Renderuje formularz notatki.
	 *
	 * @param int    $id   ID zgłoszenia.
	 * @param string $note Bieżąca notatka.
	 */
	private function render_note_form( int $id, string $note ): void {
		echo '<h2>' . esc_html__( 'Notatka organizatora', 'event-registration' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( RegistrationsScreen::ACTION_NOTE ) );
		printf( '<input type="hidden" name="id" value="%d" />', $id );
		wp_nonce_field( RegistrationsScreen::ACTION_NOTE . '_' . $id );
		printf( '<textarea name="note" rows="4" class="large-text">%s</textarea>', esc_textarea( $note ) );
		echo '<p>';
		submit_button( __( 'Zapisz notatkę', 'event-registration' ), 'secondary', 'submit', false );
		echo '</p></form>';
	}

	/**
	 * Renderuje przyciski akcji cyklu życia wg statusu.
	 *
	 * @param int    $id     ID zgłoszenia.
	 * @param string $status Status zgłoszenia.
	 */
	private function render_actions( int $id, string $status ): void {
		$buttons = array();

		if ( 'pending' === $status ) {
			$buttons[] = $this->action_button( RegistrationsScreen::ACTION_CONFIRM, $id, __( 'Potwierdź', 'event-registration' ), 'primary' );
		}
		if ( 'waitlist' === $status ) {
			$buttons[] = $this->action_button( RegistrationsScreen::ACTION_PROMOTE, $id, __( 'Promuj', 'event-registration' ), 'primary' );
		}
		if ( in_array( $status, array( 'pending', 'confirmed', 'waitlist' ), true ) ) {
			$buttons[] = $this->edit_link( $id );
			$buttons[] = $this->action_button( RegistrationsScreen::ACTION_CANCEL, $id, __( 'Anuluj', 'event-registration' ), 'secondary' );
		}
		if ( 'cancelled' === $status ) {
			$buttons[] = $this->action_button( RegistrationsScreen::ACTION_DELETE, $id, __( 'Usuń trwale', 'event-registration' ), 'delete' );
		}

		if ( array() === $buttons ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Akcje', 'event-registration' ) . '</h2><p>' . implode( ' ', $buttons ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- przyciski już escapowane w action_button.
	}

	/**
	 * Buduje przycisk akcji (link z nonce).
	 *
	 * @param string $action  Nazwa akcji.
	 * @param int    $id      ID zgłoszenia.
	 * @param string $label   Etykieta.
	 * @param string $variant Wariant klasy (primary|secondary|delete).
	 */
	private function action_button( string $action, int $id, string $label, string $variant ): string {
		$class = 'delete' === $variant ? 'button button-link-delete' : ( 'primary' === $variant ? 'button button-primary' : 'button' );

		return sprintf(
			'<a class="%s" href="%s">%s</a>',
			esc_attr( $class ),
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . $action . '&id=' . $id ), $action . '_' . $id ) ),
			esc_html( $label )
		);
	}

	/**
	 * Buduje link „Edytuj" do ekranu edycji odpowiedzi (bez akcji admin-post — sama nawigacja).
	 *
	 * @param int $id ID zgłoszenia.
	 */
	private function edit_link( int $id ): string {
		$url = add_query_arg(
			array(
				'post_type' => EventPostType::POST_TYPE,
				'page'      => RegistrationsScreen::SLUG,
				'action'    => 'edit',
				'id'        => $id,
			),
			admin_url( 'edit.php' )
		);

		return sprintf( '<a class="button" href="%s">%s</a>', esc_url( $url ), esc_html__( 'Edytuj', 'event-registration' ) );
	}

	/**
	 * Renderuje jeden wiersz tabeli szczegółów.
	 *
	 * @param string $label Etykieta.
	 * @param string $value Wartość (escapowana).
	 */
	private function detail_row( string $label, string $value ): void {
		printf( '<tr><th scope="row" style="width:180px;">%s</th><td>%s</td></tr>', esc_html( $label ), esc_html( $value ) );
	}
}
