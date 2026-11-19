<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Admin;

use EvReg\Admin\EventConfigAssets;
use EvReg\Admin\EventPostType;
use EvReg\Frontend\DefaultNotices;
use WP_UnitTestCase;

final class EventConfigAssetsTest extends WP_UnitTestCase {

	public function test_assets_not_enqueued_outside_event_screen(): void {
		EventConfigAssets::register();

		set_current_screen( 'edit.php' );
		do_action( 'admin_enqueue_scripts', 'edit.php' );

		$this->assertFalse( wp_script_is( EventConfigAssets::HANDLE, 'enqueued' ) );
	}

	public function test_mount_container_printed_after_title_for_event(): void {
		$event = self::factory()->post->create_and_get( array( 'post_type' => EventPostType::POST_TYPE ) );

		ob_start();
		do_action( 'edit_form_after_title', $event );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="evreg-admin-root"', $html );
	}

	public function test_no_mount_container_for_other_post_types(): void {
		$page = self::factory()->post->create_and_get( array( 'post_type' => 'page' ) );

		ob_start();
		do_action( 'edit_form_after_title', $page );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'evreg-admin-root', $html );
	}

	/**
	 * Panel nie ma własnej kopii domyślnych komunikatów o pełnym evencie — dostaje je
	 * z serwera, żeby placeholder w ustawieniach i baza wiersza tłumaczenia mówiły
	 * dokładnie to, co renderuje publiczny formularz (backlog B41).
	 */
	public function test_notice_defaults_are_localized_for_the_admin_app(): void {
		$payload = $this->enqueued_payload();

		$this->assertArrayHasKey( 'noticeDefaults', $payload );
		foreach ( DefaultNotices::keys() as $key ) {
			$this->assertNotSame( '', DefaultNotices::get( $key ) );
			$this->assertSame( DefaultNotices::get( $key ), $payload['noticeDefaults'][ $key ] ?? null );
		}
	}

	/**
	 * Enqueue'uje aplikację na ekranie edycji eventu i zwraca zdekodowany obiekt
	 * `evregAdmin` wypisywany przez wp_localize_script.
	 *
	 * @return array<string,mixed>
	 */
	private function enqueued_payload(): array {
		$event = self::factory()->post->create( array( 'post_type' => EventPostType::POST_TYPE ) );

		$_GET['post'] = (string) $event;
		set_current_screen( 'post.php' );
		get_current_screen()->post_type = EventPostType::POST_TYPE;

		EventConfigAssets::enqueue( 'post.php' );
		unset( $_GET['post'] );

		$data = (string) wp_scripts()->get_data( EventConfigAssets::HANDLE, 'data' );
		$this->assertMatchesRegularExpression( '/^var evregAdmin = .*;$/s', trim( $data ) );

		$json    = (string) preg_replace( '/^var evregAdmin = (.*);$/s', '$1', trim( $data ) );
		$decoded = json_decode( $json, true );
		$this->assertIsArray( $decoded );

		return $decoded;
	}
}
