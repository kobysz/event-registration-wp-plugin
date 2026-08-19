<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Frontend;

use EvReg\Frontend\ConfirmationController;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Services\ReservationService;
use WP_UnitTestCase;

final class ConfirmationControllerTest extends WP_UnitTestCase {

	private ConfirmationController $controller;

	private RegistrationRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'locks' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$this->repository = new RegistrationRepository();
		$this->controller = new ConfirmationController(
			new ReservationService( $this->repository, new EventConfigRepository() )
		);
	}

	public function test_resolve_confirms_pending_token(): void {
		$token = bin2hex( random_bytes( 16 ) );
		$this->repository->insertRegistration(
			array(
				'event_id'    => 1,
				'type_key'    => 'uczestnik',
				'status'      => 'pending',
				'email'       => 'jan@example.com',
				'name'        => 'Jan',
				'token'       => $token,
				'data'        => '{}',
				'price_total' => 0.0,
				'expires_at'  => '2099-01-01 00:00:00',
			)
		);

		$this->assertSame( 'confirmed', $this->controller->resolve( $token ) );
		$this->assertSame( 'confirmed', $this->repository->findByToken( $token )['status'] );
	}

	public function test_resolve_unknown_token_is_not_found(): void {
		$this->assertSame( 'not_found', $this->controller->resolve( 'brak' ) );
	}

	public function test_confirmed_message_nonempty_for_each_code(): void {
		foreach ( array( 'confirmed', 'already_confirmed', 'expired', 'waitlist', 'not_found' ) as $code ) {
			$this->assertNotSame( '', ConfirmationController::confirmedMessage( $code ) );
		}
	}
}
