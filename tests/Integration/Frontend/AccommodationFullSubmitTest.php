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

final class AccommodationFullSubmitTest extends WP_UnitTestCase {

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
					'rooms'             => array( array( 'key' => 'double', 'label' => 'Dwuosobowy' ) ),
					'inventory'         => array( array( 'package' => 'n18', 'room' => 'double', 'capacity' => 1, 'price' => 0.0 ) ),
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

	/**
	 * @return array<string,mixed>
	 */
	private function post( string $email, bool $companion = false, string $companion_name = '' ): array {
		$post = array(
			'evreg_field' => array(
				'__type' => 'uczestnik',
				'email'  => $email,
				'nocleg' => array( 'slot' => 'n18|double', 'roommate' => '' ),
			),
		);

		if ( $companion ) {
			$post['evreg_companion']      = '1';
			$post['evreg_companion_name'] = $companion_name;
		}

		return $post;
	}

	public function test_full_slot_returns_invalid_with_preserved_answers(): void {
		$first = $this->handler->process( $this->event_id, $this->post( 'a@example.com' ) );
		$this->assertTrue( $first->isSuccess() );

		$result = $this->handler->process( $this->event_id, $this->post( 'b@example.com', true, 'Anna Kowalska' ) );

		$this->assertSame( 'invalid', $result->code() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( 'accommodation_full', $result->errors()['nocleg'] ?? null );

		// Wpisane dane muszą przeżyć re-render: slot jako TABLICA (nie obiekt
		// AccommodationSelection) oraz stan osoby towarzyszącej.
		$this->assertSame(
			array( 'package' => 'n18', 'room' => 'double', 'roommate' => '' ),
			$result->submittedValue( 'nocleg' )
		);
		$this->assertTrue( (bool) $result->submittedValue( 'evreg_companion' ) );
		$this->assertSame( 'Anna Kowalska', $result->submittedValue( 'evreg_companion_name' ) );
	}

	public function test_nothing_is_stored_for_the_refused_submission(): void {
		$this->handler->process( $this->event_id, $this->post( 'a@example.com' ) );
		$this->handler->process( $this->event_id, $this->post( 'b@example.com' ) );

		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::table( 'registrations' ) . ' WHERE event_id = %d', $this->event_id )
		);

		$this->assertSame( 1, $count );
	}
}
