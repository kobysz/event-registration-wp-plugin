<?php
/**
 * Ekran admina panelu zgłoszeń: submenu, lista, szczegóły, akcje cyklu życia.
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
use EvReg\Frontend\NightsResolver;
use EvReg\Frontend\SubmissionAssembler;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Services\ReservationRequest;
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
	 * Renderuje komunikat wyniku akcji z parametru evreg_msg.
	 *
	 * @param NightsConfig|null $nights Konfiguracja trybu „doby” rozstrzygnięta u wejścia ekranu;
	 *                                  null = tryb klasyczny albo ekran bez kontekstu eventu.
	 */
	private static function render_notice( ?NightsConfig $nights = null ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['evreg_msg'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code     = sanitize_text_field( wp_unslash( (string) $_GET['evreg_msg'] ) );
		$messages = array(
			'confirmed'                 => array( 'success', __( 'Zgłoszenie potwierdzone.', 'event-registration' ) ),
			'cancelled'                 => array( 'success', __( 'Zgłoszenie anulowane, miejsce zwolnione.', 'event-registration' ) ),
			'promoted'                  => array( 'success', __( 'Awansowano z listy rezerwowej — wysłano prośbę o potwierdzenie.', 'event-registration' ) ),
			'promoted_no_accommodation' => array( 'warning', __( 'Awansowano z listy rezerwowej, ale wybrany nocleg był już pełny — zgłoszenie nie ma przyznanego noclegu.', 'event-registration' ) ),
			'occupancy_mismatch'        => array( 'error', __( 'Nie można awansować: wybrany pokój ma dziś inną liczbę osób niż zapisano w zgłoszeniu. Popraw nocleg albo osobę towarzyszącą w edycji odpowiedzi.', 'event-registration' ) ),
			'deleted'                   => array( 'success', __( 'Zgłoszenie trwale usunięte.', 'event-registration' ) ),
			'note'                      => array( 'success', __( 'Notatka zapisana.', 'event-registration' ) ),
			'edited'                    => array( 'success', __( 'Odpowiedzi zgłoszenia zaktualizowane.', 'event-registration' ) ),
			'rejected'                  => array( 'error', __( 'Brak wolnych miejsc — nie można awansować.', 'event-registration' ) ),
			'invalid_status'            => array( 'error', __( 'Akcja niedozwolona dla tego statusu.', 'event-registration' ) ),
			'not_found'                 => array( 'error', __( 'Nie znaleziono zgłoszenia.', 'event-registration' ) ),
			'capacity_full'             => array( 'error', __( 'Brak wolnych miejsc dla wybranego typu zgłoszenia.', 'event-registration' ) ),
			'accommodation_full'        => array( 'error', __( 'Brak wolnych miejsc dla wybranego noclegu.', 'event-registration' ) ),
			'config_error'              => array( 'error', __( 'Konfiguracja noclegów tego wydarzenia jest niepoprawna — popraw ją w ustawieniach wydarzenia.', 'event-registration' ) ),
			'export_no_event'           => array( 'error', __( 'Wybierz event, aby wyeksportować zgłoszenia.', 'event-registration' ) ),
		);

		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}

		$text = $messages[ $code ][1];

		// Tryb „doby”: odmowa niesie KLUCZ blokującej doby — identyfikator wewnętrzny,
		// którego organizator nie ma czytać. Zamieniamy go na zdanie z etykietą doby.
		if ( 'accommodation_full' === $code && null !== $nights ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- odczyt kodu wyniku z PRG, widok read-only.
			$night = isset( $_GET['evreg_night'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['evreg_night'] ) ) : '';

			if ( '' !== $night ) {
				$text = sprintf(
					/* translators: %s: etykieta doby noclegowej. */
					__( 'Doba „%s” nie ma już tyle wolnych miejsc. Odznacz ją albo wybierz inne doby.', 'event-registration' ),
					$nights->nightLabel( $night )
				);
			}
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $messages[ $code ][0] ),
			esc_html( $text )
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

		$event_id = (int) $row['event_id'];
		$schema   = ( new EventFormLoader( new EventConfigRepository() ) )->load( $event_id );

		// Tryb „doby” rozstrzygamy RAZ, u wejścia ekranu, i wstrzykujemy niżej. Niepoprawna
		// konfiguracja degraduje się do komunikatu — detal zgłoszenia musi dać się odczytać
		// także wtedy, gdy konfiguracja eventu jest zepsuta.
		$nights       = null;
		$config_error = null;

		if ( null !== $schema ) {
			try {
				$nights = NightsResolver::forSchema( $schema );
			} catch ( SchemaException $e ) {
				$config_error = $e->getMessage();
			}
		}

		self::render_notice( $nights );

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

		$booking = null === $nights ? $repository->findAccommodationBooking( $id ) : null;
		// Wiersze dób to PRAWDA o tym, co zgłoszenie trzyma, i niosą snapshot etykiety
		// i ceny — detal czyta je, nie dzisiejszą konfigurację (przemianowana albo
		// przeceniona doba nie przepisuje historii). Jeden odczyt bulk, nie N+1.
		$night_rows = null === $nights ? array() : ( $repository->bookingNightsFor( array( $id ) )[ $id ] ?? array() );

		self::render_answers( $schema, (string) $row['data'], $booking, $nights, $night_rows, null !== $config_error );
		self::render_booking( $booking, $event_id );
		self::render_night_rows( $night_rows );
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

		// Tryb „doby” rozstrzygamy RAZ, u wejścia ekranu, i wstrzykujemy do renderera —
		// inaczej formularz wyrenderowałby kontrolkę klasyczną dla eventu dobowego, a zapis
		// skasowałby doby zgłoszenia. Niepoprawna konfiguracja degraduje się do komunikatu:
		// formularz, który nie umie oddać tego, co pokazuje, lepiej się nie pokazywać wcale.
		try {
			$nights = NightsResolver::forSchema( $schema );
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

		self::render_notice( $nights );

		wp_enqueue_script( 'evreg-public' );

		$answers = json_decode( (string) $row['data'], true );
		$answers = is_array( $answers ) ? $answers : array();
		// Companion NIE jest w JSON odpowiedzi (osobne kolumny DB) — dokładamy pod tymi samymi
		// kluczami evreg_companion/evreg_companion_name, których RegistrationEditForm/form.js
		// (evreg-public) oczekują (spójnie z SubmissionAssembler przy re-renderze błędu).
		$answers['evreg_companion']      = (bool) ( $row['companion'] ?? false );
		$answers['evreg_companion_name'] = (string) ( $row['companion_name'] ?? '' );

		// Tryb „doby”: prefill z ZAPISANYCH WIERSZY, nie z JSON-a odpowiedzi. Wiersze są
		// prawdą o tym, co zgłoszenie trzyma — JSON niesie życzenie i może się z nimi
		// rozejść (awans bez przyznanego noclegu, odmowa pojedynczej doby). Rozjazd między
		// zapisem i odczytem prefilla kosztował już ten projekt skasowaną rezerwację
		// (B15 na ścieżce klasycznej), więc tu czytamy stan, nie życzenie.
		$accommodation_key = $schema->accommodationFieldKey();

		if ( null !== $nights && null !== $accommodation_key ) {
			$rows                          = $repository->bookingNightsFor( array( $id ) )[ $id ] ?? array();
			$answers[ $accommodation_key ] = array_column( $rows, 'key' );
		}

		echo RegistrationEditForm::render( $schema, $answers, $id, null, null, $nights ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- już escapowane w rendererze.
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
	 * @param FormSchema|null                                                 $schema     Złożony schemat formularza albo null.
	 * @param string                                                          $data       JSON odpowiedzi.
	 * @param array<string,mixed>|null                                        $booking    Rezerwacja noclegu (nośnik snapshotu etykiet) albo null.
	 * @param NightsConfig|null                                               $nights     Konfiguracja trybu „doby” albo null (klasyczny).
	 * @param array<int,array{key:string,label:string,seats:int,price:float}> $night_rows Zapisane doby zgłoszenia.
	 * @param bool                                                            $broken     Czy konfiguracja noclegów nie dała się odczytać
	 *                                                                                    (notice stoi wyżej) — wtedy pole noclegu pokazuje
	 *                                                                                    surowe klucze, zamiast budować AccommodationConfig,
	 *                                                                                    która na niespójnym inwentarzu rzuca.
	 */
	private static function render_answers( ?FormSchema $schema, string $data, ?array $booking = null, ?NightsConfig $nights = null, array $night_rows = array(), bool $broken = false ): void {
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

			if ( FieldType::Accommodation === $field->type && null !== $nights ) {
				// Tryb „doby”: etykiety ze SNAPSHOTU wierszy. Bez wierszy (zgłoszenie
				// z listy rezerwowej albo bez przyznanego noclegu) zostaje życzenie
				// z odpowiedzi — nazwane dzisiejszą konfiguracją, bo innego źródła nie ma.
				$text = self::night_text( $nights, $value, $night_rows );
			} elseif ( FieldType::Accommodation === $field->type && $broken ) {
				// Tryb nierozstrzygnięty: bez etykiet, ale bez fatala i bez pustki.
				$text = is_array( $value ) ? implode( ', ', array_map( 'strval', array_filter( $value, 'is_scalar' ) ) ) : (string) $value;
			} elseif ( FieldType::Accommodation === $field->type && is_array( $value ) ) {
				// Surowa odpowiedź to klucze (package/room) — bez etykiet jest nieczytelna.
				// Rezerwacja niesie snapshot etykiet, więc jest lepszym źródłem niż same klucze.
				$stored = AccommodationSelection::normalizeArray( $value );
				$text   = self::accommodation_text(
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

			self::detail_row( $field->label, $text );
		}

		echo '</tbody></table>';
	}

	/**
	 * Renderuje rezerwację noclegową, jeśli istnieje.
	 *
	 * @param array<string,mixed>|null $booking  Wiersz bookingu albo null.
	 * @param int                      $event_id ID eventu (źródło etykiet pakietu/pokoju).
	 */
	private static function render_booking( ?array $booking, int $event_id ): void {
		if ( null === $booking ) {
			return;
		}

		$config = ( new EventConfigRepository() )->get( $event_id );
		$cells  = ( new RegistrationExportMapper() )->accommodationCells(
			$booking,
			AccommodationConfig::fromArray( is_array( $config['accommodation'] ) ? $config['accommodation'] : array() )
		);

		echo '<h2>' . esc_html__( 'Nocleg', 'event-registration' ) . '</h2>';
		echo '<table class="widefat striped"><tbody>';
		self::detail_row( __( 'Pakiet', 'event-registration' ), $cells['package'] );
		self::detail_row( __( 'Pokój', 'event-registration' ), $cells['room'] );
		self::detail_row( __( 'Współlokator', 'event-registration' ), $cells['roommate'] );
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
	 * @param NightsConfig                                                    $config Konfiguracja trybu „doby”.
	 * @param mixed                                                           $value  Odpowiedź pola (płaska lista kluczy dób).
	 * @param array<int,array{key:string,label:string,seats:int,price:float}> $rows   Zapisane doby zgłoszenia.
	 */
	private static function night_text( NightsConfig $config, $value, array $rows ): string {
		if ( array() !== $rows ) {
			return implode( ', ', array_column( $rows, 'label' ) );
		}

		$keys = is_array( $value ) ? array_filter( $value, 'is_scalar' ) : array();

		if ( array() === $keys ) {
			return '';
		}

		$labels = array();
		foreach ( $keys as $key ) {
			$labels[] = $config->nightLabel( (string) $key );
		}

		/* translators: %s: lista etykiet dób. */
		return sprintf( __( '%s (nocleg nieprzyznany)', 'event-registration' ), implode( ', ', $labels ) );
	}

	/**
	 * Renderuje tabelę zapisanych dób zgłoszenia (etykieta, miejsca, cena ze snapshotu).
	 *
	 * @param array<int,array{key:string,label:string,seats:int,price:float}> $rows Zapisane doby.
	 */
	private static function render_night_rows( array $rows ): void {
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
	private static function accommodation_text( AccommodationConfig $config, array $booking ): string {
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

		$schema = ( new EventFormLoader( new EventConfigRepository() ) )->load( (int) $row['event_id'] );

		if ( null === $schema ) {
			self::redirect( 'not_found' );
			return;
		}

		// Tryb „doby” rozstrzygamy RAZ, tu, u wejścia (jak SubmitHandler na ścieżce publicznej):
		// bez tego asembler wyciągnąłby odpowiedzi w kształcie klasycznym, a walidator — który
		// tryb czyta sam — zablokowałby zapis na `required`. Niepoprawna konfiguracja nie może
		// dotrzeć ani do asemblera, ani do walidatora, ani do serwisu.
		try {
			$nights = NightsResolver::forSchema( $schema );
		} catch ( SchemaException ) {
			self::redirect( 'config_error' );
			return;
		}

		$posted    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce zweryfikowany wyżej.
		$assembled = ( new SubmissionAssembler() )->assemble( $schema, is_array( $posted ) ? $posted : array(), $nights );

		if ( ! $assembled->isValid() ) {
			echo '<div class="wrap">';
			echo '<h1>' . esc_html__( 'Edytuj zgłoszenie', 'event-registration' ) . '</h1>';
			echo RegistrationEditForm::render( $schema, array(), $id, $assembled->errors(), $assembled->values(), $nights ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- już escapowane w rendererze.
			echo '</div>';
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

					echo '<div class="wrap">';
					echo '<h1>' . esc_html__( 'Edytuj zgłoszenie', 'event-registration' ) . '</h1>';
					echo RegistrationEditForm::render( $schema, array(), $id, $errors, $assembled->rawAnswers(), $nights ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- już escapowane w rendererze.
					echo '</div>';
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
				// Odmowa niesie KLUCZ blokującej doby — przenosimy go przez PRG, żeby ekran
				// edycji nazwał ją etykietą (render_notice). Na ścieżce klasycznej reason
				// jest null i komunikat zostaje dzisiejszy.
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
