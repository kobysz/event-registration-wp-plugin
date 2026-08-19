<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Frontend;

use EvReg\Frontend\Shortcode;
use EvReg\Persistence\EventConfigRepository;
use WP_UnitTestCase;

final class ShortcodeTest extends WP_UnitTestCase {

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		$this->event_id = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
		( new EventConfigRepository() )->save(
			$this->event_id,
			array(
				'schema' => array(
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
				'types'         => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 0.0 ) ),
				'accommodation' => array( 'packages' => array(), 'rooms' => array(), 'inventory' => array() ),
			)
		);
	}

	public function test_shortcode_renders_form_for_event(): void {
		$html = Shortcode::render( array( 'event' => (string) $this->event_id ) );

		$this->assertStringContainsString( '<form', $html );
		$this->assertStringContainsString( 'name="email"', $html );
	}

	public function test_shortcode_shows_success_message_after_reserved(): void {
		$_GET['evreg'] = 'reserved';

		$html = Shortcode::render( array( 'event' => (string) $this->event_id ) );

		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringContainsString( 'evreg-success', $html );

		unset( $_GET['evreg'] );
	}

	public function test_shortcode_without_valid_event_renders_nothing_useful(): void {
		$html = Shortcode::render( array( 'event' => '0' ) );

		$this->assertStringNotContainsString( '<form', $html );
	}
}
