<?php
/**
 * Endpoint REST nadpisań tłumaczeń treści eventu (overlay).
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Rest;

use EvReg\Admin\Capabilities;
use EvReg\Admin\EventPostType;
use EvReg\Persistence\EventConfigRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Endpoint REST nadpisań tłumaczeń treści eventu (overlay).
 *
 * Osobny od EventConfigController i MailTemplateController: overlay to
 * dowolnie zagnieżdżona mapa lang → sekcja → klucz, sanitowana rekurencyjnie.
 */
final class I18nController {

	public const REST_NAMESPACE = 'evreg/v1';

	/**
	 * Repozytorium konfiguracji eventu.
	 *
	 * @var EventConfigRepository
	 */
	private EventConfigRepository $repository;

	/**
	 * Tworzy kontroler z repozytorium.
	 */
	public function __construct() {
		$this->repository = new EventConfigRepository();
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
			'/events/(?P<id>\d+)/i18n',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_overlay' ),
					'permission_callback' => array( $this, 'can_edit' ),
					'args'                => array(
						'id' => array( 'validate_callback' => static fn ( $value ): bool => is_numeric( $value ) ),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_overlay' ),
					'permission_callback' => array( $this, 'can_edit' ),
					'args'                => array(
						'id' => array( 'validate_callback' => static fn ( $value ): bool => is_numeric( $value ) ),
					),
				),
			)
		);
	}

	/**
	 * Sprawdza uprawnienia do edycji overlayu tłumaczeń eventu.
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
	 * Zwraca zapisany overlay tłumaczeń.
	 *
	 * @param WP_REST_Request $request Żądanie REST.
	 */
	public function get_overlay( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( $this->repository->getI18n( (int) $request['id'] ), 200 );
	}

	/**
	 * Sanityzuje i zapisuje overlay, po czym zwraca świeży stan.
	 *
	 * @param WP_REST_Request $request Żądanie REST.
	 */
	public function update_overlay( WP_REST_Request $request ): WP_REST_Response {
		$event_id = (int) $request['id'];
		$incoming = $request->get_json_params();

		if ( ! is_array( $incoming ) ) {
			$incoming = $request->get_body_params();
		}

		if ( ! is_array( $incoming ) ) {
			$incoming = array();
		}

		$clean = $this->sanitize( $incoming );
		$clean = is_array( $clean ) ? $clean : array();

		$this->repository->saveI18n( $event_id, $clean );

		return new WP_REST_Response( $this->repository->getI18n( $event_id ), 200 );
	}

	/**
	 * Sanityzuje zagnieżdżoną mapę tłumaczeń: liście przez sanitize_text_field.
	 *
	 * @param mixed $value Surowa wartość z żądania.
	 *
	 * @return array<string,mixed>|string
	 */
	private function sanitize( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$out[ (string) $k ] = $this->sanitize( $v );
			}
			return $out;
		}
		return sanitize_text_field( (string) $value );
	}
}
