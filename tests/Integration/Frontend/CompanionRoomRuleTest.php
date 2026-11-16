<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Frontend;

use EvReg\Frontend\EventFormLoader;
use EvReg\Frontend\SubmitHandler;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Services\ReservationService;
use WP_UnitTestCase;

final class CompanionRoomRuleTest extends WP_UnitTestCase {

	private SubmitHandler $handler;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'locks', 'mail_queue' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$this->event_id = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
		( new EventConfigRepository() )->save(
			$this->event_id,
			array(
				'schema'        => array(
					'version'  => 1,
					'sections' => array(
						array(
							'key'    => 'dane',
							'title'  => 'Dane',
							'fields' => array(
								array( 'key' => '__type', 'type' => 'radio', 'label' => 'Typ' ),
								array( 'key' => 'email', 'type' => 'email', 'label' => 'E-mail', 'required' => true ),
								array( 'key' => 'nocleg', 'type' => 'accommodation', 'label' => 'Nocleg' ),
							),
						),
					),
				),
				'types'         => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 0.0 ) ),
				'accommodation' => array(
					'packages'          => array( array( 'key' => 'n18', 'label' => 'Noc 18/19' ) ),
					'rooms'             => array(
						array( 'key' => 'single', 'label' => 'Jednoosobowy', 'occupancy' => 1 ),
						array( 'key' => 'double', 'label' => 'Dwuosobowy', 'occupancy' => 2 ),
						array( 'key' => 'any', 'label' => 'Dowolny' ),
					),
					'inventory'         => array(
						array( 'package' => 'n18', 'room' => 'single', 'capacity' => 10, 'price' => 0.0 ),
						array( 'package' => 'n18', 'room' => 'double', 'capacity' => 10, 'price' => 0.0 ),
						array( 'package' => 'n18', 'room' => 'any', 'capacity' => 10, 'price' => 0.0 ),
					),
					'allow_none'        => true,
					'companion_enabled' => true,
				),
				'settings'      => array( 'global_cap' => null, 'waitlist_enabled' => true ),
			)
		);

		$this->handler = new SubmitHandler(
			new EventFormLoader( new EventConfigRepository() ),
			new ReservationService( new RegistrationRepository(), new EventConfigRepository() )
		);
	}

	public function test_double_room_without_companion_is_refused(): void {
		$result = $this->handler->process( $this->event_id, $this->post( 'a@example.com', 'n18|double', false ) );

		$this->assertSame( 'invalid', $result->code() );
		$this->assertSame( 'companion_required', $result->errors()['nocleg'] ?? null );
		$this->assertSame(
			array( 'package' => 'n18', 'room' => 'double', 'roommate' => '' ),
			$result->submittedValue( 'nocleg' )
		);
		$this->assertSame( 0, $this->countRegistrations() );
	}

	public function test_single_room_with_companion_is_refused(): void {
		$result = $this->handler->process( $this->event_id, $this->post( 'a@example.com', 'n18|single', true, 'Anna Kowalska' ) );

		$this->assertSame( 'invalid', $result->code() );
		$this->assertSame( 'companion_not_allowed', $result->errors()['nocleg'] ?? null );
		$this->assertSame( 0, $this->countRegistrations() );
	}

	public function test_matching_pairs_pass(): void {
		$this->assertTrue( $this->handler->process( $this->event_id, $this->post( 'a@example.com', 'n18|single', false ) )->isSuccess() );
		$this->assertTrue( $this->handler->process( $this->event_id, $this->post( 'b@example.com', 'n18|double', true, 'Anna Kowalska' ) )->isSuccess() );
	}

	public function test_room_without_occupancy_passes_in_both_states(): void {
		$this->assertTrue( $this->handler->process( $this->event_id, $this->post( 'a@example.com', 'n18|any', false ) )->isSuccess() );
		$this->assertTrue( $this->handler->process( $this->event_id, $this->post( 'b@example.com', 'n18|any', true, 'Anna Kowalska' ) )->isSuccess() );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function post( string $email, string $slot, bool $companion, string $companion_name = '' ): array {
		$post = array(
			'evreg_field' => array(
				'__type' => 'uczestnik',
				'email'  => $email,
				'nocleg' => array( 'slot' => $slot, 'roommate' => '' ),
			),
		);

		if ( $companion ) {
			$post['evreg_companion']      = '1';
			$post['evreg_companion_name'] = $companion_name;
		}

		return $post;
	}

	private function countRegistrations(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::table( 'registrations' ) . ' WHERE event_id = %d', $this->event_id )
		);
	}
}
