<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Persistence;

use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use WP_UnitTestCase;

final class SumPriceTotalTest extends WP_UnitTestCase {

	private RegistrationRepository $repo;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( 'registrations' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$this->repo     = new RegistrationRepository();
		$this->event_id = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
	}

	private function seed( string $status, float $price, ?int $event_id = null ): void {
		$this->repo->insertRegistration(
			array(
				'event_id'    => $event_id ?? $this->event_id,
				'type_key'    => 'uczestnik',
				'status'      => $status,
				'email'       => $status . $price . '@example.com',
				'name'        => 'Jan',
				'token'       => bin2hex( random_bytes( 16 ) ),
				'data'        => '{}',
				'price_total' => $price,
				'expires_at'  => null,
			)
		);
	}

	public function test_sums_only_occupying_statuses(): void {
		$this->seed( 'confirmed', 100.0 );
		$this->seed( 'pending', 50.5 );
		$this->seed( 'waitlist', 999.0 );   // nie zajmuje miejsca
		$this->seed( 'cancelled', 777.0 );  // nie zajmuje miejsca

		$this->assertSame( 150.5, $this->repo->sumPriceTotal( $this->event_id ) );
	}

	public function test_ignores_other_events(): void {
		$other = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
		$this->seed( 'confirmed', 100.0 );
		$this->seed( 'confirmed', 42.0, $other );

		$this->assertSame( 100.0, $this->repo->sumPriceTotal( $this->event_id ) );
	}

	public function test_returns_zero_without_rows(): void {
		$this->assertSame( 0.0, $this->repo->sumPriceTotal( $this->event_id ) );
	}
}
