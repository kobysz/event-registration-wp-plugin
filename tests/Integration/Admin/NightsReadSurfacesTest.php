<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Admin;

use EvReg\Admin\RegistrationsExporter;
use EvReg\Admin\RegistrationsScreen;
use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Domain\Registration\RegistrationTypeCollection;
use EvReg\Domain\Schema\FormSchema;
use EvReg\Domain\Schema\SchemaException;
use EvReg\Frontend\EventFormLoader;
use EvReg\Frontend\NightsResolver;
use EvReg\Mail\PlaceholderFactory;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Privacy\PrivacyProvider;
use WP_UnitTestCase;

/**
 * Trzy powierzchnie odczytu (eksport CSV, mail, WP Privacy) dla eventu w trybie „doby”.
 * Event niesie CELOWO niespójny, zapomniany inwentarz klasyczny — AccommodationConfig::fromArray
 * rzuciłby na nim SchemaException; żadna powierzchnia nie może przez to paść.
 */
final class NightsReadSurfacesTest extends WP_UnitTestCase {

	private RegistrationRepository $repository;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'booking_nights', 'locks', 'mail_queue' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$this->repository = new RegistrationRepository();
		$this->event_id   = self::factory()->post->create(
			array(
				'post_type'  => 'evreg_event',
				'post_title' => 'Zjazd 2026',
			)
		);

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
				'types'         => array( array( 'key' => 'std', 'label' => 'Standard', 'price' => 100.0 ) ),
				'accommodation' => array(
					'mode'      => 'nights',
					'nights'    => array(
						array( 'key' => 'n1', 'label' => 'Noc 1', 'price' => 80.0, 'seats' => 4 ),
						array( 'key' => 'n2', 'label' => 'Noc 2', 'price' => 90.0, 'seats' => 4 ),
					),
					// Zapomniany inwentarz klasyczny: pakiet/pokój nie istnieją → fromArray rzuca.
					'inventory' => array( array( 'package' => 'ghost', 'room' => 'ghost', 'capacity' => 5, 'price' => 1.0 ) ),
				),
				'settings'      => array( 'waitlist_enabled' => false ),
			)
		);
	}

	/**
	 * Wstawia zgłoszenie z dobami i zwraca jego ID.
	 *
	 * @param array<int,array{key:string,label:string,seats:int,price:float}> $nights Doby.
	 */
	private function registration( array $nights, string $email = 'a@b.pl' ): int {
		$id = $this->repository->insertRegistration(
			array(
				'event_id'    => $this->event_id,
				'type_key'    => 'std',
				'status'      => 'confirmed',
				'email'       => $email,
				'name'        => 'Jan Kowalski',
				'token'       => md5( uniqid( '', true ) ),
				'data'        => (string) wp_json_encode( array( 'email' => $email ) ),
				'price_total' => 260.0,
				'expires_at'  => null,
			)
		);
		$this->repository->replaceBookingNights( $id, $nights );

		return $id;
	}

	/**
	 * @return array<int,array{key:string,label:string,seats:int,price:float}>
	 */
	private function twoNights(): array {
		return array(
			array( 'key' => 'n1', 'label' => 'Noc 1', 'seats' => 1, 'price' => 80.0 ),
			array( 'key' => 'n2', 'label' => 'Noc 2', 'seats' => 1, 'price' => 90.0 ),
		);
	}

	public function test_the_classic_config_really_is_inconsistent(): void {
		$config = ( new EventConfigRepository() )->get( $this->event_id );

		$this->expectException( SchemaException::class );
		AccommodationConfig::fromArray( $config['accommodation'] );
	}

	public function test_export_survives_stale_classic_inventory_and_carries_the_nights(): void {
		$this->registration( $this->twoNights() );
		$this->registration( array(), 'bez@b.pl' );

		$csv = RegistrationsScreen::build_export_csv( $this->event_id, array( 'event_id' => $this->event_id ) );

		$this->assertNotNull( $csv );
		$lines = explode( "\n", trim( substr( (string) $csv, 3 ) ) );
		$this->assertStringEndsWith( ',Doby', $lines[0] );
		$this->assertStringNotContainsString( 'Nocleg – pakiet', $lines[0] );
		$this->assertStringEndsWith( ',"Noc 1, Noc 2"', $lines[1] );
		$this->assertStringEndsWith( ',', $lines[2] );
	}

	public function test_buildCsv_nights_mode_neutralizes_a_night_label(): void {
		$id  = $this->registration( array( array( 'key' => 'n1', 'label' => '=HYPERLINK("x")', 'seats' => 1, 'price' => 1.0 ) ) );
		$row = $this->repository->findById( $id );
		$this->assertNotNull( $row );

		$schema = ( new EventFormLoader( new EventConfigRepository() ) )->load( $this->event_id );
		$this->assertInstanceOf( FormSchema::class, $schema );

		$csv = ( new RegistrationsExporter() )->buildCsv(
			$schema,
			null,
			RegistrationTypeCollection::fromArray( array() ),
			array( $row ),
			array(),
			$this->repository->bookingNightsFor( array( $id ) )
		);

		$this->assertStringContainsString( '"\'=HYPERLINK(""x"")"', $csv );
	}

	public function test_mail_survives_stale_classic_inventory_and_lists_the_nights(): void {
		$id  = $this->registration( $this->twoNights() );
		$row = $this->repository->findById( $id );
		$this->assertNotNull( $row );

		$config = new EventConfigRepository();
		$values = ( new PlaceholderFactory( $config, $this->repository, new EventFormLoader( $config ) ) )->build( $row );

		$this->assertSame( 'Noc 1, Noc 2', $values->get( 'noclegi' ) );
		$this->assertSame( '', $values->get( 'nocleg' ) );
		$this->assertSame( 'Jan Kowalski', $values->get( 'imie' ) );
		$this->assertSame( 'Standard', $values->get( 'typ' ) );
		$this->assertStringContainsString( 'Nocleg: Noc 1, Noc 2', $values->get( 'podsumowanie' ) );
	}

	public function test_privacy_export_and_eraser_survive_stale_classic_inventory(): void {
		$id = $this->registration( $this->twoNights() );

		$export = ( new PrivacyProvider() )->export( 'a@b.pl', 1 );

		$this->assertSame( 'evreg_registration', $export['data'][0]['group_id'] );
		$values = wp_list_pluck( $export['data'][0]['data'], 'value', 'name' );
		$this->assertSame( 'Noc 1, Noc 2', $values['Doby'] );

		$erase = ( new PrivacyProvider() )->erase( 'a@b.pl', 1 );

		$this->assertTrue( $erase['done'] );
		$this->assertTrue( $erase['items_retained'] );
		$row = $this->repository->findById( $id );
		$this->assertNotNull( $row );
		$this->assertSame( 'deleted-' . $id . '@example.invalid', $row['email'] );
	}

	/**
	 * Zapisuje event z niepoprawną konfiguracją dób (zduplikowany klucz) i zapomnianym inwentarzem.
	 */
	private function breakNightsConfig(): void {
		$repo   = new EventConfigRepository();
		$config = $repo->get( $this->event_id );
		// Schemat, który się nie składa (nieznany typ pola): loader zwraca null.
		$config['schema']['sections'][0]['fields'][] = array( 'key' => 'zepsute', 'type' => 'bogus', 'label' => 'X' );
		$config['accommodation']['nights'] = array(
			array( 'key' => 'n1', 'label' => 'Noc 1', 'price' => 80.0, 'seats' => 4 ),
			array( 'key' => 'n1', 'label' => 'Noc 1 bis', 'price' => 80.0, 'seats' => 4 ),
		);
		$repo->save( $this->event_id, $config );
	}

	public function test_isNightsMode_degrades_to_true_when_the_nights_config_is_malformed(): void {
		$schema = FormSchema::fromArray(
			array(
				'version'  => 1,
				'sections' => array(
					array(
						'key'    => 's',
						'title'  => 'S',
						'fields' => array(
							array(
								'key'    => 'nocleg',
								'type'   => 'accommodation',
								'label'  => 'Nocleg',
								'config' => array(
									'mode'   => 'nights',
									'nights' => array( array( 'key' => 'n1' ), array( 'key' => 'n1' ) ),
								),
							),
						),
					),
				),
			)
		);

		$this->assertTrue( NightsResolver::isNightsMode( $schema ) );
	}

	public function test_mail_does_not_fatal_on_malformed_nights_config_with_a_stale_classic_booking(): void {
		$this->breakNightsConfig();
		$id = $this->registration( array() );
		$this->repository->insertAccommodationBooking( $id, new AccommodationSelection( 'ghost', 'ghost', '' ), 1.0 );
		$row = $this->repository->findById( $id );
		$this->assertNotNull( $row );

		$config = new EventConfigRepository();
		$values = ( new PlaceholderFactory( $config, $this->repository, new EventFormLoader( $config ) ) )->build( $row );

		$this->assertSame( 'Jan Kowalski', $values->get( 'imie' ) );
		$this->assertSame( '', $values->get( 'nocleg' ) );
		$this->assertSame( '', $values->get( 'noclegi' ) );
	}
}
