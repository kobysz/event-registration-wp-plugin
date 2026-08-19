<?php
/**
 * Tabela zgłoszeń w adminie.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Admin;

use EvReg\Domain\Registration\RegistrationStatus;
use EvReg\Domain\Registration\RegistrationTypeCollection;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\RegistrationRepository;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Tabela zgłoszeń: lista wierszy evreg_registrations z filtrami i akcjami cyklu życia.
 */
final class RegistrationsListTable extends \WP_List_Table {

	public const PER_PAGE = 20;

	/**
	 * Repozytorium zgłoszeń.
	 *
	 * @var RegistrationRepository
	 */
	private RegistrationRepository $repository;

	/**
	 * Repozytorium konfiguracji (etykiety typów).
	 *
	 * @var EventConfigRepository
	 */
	private EventConfigRepository $config;

	/**
	 * Tworzy tabelę.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'evreg_registration',
				'plural'   => 'evreg_registrations',
				'ajax'     => false,
			)
		);

		$this->repository = new RegistrationRepository();
		$this->config     = new EventConfigRepository();
	}

	/**
	 * Zwraca przetłumaczoną etykietę statusu (fallback: surowa wartość).
	 *
	 * @param string $status Wartość statusu.
	 */
	public static function status_label( string $status ): string {
		$labels = array(
			RegistrationStatus::Pending->value   => __( 'Oczekuje', 'event-registration' ),
			RegistrationStatus::Confirmed->value => __( 'Potwierdzone', 'event-registration' ),
			RegistrationStatus::Waitlist->value  => __( 'Lista rezerwowa', 'event-registration' ),
			RegistrationStatus::Cancelled->value => __( 'Anulowane', 'event-registration' ),
		);

		return $labels[ $status ] ?? $status;
	}

	/**
	 * Definiuje kolumny listy.
	 *
	 * @return array<string,string>
	 */
	public function get_columns(): array {
		return array(
			'name'        => __( 'Imię i nazwisko', 'event-registration' ),
			'email'       => __( 'E-mail', 'event-registration' ),
			'type'        => __( 'Typ', 'event-registration' ),
			'status'      => __( 'Status', 'event-registration' ),
			'event'       => __( 'Wydarzenie', 'event-registration' ),
			'price_total' => __( 'Kwota', 'event-registration' ),
			'created_at'  => __( 'Zgłoszono', 'event-registration' ),
		);
	}

	/**
	 * Ładuje wiersze wg filtrów z żądania i ustawia paginację.
	 */
	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$filters = $this->currentFilters();
		$paged   = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$offset  = ( $paged - 1 ) * self::PER_PAGE;

		$total = $this->repository->countRegistrations( $filters );

		$this->items = $this->repository->paginateRegistrations( $filters, self::PER_PAGE, $offset );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) ceil( $total / self::PER_PAGE ),
			)
		);
	}

	/**
	 * Renderuje kolumnę domyślną, escapując wartość.
	 *
	 * @param array<string,mixed> $item   Wiersz zgłoszenia.
	 * @param string              $column Klucz kolumny.
	 */
	public function column_default( $item, $column ): string {
		if ( 'status' === $column ) {
			return esc_html( self::status_label( (string) ( $item['status'] ?? '' ) ) );
		}

		if ( 'type' === $column ) {
			return esc_html( $this->typeLabel( (int) ( $item['event_id'] ?? 0 ), (string) ( $item['type_key'] ?? '' ) ) );
		}

		if ( 'event' === $column ) {
			$title = get_the_title( (int) ( $item['event_id'] ?? 0 ) );

			return '' === $title ? '#' . (int) ( $item['event_id'] ?? 0 ) : esc_html( $title );
		}

		return esc_html( (string) ( $item[ $column ] ?? '' ) );
	}

	/**
	 * Dokłada akcje wiersza wg statusu pod pierwszą kolumną.
	 *
	 * @param array<string,mixed> $item    Wiersz zgłoszenia.
	 * @param string              $column  Aktualna kolumna.
	 * @param string              $primary Kolumna główna.
	 */
	public function handle_row_actions( $item, $column, $primary ): string {
		if ( $column !== $primary ) {
			return '';
		}

		$id      = (int) ( $item['id'] ?? 0 );
		$status  = (string) ( $item['status'] ?? '' );
		$base    = admin_url( 'edit.php?post_type=' . EventPostType::POST_TYPE );
		$actions = array(
			'view' => sprintf(
				'<a href="%s">%s</a>',
				esc_url(
					add_query_arg(
						array(
							'page'   => 'evreg-registrations',
							'action' => 'view',
							'id'     => $id,
						),
						$base
					)
				),
				esc_html__( 'Podgląd', 'event-registration' )
			),
		);

		if ( RegistrationStatus::Pending->value === $status ) {
			$actions['confirm'] = $this->actionLink( 'evreg_reg_confirm', $id, __( 'Potwierdź', 'event-registration' ) );
		}

		if ( RegistrationStatus::Waitlist->value === $status ) {
			$actions['promote'] = $this->actionLink( 'evreg_reg_promote', $id, __( 'Promuj', 'event-registration' ) );
		}

		if ( in_array( $status, array( RegistrationStatus::Pending->value, RegistrationStatus::Confirmed->value, RegistrationStatus::Waitlist->value ), true ) ) {
			$actions['cancel'] = $this->actionLink( 'evreg_reg_cancel', $id, __( 'Anuluj', 'event-registration' ) );
		}

		if ( RegistrationStatus::Cancelled->value === $status ) {
			$actions['delete'] = $this->actionLink( 'evreg_reg_delete', $id, __( 'Usuń trwale', 'event-registration' ) );
		}

		return $this->row_actions( $actions );
	}

	/**
	 * Buduje link akcji admin-post z nonce.
	 *
	 * @param string $action Nazwa akcji.
	 * @param int    $id     ID zgłoszenia.
	 * @param string $label  Etykieta linku.
	 */
	private function actionLink( string $action, int $id, string $label ): string {
		return sprintf(
			'<a href="%s">%s</a>',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . $action . '&id=' . $id ), $action . '_' . $id ) ),
			esc_html( $label )
		);
	}

	/**
	 * Renderuje selekty filtrów nad tabelą.
	 *
	 * @param string $which Górny/dolny tablenav.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		$filters = $this->currentFilters();
		$status  = $filters['status'] ?? '';
		$type    = $filters['type_key'] ?? '';
		$event   = $filters['event_id'] ?? 0;

		echo '<div class="alignleft actions">';

		echo '<select name="status">';
		echo '<option value="">' . esc_html__( 'Wszystkie statusy', 'event-registration' ) . '</option>';
		foreach ( array( RegistrationStatus::Pending, RegistrationStatus::Confirmed, RegistrationStatus::Waitlist, RegistrationStatus::Cancelled ) as $case ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $case->value ), selected( $status, $case->value, false ), esc_html( self::status_label( $case->value ) ) );
		}
		echo '</select>';

		echo '<select name="event_id">';
		echo '<option value="0">' . esc_html__( 'Wszystkie wydarzenia', 'event-registration' ) . '</option>';
		foreach ( $this->repository->distinctEventIds() as $event_id ) {
			$title = get_the_title( $event_id );
			printf(
				'<option value="%d"%s>%s</option>',
				$event_id,
				selected( $event, $event_id, false ),
				esc_html( '' === $title ? '#' . $event_id : $title )
			);
		}
		echo '</select>';

		if ( $event > 0 ) {
			echo '<select name="type_key">';
			echo '<option value="">' . esc_html__( 'Wszystkie typy', 'event-registration' ) . '</option>';
			foreach ( $this->repository->distinctTypeKeys( $event ) as $type_key ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $type_key ), selected( $type, $type_key, false ), esc_html( $this->typeLabel( $event, $type_key ) ) );
			}
			echo '</select>';
		}

		submit_button( __( 'Filtruj', 'event-registration' ), '', 'filter_action', false );

		echo '</div>';
	}

	/**
	 * Zwraca etykietę typu z configu eventu, fallback: sam klucz.
	 *
	 * @param int    $event_id ID eventu.
	 * @param string $type_key Klucz typu.
	 */
	private function typeLabel( int $event_id, string $type_key ): string {
		if ( 0 === $event_id || '' === $type_key ) {
			return $type_key;
		}

		$config = $this->config->get( $event_id );
		$types  = RegistrationTypeCollection::fromArray( is_array( $config['types'] ) ? $config['types'] : array() );
		$type   = $types->get( $type_key );

		return null === $type ? $type_key : $type->label;
	}

	/**
	 * Odczytuje filtry z żądania (sanityzacja + whitelist statusu).
	 *
	 * @return array{status?: string, type_key?: string, event_id?: int}
	 */
	private function currentFilters(): array {
		$filters = array();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['status'] ) ) : '';
		if ( '' !== $status ) {
			$filters['status'] = $status;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type_key = isset( $_GET['type_key'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['type_key'] ) ) : '';
		if ( '' !== $type_key ) {
			$filters['type_key'] = $type_key;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$event_id = isset( $_GET['event_id'] ) ? (int) $_GET['event_id'] : 0;
		if ( $event_id > 0 ) {
			$filters['event_id'] = $event_id;
		}

		return $filters;
	}
}
