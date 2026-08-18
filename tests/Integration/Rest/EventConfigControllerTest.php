<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Rest;

use EvReg\Admin\Capabilities;
use EvReg\Rest\EventConfigController;
use WP_REST_Request;
use WP_UnitTestCase;

final class EventConfigControllerTest extends WP_UnitTestCase {

	private int $event_id;

	private int $admin_id;

	protected function setUp(): void {
		parent::setUp();

		do_action( 'rest_api_init' );

		$this->event_id = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );

		Capabilities::grant();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function valid_body(): array {
		return array(
			'schema'        => array(
				'version'  => 1,
				'sections' => array(
					array(
						'key'    => 'dane',
						'title'  => 'Dane',
						'fields' => array(
							array( 'key' => '__type', 'type' => 'radio', 'label' => 'Typ' ),
							array( 'key' => 'email', 'type' => 'email', 'label' => 'E-mail', 'required' => true ),
						),
					),
				),
			),
			'types'         => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 450.0 ) ),
			'accommodation' => array( 'packages' => array(), 'rooms' => array(), 'inventory' => array() ),
			'settings'      => array( 'global_cap' => 200, 'waitlist_enabled' => true ),
		);
	}

	public function test_get_requires_capability(): void {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', "/evreg/v1/events/{$this->event_id}/config" );
		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_authenticated_user_without_capability_gets_403(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$request  = new WP_REST_Request( 'GET', "/evreg/v1/events/{$this->event_id}/config" );
		$response = rest_do_request( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_put_saves_and_returns_valid_report(): void {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'PUT', "/evreg/v1/events/{$this->event_id}/config" );
		$request->set_body_params( $this->valid_body() );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['validation']['valid'] );
		$this->assertSame( array(), $data['validation']['errors'] );
	}

	public function test_get_after_put_returns_saved_config(): void {
		wp_set_current_user( $this->admin_id );

		$put = new WP_REST_Request( 'PUT', "/evreg/v1/events/{$this->event_id}/config" );
		$put->set_body_params( $this->valid_body() );
		rest_do_request( $put );

		$get      = new WP_REST_Request( 'GET', "/evreg/v1/events/{$this->event_id}/config" );
		$response = rest_do_request( $get );
		$data     = $response->get_data();

		$this->assertSame( $this->valid_body()['types'], $data['types'] );
		$this->assertSame( 200, $data['settings']['global_cap'] );
	}

	public function test_put_saves_invalid_config_but_reports_invalid(): void {
		wp_set_current_user( $this->admin_id );

		$body = $this->valid_body();
		// Usuń typy — pole __type zostanie bez opcji, złożona schema niepoprawna.
		$body['types'] = array();

		$request = new WP_REST_Request( 'PUT', "/evreg/v1/events/{$this->event_id}/config" );
		$request->set_body_params( $body );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( $data['validation']['valid'] );
		$this->assertNotEmpty( $data['validation']['errors'] );
		$this->assertArrayHasKey( 'code', $data['validation']['errors'][0] );

		// Mimo niepoprawności dane zostały zapisane.
		$get      = new WP_REST_Request( 'GET', "/evreg/v1/events/{$this->event_id}/config" );
		$get_data = rest_do_request( $get )->get_data();
		$this->assertSame( array(), $get_data['types'] );
	}

	public function test_put_sanitizes_string_values(): void {
		wp_set_current_user( $this->admin_id );

		$body                            = $this->valid_body();
		$body['types'][0]['label']       = '<script>alert(1)</script>Uczestnik';
		$body['settings']['global_cap']  = 200;

		$request = new WP_REST_Request( 'PUT', "/evreg/v1/events/{$this->event_id}/config" );
		$request->set_body_params( $body );
		rest_do_request( $request );

		$get      = new WP_REST_Request( 'GET', "/evreg/v1/events/{$this->event_id}/config" );
		$data     = rest_do_request( $get )->get_data();
		$saved    = $data['types'][0]['label'];

		$this->assertStringNotContainsString( '<script>', $saved );
		$this->assertStringContainsString( 'Uczestnik', $saved );
		// Wartości nie-łańcuchowe zachowane bez zmian typu.
		$this->assertSame( 200, $data['settings']['global_cap'] );
	}
}
