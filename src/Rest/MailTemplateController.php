<?php
/**
 * Endpoint REST edycji szablonów maili eventu.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Rest;

use EvReg\Admin\Capabilities;
use EvReg\Admin\EventPostType;
use EvReg\Mail\DefaultTemplates;
use EvReg\Persistence\MailTemplateRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Endpoint REST edycji szablonów maili eventu.
 *
 * Osobny od EventConfigController: treść jest wieloliniowa i wymaga
 * sanitize_textarea_field, a kontrakt konfiguracji nie może się zmienić.
 */
final class MailTemplateController {

	public const REST_NAMESPACE = 'evreg/v1';

	/**
	 * Repozytorium szablonów.
	 *
	 * @var MailTemplateRepository
	 */
	private MailTemplateRepository $repository;

	/**
	 * Tworzy kontroler z repozytorium.
	 */
	public function __construct() {
		$this->repository = new MailTemplateRepository();
	}

	/**
	 * Podpina rejestrację tras.
	 */
	public static function register(): void {
		add_action(
			'rest_api_init',
			static function (): void {
				( new self() )->register_routes();
			}
		);
	}

	/**
	 * Rejestruje trasy GET/POST.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/events/(?P<id>\d+)/mail-templates',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_templates' ),
					'permission_callback' => array( $this, 'can_edit' ),
					'args'                => array(
						'id' => array( 'validate_callback' => static fn ( $value ): bool => is_numeric( $value ) ),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_templates' ),
					'permission_callback' => array( $this, 'can_edit' ),
					'args'                => array(
						'id' => array( 'validate_callback' => static fn ( $value ): bool => is_numeric( $value ) ),
					),
				),
			)
		);
	}

	/**
	 * Sprawdza uprawnienia do edycji szablonów eventu.
	 *
	 * @param WP_REST_Request $request Żądanie REST.
	 * @return bool|WP_Error
	 */
	public function can_edit( WP_REST_Request $request ) {
		$event_id = (int) $request['id'];

		if ( EventPostType::POST_TYPE !== get_post_type( $event_id ) ) {
			return new WP_Error(
				'evreg_not_found',
				__( 'Nie znaleziono wydarzenia.', 'event-registration' ),
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( Capabilities::CAP ) || ! current_user_can( 'edit_post', $event_id ) ) {
			return new WP_Error(
				'evreg_forbidden',
				__( 'Brak uprawnień do edycji tego wydarzenia.', 'event-registration' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Zwraca zapisane nadpisania i teksty domyślne.
	 *
	 * @param WP_REST_Request $request Żądanie REST.
	 */
	public function get_templates( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( $this->payload( (int) $request['id'] ), 200 );
	}

	/**
	 * Sanityzuje i zapisuje nadpisania, po czym zwraca świeży stan.
	 *
	 * @param WP_REST_Request $request Żądanie REST.
	 */
	public function update_templates( WP_REST_Request $request ): WP_REST_Response {
		$event_id = (int) $request['id'];
		$incoming = $request->get_param( 'templates' );
		$clean    = array();

		if ( is_array( $incoming ) ) {
			foreach ( DefaultTemplates::keys() as $type ) {
				if ( ! isset( $incoming[ $type ] ) || ! is_array( $incoming[ $type ] ) ) {
					continue;
				}

				$clean[ $type ] = array(
					'subject' => sanitize_text_field( (string) ( $incoming[ $type ]['subject'] ?? '' ) ),
					'body'    => sanitize_textarea_field( (string) ( $incoming[ $type ]['body'] ?? '' ) ),
				);
			}
		}

		$this->repository->save( $event_id, $clean );

		return new WP_REST_Response( $this->payload( $event_id ), 200 );
	}

	/**
	 * Buduje odpowiedź: zapisane nadpisania + teksty domyślne.
	 *
	 * @param int $event_id ID posta eventu.
	 * @return array{templates: array<string,array<string,string>>, defaults: array<string,array{subject:string,body:string}>}
	 */
	private function payload( int $event_id ): array {
		$defaults = array();

		foreach ( DefaultTemplates::keys() as $type ) {
			$defaults[ $type ] = DefaultTemplates::get( $type );
		}

		return array(
			'templates' => $this->repository->get( $event_id ),
			'defaults'  => $defaults,
		);
	}
}
