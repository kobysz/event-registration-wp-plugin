<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Rest;

use EvReg\Admin\Capabilities;
use EvReg\Mail\DefaultTemplates;
use EvReg\Persistence\MailTemplateRepository;
use WP_REST_Request;
use WP_UnitTestCase;

final class MailTemplateControllerTest extends WP_UnitTestCase {

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
		return "/evreg/v1/events/{$this->event_id}/mail-templates";
	}

	public function test_get_requires_capability(): void {
		wp_set_current_user( 0 );

		$response = rest_do_request( new WP_REST_Request( 'GET', $this->path() ) );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_get_returns_defaults_and_empty_templates(): void {
		wp_set_current_user( $this->admin_id );

		$data = rest_do_request( new WP_REST_Request( 'GET', $this->path() ) )->get_data();

		$this->assertSame( array(), $data['templates'] );
		$this->assertSame( DefaultTemplates::get( 'optin' ), $data['defaults']['optin'] );
		$this->assertCount( count( DefaultTemplates::keys() ), $data['defaults'] );
	}

	public function test_post_saves_and_get_round_trips(): void {
		wp_set_current_user( $this->admin_id );

		$post = new WP_REST_Request( 'POST', $this->path() );
		$post->set_body_params( array( 'templates' => array( 'optin' => array( 'subject' => 'Mój temat', 'body' => "Linia 1\nLinia 2" ) ) ) );
		$response = rest_do_request( $post );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Mój temat', $response->get_data()['templates']['optin']['subject'] );

		$get = rest_do_request( new WP_REST_Request( 'GET', $this->path() ) )->get_data();
		$this->assertSame( "Linia 1\nLinia 2", $get['templates']['optin']['body'] );
	}

	public function test_post_preserves_newlines_via_textarea_sanitize(): void {
		wp_set_current_user( $this->admin_id );

		$body = "Wiersz 1\n\nWiersz 3 z Michałem";
		$post = new WP_REST_Request( 'POST', $this->path() );
		$post->set_body_params( array( 'templates' => array( 'confirmed' => array( 'subject' => 'Temat', 'body' => $body ) ) ) );
		rest_do_request( $post );

		$saved = ( new MailTemplateRepository() )->get( $this->event_id );
		$this->assertSame( $body, $saved['confirmed']['body'] );
	}

	public function test_post_ignores_unknown_type(): void {
		wp_set_current_user( $this->admin_id );

		$post = new WP_REST_Request( 'POST', $this->path() );
		$post->set_body_params( array( 'templates' => array( 'nie_ma_takiego' => array( 'subject' => 'X', 'body' => 'Y' ) ) ) );
		rest_do_request( $post );

		$this->assertSame( array(), ( new MailTemplateRepository() )->get( $this->event_id ) );
	}

	public function test_post_drops_empty_type(): void {
		wp_set_current_user( $this->admin_id );

		$post = new WP_REST_Request( 'POST', $this->path() );
		$post->set_body_params( array( 'templates' => array( 'optin' => array( 'subject' => '', 'body' => '' ) ) ) );
		$data = rest_do_request( $post )->get_data();

		$this->assertSame( array(), $data['templates'] );
	}

	public function test_post_strips_html_from_body(): void {
		wp_set_current_user( $this->admin_id );

		$post = new WP_REST_Request( 'POST', $this->path() );
		$post->set_body_params( array( 'templates' => array( 'optin' => array( 'subject' => 'T', 'body' => "Tekst <script>alert(1)</script> koniec" ) ) ) );
		rest_do_request( $post );

		$saved = ( new MailTemplateRepository() )->get( $this->event_id )['optin']['body'];
		$this->assertStringNotContainsString( '<script>', $saved );
	}
}
