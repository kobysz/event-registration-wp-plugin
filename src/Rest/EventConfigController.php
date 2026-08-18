<?php
/**
 * Endpoint REST konfiguracji eventu — jeden zasób na cały config.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Rest;

use EvReg\Admin\Capabilities;
use EvReg\Domain\Schema\SchemaException;
use EvReg\Domain\Schema\SchemaAssembler;
use EvReg\Persistence\EventConfigRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Endpoint REST konfiguracji eventu — jeden zasób na cały config.
 */
final class EventConfigController {

	public const REST_NAMESPACE = 'evreg/v1';

	/**
	 * Repozytorium konfiguracji eventu.
	 *
	 * @var EventConfigRepository
	 */
	private EventConfigRepository $repository;

	/**
	 * Assembler składający i walidujący pełną schemę.
	 *
	 * @var SchemaAssembler
	 */
	private SchemaAssembler $assembler;

	/**
	 * Tworzy kontroler wraz z jego zależnościami.
	 */
	public function __construct() {
		$this->repository = new EventConfigRepository();
		$this->assembler  = new SchemaAssembler();
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
	 * Rejestruje trasy GET/PUT.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/events/(?P<id>\d+)/config',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_config' ),
					'permission_callback' => array( $this, 'can_edit' ),
					'args'                => array(
						'id' => array( 'validate_callback' => static fn ( $value ): bool => is_numeric( $value ) ),
					),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_config' ),
					'permission_callback' => array( $this, 'can_edit' ),
					'args'                => array(
						'id' => array( 'validate_callback' => static fn ( $value ): bool => is_numeric( $value ) ),
					),
				),
			)
		);
	}

	/**
	 * Sprawdza uprawnienia do edycji konfiguracji eventu.
	 *
	 * @param WP_REST_Request $request Żądanie REST.
	 * @return bool|WP_Error
	 */
	public function can_edit( WP_REST_Request $request ) {
		$event_id = (int) $request['id'];

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
	 * Zwraca konfigurację eventu wraz z raportem walidacji.
	 *
	 * @param WP_REST_Request $request Żądanie REST.
	 */
	public function get_config( WP_REST_Request $request ): WP_REST_Response {
		$event_id = (int) $request['id'];

		return new WP_REST_Response( $this->build_payload( $event_id ), 200 );
	}

	/**
	 * Zapisuje konfigurację (nieblokująco) i zwraca ją z raportem walidacji.
	 *
	 * @param WP_REST_Request $request Żądanie REST.
	 */
	public function update_config( WP_REST_Request $request ): WP_REST_Response {
		$event_id = (int) $request['id'];

		$incoming = array();

		foreach ( array( 'schema', 'types', 'accommodation', 'settings' ) as $key ) {
			$value = $request->get_param( $key );

			if ( is_array( $value ) ) {
				$incoming[ $key ] = $this->sanitize( $value );
			}
		}

		$this->repository->save( $event_id, $incoming );

		return new WP_REST_Response( $this->build_payload( $event_id ), 200 );
	}

	/**
	 * Rekurencyjnie sanityzuje łańcuchowe liście konfiguracji, zachowując
	 * strukturę i typy nie-łańcuchowe. Config nie zawiera treści wieloliniowej
	 * ani HTML — etykiety, klucze, daty, e-maile i liczby są jednoliniowe.
	 *
	 * @param array<mixed> $value Fragment konfiguracji.
	 * @return array<mixed>
	 */
	private function sanitize( array $value ): array {
		$clean = array();

		foreach ( $value as $k => $v ) {
			if ( is_array( $v ) ) {
				$clean[ $k ] = $this->sanitize( $v );
			} elseif ( is_string( $v ) ) {
				$clean[ $k ] = sanitize_text_field( $v );
			} else {
				$clean[ $k ] = $v;
			}
		}

		return $clean;
	}

	/**
	 * Buduje odpowiedź: config z repozytorium + raport walidacji złożonej schemy.
	 *
	 * @param int $event_id ID posta eventu.
	 * @return array<string,mixed>
	 */
	private function build_payload( int $event_id ): array {
		$config = $this->repository->get( $event_id );

		return array(
			'schema'        => $config['schema'],
			'types'         => $config['types'],
			'accommodation' => $config['accommodation'],
			'settings'      => $config['settings'],
			'validation'    => $this->validation_report( $config ),
		);
	}

	/**
	 * Waliduje złożoną schemę i zwraca raport.
	 *
	 * @param array<string,mixed> $config Config z repozytorium.
	 * @return array{valid: bool, errors: array<int, array{code: string, detail: string}>}
	 */
	private function validation_report( array $config ): array {
		try {
			$this->assembler->validate(
				is_array( $config['schema'] ) ? $config['schema'] : array(),
				is_array( $config['types'] ) ? $config['types'] : array(),
				is_array( $config['accommodation'] ) ? $config['accommodation'] : array()
			);

			return array(
				'valid'  => true,
				'errors' => array(),
			);
		} catch ( SchemaException $e ) {
			return array(
				'valid'  => false,
				'errors' => array(
					array(
						'code'   => 'schema_invalid',
						'detail' => $e->getMessage(),
					),
				),
			);
		}
	}
}
