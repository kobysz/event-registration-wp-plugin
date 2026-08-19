<?php
/**
 * Ekran admina kolejki mailowej: submenu, lista, szczegóły, wznowienie.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Admin;

use EvReg\Persistence\MailQueueRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Ekran admina kolejki mailowej.
 */
final class MailQueueScreen {

	public const SLUG           = 'evreg-mail-queue';
	public const REQUEUE_ACTION = 'evreg_requeue_mail';

	/**
	 * Podpina submenu i handler wznowienia.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_post_' . self::REQUEUE_ACTION, array( self::class, 'handle_requeue' ) );
	}

	/**
	 * Rejestruje submenu pod menu CPT wydarzeń.
	 */
	public static function add_menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . EventPostType::POST_TYPE,
			__( 'Kolejka maili', 'event-registration' ),
			__( 'Kolejka maili', 'event-registration' ),
			Capabilities::CAP,
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * Renderuje listę albo ekran szczegółów wg parametru action.
	 */
	public static function render(): void {
		if ( ! current_user_can( Capabilities::CAP ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'event-registration' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing widoku, nie akcja.
		$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['action'] ) ) : '';

		if ( 'view' === $action ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::render_detail( (int) ( $_GET['id'] ?? 0 ) );
			return;
		}

		self::render_list();
	}

	/**
	 * Renderuje tabelę listy z formularzem filtrów.
	 */
	private static function render_list(): void {
		$table = new MailQueueListTable();
		$table->prepare_items();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Kolejka maili', 'event-registration' ) . '</h1>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['evreg_requeued'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$ok = '1' === (string) $_GET['evreg_requeued'];
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				$ok ? 'success' : 'error',
				$ok ? esc_html__( 'Mail wznowiony — trafi do najbliższego przebiegu wysyłki.', 'event-registration' ) : esc_html__( 'Nie udało się wznowić maila.', 'event-registration' )
			);
		}

		echo '<form method="get">';
		printf( '<input type="hidden" name="post_type" value="%s" />', esc_attr( EventPostType::POST_TYPE ) );
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( self::SLUG ) );
		$table->display();
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Renderuje ekran szczegółów jednego wiersza (read-only).
	 *
	 * @param int $id ID wiersza.
	 */
	private static function render_detail( int $id ): void {
		$row      = ( new MailQueueRepository() )->find( $id );
		$back_url = add_query_arg(
			array(
				'post_type' => EventPostType::POST_TYPE,
				'page'      => self::SLUG,
			),
			admin_url( 'edit.php' )
		);

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Szczegóły maila', 'event-registration' ) . '</h1>';
		printf( '<p><a href="%s">%s</a></p>', esc_url( $back_url ), esc_html__( '← wróć do kolejki', 'event-registration' ) );

		if ( null === $row ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Nie znaleziono wiersza kolejki.', 'event-registration' ) . '</p></div></div>';
			return;
		}

		$title = get_the_title( (int) $row['event_id'] );

		echo '<table class="widefat striped"><tbody>';
		self::detail_row( __( 'Status', 'event-registration' ), (string) $row['status'] );
		self::detail_row( __( 'Odbiorca', 'event-registration' ), (string) $row['recipient'] );
		self::detail_row( __( 'Wydarzenie', 'event-registration' ), '' === $title ? '#' . (int) $row['event_id'] : $title );
		self::detail_row( __( 'Typ maila', 'event-registration' ), (string) $row['template_key'] );
		self::detail_row( __( 'Próby', 'event-registration' ), (string) $row['attempts'] );
		self::detail_row( __( 'Zaplanowano', 'event-registration' ), (string) $row['scheduled_at'] );
		self::detail_row( __( 'Wysłano', 'event-registration' ), (string) ( $row['sent_at'] ?? '' ) );
		self::detail_row( __( 'Nagłówki', 'event-registration' ), (string) ( $row['headers'] ?? '' ) );
		self::detail_row( __( 'Ostatni błąd', 'event-registration' ), (string) ( $row['last_error'] ?? '' ) );
		self::detail_row( __( 'Temat', 'event-registration' ), (string) $row['subject'] );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Treść', 'event-registration' ) . '</h2>';
		echo '<pre style="white-space:pre-wrap;background:#fff;border:1px solid #ccd0d4;padding:1em;">' . esc_html( (string) $row['body'] ) . '</pre>';

		if ( MailQueueRepository::STATUS_FAILED === $row['status'] ) {
			printf(
				'<p><a class="button button-primary" href="%s">%s</a></p>',
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::REQUEUE_ACTION . '&id=' . $id ), self::REQUEUE_ACTION . '_' . $id ) ),
				esc_html__( 'Wznów', 'event-registration' )
			);
		}

		echo '</div>';
	}

	/**
	 * Renderuje jeden wiersz tabeli szczegółów.
	 *
	 * @param string $label Etykieta.
	 * @param string $value Wartość (escapowana).
	 */
	private static function detail_row( string $label, string $value ): void {
		printf(
			'<tr><th scope="row" style="width:180px;">%s</th><td>%s</td></tr>',
			esc_html( $label ),
			esc_html( $value )
		);
	}

	/**
	 * Obsługuje wznowienie: nonce + cap → requeueFailed → PRG redirect.
	 */
	public static function handle_requeue(): void {
		$id = (int) ( $_POST['id'] ?? $_GET['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		check_admin_referer( self::REQUEUE_ACTION . '_' . $id );

		if ( ! current_user_can( Capabilities::CAP ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'event-registration' ) );
		}

		$ok = ( new MailQueueRepository() )->requeueFailed( $id );

		$redirect = add_query_arg(
			array(
				'post_type'      => EventPostType::POST_TYPE,
				'page'           => self::SLUG,
				'evreg_requeued' => $ok ? '1' : '0',
			),
			admin_url( 'edit.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}
}
