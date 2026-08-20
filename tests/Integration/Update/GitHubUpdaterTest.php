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
