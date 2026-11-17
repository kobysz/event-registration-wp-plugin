<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Rest;

use EvReg\Admin\Capabilities;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use WP_REST_Request;
use WP_UnitTestCase;

final class AccommodationModeTest extends WP_UnitTestCase {

	private int $event_id;

	private int $seq = 0;

	private RegistrationRepository $repository;

	protected function setUp(): void {
		parent::setUp();

		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'booking_nights', 'locks' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		do_action( 'rest_api_init' );
		Capabilities::grant();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->event_id   = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
		$this->repository = new RegistrationRepository();
	}

	/**
	 * @param array<string,mixed> $accommodation Config noclegów.
	 * @return \WP_REST_Response
	 */
	private function put( array $accommodation ) {
		$request = new WP_REST_Request( 'PUT', "/evreg/v1/events/{$this->event_id}/config" );
		$request->set_body_params( array( 'accommodation' => $accommodation ) );
		return rest_do_request( $request );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function get_payload(): array {
		$response = rest_do_request( new WP_REST_Request( 'GET', "/evreg/v1/events/{$this->event_id}/config" ) );
		return $response->get_data();
	}

	/**
	 * @param array<int,array{key:string,seats:int}> $nights Doby zgłoszenia.
	 */
	private function register( string $status, array $nights ): int {
		++$this->seq;
		$id = $this->repository->insertRegistration(
			array(
				'event_id'    => $this->event_id,
				'type_key'    => 'uczestnik',
				'status'      => $status,
				'email'       => 'p' . $this->seq . '@example.com',
				'name'        => 'P' . $this->seq,
				'token'       => str_pad( (string) $this->seq, 32, '0', STR_PAD_LEFT ),
				'data'        => '{}',
				'price_total' => 0.0,
				'expires_at'  => '2099-01-01 00:00:00',
			)
		);
		$rows = array();
		foreach ( $nights as $night ) {
			$rows[] = array(
				'key'   => $night['key'],
				'label' => $night['key'],
				'seats' => $night['seats'],
				'price' => 100.0,
			);
		}
		$this->repository->replaceBookingNights( $id, $rows );
		return $id;
	}

	public function test_changing_the_mode_is_refused_when_the_event_has_accommodation(): void {
		$this->put( array( 'mode' => 'classic', 'packages' => array() ) );
		$this->register( 'confirmed', array( array( 'key' => 'n1', 'seats' => 1 ) ) );

		$response = $this->put( array( 'mode' => 'nights', 'nights' => array( array( 'key' => 'n1', 'seats' => 5 ) ) ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'evreg_mode_locked', $response->get_data()['code'] );
		// Blokada przed zapisem: meta zostaje, jak było.
		$saved = $this->get_payload()['accommodation'];
		$this->assertSame( 'classic', $saved['mode'] );
		$this->assertArrayNotHasKey( 'nights', $saved );
	}

	public function test_changing_the_mode_is_allowed_when_only_cancelled_registrations_exist(): void {
		$this->put( array( 'mode' => 'classic' ) );
		$this->register( 'cancelled', array( array( 'key' => 'n1', 'seats' => 1 ) ) );

		$response = $this->put( array( 'mode' => 'nights', 'nights' => array() ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'nights', $this->get_payload()['accommodation']['mode'] );
	}

	public function test_saving_the_same_mode_is_not_refused(): void {
		$this->put( array( 'mode' => 'nights', 'nights' => array( array( 'key' => 'n1', 'seats' => 5 ) ) ) );
		$this->register( 'confirmed', array( array( 'key' => 'n1', 'seats' => 1 ) ) );

		$same = $this->put( array( 'mode' => 'nights', 'nights' => array( array( 'key' => 'n1', 'seats' => 9 ) ) ) );

		$this->assertSame( 200, $same->get_status() );
		$this->assertSame( 9, $this->get_payload()['accommodation']['nights'][0]['seats'] );
	}

	public function test_a_missing_mode_key_counts_as_classic_and_is_not_a_change(): void {
		$this->put( array( 'packages' => array() ) );
		$this->register( 'confirmed', array( array( 'key' => 'n1', 'seats' => 1 ) ) );

		$response = $this->put( array( 'mode' => 'classic', 'packages' => array() ) );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_payload_reports_the_lock_and_the_per_night_counts(): void {
		$this->put( array( 'mode' => 'nights', 'nights' => array( array( 'key' => 'n1', 'seats' => 5 ), array( 'key' => 'n2', 'seats' => 5 ) ) ) );
		$this->register( 'confirmed', array( array( 'key' => 'n1', 'seats' => 2 ), array( 'key' => 'n2', 'seats' => 2 ) ) );
		$this->register( 'pending', array( array( 'key' => 'n1', 'seats' => 1 ) ) );
		$this->register( 'cancelled', array( array( 'key' => 'n1', 'seats' => 1 ) ) );

		$stats = $this->get_payload()['stats'];

		$this->assertTrue( $stats['mode_locked'] );
		$this->assertSame( array( 'n1' => 3, 'n2' => 2 ), $stats['night_seats'] );
	}

	public function test_payload_stats_are_unlocked_for_an_event_without_bookings(): void {
		$stats = $this->get_payload()['stats'];

		$this->assertFalse( $stats['mode_locked'] );
		$this->assertSame( array(), $stats['night_seats'] );
	}

	public function test_sanitize_passes_the_new_keys_through(): void {
		$this->put(
			array(
				'mode'   => 'nights',
				'nights' => array(
					array( 'key' => 'n1', 'label' => '<b>Piątek</b>', 'price' => 120.5, 'seats' => 30, 'companion_cap' => 4 ),
					array( 'key' => 'n2', 'label' => 'Sobota', 'price' => 0, 'seats' => 0, 'companion_cap' => '' ),
					// Trzecia konwencja pustki, którą łatwo pomylić z dwiema wyżej:
					// companion_cap === 0 to doba ZAMKNIĘTA dla par, nie „bez limitu”.
					// Gdyby sanitize zaczęło przycinać wartości falsy, limit 0 zamieniłby
					// się po cichu w brak limitu i nikt by tego nie zauważył.
					array( 'key' => 'n3', 'label' => 'Niedziela', 'price' => 10, 'seats' => 5, 'companion_cap' => 0 ),
				),
			)
		);

		$saved = $this->get_payload()['accommodation'];

		$this->assertSame( 'nights', $saved['mode'] );
		$this->assertSame(
			array(
				array( 'key' => 'n1', 'label' => 'Piątek', 'price' => 120.5, 'seats' => 30, 'companion_cap' => 4 ),
				array( 'key' => 'n2', 'label' => 'Sobota', 'price' => 0, 'seats' => 0, 'companion_cap' => '' ),
				array( 'key' => 'n3', 'label' => 'Niedziela', 'price' => 10, 'seats' => 5, 'companion_cap' => 0 ),
			),
			$saved['nights']
		);
	}
}
