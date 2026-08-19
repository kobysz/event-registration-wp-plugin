<?php
/**
 * Tabela kolejki mailowej w adminie.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Admin;

use EvReg\Persistence\MailQueueRepository;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Tabela kolejki mailowej: lista wierszy evreg_mail_queue z filtrami i akcjami.
 */
final class MailQueueListTable extends \WP_List_Table {

	public const PER_PAGE = 20;

	/**
	 * Repozytorium kolejki.
	 *
	 * @var MailQueueRepository
	 */
	private MailQueueRepository $repository;

	/**
	 * Tworzy tabelę.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'evreg_mail',
				'plural'   => 'evreg_mails',
				'ajax'     => false,
			)
		);

		$this->repository = new MailQueueRepository();
	}

	/**
	 * Definiuje kolumny listy.
	 *
	 * @return array<string,string>
	 */
	public function get_columns(): array {
		return array(
			'status'       => __( 'Status', 'event-registration' ),
			'template_key' => __( 'Typ maila', 'event-registration' ),
			'recipient'    => __( 'Odbiorca', 'event-registration' ),
			'event'        => __( 'Wydarzenie', 'event-registration' ),
			'attempts'     => __( 'Próby', 'event-registration' ),
			'scheduled_at' => __( 'Zaplanowano', 'event-registration' ),
			'sent_at'      => __( 'Wysłano', 'event-registration' ),
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

		$total = $this->repository->countByFilter( $filters );

		$this->items = $this->repository->paginate( $filters, self::PER_PAGE, $offset );

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
	 * @param array<string,mixed> $item   Wiersz kolejki.
	 * @param string              $column Klucz kolumny.
	 */
	public function column_default( $item, $column ): string {
		if ( 'event' === $column ) {
			$title = get_the_title( (int) ( $item['event_id'] ?? 0 ) );

			return '' === $title ? '#' . (int) ( $item['event_id'] ?? 0 ) : esc_html( $title );
		}

		return esc_html( (string) ( $item[ $column ] ?? '' ) );
	}

	/**
	 * Dokłada akcje wiersza pod pierwszą kolumną.
	 *
	 * @param array<string,mixed> $item    Wiersz kolejki.
	 * @param string              $column  Aktualna kolumna.
	 * @param string              $primary Kolumna główna.
	 */
	public function handle_row_actions( $item, $column, $primary ): string {
		if ( $column !== $primary ) {
			return '';
		}

		$id      = (int) ( $item['id'] ?? 0 );
		$actions = array(
			'view' => sprintf(
				'<a href="%s">%s</a>',
				esc_url(
					add_query_arg(
						array(
							'page'   => MailQueueScreen::SLUG,
							'action' => 'view',
							'id'     => $id,
						),
						admin_url( 'edit.php?post_type=' . EventPostType::POST_TYPE )
					)
				),
				esc_html__( 'Podgląd', 'event-registration' )
			),
		);

		if ( MailQueueRepository::STATUS_FAILED === ( $item['status'] ?? '' ) ) {
			$actions['requeue'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . MailQueueScreen::REQUEUE_ACTION . '&id=' . $id ), MailQueueScreen::REQUEUE_ACTION . '_' . $id ) ),
				esc_html__( 'Wznów', 'event-registration' )
			);
		}

		return $this->row_actions( $actions );
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

		$current_status = $this->currentFilters()['status'] ?? '';
		$current_event  = $this->currentFilters()['event_id'] ?? 0;

		echo '<div class="alignleft actions">';

		echo '<select name="status">';
		echo '<option value="">' . esc_html__( 'Wszystkie statusy', 'event-registration' ) . '</option>';
		foreach ( array( MailQueueRepository::STATUS_QUEUED, MailQueueRepository::STATUS_SENDING, MailQueueRepository::STATUS_SENT, MailQueueRepository::STATUS_FAILED ) as $status ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $status ),
				selected( $current_status, $status, false ),
				esc_html( $status )
			);
		}
		echo '</select>';

		echo '<select name="event_id">';
		echo '<option value="0">' . esc_html__( 'Wszystkie wydarzenia', 'event-registration' ) . '</option>';
		foreach ( $this->repository->distinctEventIds() as $event_id ) {
			$title = get_the_title( $event_id );
			printf(
				'<option value="%d"%s>%s</option>',
				$event_id,
				selected( $current_event, $event_id, false ),
				esc_html( '' === $title ? '#' . $event_id : $title )
			);
		}
		echo '</select>';

		submit_button( __( 'Filtruj', 'event-registration' ), '', 'filter_action', false );

		echo '</div>';
	}

	/**
	 * Odczytuje filtry z żądania (sanityzacja + whitelist).
	 *
	 * @return array{status?: string, event_id?: int}
	 */
	private function currentFilters(): array {
		$filters = array();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filtr GET listy, nie akcja zmieniająca stan.
		$status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['status'] ) ) : '';
		if ( '' !== $status ) {
			$filters['status'] = $status;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$event_id = isset( $_GET['event_id'] ) ? (int) $_GET['event_id'] : 0;
		if ( $event_id > 0 ) {
			$filters['event_id'] = $event_id;
		}

		return $filters;
	}
}
