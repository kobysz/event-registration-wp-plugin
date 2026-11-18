<?php
/**
 * Ekran admina panelu zgłoszeń: submenu, lista, handlery akcji cyklu życia i eksport.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Admin;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Accommodation\NightsConfig;
use EvReg\Domain\Registration\ManualEntry;
use EvReg\Domain\Registration\RegistrationTypeCollection;
use EvReg\Domain\Schema\FormSchema;
use EvReg\Domain\Schema\SchemaException;
use EvReg\Frontend\EventFormLoader;
use EvReg\Frontend\NightsResolver;
use EvReg\Frontend\SubmissionAssembler;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Services\ReservationService;

defined( 'ABSPATH' ) || exit;

/**
 * Ekran admina panelu zgłoszeń.
 *
 * Menu, routing i lista zgłoszeń plus handlery `admin-post` akcji cyklu życia
 * (nonce → cap → akcja → PRG) i eksport CSV. Ekrany jednego zgłoszenia — szczegóły
 * i edycja odpowiedzi — renderuje {@see RegistrationView}.
 */
final class RegistrationsScreen {

	public const SLUG           = 'evreg-registrations';
	public const ACTION_CONFIRM = 'evreg_reg_confirm';
	public const ACTION_CANCEL  = 'evreg_reg_cancel';
	public const ACTION_PROMOTE = 'evreg_reg_promote';
	public const ACTION_DELETE  = 'evreg_reg_delete';
	public const ACTION_NOTE    = 'evreg_reg_note';
	public const ACTION_EDIT    = 'evreg_edit_registration';
	public const ACTION_ADD     = 'evreg_add_registration';
	public const ACTION_EXPORT  = 'evreg_export';

	/**
	 * Wartość `action` w adresie GET, która routuje na ekran ręcznego dodawania.
	 *
	 * Osobna od ACTION_ADD: tamta nazywa akcję `admin-post` (zapis), ta — widok. Stała
	 * istnieje, żeby wybór wydarzenia ({@see RegistrationView}), przycisk na liście
	 * i gałąź routera nie mogły się rozejść.
	 */
	public const ROUTE_ADD = 'add';

	/**
	 * Podpina submenu i handlery akcji.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_post_' . self::ACTION_CONFIRM, array( self::class, 'handle_confirm' ) );
		add_action( 'admin_post_' . self::ACTION_CANCEL, array( self::class, 'handle_cancel' ) );
		add_action( 'admin_post_' . self::ACTION_PROMOTE, array( self::class, 'handle_promote' ) );
		add_action( 'admin_post_' . self::ACTION_DELETE, array( self::class, 'handle_delete' ) );
		add_action( 'admin_post_' . self::ACTION_NOTE, array( self::class, 'handle_note' ) );
		add_action( 'admin_post_' . self::ACTION_EDIT, array( self::class, 'handle_edit' ) );
		add_action( 'admin_post_' . self::ACTION_ADD, array( self::class, 'handle_add' ) );
		add_action( 'admin_post_' . self::ACTION_EXPORT, array( self::class, 'handle_export' ) );
	}

	/**
	 * Rejestruje submenu pod menu CPT wydarzeń.
	 */
	public static function add_menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . EventPostType::POST_TYPE,
			__( 'Zgłoszenia', 'event-registration' ),
			__( 'Zgłoszenia', 'event-registration' ),
			Capabilities::CAP,
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * Renderuje listę albo — wg `action` — szczegóły, edycję albo ręczne dodanie zgłoszenia.
	 */
	public static function render(): void {
		if ( ! current_user_can( Capabilities::CAP ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'event-registration' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing widoku.
		$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['action'] ) ) : '';

		if ( 'view' === $action ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::view()->render_detail( (int) ( $_GET['id'] ?? 0 ) );
			return;
		}

		if ( 'edit' === $action ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::view()->render_edit( (int) ( $_GET['id'] ?? 0 ) );
			return;
		}

		// Ekran dodawania bez wydarzenia (0) renderuje wybór wydarzenia, nie formularz —
		// to ten sam ekran w stanie „jeszcze nie wybrano”.
		if ( self::ROUTE_ADD === $action ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::view()->render_add( (int) ( $_GET['event_id'] ?? 0 ) );
			return;
		}

		self::render_list();
	}

	/**
	 * Renderuje tabelę listy z filtrami i komunikatem akcji.
	 */
	private static function render_list(): void {
		$table = new RegistrationsListTable();
		$table->prepare_items();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Zgłoszenia', 'event-registration' ) . '</h1>';

		RegistrationNotice::render();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- odczyt bieżącego filtra listy, widok read-only.
		RegistrationsOverview::render( isset( $_GET['event_id'] ) ? (int) $_GET['event_id'] : 0 );
		self::render_add_button();
		self::render_export_button();

		echo '<form method="get">';
		printf( '<input type="hidden" name="post_type" value="%s" />', esc_attr( EventPostType::POST_TYPE ) );
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( self::SLUG ) );
		$table->display();
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Renderuje link „Dodaj zgłoszenie" niosący bieżący filtr wydarzenia.
	 *
	 * Bez nonce: to zwykłe przejście na widok (zapis ma własny nonce w formularzu). Bez
	 * filtra wydarzenia link prowadzi na ekran z wyborem wydarzenia, więc organizator,
	 * który listę ma nieprzefiltrowaną, i tak nie trafia w ślepą uliczkę.
	 */
	private static function render_add_button(): void {
		$args = array(
			'post_type' => EventPostType::POST_TYPE,
			'page'      => self::SLUG,
			'action'    => self::ROUTE_ADD,
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- odczyt bieżącego filtra listy do linku widoku.
		$event_id = isset( $_GET['event_id'] ) ? (int) $_GET['event_id'] : 0;
		if ( $event_id > 0 ) {
			$args['event_id'] = $event_id;
		}

		printf(
			'<p><a class="button" href="%s">%s</a></p>',
			esc_url( add_query_arg( $args, admin_url( 'edit.php' ) ) ),
			esc_html__( 'Dodaj zgłoszenie', 'event-registration' )
		);
	}

	/**
	 * Renderuje link „Eksportuj CSV" niosący bieżące filtry listy (status/typ/event).
	 * Serwer i tak re-waliduje filtry w handlerze — link jest tylko wygodą.
	 */
	private static function render_export_button(): void {
		$export_args = array( 'action' => self::ACTION_EXPORT );
		foreach ( array( 'status', 'type_key', 'event_id' ) as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tylko odczyt bieżących filtrów do linku, re-walidowane w handlerze.
			if ( isset( $_GET[ $key ] ) && '' !== (string) $_GET[ $key ] ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$export_args[ $key ] = sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) );
			}
		}

		$export_url = wp_nonce_url( add_query_arg( $export_args, admin_url( 'admin-post.php' ) ), self::ACTION_EXPORT );

		printf(
			'<p><a class="button" href="%s">%s</a></p>',
			esc_url( $export_url ),
			esc_html__( 'Eksportuj CSV', 'event-registration' )
		);
	}

	/**
	 * Wspólny guard nonce+cap dla akcji, zwraca ID zgłoszenia.
	 *
	 * @param string $action Nazwa akcji (do nonce).
	 */
	private static function guard( string $action ): int {
		$id = (int) ( $_POST['id'] ?? $_GET['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		check_admin_referer( $action . '_' . $id );

		if ( ! current_user_can( Capabilities::CAP ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'event-registration' ) );
		}

		return $id;
	}

	/**
	 * Składa widok jednego zgłoszenia (detal, edycja, re-render błędu edycji).
	 */
	private static function view(): RegistrationView {
		return new RegistrationView( new RegistrationRepository(), new EventConfigRepository() );
	}

	/**
	 * Składa ReservationService.
	 */
	private static function service(): ReservationService {
		return new ReservationService( new RegistrationRepository(), new EventConfigRepository() );
	}

	/**
	 * Przekierowuje na listę (albo, z $extra, na inny widok) z kodem komunikatu (PRG).
	 *
	 * @param string              $code  Kod wyniku.
	 * @param array<string,mixed> $extra Dodatkowe argumenty query (np. widok szczegółów).
	 */
	private static function redirect( string $code, array $extra = array() ): void {
		wp_safe_redirect(
			add_query_arg(
				array_merge(
					array(
						'post_type' => EventPostType::POST_TYPE,
						'page'      => self::SLUG,
						'evreg_msg' => $code,
					),
					$extra
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/** Handler potwierdzenia. */
	public static function handle_confirm(): void {
		$id = self::guard( self::ACTION_CONFIRM );
		self::redirect( self::service()->confirmManually( $id )->code );
	}

	/** Handler anulowania. */
	public static function handle_cancel(): void {
		$id = self::guard( self::ACTION_CANCEL );
		self::redirect( self::service()->cancel( $id )->code );
	}

	/**
	 * Handler promocji z waitlisty. `promoteFromWaitlist` czyta konfigurację noclegów
	 * zapisaną przy eventcie i rzuca, gdy jest niepoprawna — degradujemy do komunikatu
	 * zamiast fatala (handler nie ma czego re-renderować, więc PRG jak przy innych kodach).
	 *
	 * Wpis ręczny dostaje WŁASNY kod wyniku: jego awans kończy się potwierdzeniem i nie
	 * wysyła nic (patrz `ReservationService::announcePromotionAfterCommit`), więc zdanie
	 * o wysłanej prośbie o potwierdzenie byłoby nieprawdą. Serwis zwraca dla obu pochodzeń
	 * ten sam kod `promoted` — rozróżnia je dopiero ta powierzchnia, po trwałym znaczniku
	 * z wiersza. Kod `promoted_no_accommodation` zostaje wspólny: o mailu nie mówi.
	 */
	public static function handle_promote(): void {
		$id = self::guard( self::ACTION_PROMOTE );

		// Znacznik czytamy PRZED awansem: po nim wiersza może już nie być w stanie, o który
		// pytamy, a sam odczyt niczego nie blokuje (awans bierze własny lock i transakcję).
		$row    = ( new RegistrationRepository() )->findById( $id );
		$manual = null !== $row && ! empty( $row['manual'] );

		try {
			$code = self::service()->promoteFromWaitlist( $id )->code;
		} catch ( SchemaException ) {
			$code = 'config_error';
		}

		if ( $manual && 'promoted' === $code ) {
			$code = 'promoted_manual';
		}

		self::redirect( $code );
	}

	/** Handler trwałego usunięcia. */
	public static function handle_delete(): void {
		$id = self::guard( self::ACTION_DELETE );
		self::redirect( self::service()->deleteRegistration( $id )->code );
	}

	/** Handler zapisu notatki. */
	public static function handle_note(): void {
		$id   = self::guard( self::ACTION_NOTE );
		$note = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['note'] ) ) : '';

		( new RegistrationRepository() )->updateNote( $id, $note );

		self::redirect(
			'note',
			array(
				'action' => 'view',
				'id'     => $id,
			)
		);
	}

	/**
	 * Handler edycji odpowiedzi zgłoszenia. Nonce własny (formularz z {@see RegistrationEditForm}),
	 * nie `guard()` — inny schemat nonce (`evreg_edit_<id>`) i pole `registration` zamiast `id`.
	 * Przy błędach walidacji re-renderuje formularz inline (bez PRG), by zachować wpisane wartości.
	 */
	public static function handle_edit(): void {
		$id = isset( $_POST['registration'] ) ? (int) $_POST['registration'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce zweryfikowany niżej.

		check_admin_referer( 'evreg_edit_' . $id );

		if ( ! current_user_can( Capabilities::CAP ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'event-registration' ) );
		}

		$repository = new RegistrationRepository();
		$row        = $repository->findById( $id );

		if ( null === $row ) {
			self::redirect( 'not_found' );
			return;
		}

		$event_id = (int) $row['event_id'];
		$loader   = new EventFormLoader( new EventConfigRepository() );
		$schema   = $loader->load( $event_id );

		if ( null === $schema ) {
			self::redirect( 'not_found' );
			return;
		}

		// Tryb „doby” rozstrzygamy RAZ, tu, u wejścia (jak SubmitHandler na ścieżce publicznej),
		// z KONFIGURACJI EVENTU: bez tego asembler wyciągnąłby odpowiedzi w kształcie klasycznym,
		// a walidator — który tryb czyta sam — zablokowałby zapis na `required`. Niepoprawna
		// konfiguracja nie może dotrzeć ani do asemblera, ani do walidatora, ani do serwisu.
		try {
			$nights = $loader->nights( $event_id );
		} catch ( SchemaException ) {
			self::redirect( 'config_error' );
			return;
		}

		$posted    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce zweryfikowany wyżej.
		$assembled = ( new SubmissionAssembler() )->assemble( $schema, is_array( $posted ) ? $posted : array(), $nights );

		if ( ! $assembled->isValid() ) {
			self::view()->render_edit_errors( $schema, $id, $assembled->errors(), $assembled->values(), $nights );
			return;
		}

		$request = $assembled->request();

		if ( null === $request ) {
			self::redirect( 'not_found' );
			return;
		}

		// Reguła spójności pary obowiązuje w adminie TYLKO gdy edycja faktycznie zmienia
		// nocleg albo flagę towarzyszącej. Bez tego oznaczenie pokoju jako dwuosobowego
		// zablokowałoby każdy zapis starych zgłoszeń w tym pokoju — admin nie poprawiłby
		// w nich numeru telefonu bez rozstrzygania osoby towarzyszącej.
		//
		// Cała reguła dotyczy POKOJU, którego tryb „doby” nie ma — tam miejsca liczy doba,
		// a liczbę miejsc rozstrzyga sama flaga towarzyszącej (bramka w editAnswersNights).
		// Dlatego gałąź dobowa nie wchodzi tu wcale i nie buduje AccommodationConfig
		// (rzuca na niespójnym inwentarzu, a event dobowy może nieść zapomniane dane klasyczne).
		if ( null === $nights ) {
			$booking       = $repository->findAccommodationBooking( $id );
			$previous_slot = null === $booking
				? ''
				: (string) $booking['package_key'] . '|' . (string) $booking['room_type_key'];

			// O POKOJU, który ta edycja daje zgłoszeniu, rozstrzyga ten sam skrót, którym idzie
			// editAnswers: wybór PODANY wygrywa, a edycja, która odpowiedzi na pole noclegu nie
			// podała (warunek widoczności ukrył pole), zostaje przy wyborze ZAPISANYM. Dopiero
			// po B21 te dwa fakty się rozchodzą — wcześniej taka edycja nocleg niszczyła, więc
			// sprzeczności nie było jak zbudować. Sądzenie reguły po samym `$request->selection`
			// czytałoby ukryte pole jako „bez noclegu" i puszczało parę, którą reguła istnieje
			// żeby odrzucić: dwie osoby w pokoju jednoosobowym (jedno miejsce, rachunek ×1) albo
			// jedną w dwuosobowym (dwa łóżka, rachunek ×2) — po cichu, pod „zaktualizowano".
			$supplied      = $request->accommodationAnswered( nights_mode: false );
			$previous_room = null === $booking ? null : (string) $booking['room_type_key'];
			$new_room      = $supplied
				? ( null === $request->selection ? null : $request->selection->roomKey )
				: $previous_room;
			$new_slot      = $supplied
				? ( null === $request->selection ? '' : $request->selection->slotKey() )
				: $previous_slot;
			$changed       = $previous_slot !== $new_slot || (bool) ( $row['companion'] ?? false ) !== $request->companion;

			if ( $changed ) {
				$mismatch = self::companionRoomMismatch( $schema, $new_room, $request->companion );

				if ( null !== $mismatch ) {
					$key    = $schema->accommodationFieldKey();
					$errors = null === $key ? array() : array( $key => $mismatch );

					self::view()->render_edit_errors( $schema, $id, $errors, $assembled->rawAnswers(), $nights );
					return;
				}
			}
		}

		try {
			$result = self::service()->editAnswers( $id, $request );
		} catch ( SchemaException ) {
			self::redirect( 'config_error' );
			return;
		}

		switch ( $result->code ) {
			case 'edited':
				self::redirect(
					'edited',
					array(
						'action' => 'view',
						'id'     => $id,
					)
				);
				break;
			case 'accommodation_full':
			case 'accommodation_gone':
				// Odmowa niesie KLUCZ doby — przenosimy go przez PRG, żeby ekran edycji
				// nazwał ją etykietą (doba pełna) albo samym kluczem (doba usunięta
				// z konfiguracji, która etykiety już nie ma) — patrz RegistrationNotice::render().
				// Na ścieżce klasycznej reason jest null i komunikat zostaje dzisiejszy.
				$extra = array(
					'action' => 'edit',
					'id'     => $id,
				);

				if ( null !== $result->reason && '' !== $result->reason ) {
					$extra['evreg_night'] = $result->reason;
				}

				self::redirect( $result->code, $extra );
				break;
			case 'capacity_full':
				self::redirect(
					$result->code,
					array(
						'action' => 'edit',
						'id'     => $id,
					)
				);
				break;
			default: // invalid_status / not_found.
				self::redirect( $result->code );
		}
	}

	/**
	 * Handler ręcznego dodania zgłoszenia przez organizatora (wpis z rozmowy telefonicznej).
	 *
	 * Nonce własny (formularz z {@see RegistrationEditForm::renderAdd}), o zakresie
	 * WYDARZENIA, nie zgłoszenia — dodawanego zgłoszenia jeszcze nie ma, więc nie ma czym
	 * nonce zakresować. Kolejność straży jest wiążąca: nonce → uprawnienie → wydarzenie →
	 * schemat → tryb noclegu → walidacja → typ → przełącznik wysyłki → spójność pary
	 * pokój/osoba towarzysząca → rezerwacja.
	 *
	 * Każdy błąd, który organizator może poprawić na formularzu, wraca INLINE (bez PRG) —
	 * PRG zgubiłby wszystko, co wpisał, a tu nie ma zapisanego zgłoszenia, z którego
	 * ekran odtworzyłby odpowiedzi.
	 *
	 * @throws \RuntimeException Gdy rezerwacja na ścieżce KLASYCZNEJ rzuci wyjątek zapisu;
	 *                           ścieżka dobowa degraduje go do komunikatu (patrz niżej).
	 */
	public static function handle_add(): void {
		$event_id = isset( $_POST['event_id'] ) ? (int) $_POST['event_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce zweryfikowany niżej.

		check_admin_referer( 'evreg_add_' . $event_id );

		if ( ! current_user_can( Capabilities::CAP ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'event-registration' ) );
		}

		// Nonce zakresu wydarzenia dowodzi, że formularz wyszedł z tego ekranu dla TEJ
		// liczby — nie tego, że liczba wskazuje na wydarzenie. Post innego typu (albo
		// usunięty) dałby niżej schemat null i komunikat o konfiguracji, choć problemem
		// jest samo wydarzenie.
		if ( $event_id <= 0 || EventPostType::POST_TYPE !== get_post_type( $event_id ) ) {
			self::redirect( 'add_no_event' );
			return;
		}

		$loader = new EventFormLoader( new EventConfigRepository() );
		$schema = $loader->load( $event_id );

		if ( null === $schema ) {
			self::redirect( 'config_error' );
			return;
		}

		// Tryb „doby” rozstrzygamy RAZ, u wejścia, jak handle_edit i SubmitHandler:
		// niepoprawna konfiguracja nie może dotrzeć do asemblera, walidatora ani serwisu.
		try {
			$nights = $loader->nights( $event_id );
		} catch ( SchemaException ) {
			self::redirect( 'config_error' );
			return;
		}

		$raw    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce zweryfikowany wyżej.
		$posted = is_array( $raw ) ? $raw : array();
		$notify = ! empty( $posted['evreg_notify'] );

		$assembler = new SubmissionAssembler();

		// E-mail zwolniony z `required`: organizator przepisuje rozmowę telefoniczną, a
		// rozmówca — często osoba starsza — adresu zwykle nie ma. Poprawność PODANEGO
		// adresu jest dalej sprawdzana, zwolnienie dotyczy wyłącznie obowiązku podania.
		$assembled = $assembler->assemble( $schema, $posted, $nights, true );

		if ( ! $assembled->isValid() ) {
			self::rerender_add( $schema, $event_id, $assembled->errors(), $assembled->values(), $nights, $notify );
			return;
		}

		$request = $assembled->request();

		if ( null === $request ) {
			self::redirect( 'config_error' );
			return;
		}

		// Typ zgłoszenia niesie limit miejsc i cenę, więc wiersz bez niego nie ma
		// ani jednego, ani drugiej. Walidacja go nie złapie, gdy autor formularza nie
		// oznaczył pola typu jako wymaganego, a serwis przyjąłby taki wpis milcząco.
		$config = ( new EventConfigRepository() )->get( $event_id );
		$types  = RegistrationTypeCollection::fromArray( is_array( $config['types'] ) ? $config['types'] : array() );

		if ( '' === $request->typeKey || ! $types->has( $request->typeKey ) ) {
			self::rerender_add_field(
				$schema,
				$event_id,
				FormSchema::TYPE_FIELD_KEY,
				'' === $request->typeKey ? 'required' : 'not_in_options',
				$assembled->rawAnswers(),
				$nights,
				$notify
			);
			return;
		}

		// Przełącznik wysyłki obiecuje maila, a pusty adres znaczy obietnicę, której nic
		// nie spełni. Odmawiamy zapisu, zamiast zapisać po cichu bez maila: organizator
		// zaznaczył wysyłkę, bo liczy na to, że uczestnik potwierdzenie dostanie.
		if ( $notify && '' === $request->email ) {
			$email_key = $assembler->emailFieldKey( $schema );

			if ( '' === $email_key ) {
				// Formularz tego wydarzenia nie ma pola e-mail, więc adresu nie ma gdzie
				// podać — to nie jest błąd pola i nie ma czego re-renderować z błędem.
				self::redirect( 'add_notify_no_email' );
				return;
			}

			self::rerender_add_field( $schema, $event_id, $email_key, 'required', $assembled->rawAnswers(), $nights, $notify );
			return;
		}

		// Reguła spójności pary pokój/osoba towarzysząca obowiązuje tu BEZWARUNKOWO, inaczej
		// niż w handle_edit: tam warunek chroni zgłoszenia zastane w pokoju oznaczonym jako
		// dwuosobowy PO fakcie, a nowy wpis nie ma czego zastać — każde pole jest świeżym
		// wyborem organizatora, więc sprzeczność jest jego pomyłką do poprawienia teraz.
		//
		// Tryb „doby” nie wchodzi: reguła dotyczy POKOJU, którego ten tryb nie ma, a
		// AccommodationConfig::fromArray rzuca na zalegającym inwentarzu klasycznym.
		if ( null === $nights ) {
			$mismatch = self::companionRoomMismatch(
				$schema,
				null === $request->selection ? null : $request->selection->roomKey,
				$request->companion
			);

			if ( null !== $mismatch ) {
				self::rerender_add_field( $schema, $event_id, $schema->accommodationFieldKey(), $mismatch, $assembled->rawAnswers(), $nights, $notify );
				return;
			}
		}

		// Czytamy PRZED zapisem: po nim aktywnym zgłoszeniem na ten adres jest już to,
		// które właśnie powstało, więc KAŻDY wpis wyglądałby na duplikat. Sam duplikat
		// nie blokuje — rodzina na jednej skrzynce to normalny przypadek, a organizator
		// ma tylko wiedzieć, że drugie zgłoszenie na ten adres nie jest pomyłką.
		$duplicate = '' !== $request->email
			&& ( new RegistrationRepository() )->activeRegistrationExists( $event_id, $request->email );

		try {
			$result = self::service()->reserve( $event_id, $request, new ManualEntry( $notify ) );
		} catch ( SchemaException ) {
			self::redirect( 'config_error' );
			return;
		} catch ( \RuntimeException $e ) {
			// Tryb „doby”: serwis rzuca, gdy liczba zapisanych wierszy dób nie zgadza się
			// z żądaną — awaria integralności zapisu, nie błąd wyboru. Transakcja jest już
			// wycofana, więc NIC nie powstało, ale organizator dowie się o tym tylko wtedy,
			// gdy mu to powiemy: bez tej gałęzi dostaje fatala, czyli białą stronę bez
			// żadnej informacji, czy zgłoszenie zostało zapisane. Degradacja i treść jak na
			// ścieżce publicznej (SubmitHandler + Shortcode::resultNotice), żeby ta sama
			// awaria nie opowiadała dwóch różnych historii; log zostaje dla operatora.
			//
			// O TRYB pytamy rozstrzygnięcie z wejścia, nie żądanie (jak SubmitHandler):
			// `request->nights === null` znaczy „wyboru dób nie podano", a nie „to event
			// klasyczny". Ścieżka klasyczna takiego rzutu nie ma, więc jej wyjątki — czyli
			// coś nieprzewidzianego — lecą dalej, głośno, zamiast chować się za komunikatem.
			if ( null === $nights ) {
				throw $e;
			}

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'evreg manual add failed: ' . $e->getMessage() );

			self::redirect( 'add_failed' );
			return;
		}

		switch ( $result->code ) {
			case 'reserved':
			case 'waitlisted':
				$extra = array(
					'action' => 'view',
					'id'     => (int) $result->registrationId,
				);

				if ( $duplicate ) {
					$extra['evreg_dup'] = '1';
				}

				self::redirect( 'reserved' === $result->code ? 'added' : 'added_waitlist', $extra );
				break;
			case 'accommodation_full':
				// Nocleg zajęty: zgłoszenia NIE zapisano (serwis wycofał transakcję), więc
				// wracamy inline z błędem przy polu noclegu. W trybie „doby” wynik niesie
				// klucz blokującej doby — renderer zamienia go na zdanie z jej etykietą.
				self::rerender_add_field(
					$schema,
					$event_id,
					$schema->accommodationFieldKey(),
					'' === $result->blockingNight ? 'accommodation_full' : 'night_full:' . $result->blockingNight,
					$assembled->rawAnswers(),
					$nights,
					$notify
				);
				break;
			case 'rejected':
				self::redirect( 'capacity_full' );
				break;
			default: // duplicate — nieosiągalne dla wpisu ręcznego (serwis pomija guard duplikatu).
				self::redirect( $result->code );
		}
	}

	/**
	 * Re-renderuje ekran dodawania (bez PRG) z błędami i z tym, co organizator wpisał.
	 *
	 * Jedyne wyjście inline w handle_add(), bo stan przełącznika wysyłki trzeba odtworzyć
	 * na KAŻDEJ odrzuconej ścieżce. `evreg_notify` nie jest polem schematu, więc nie ma go
	 * ani w `values()`, ani w `rawAnswers()`; bez dołożenia wracałby odznaczony, a następny
	 * zapis poszedłby po cichu bez maila, o który organizator właśnie prosił.
	 *
	 * @param FormSchema           $schema   Złożony schemat formularza.
	 * @param int                  $event_id ID wydarzenia.
	 * @param array<string,string> $errors   Błędy walidacji: klucz pola → kod błędu.
	 * @param array<string,mixed>  $answers  Odpowiedzi do prefillu.
	 * @param NightsConfig|null    $nights   Konfiguracja trybu „doby” albo null (klasyczny).
	 * @param bool                 $notify   Stan przełącznika wysyłki potwierdzenia.
	 */
	private static function rerender_add( FormSchema $schema, int $event_id, array $errors, array $answers, ?NightsConfig $nights, bool $notify ): void {
		if ( $notify ) {
			$answers['evreg_notify'] = '1';
		}

		self::view()->render_add_errors( $schema, $event_id, $errors, $answers, $nights );
	}

	/**
	 * Re-renderuje ekran dodawania z jednym błędem przy wskazanym polu.
	 *
	 * Wartości MUSZĄ przychodzić z `rawAnswers()` wołającego, nie z `values()`: na gałęzi
	 * poprawnej walidacji `values()` niesie nocleg jako obiekt AccommodationSelection
	 * i nie ma kluczy `evreg_companion*`, więc re-render z nich pokazałby „Bez noclegu"
	 * jako zaznaczone i po cichu zgubił osobę towarzyszącą.
	 *
	 * @param FormSchema          $schema   Złożony schemat formularza.
	 * @param int                 $event_id ID wydarzenia.
	 * @param string|null         $key      Klucz pola, przy którym stoi błąd; null = schemat
	 *                                      tego pola nie ma (wtedy nie ma czego oznaczyć).
	 * @param string              $code     Kod błędu.
	 * @param array<string,mixed> $answers  Surowe odpowiedzi do prefillu.
	 * @param NightsConfig|null   $nights   Konfiguracja trybu „doby” albo null (klasyczny).
	 * @param bool                $notify   Stan przełącznika wysyłki potwierdzenia.
	 */
	private static function rerender_add_field( FormSchema $schema, int $event_id, ?string $key, string $code, array $answers, ?NightsConfig $nights, bool $notify ): void {
		self::rerender_add( $schema, $event_id, null === $key ? array() : array( $key => $code ), $answers, $nights, $notify );
	}

	/**
	 * Zwraca kod błędu, gdy wybrany pokój nie zgadza się z flagą osoby towarzyszącej.
	 *
	 * Bliźniak `SubmitHandler::companionRoomMismatch` — dwie powierzchnie, dwa wyzwalacze;
	 * sąd siedzi w jednym miejscu (`RoomType::companionMismatch`).
	 *
	 * Pokój dostaje z zewnątrz, a nie z żądania: na ścieżce publicznej to zawsze pokój
	 * z submisji, w adminie — pokój, który zgłoszenie po tej edycji realnie dostanie
	 * (podany albo, gdy pole było ukryte, zapisany). Patrz wyzwalacz w handle_edit().
	 *
	 * @param FormSchema  $schema    Złożony schemat formularza.
	 * @param string|null $room_key  Klucz pokoju po tej edycji (null = bez noclegu).
	 * @param bool        $companion Czy zgłoszenie ma osobę towarzyszącą.
	 */
	private static function companionRoomMismatch( FormSchema $schema, ?string $room_key, bool $companion ): ?string {
		if ( null === $room_key ) {
			return null;
		}

		$key = $schema->accommodationFieldKey();
		if ( null === $key ) {
			return null;
		}

		$field = $schema->findField( $key );
		if ( null === $field ) {
			return null;
		}

		$room = AccommodationConfig::fromArray( $field->config )->room( $room_key );

		return null === $room ? null : $room->companionMismatch( $companion );
	}

	/**
	 * Składa tekst CSV eksportu eventu albo null, gdy event nie ma schematu.
	 *
	 * Tryb rozstrzygany RAZ, u wejścia: w trybie „doby” nie powstaje AccommodationConfig
	 * (rzuca na niespójnym inwentarzu, a event dobowy może nieść zapomniane dane klasyczne),
	 * a kolumna „Doby” czyta zapisane wiersze zbiorczo — jedno zapytanie na cały eksport.
	 *
	 * Tryb czytamy z KONFIGURACJI NOCLEGU EVENTU, tą samą drogą co ReservationService,
	 * panel obłożenia i kolejka maili — i tu wystarcza sam `mode`, bez obiektu konfiguracji
	 * (eksport nazywa doby ze snapshotów wierszy, nie dzisiejszą konfiguracją), więc odczyt
	 * nie może paść na błędzie konfiguracji.
	 *
	 * @param int                 $event_id ID eventu.
	 * @param array<string,mixed> $filters  Filtry listy (status, type_key, event_id).
	 */
	public static function build_export_csv( int $event_id, array $filters ): ?string {
		$schema = ( new EventFormLoader( new EventConfigRepository() ) )->load( $event_id );
		if ( null === $schema ) {
			return null;
		}
		$config = ( new EventConfigRepository() )->get( $event_id );
		$types  = RegistrationTypeCollection::fromArray( is_array( $config['types'] ) ? $config['types'] : array() );
		$raw    = is_array( $config['accommodation'] ) ? $config['accommodation'] : array();

		$repository = new RegistrationRepository();
		$rows       = $repository->exportRegistrations( $filters );
		$ids        = array_map( 'intval', array_column( $rows, 'id' ) );
		$exporter   = new RegistrationsExporter();

		if ( NightsResolver::isNights( $raw ) ) {
			return $exporter->buildCsv( $schema, null, $types, $rows, array(), $repository->bookingNightsFor( $ids ) );
		}

		$accommodation = AccommodationConfig::fromArray( $raw );

		return $exporter->buildCsv( $schema, $accommodation, $types, $rows, $repository->accommodationBookingsFor( $ids ) );
	}

	/** Handler eksportu CSV zgłoszeń (streaming, bez PRG na sukcesie). */
	public static function handle_export(): void {
		check_admin_referer( self::ACTION_EXPORT );
		if ( ! current_user_can( Capabilities::CAP ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'event-registration' ) );
		}

		$filters = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce sprawdzony wyżej.
		if ( isset( $_GET['status'] ) && '' !== (string) $_GET['status'] ) {
			$filters['status'] = sanitize_text_field( wp_unslash( (string) $_GET['status'] ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['type_key'] ) && '' !== (string) $_GET['type_key'] ) {
			$filters['type_key'] = sanitize_text_field( wp_unslash( (string) $_GET['type_key'] ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$event_id = isset( $_GET['event_id'] ) ? (int) $_GET['event_id'] : 0;
		if ( $event_id <= 0 ) {
			self::redirect( 'export_no_event' );
			return;
		}
		$filters['event_id'] = $event_id;

		$csv = self::build_export_csv( $event_id, $filters );
		if ( null === $csv ) {
			self::redirect( 'export_no_event' );
			return;
		}

		$filename = sanitize_file_name( 'zgloszenia-event-' . $event_id . '-' . current_time( 'Y-m-d' ) . '.csv' );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV binarny, nie HTML; komórki neutralizowane w buildCsv.
		exit;
	}
}
