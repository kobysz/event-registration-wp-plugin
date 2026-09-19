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

	public function test_current_language_en_translates_accommodation_labels(): void {
		$event = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
		$repo  = new EventConfigRepository();
		$repo->save(
			$event,
			array(
				'schema'        => array(
					'version'  => 1,
					'sections' => array(
						array(
							'key'    => 'dane',
							'title'  => 'Dane',
							'fields' => array(
								array( 'key' => '__type', 'type' => 'radio', 'label' => 'Typ' ),
								array( 'key' => 'nocleg', 'type' => 'accommodation', 'label' => 'Nocleg' ),
							),
						),
					),
				),
				'types'         => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 0.0 ) ),
				'accommodation' => array(
					'packages'  => array( array( 'key' => 'n12', 'label' => 'Noc 1–2' ) ),
					'rooms'     => array( array( 'key' => 'double', 'label' => 'Pokój 2-osobowy' ) ),
					'inventory' => array( array( 'package' => 'n12', 'room' => 'double', 'capacity' => 5, 'price' => 180.0 ) ),
				),
			)
		);
		$repo->saveI18n(
			$event,
			array(
				'en' => array(
					'accommodation' => array(
						'packages' => array( 'n12' => 'Night 1–2' ),
						'rooms'    => array( 'double' => 'Double room' ),
					),
				),
			)
		);

		add_filter( 'evreg_current_language', static fn (): string => 'en' );
		$schema = ( new EventFormLoader( new EventConfigRepository() ) )->load( $event );
		$config = $this->accommodationConfigOf( $schema );

		$this->assertSame( 'Night 1–2', $config['packages'][0]['label'] );
		$this->assertSame( 'Double room', $config['rooms'][0]['label'] );
	}

	public function test_base_language_keeps_polish_accommodation_labels(): void {
		$event = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
		$repo  = new EventConfigRepository();
		$repo->save(
			$event,
			array(
				'schema'        => array(
					'version'  => 1,
					'sections' => array(
						array(
							'key'    => 'dane',
							'title'  => 'Dane',
							'fields' => array(
								array( 'key' => '__type', 'type' => 'radio', 'label' => 'Typ' ),
								array( 'key' => 'nocleg', 'type' => 'accommodation', 'label' => 'Nocleg' ),
							),
						),
					),
				),
				'types'         => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 0.0 ) ),
				'accommodation' => array(
					'packages'  => array( array( 'key' => 'n12', 'label' => 'Noc 1–2' ) ),
					'rooms'     => array( array( 'key' => 'double', 'label' => 'Pokój 2-osobowy' ) ),
					'inventory' => array( array( 'package' => 'n12', 'room' => 'double', 'capacity' => 5, 'price' => 180.0 ) ),
				),
			)
		);
		$repo->saveI18n( $event, array( 'en' => array( 'accommodation' => array( 'packages' => array( 'n12' => 'Night 1–2' ) ) ) ) );

		$schema = ( new EventFormLoader( new EventConfigRepository() ) )->load( $event );
		$config = $this->accommodationConfigOf( $schema );

		$this->assertSame( 'Noc 1–2', $config['packages'][0]['label'] );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function accommodationConfigOf( $schema ): array {
		foreach ( $schema->sections() as $section ) {
			foreach ( $section->fields as $field ) {
				if ( 'nocleg' === $field->key ) {
					return $field->config;
				}
			}
		}
		return array();
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
