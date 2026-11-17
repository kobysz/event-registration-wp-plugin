<?php
/**
 * Ekran admina panelu zgłoszeń: submenu, lista, handlery akcji cyklu życia i eksport.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Admin;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Registration\RegistrationTypeCollection;
use EvReg\Domain\Schema\FormSchema;
use EvReg\Domain\Schema\SchemaException;
use EvReg\Frontend\EventFormLoader;
use EvReg\Frontend\NightsResolver;
use EvReg\Frontend\SubmissionAssembler;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Services\ReservationRequest;
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
	public const ACTION_EXPORT  = 'evreg_export';

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
	 * Renderuje listę albo — wg `action` — ekran szczegółów albo edycji jednego zgłoszenia.
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
		self::render_export_button();

		echo '<form method="get">';
		printf( '<input type="hidden" name="post_type" value="%s" />', esc_attr( EventPostType::POST_TYPE ) );
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( self::SLUG ) );
		$table->display();
		echo '</form>';
		echo '</div>';
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
	 */
	public static function handle_promote(): void {
		$id = self::guard( self::ACTION_PROMOTE );

		try {
			$code = self::service()->promoteFromWaitlist( $id )->code;
		} catch ( SchemaException ) {
			$code = 'config_error';
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
			$new_slot      = null === $request->selection ? '' : $request->selection->slotKey();
			$changed       = $previous_slot !== $new_slot || (bool) ( $row['companion'] ?? false ) !== $request->companion;

			if ( $changed ) {
				$mismatch = self::companionRoomMismatch( $schema, $request );

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
	 * Zwraca kod błędu, gdy wybrany pokój nie zgadza się z flagą osoby towarzyszącej.
	 *
	 * Bliźniak `SubmitHandler::companionRoomMismatch` — dwie powierzchnie, dwa wyzwalacze;
	 * sąd siedzi w jednym miejscu (`RoomType::companionMismatch`).
	 *
	 * @param FormSchema         $schema  Złożony schemat formularza.
	 * @param ReservationRequest $request Zbudowane żądanie rezerwacji.
	 */
	private static function companionRoomMismatch( FormSchema $schema, ReservationRequest $request ): ?string {
		if ( null === $request->selection ) {
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

		$room = AccommodationConfig::fromArray( $field->config )->room( $request->selection->roomKey );

		return null === $room ? null : $room->companionMismatch( $request->companion );
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
