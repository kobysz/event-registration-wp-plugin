<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Frontend;

use EvReg\Frontend\EventFormLoader;
use EvReg\Persistence\EventConfigRepository;
use WP_UnitTestCase;

final class EventFormLoaderI18nTest extends WP_UnitTestCase {

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		$this->event_id = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
		$repo           = new EventConfigRepository();
		$repo->save(
			$this->event_id,
			array(
				'schema'        => array(
					'version'  => 1,
					'sections' => array(
						array(
							'key'    => 'dane',
							'title'  => 'Dane',
							'fields' => array(
								array(
									'key'   => '__type',
									'type'  => 'radio',
									'label' => 'Typ',
								),
								array(
									'key'      => 'imie',
									'type'     => 'text',
									'label'    => 'Imię',
									'required' => true,
								),
							),
						),
					),
				),
				'types'         => array(
					array(
						'key'   => 'uczestnik',
						'label' => 'Uczestnik',
						'price' => 0.0,
					),
				),
				'accommodation' => array(
					'packages'  => array(),
					'rooms'     => array(),
					'inventory' => array(),
				),
			)
		);
		$repo->saveI18n( $this->event_id, array( 'en' => array( 'fields' => array( 'imie' => 'First name' ) ) ) );
	}

	protected function tearDown(): void {
		remove_all_filters( 'evreg_current_language' );
		parent::tearDown();
	}

	public function test_base_language_renders_polish(): void {
		$schema = ( new EventFormLoader( new EventConfigRepository() ) )->load( $this->event_id );
		$label  = $this->labelOf( $schema, 'imie' );
		$this->assertSame( 'Imię', $label );
	}

	public function test_current_language_en_applies_overlay(): void {
		add_filter( 'evreg_current_language', static fn (): string => 'en' );
		$schema = ( new EventFormLoader( new EventConfigRepository() ) )->load( $this->event_id );
		$label  = $this->labelOf( $schema, 'imie' );
		$this->assertSame( 'First name', $label );
	}

	private function labelOf( $schema, string $key ): string {
		foreach ( $schema->sections() as $section ) {
			foreach ( $section->fields as $field ) {
				if ( $field->key === $key ) {
					return $field->label;
				}
			}
		}
		return '';
	}
}
