<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Privacy;

use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\MailQueueRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Privacy\PrivacyProvider;
use WP_UnitTestCase;

final class PrivacyProviderTest extends WP_UnitTestCase {

	private RegistrationRepository $repository;

	private MailQueueRepository $mail_repository;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'locks', 'mail_queue' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$this->repository      = new RegistrationRepository();
		$this->mail_repository = new MailQueueRepository();
		$this->event_id        = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );

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
								array( 'key' => 'imie', 'type' => 'text', 'label' => 'Imię', 'required' => true ),
								array(
									'key'     => 'dni',
									'type'    => 'checkbox-group',
									'label'   => 'Dni',
									'options' => array(
										array( 'value' => 'sob', 'label' => 'Sobota' ),
										array( 'value' => 'ndz', 'label' => 'Niedziela' ),
									),
								),
							),
						),
					),
				),
				'types'         => array( array( 'key' => 'std', 'label' => 'Standard', 'price' => 150.0, 'capacity' => 50 ) ),
				'accommodation' => array(
					'packages'  => array( array( 'key' => 'n12', 'label' => 'Noc 1-2' ) ),
					'rooms'     => array( array( 'key' => 'double', 'label' => 'Pokój 2-osobowy', 'roommate_field' => true ) ),
					'inventory' => array( array( 'package' => 'n12', 'room' => 'double', 'capacity' => 10, 'price' => 180.0 ) ),
				),
				'settings'      => array( 'global_cap' => null, 'waitlist_enabled' => true ),
			)
		);
	}

	/**
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function row( array $overrides = array() ): array {
		return array_merge(
			array(
				'event_id'    => $this->event_id,
				'type_key'    => 'std',
				'status'      => 'confirmed',
				'email'       => 'a@b.pl',
				'name'        => 'Jan Kowalski',
				'token'       => md5( uniqid( '', true ) ),
				'data'        => wp_json_encode(
					array(
						'email' => 'a@b.pl',
						'imie'  => 'Jan Kowalski',
						'dni'   => array( 'sob', 'ndz' ),
					)
				),
				'price_total' => 150.0,
				'expires_at'  => null,
			),
			$overrides
		);
	}

	public function test_export_returns_persons_registration_group(): void {
		$id = $this->repository->insertRegistration( $this->row() );
		$this->repository->updateNote( $id, 'Notatka organizatora' );

		$result = ( new PrivacyProvider() )->export( 'a@b.pl', 1 );

		$this->assertTrue( $result['done'] );
		$this->assertNotEmpty( $result['data'] );

		$group = $result['data'][0];
		$this->assertSame( 'evreg_registration', $group['group_id'] );

		$values = wp_list_pluck( $group['data'], 'value', 'name' );
		$this->assertContains( 'a@b.pl', $values );
		$this->assertArrayHasKey( 'Status', $values );
		$this->assertSame( 'Potwierdzone', $values['Status'] );
	}

	public function test_export_other_email_returns_empty_and_done(): void {
		$this->repository->insertRegistration( $this->row() );

		$result = ( new PrivacyProvider() )->export( 'inny@b.pl', 1 );

		$this->assertTrue( $result['done'] );
		$this->assertSame( array(), $result['data'] );
	}

	public function test_export_pages_when_more_than_fifty_rows(): void {
		for ( $i = 0; $i < 51; $i++ ) {
			$this->repository->insertRegistration( $this->row( array( 'token' => md5( (string) $i ) ) ) );
		}

		$result = ( new PrivacyProvider() )->export( 'a@b.pl', 1 );

		$this->assertFalse( $result['done'] );
		$this->assertCount( 50, $result['data'] );
	}

	public function test_erase_anonymizes_and_keeps_status(): void {
		$id = $this->repository->insertRegistration( $this->row() );
		$this->repository->insertAccommodationBooking(
			$id,
			new \EvReg\Domain\Accommodation\AccommodationSelection( 'n12', 'double', 'Ewa' ),
			180.0
		);
		$this->mail_repository->insert(
			array(
				'registration_id' => $id,
				'event_id'        => $this->event_id,
				'template_key'    => 'optin',
				'recipient'       => 'a@b.pl',
				'subject'         => 'Potwierdź zgłoszenie',
				'body'            => 'Treść',
				'headers'         => '',
				'scheduled_at'    => '2026-08-19 10:00:00',
			)
		);

		$provider = new PrivacyProvider();
		$result   = $provider->erase( 'a@b.pl', 1 );

		$this->assertTrue( $result['items_retained'] );
		$this->assertFalse( $result['items_removed'] );
		$this->assertNotEmpty( $result['messages'] );
		$this->assertTrue( $result['done'] );

		$row = $this->repository->findById( $id );
		$this->assertStringEndsWith( '@example.invalid', (string) $row['email'] );
		$this->assertSame( 'confirmed', (string) $row['status'] );

		// Idempotencja: drugi erase po pierwotnym mailu nie znajduje już nic.
		$again = $provider->erase( 'a@b.pl', 1 );
		$this->assertTrue( $again['done'] );
		$this->assertFalse( $again['items_retained'] );
		$this->assertSame( array(), $again['messages'] );
	}
}
