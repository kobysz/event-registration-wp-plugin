<?php
/**
 * Testy integracyjne EventFormLoader.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Frontend;

use EvReg\Domain\Schema\FormSchema;
use EvReg\Frontend\EventFormLoader;
use EvReg\Persistence\EventConfigRepository;
use WP_UnitTestCase;

/**
 * Testy integracyjne EventFormLoader.
 */
final class EventFormLoaderTest extends WP_UnitTestCase {

	private EventFormLoader $loader;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		$this->event_id = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
		$this->loader   = new EventFormLoader( new EventConfigRepository() );
	}

	private function configure(): void {
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
				'types'         => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 450.0 ) ),
				'accommodation' => array( 'packages' => array(), 'rooms' => array(), 'inventory' => array() ),
			)
		);
	}

	public function test_load_returns_assembled_schema_with_injected_type_options(): void {
		$this->configure();

		$schema = $this->loader->load( $this->event_id );

		$this->assertInstanceOf( FormSchema::class, $schema );
		$type = $schema->findField( '__type' );
		$this->assertNotNull( $type );
		$values = array_map( static fn ( $o ) => $o->value, $type->options );
		$this->assertSame( array( 'uczestnik' ), $values );
	}

	public function test_load_returns_null_for_unconfigured_event(): void {
		$this->assertNull( $this->loader->load( $this->event_id ) );
	}
}
