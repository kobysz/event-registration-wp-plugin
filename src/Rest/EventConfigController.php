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
use EvReg\Domain\Registration\RegistrationStatus;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\RegistrationRepository;
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
	 * Zapisuje konfigurację (walidacja nie blokuje zapisu) i zwraca ją z raportem walidacji.
	 *
	 * @param WP_REST_Request $request Żądanie REST.
	 *
	 * @throws \Throwable Gdy zapis w transakcji się nie powiedzie (ROLLBACK — czyli
	 *                    zwolnienie zamka eventu — przed ponownym rzuceniem).
	 */
	public function update_config( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$event_id = (int) $request['id'];

		$incoming = array();

		foreach ( array( 'schema', 'types', 'accommodation', 'settings' ) as $key ) {
			$value = $request->get_param( $key );

			if ( is_array( $value ) ) {
				$incoming[ $key ] = $this->sanitize( $value );
			}
		}

		$registrations = new RegistrationRepository();

		// Sprawdzenie i zapis pod TYM SAMYM zamkiem wiersza evreg_locks, który biorą
		// ścieżki rezerwacji: inaczej zgłoszenie z noclegiem zatwierdzone MIĘDZY
		// sprawdzeniem a zapisem przeszłoby bramkę, a zapis przełączyłby tryb i zostawił
		// booking, którego sens już nie pasuje do trybu. Zamek trzymamy tylko na czas
		// odczytu zajętości i czterech update_post_meta() — budowa odpowiedzi (kolejne
		// odczyty zajętości) leży już po COMMIT, żeby żadna rezerwacja nie czekała dłużej,
		// niż trzeba. KOLEJNOŚĆ: lockEvent() przed pierwszym odczytem zajętości (jak w
		// ReservationService::reserve) — NIE ZMIENIAĆ.
		$registrations->beginTransaction();

		try {
			$registrations->lockEvent( $event_id );

			// Serwer jest arbitrem: zmiana trybu noclegów przy istniejących noclegach
			// zmieniłaby sens zapisanych rezerwacji, więc odmowa idzie PRZED zapisem.
			if ( isset( $incoming['accommodation'] ) && $this->mode_change_locked( $registrations, $event_id, $incoming['accommodation'] ) ) {
				$registrations->rollback();

				return new WP_Error(
					'evreg_mode_locked',
					__( 'Nie można zmienić trybu noclegów: wydarzenie ma już zgłoszenia z noclegiem. Anuluj te zgłoszenia, aby zmienić tryb.', 'event-registration' ),
					array( 'status' => 409 )
				);
			}

			$this->repository->save( $event_id, $incoming );

			$registrations->commit();
		} catch ( \Throwable $e ) {
			$registrations->rollback();
			throw $e;
		}

		return new WP_REST_Response( $this->build_payload( $event_id ), 200 );
	}

	/**
	 * Czy zapis zmienia tryb noclegów, a event ma już żywe zgłoszenia z noclegiem.
	 *
	 * Brak klucza `mode` znaczy tryb klasyczny — tak samo jak w domenie.
	 *
	 * Wołać WYŁĄCZNIE pod zamkiem eventu: odczyt zajętości musi być w tej samej
	 * transakcji co zapis, inaczej wraca wyścig B17.
	 *
	 * @param RegistrationRepository $registrations Repozytorium zgłoszeń trzymające zamek.
	 * @param int                    $event_id      ID eventu.
	 * @param array<mixed>           $incoming      Przychodzący (już sanityzowany) config noclegów.
	 */
	private function mode_change_locked( RegistrationRepository $registrations, int $event_id, array $incoming ): bool {
		$stored = $this->repository->get( $event_id )['accommodation'];

		if ( $this->mode_of( $incoming ) === $this->mode_of( $stored ) ) {
			return false;
		}

		return $registrations->eventHasAccommodation( $event_id );
	}

	/**
	 * Tryb noclegów z surowego configu: 'nights' albo (domyślnie) 'classic'.
	 *
	 * @param array<mixed> $accommodation Config noclegów.
	 */
	private function mode_of( array $accommodation ): string {
		return 'nights' === ( $accommodation['mode'] ?? 'classic' ) ? 'nights' : 'classic';
	}

	/**
	 * Klucze bool-owe gałęzi noclegowej (`_evreg_accommodation`) — niezależnie
	 * od tego, co przyśle klient (string "1"/"" itp.), zapisujemy je jako
	 * rzeczywisty PHP bool.
	 */
	private const ACCOMMODATION_BOOL_KEYS = array( 'companion_enabled', 'companion_counts_event' );

	/**
	 * Klucze z treścią formatowaną — sanitize_text_field wycina tam tagi i zjada
	 * znaki nowej linii, więc opisy (pola i opcji) przepuszczamy przez
	 * wp_kses_post(): link i pogrubienie zostają, skrypty i atrybuty zdarzeń nie.
	 * Autorem treści jest admin z capem edycji wydarzeń — ten sam poziom zaufania
	 * co dla treści wpisu.
	 */
	private const RICH_TEXT_KEYS = array( 'description' );

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
			} elseif ( is_string( $k ) && in_array( $k, self::ACCOMMODATION_BOOL_KEYS, true ) ) {
				$clean[ $k ] = (bool) $v;
			} elseif ( is_string( $v ) && is_string( $k ) && in_array( $k, self::RICH_TEXT_KEYS, true ) ) {
				$clean[ $k ] = wp_kses_post( $v );
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
			'stats'         => $this->stats( $event_id ),
		);
	}

	/**
	 * Statystyki dla edytora: blokada zmiany trybu i zajęte miejsca per doba.
	 *
	 * @param int $event_id ID eventu.
	 * @return array{mode_locked: bool, night_seats: array<string,int>}
	 */
	private function stats( int $event_id ): array {
		$registrations = new RegistrationRepository();
		$seats         = $registrations->nightOccupancy( $event_id, RegistrationStatus::occupyingValues() )->seatsByNight();

		return array(
			'mode_locked' => $registrations->eventHasAccommodation( $event_id ),
			'night_seats' => $seats,
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
