<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Rest;

use EvReg\Admin\Capabilities;
use WP_REST_Request;
use WP_UnitTestCase;

final class I18nControllerTest extends WP_UnitTestCase {

	private int $event_id;

	private int $admin_id;

	protected function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		$this->event_id = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
		Capabilities::grant();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	private function path(): string {
		return "/evreg/v1/events/{$this->event_id}/i18n";
	}

	public function test_get_requires_capability(): void {
		wp_set_current_user( 0 );

		$response = rest_do_request( new WP_REST_Request( 'GET', $this->path() ) );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_non_privileged_user_forbidden(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$response = rest_do_request( new WP_REST_Request( 'GET', $this->path() ) );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_get_returns_empty_overlay_by_default(): void {
		wp_set_current_user( $this->admin_id );

		$data = rest_do_request( new WP_REST_Request( 'GET', $this->path() ) )->get_data();

		$this->assertSame( array(), $data );
	}

	public function test_post_saves_and_get_round_trips(): void {
		wp_set_current_user( $this->admin_id );

		$post = new WP_REST_Request( 'POST', $this->path() );
		$post->set_body_params(
			array(
				'en' => array(
					'fields' => array(
						'imie' => 'First name',
					),
				),
			)
		);
		$response = rest_do_request( $post );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'First name', $response->get_data()['en']['fields']['imie'] );

		$get = rest_do_request( new WP_REST_Request( 'GET', $this->path() ) )->get_data();
		$this->assertSame( 'First name', $get['en']['fields']['imie'] );
	}

	public function test_post_sanitizes_html_from_leaves(): void {
		wp_set_current_user( $this->admin_id );

		$post = new WP_REST_Request( 'POST', $this->path() );
		$post->set_body_params(
			array(
				'en' => array(
					'fields' => array(
						'imie' => 'First <script>alert(1)</script> name',
					),
				),
			)
		);
		$data = rest_do_request( $post )->get_data();

		$this->assertStringNotContainsString( '<script>', $data['en']['fields']['imie'] );
	}

	public function test_companion_note_leaf_keeps_rich_text_but_a_field_keyed_note_stays_plain(): void {
		wp_set_current_user( $this->admin_id );

		$post = new WP_REST_Request( 'POST', $this->path() );
		$post->set_body_params(
			array(
				'en' => array(
					'accommodation' => array(
						'packages'  => array( 'note' => '<script>x()</script><a href="https://example.com">link</a> plain' ),
						'companion' => array(
							'other' => '<script>x()</script><a href="https://example.com">link</a> plain',
							'note' => "Line one
<a href=\"https://example.com\">link</a> <strong>bold</strong><script>alert(1)</script>",
						),
					),
					'fields'        => array(
						'note' => '<a href="https://example.com">link</a> plain',
					),
				),
			)
		);
		$data = rest_do_request( $post )->get_data();

		$note = $data['en']['accommodation']['companion']['note'];
		$this->assertStringContainsString( '<a href="https://example.com">link</a>', $note );
		$this->assertStringContainsString( '<strong>bold</strong>', $note );
		$this->assertStringContainsString( "
", $note );
		$this->assertStringNotContainsString( '<script', $note );
		$this->assertSame( 'link plain', $data['en']['fields']['note'] );
		$this->assertSame( 'link plain', $data['en']['accommodation']['packages']['note'] );
		$this->assertSame( 'link plain', $data['en']['accommodation']['companion']['other'] );
	}

	private function store_overlay(): void {
		( new \EvReg\Persistence\EventConfigRepository() )->saveI18n(
			$this->event_id,
			array( 'en' => array( 'fields' => array( 'imie' => 'First name' ) ) )
		);
	}

	public function test_post_refuses_to_wipe_a_stored_overlay_with_an_empty_payload(): void {
		wp_set_current_user( $this->admin_id );
		$this->store_overlay();

		$response = rest_do_request( new WP_REST_Request( 'POST', $this->path() ) );

		$this->assertSame( 409, $response->get_status() );
		$get = rest_do_request( new WP_REST_Request( 'GET', $this->path() ) )->get_data();
		$this->assertSame( 'First name', $get['en']['fields']['imie'] );
	}

	public function test_post_clears_the_overlay_when_clearing_is_requested(): void {
		wp_set_current_user( $this->admin_id );
		$this->store_overlay();

		$post = new WP_REST_Request( 'POST', $this->path() );
		$post->set_query_params( array( 'clear' => '1' ) );
		$response = rest_do_request( $post );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $response->get_data() );
	}

	public function test_post_accepts_an_empty_payload_when_nothing_is_stored(): void {
		wp_set_current_user( $this->admin_id );

		$response = rest_do_request( new WP_REST_Request( 'POST', $this->path() ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $response->get_data() );
	}
}
