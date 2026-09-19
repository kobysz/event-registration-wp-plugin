<?php
/**
 * Ekran admina panelu zgłoszeń: submenu, lista, szczegóły, akcje cyklu życia.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Admin;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Registration\RegistrationTypeCollection;
use EvReg\Frontend\EventFormLoader;
use EvReg\Frontend\SubmissionAssembler;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Services\ReservationService;

defined( 'ABSPATH' ) || exit;

/**
 * Ekran admina panelu zgłoszeń.
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
	 * Renderuje listę albo ekran szczegółów.
	 */
	public static function render(): void {
		if ( ! current_user_can( Capabilities::CAP ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'event-registration' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing widoku.
		$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['action'] ) ) : '';

		if ( 'view' === $action ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::render_detail( (int) ( $_GET['id'] ?? 0 ) );
			return;
		}

		if ( 'edit' === $action ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::render_edit( (int) ( $_GET['id'] ?? 0 ) );
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

		self::render_notice();
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
	 * Renderuje komunikat wyniku akcji z parametru evreg_msg.
	 */
	private static function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['evreg_msg'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code     = sanitize_text_field( wp_unslash( (string) $_GET['evreg_msg'] ) );
		$messages = array(
			'confirmed'          => array( 'success', __( 'Zgłoszenie potwierdzone.', 'event-registration' ) ),
			'cancelled'          => array( 'success', __( 'Zgłoszenie anulowane, miejsce zwolnione.', 'event-registration' ) ),
			'promoted'           => array( 'success', __( 'Awansowano z listy rezerwowej — wysłano prośbę o potwierdzenie.', 'event-registration' ) ),
			'deleted'            => array( 'success', __( 'Zgłoszenie trwale usunięte.', 'event-registration' ) ),
			'note'               => array( 'success', __( 'Notatka zapisana.', 'event-registration' ) ),
			'edited'             => array( 'success', __( 'Odpowiedzi zgłoszenia zaktualizowane.', 'event-registration' ) ),
			'rejected'           => array( 'error', __( 'Brak wolnych miejsc — nie można awansować.', 'event-registration' ) ),
			'invalid_status'     => array( 'error', __( 'Akcja niedozwolona dla tego statusu.', 'event-registration' ) ),
			'not_found'          => array( 'error', __( 'Nie znaleziono zgłoszenia.', 'event-registration' ) ),
			'capacity_full'      => array( 'error', __( 'Brak wolnych miejsc dla wybranego typu zgłoszenia.', 'event-registration' ) ),
			'accommodation_full' => array( 'error', __( 'Brak wolnych miejsc dla wybranego noclegu.', 'event-registration' ) ),
			'export_no_event'    => array( 'error', __( 'Wybierz event, aby wyeksportować zgłoszenia.', 'event-registration' ) ),
		);

		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $messages[ $code ][0] ),
			esc_html( $messages[ $code ][1] )
		);
	}

	/**
	 * Renderuje ekran szczegółów zgłoszenia (read-only + notatka).
	 *
	 * @param int $id ID zgłoszenia.
	 */
	private static function render_detail( int $id ): void {
		$repository = new RegistrationRepository();
		$row        = $repository->findById( $id );
		$back       = add_query_arg(
			array(
				'post_type' => EventPostType::POST_TYPE,
				'page'      => self::SLUG,
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

		self::render_notice();

		$event_id = (int) $row['event_id'];

		echo '<table class="widefat striped"><tbody>';
		self::detail_row( __( 'Status', 'event-registration' ), RegistrationsListTable::status_label( (string) $row['status'] ) );
		self::detail_row( __( 'Imię i nazwisko', 'event-registration' ), (string) $row['name'] );
		self::detail_row( __( 'E-mail', 'event-registration' ), (string) $row['email'] );
		self::detail_row( __( 'Typ', 'event-registration' ), self::type_label( $event_id, (string) $row['type_key'] ) );
		self::detail_row( __( 'Kwota', 'event-registration' ), (string) $row['price_total'] );
		self::detail_row( __( 'Zgłoszono', 'event-registration' ), (string) $row['created_at'] );
		self::detail_row( __( 'Potwierdzono', 'event-registration' ), (string) ( $row['confirmed_at'] ?? '' ) );
		self::detail_row( __( 'Wygasa', 'event-registration' ), (string) ( $row['expires_at'] ?? '' ) );
		if ( ! empty( $row['companion'] ) ) {
			self::detail_row( __( 'Osoba towarzysząca', 'event-registration' ), (string) ( $row['companion_name'] ?? '' ) );
		}
		echo '</tbody></table>';

		self::render_answers( $event_id, (string) $row['data'] );
		self::render_booking( $repository->findAccommodationBooking( $id ) );
		self::render_note_form( $id, (string) ( $row['note'] ?? '' ) );
		self::render_actions( $id, (string) $row['status'] );

		echo '</div>';
	}

	/**
	 * Renderuje formularz edycji odpowiedzi zgłoszenia (albo notice dla braku/anulowanego/niepoprawnego eventu).
	 *
	 * @param int $id ID zgłoszenia.
	 */
	private static function render_edit( int $id ): void {
		$repository = new RegistrationRepository();
		$row        = $repository->findById( $id );

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

		$schema = ( new EventFormLoader( new EventConfigRepository() ) )->load( (int) $row['event_id'] );

		if ( null === $schema ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Nie udało się załadować formularza tego wydarzenia.', 'event-registration' ) . '</p></div></div>';
			return;
		}

		wp_enqueue_script( 'evreg-public' );

		$answers = json_decode( (string) $row['data'], true );
		$answers = is_array( $answers ) ? $answers : array();
		// Companion NIE jest w JSON odpowiedzi (osobne kolumny DB) — dokładamy pod tymi samymi
		// kluczami evreg_companion/evreg_companion_name, których RegistrationEditForm/form.js
		// (evreg-public) oczekują (spójnie z SubmissionAssembler przy re-renderze błędu).
		$answers['evreg_companion']      = (bool) ( $row['companion'] ?? false );
		$answers['evreg_companion_name'] = (string) ( $row['companion_name'] ?? '' );

		echo RegistrationEditForm::render( $schema, $answers, $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- już escapowane w rendererze.
		echo '</div>';
	}

	/**
	 * Zwraca etykietę typu z configu eventu, fallback: sam klucz.
	 *
	 * @param int    $event_id ID eventu.
	 * @param string $type_key Klucz typu.
	 */
	private static function type_label( int $event_id, string $type_key ): string {
		if ( 0 === $event_id || '' === $type_key ) {
			return $type_key;
		}

		$config = ( new EventConfigRepository() )->get( $event_id );
		$types  = RegistrationTypeCollection::fromArray( is_array( $config['types'] ) ? $config['types'] : array() );
		$type   = $types->get( $type_key );

		return null === $type ? $type_key : $type->label;
	}

	/**
	 * Renderuje odpowiedzi uczestnika ze złożonej schemy.
	 *
	 * @param int    $event_id ID eventu.
	 * @param string $data     JSON odpowiedzi.
	 */
	private static function render_answers( int $event_id, string $data ): void {
		$schema = ( new EventFormLoader( new EventConfigRepository() ) )->load( $event_id );

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
			$text  = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
			self::detail_row( $field->label, $text );
		}

		echo '</tbody></table>';
	}

	/**
	 * Renderuje rezerwację noclegową, jeśli istnieje.
	 *
	 * @param array<string,mixed>|null $booking Wiersz bookingu albo null.
	 */
	private static function render_booking( ?array $booking ): void {
		if ( null === $booking ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Nocleg', 'event-registration' ) . '</h2>';
		echo '<table class="widefat striped"><tbody>';
		self::detail_row( __( 'Pakiet', 'event-registration' ), (string) $booking['package_key'] );
		self::detail_row( __( 'Pokój', 'event-registration' ), (string) $booking['room_type_key'] );
		self::detail_row( __( 'Współlokator', 'event-registration' ), (string) ( $booking['roommate_pref'] ?? '' ) );
		echo '</tbody></table>';
	}

	/**
	 * Renderuje formularz notatki.
	 *
	 * @param int    $id   ID zgłoszenia.
	 * @param string $note Bieżąca notatka.
	 */
	private static function render_note_form( int $id, string $note ): void {
		echo '<h2>' . esc_html__( 'Notatka organizatora', 'event-registration' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( self::ACTION_NOTE ) );
		printf( '<input type="hidden" name="id" value="%d" />', $id );
		wp_nonce_field( self::ACTION_NOTE . '_' . $id );
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
	private static function render_actions( int $id, string $status ): void {
		$buttons = array();

		if ( 'pending' === $status ) {
			$buttons[] = self::action_button( self::ACTION_CONFIRM, $id, __( 'Potwierdź', 'event-registration' ), 'primary' );
		}
		if ( 'waitlist' === $status ) {
			$buttons[] = self::action_button( self::ACTION_PROMOTE, $id, __( 'Promuj', 'event-registration' ), 'primary' );
		}
		if ( in_array( $status, array( 'pending', 'confirmed', 'waitlist' ), true ) ) {
			$buttons[] = self::edit_link( $id );
			$buttons[] = self::action_button( self::ACTION_CANCEL, $id, __( 'Anuluj', 'event-registration' ), 'secondary' );
		}
		if ( 'cancelled' === $status ) {
			$buttons[] = self::action_button( self::ACTION_DELETE, $id, __( 'Usuń trwale', 'event-registration' ), 'delete' );
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
	private static function action_button( string $action, int $id, string $label, string $variant ): string {
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
	private static function edit_link( int $id ): string {
		$url = add_query_arg(
			array(
				'post_type' => EventPostType::POST_TYPE,
				'page'      => self::SLUG,
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
	private static function detail_row( string $label, string $value ): void {
		printf( '<tr><th scope="row" style="width:180px;">%s</th><td>%s</td></tr>', esc_html( $label ), esc_html( $value ) );
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

	/** Handler promocji z waitlisty. */
	public static function handle_promote(): void {
		$id = self::guard( self::ACTION_PROMOTE );
		self::redirect( self::service()->promoteFromWaitlist( $id )->code );
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

		$schema = ( new EventFormLoader( new EventConfigRepository() ) )->load( (int) $row['event_id'] );

		if ( null === $schema ) {
			self::redirect( 'not_found' );
			return;
		}

		$posted    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce zweryfikowany wyżej.
		$assembled = ( new SubmissionAssembler() )->assemble( $schema, is_array( $posted ) ? $posted : array() );

		if ( ! $assembled->isValid() ) {
			echo '<div class="wrap">';
			echo '<h1>' . esc_html__( 'Edytuj zgłoszenie', 'event-registration' ) . '</h1>';
			echo RegistrationEditForm::render( $schema, array(), $id, $assembled->errors(), $assembled->values() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- już escapowane w rendererze.
			echo '</div>';
			return;
		}

		$request = $assembled->request();

		if ( null === $request ) {
			self::redirect( 'not_found' );
			return;
		}

		$result = self::service()->editAnswers( $id, $request );

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
			case 'capacity_full':
			case 'accommodation_full':
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

		$schema = ( new EventFormLoader( new EventConfigRepository() ) )->load( $event_id );
		if ( null === $schema ) {
			self::redirect( 'export_no_event' );
			return;
		}
		$config        = ( new EventConfigRepository() )->get( $event_id );
		$types         = RegistrationTypeCollection::fromArray( is_array( $config['types'] ) ? $config['types'] : array() );
		$accommodation = AccommodationConfig::fromArray( is_array( $config['accommodation'] ) ? $config['accommodation'] : array() );

		$repository = new RegistrationRepository();
		$rows       = $repository->exportRegistrations( $filters );
		$ids        = array_map( 'intval', array_column( $rows, 'id' ) );
		$bookings   = $repository->accommodationBookingsFor( $ids );

		$csv      = ( new RegistrationsExporter() )->buildCsv( $schema, $accommodation, $types, $rows, $bookings );
		$filename = sanitize_file_name( 'zgloszenia-event-' . $event_id . '-' . current_time( 'Y-m-d' ) . '.csv' );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV binarny, nie HTML; komórki neutralizowane w buildCsv.
		exit;
	}
}
