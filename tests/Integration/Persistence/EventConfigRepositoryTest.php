<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Persistence;

use EvReg\Persistence\EventConfigRepository;
use WP_UnitTestCase;

final class EventConfigRepositoryTest extends WP_UnitTestCase {

	private EventConfigRepository $repository;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		$this->repository = new EventConfigRepository();
		$this->event_id  = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function sample_config(): array {
		return array(
			'schema'        => array(
				'version'  => 1,
				'sections' => array(
					array(
						'key'    => 'dane',
						'title'  => 'Dane',
						'fields' => array( array( 'key' => '__type', 'type' => 'radio', 'label' => 'Typ' ) ),
					),
				),
			),
			'types'         => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 450.0 ) ),
			'accommodation' => array( 'packages' => array(), 'rooms' => array(), 'inventory' => array() ),
			'settings'      => array( 'global_cap' => 200, 'waitlist_enabled' => true ),
		);
	}

	public function test_get_returns_empty_arrays_for_unconfigured_event(): void {
		$config = $this->repository->get( $this->event_id );

		$this->assertSame(
			array(
				'schema'        => array(),
				'types'         => array(),
				'accommodation' => array(),
				'settings'      => array(),
			),
			$config
		);
	}

	public function test_save_then_get_round_trips(): void {
		$this->repository->save( $this->event_id, $this->sample_config() );

		$this->assertSame( $this->sample_config(), $this->repository->get( $this->event_id ) );
	}

	public function test_save_persists_as_json_string(): void {
		$this->repository->save( $this->event_id, $this->sample_config() );

		$raw = get_post_meta( $this->event_id, EventConfigRepository::META_SETTINGS, true );

		$this->assertIsString( $raw );
		$this->assertSame( array( 'global_cap' => 200, 'waitlist_enabled' => true ), json_decode( $raw, true ) );
	}

	public function test_save_ignores_absent_keys(): void {
		$this->repository->save( $this->event_id, $this->sample_config() );
		$this->repository->save( $this->event_id, array( 'settings' => array( 'global_cap' => 50 ) ) );

		$config = $this->repository->get( $this->event_id );

		$this->assertSame( array( 'global_cap' => 50 ), $config['settings'] );
		$this->assertSame( $this->sample_config()['types'], $config['types'] );
	}

	public function test_save_preserves_non_ascii_characters(): void {
		$config = $this->sample_config();

		$config['schema']['sections'][0]['fields'][0]['label'] = 'Imię i nazwisko';
		$config['accommodation']                               = array(
			'packages'  => array( array( 'key' => 'n12', 'label' => 'Noc 1–2' ) ),
			'rooms'     => array(),
			'inventory' => array(),
		);

		$this->repository->save( $this->event_id, $config );

		$reloaded = $this->repository->get( $this->event_id );

		$this->assertSame( 'Imię i nazwisko', $reloaded['schema']['sections'][0]['fields'][0]['label'] );
		$this->assertSame( 'Noc 1–2', $reloaded['accommodation']['packages'][0]['label'] );
	}
}
