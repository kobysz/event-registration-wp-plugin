<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Services;

use EvReg\Frontend\CurrentLanguage;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Services\ReservationRequest;
use EvReg\Services\ReservationService;
use WP_UnitTestCase;

final class ReserveStoresLangTest extends WP_UnitTestCase {

	private ReservationService $service;

	private RegistrationRepository $repository;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'locks' ) as $table ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$config           = new EventConfigRepository();
		$this->repository = new RegistrationRepository();
		$this->service    = new ReservationService( $this->repository, $config );
		$this->event_id   = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );

		$config->save(
			$this->event_id,
			array(
				'types'    => array(
					array(
						'key'   => 'uczestnik',
						'label' => 'Uczestnik',
						'price' => 0.0,
					),
				),
				'settings' => array( 'waitlist_enabled' => true ),
			)
		);
	}

	protected function tearDown(): void {
		remove_all_filters( 'evreg_current_language' );
		parent::tearDown();
	}

	private function request( string $email ): ReservationRequest {
		return new ReservationRequest( $email, 'Jan', 'uczestnik', array( '__type' => 'uczestnik' ), null, lang: CurrentLanguage::get() );
	}

	public function test_reserve_stores_current_language_when_filter_set(): void {
		add_filter( 'evreg_current_language', static fn () => 'en' );

		$result = $this->service->reserve( $this->event_id, $this->request( 'jan@example.com' ) );

		$this->assertSame( 'en', $this->repository->langOf( (int) $result->registrationId ) );
	}

	public function test_reserve_stores_empty_lang_without_filter(): void {
		$result = $this->service->reserve( $this->event_id, $this->request( 'ewa@example.com' ) );

		$this->assertSame( '', $this->repository->langOf( (int) $result->registrationId ) );
	}
}
