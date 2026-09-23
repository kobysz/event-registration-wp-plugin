<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Update;

use EvReg\Plugin;
use EvReg\Update\GitHubUpdater;
use WP_Error;
use WP_UnitTestCase;

final class GitHubUpdaterTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		delete_transient( 'evreg_update_latest' );
	}

	public function tearDown(): void {
		delete_transient( 'evreg_update_latest' );
		parent::tearDown();
	}

	/**
	 * Builds a `pre_http_request` callback returning a canned 200 response
	 * with the given release payload as JSON body.
	 *
	 * @param array<string,mixed> $release Decoded GitHub release payload.
	 */
	private function mk_response( array $release ): callable {
		return static function () use ( $release ) {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( $release ),
			);
		};
	}

	/**
	 * @param int $code HTTP status code to return.
	 */
	private function mk_error_response( int $code ): callable {
		return static function () use ( $code ) {
			return array(
				'response' => array( 'code' => $code ),
				'body'     => '',
			);
		};
	}

	private function mk_wp_error(): callable {
		return static function () {
			return new WP_Error( 'http_request_failed', 'Connection timed out' );
		};
	}

	public function test_inject_update_injects_when_version_is_newer(): void {
		add_filter(
			'pre_http_request',
			$this->mk_response(
				array(
					'tag_name' => 'v99.0.0',
					'html_url' => 'https://github.com/kobysz/event-registration-wp-plugin/releases/tag/v99.0.0',
					'body'     => 'Zmiany',
					'assets'   => array(
						array(
							'name'                 => 'event-registration.zip',
							'browser_download_url' => 'https://example.com/e.zip',
						),
					),
				)
			),
			10,
			3
		);

		$updater  = new GitHubUpdater();
		$result   = $updater->inject_update( new \stdClass() );
		$basename = plugin_basename( Plugin::plugin_file() );

		$this->assertTrue( isset( $result->response[ $basename ] ) );
		$this->assertSame( '99.0.0', $result->response[ $basename ]->new_version );
		$this->assertSame( 'https://example.com/e.zip', $result->response[ $basename ]->package );
		$this->assertSame( 'https://github.com/kobysz/event-registration-wp-plugin/releases/tag/v99.0.0', $result->response[ $basename ]->url );
		$this->assertSame( $basename, $result->response[ $basename ]->plugin );
	}

	public function test_inject_update_skips_when_version_is_equal(): void {
		add_filter(
			'pre_http_request',
			$this->mk_response(
				array(
					'tag_name' => 'v' . Plugin::version(),
					'html_url' => 'https://example.com',
					'body'     => 'Zmiany',
					'assets'   => array(
						array(
							'name'                 => 'event-registration.zip',
							'browser_download_url' => 'https://example.com/e.zip',
						),
					),
				)
			),
			10,
			3
		);

		$updater  = new GitHubUpdater();
		$result   = $updater->inject_update( new \stdClass() );
		$basename = plugin_basename( Plugin::plugin_file() );

		$this->assertFalse( isset( $result->response[ $basename ] ) );
	}

	/**
	 * Buduje odpowiedź wydania o podanym tagu z poprawnym assetem.
	 *
	 * @param string $tag Tag wydania (np. v0.1.0).
	 */
	private function mk_release( string $tag ): callable {
		return $this->mk_response(
			array(
				'tag_name' => $tag,
				'html_url' => 'https://example.com',
				'body'     => 'Zmiany',
				'assets'   => array(
					array(
						'name'                 => 'event-registration.zip',
						'browser_download_url' => 'https://example.com/e.zip',
					),
				),
			)
		);
	}

	public function test_no_update_entry_added_when_up_to_date(): void {
		// Bez wpisu w response ANI no_update rdzeń WP ustawia update-supported=false
		// i ukrywa przełącznik „Włącz automatyczne aktualizacje".
		add_filter( 'pre_http_request', $this->mk_release( 'v' . Plugin::version() ), 10, 3 );

		$result   = ( new GitHubUpdater() )->inject_update( new \stdClass() );
		$basename = plugin_basename( Plugin::plugin_file() );

		$this->assertTrue( isset( $result->no_update[ $basename ] ) );
		$this->assertSame( Plugin::version(), $result->no_update[ $basename ]->new_version );
		$this->assertSame( $basename, $result->no_update[ $basename ]->plugin );
		$this->assertSame( dirname( $basename ), $result->no_update[ $basename ]->slug );
		$this->assertFalse( isset( $result->response[ $basename ] ) );
	}

	public function test_no_update_entry_added_when_release_is_older(): void {
		add_filter( 'pre_http_request', $this->mk_release( 'v0.0.1' ), 10, 3 );

		$result   = ( new GitHubUpdater() )->inject_update( new \stdClass() );
		$basename = plugin_basename( Plugin::plugin_file() );

		$this->assertTrue( isset( $result->no_update[ $basename ] ) );
		$this->assertFalse( isset( $result->response[ $basename ] ) );
	}

	public function test_stale_response_entry_is_cleared_when_up_to_date(): void {
		add_filter( 'pre_http_request', $this->mk_release( 'v' . Plugin::version() ), 10, 3 );

		$basename            = plugin_basename( Plugin::plugin_file() );
		$transient           = new \stdClass();
		$transient->response = array( $basename => (object) array( 'new_version' => '99.0.0' ) );

		$result = ( new GitHubUpdater() )->inject_update( $transient );

		$this->assertFalse( isset( $result->response[ $basename ] ), 'Nieaktualny wpis o aktualizacji musi zniknąć.' );
		$this->assertTrue( isset( $result->no_update[ $basename ] ) );
	}

	public function test_stale_no_update_entry_is_cleared_when_newer_exists(): void {
		add_filter( 'pre_http_request', $this->mk_release( 'v99.0.0' ), 10, 3 );

		$basename             = plugin_basename( Plugin::plugin_file() );
		$transient            = new \stdClass();
		$transient->no_update = array( $basename => (object) array( 'new_version' => Plugin::version() ) );

		$result = ( new GitHubUpdater() )->inject_update( $transient );

		$this->assertTrue( isset( $result->response[ $basename ] ) );
		$this->assertFalse( isset( $result->no_update[ $basename ] ) );
	}

	public function test_no_entries_when_github_unavailable(): void {
		add_filter( 'pre_http_request', $this->mk_wp_error(), 10, 3 );

		$result   = ( new GitHubUpdater() )->inject_update( new \stdClass() );
		$basename = plugin_basename( Plugin::plugin_file() );

		$this->assertFalse( isset( $result->response[ $basename ] ) );
		$this->assertFalse( isset( $result->no_update[ $basename ] ) );
	}

	public function test_inject_update_skips_when_asset_missing(): void {
		add_filter(
			'pre_http_request',
			$this->mk_response(
				array(
					'tag_name' => 'v99.0.0',
					'html_url' => 'https://example.com',
					'body'     => 'Zmiany',
					'assets'   => array(
						array(
							'name'                 => 'some-other-file.zip',
							'browser_download_url' => 'https://example.com/e.zip',
						),
					),
				)
			),
			10,
			3
		);

		$updater  = new GitHubUpdater();
		$result   = $updater->inject_update( new \stdClass() );
		$basename = plugin_basename( Plugin::plugin_file() );

		$this->assertFalse( isset( $result->response[ $basename ] ) );
	}

	public function test_inject_update_skips_on_http_error_status(): void {
		add_filter( 'pre_http_request', $this->mk_error_response( 500 ), 10, 3 );

		$updater  = new GitHubUpdater();
		$result   = $updater->inject_update( new \stdClass() );
		$basename = plugin_basename( Plugin::plugin_file() );

		$this->assertFalse( isset( $result->response[ $basename ] ) );
	}

	public function test_inject_update_skips_on_wp_error(): void {
		add_filter( 'pre_http_request', $this->mk_wp_error(), 10, 3 );

		$updater  = new GitHubUpdater();
		$result   = $updater->inject_update( new \stdClass() );
		$basename = plugin_basename( Plugin::plugin_file() );

		$this->assertFalse( isset( $result->response[ $basename ] ) );
	}

	public function test_negative_cache_prevents_second_http_call_after_error(): void {
		$calls = 0;
		add_filter(
			'pre_http_request',
			function () use ( &$calls ) {
				++$calls;
				return array(
					'response' => array( 'code' => 500 ),
					'body'     => '',
				);
			},
			10,
			3
		);

		$updater = new GitHubUpdater();
		$updater->inject_update( new \stdClass() );
		$updater->inject_update( new \stdClass() );

		$this->assertSame( 1, $calls );
	}

	public function test_cache_hit_prevents_second_http_call_after_success(): void {
		$calls = 0;
		add_filter(
			'pre_http_request',
			function () use ( &$calls ) {
				++$calls;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'tag_name' => 'v99.0.0',
							'html_url' => 'https://example.com',
							'body'     => 'Zmiany',
							'assets'   => array(
								array(
									'name'                 => 'event-registration.zip',
									'browser_download_url' => 'https://example.com/e.zip',
								),
							),
						)
					),
				);
			},
			10,
			3
		);

		$updater = new GitHubUpdater();
		$updater->inject_update( new \stdClass() );
		$updater->inject_update( new \stdClass() );

		$this->assertSame( 1, $calls );
	}

	public function test_inject_update_returns_non_object_unchanged(): void {
		$updater = new GitHubUpdater();
		$this->assertFalse( $updater->inject_update( false ) );
	}

	public function test_plugins_api_returns_info_for_matching_slug(): void {
		add_filter(
			'pre_http_request',
			$this->mk_response(
				array(
					'tag_name' => 'v99.0.0',
					'html_url' => 'https://example.com',
					'body'     => 'Zmiany w wersji',
					'assets'   => array(
						array(
							'name'                 => 'event-registration.zip',
							'browser_download_url' => 'https://example.com/e.zip',
						),
					),
				)
			),
			10,
			3
		);

		$updater = new GitHubUpdater();
		$slug    = dirname( plugin_basename( Plugin::plugin_file() ) );
		$result  = $updater->plugins_api( false, 'plugin_information', (object) array( 'slug' => $slug ) );

		$this->assertSame( '99.0.0', $result->version );
		$this->assertSame( 'https://example.com/e.zip', $result->download_link );
		$this->assertStringContainsString( 'Zmiany w wersji', $result->sections['changelog'] );
	}

	public function test_plugins_api_passes_through_for_other_action(): void {
		$updater = new GitHubUpdater();
		$original = (object) array( 'foo' => 'bar' );
		$result   = $updater->plugins_api( $original, 'other_action', (object) array( 'slug' => 'event-registration' ) );

		$this->assertSame( $original, $result );
	}

	public function test_plugins_api_passes_through_for_other_slug(): void {
		$updater  = new GitHubUpdater();
		$original = (object) array( 'foo' => 'bar' );
		$result   = $updater->plugins_api( $original, 'plugin_information', (object) array( 'slug' => 'some-other-plugin' ) );

		$this->assertSame( $original, $result );
	}
}
