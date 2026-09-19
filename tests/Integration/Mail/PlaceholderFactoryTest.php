<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Mail;

use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Frontend\EventFormLoader;
use EvReg\Mail\PlaceholderFactory;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use WP_UnitTestCase;

final class PlaceholderFactoryTest extends WP_UnitTestCase {

	private PlaceholderFactory $placeholderFactory;

	private RegistrationRepository $registrations;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings' ) as $table ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$config                   = new EventConfigRepository();
		$this->registrations      = new RegistrationRepository();
		$this->placeholderFactory = new PlaceholderFactory( $config, $this->registrations, new EventFormLoader( $config ) );
		$this->event_id           = self::factory()->post->create(
			array(
				'post_type'   => 'evreg_event',
				'post_title'  => 'Zjazd 2026',
				'post_status' => 'publish',
			)
		);

		$config->save(
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
									'label' => 'Typ zgłoszenia',
								),
								array(
									'key'   => 'imie',
									'type'  => 'text',
									'label' => 'Imię i nazwisko',
								),
								array(
									'key'   => 'email',
									'type'  => 'email',
									'label' => 'E-mail',
								),
							),
						),
					),
				),
				'types'         => array(
					array(
						'key'   => 'uczestnik',
						'label' => 'Uczestnik',
						'price' => 450.0,
					),
				),
				'accommodation' => array(
					'packages'  => array(
						array(
							'key'   => 'n12',
							'label' => 'Noc 1–2',
						),
					),
					'rooms'     => array(
						array(
							'key'            => 'double',
							'label'          => 'Pokój 2-osobowy',
							'roommate_field' => true,
						),
					),
					'inventory' => array(
						array(
							'package'  => 'n12',
							'room'     => 'double',
							'capacity' => 5,
							'price'    => 180.0,
						),
					),
				),
				'settings'      => array( 'global_cap' => 100 ),
			)
		);
	}

	/**
	 * Wstawia zgłoszenie i zwraca jego wiersz.
	 *
	 * @param array<string,mixed> $overrides Nadpisania kolumn.
	 * @return array<string,mixed>
	 */
	private function registration( array $overrides = array() ): array {
		$id = $this->registrations->insertRegistration(
			array_merge(
				array(
					'event_id'    => $this->event_id,
					'type_key'    => 'uczestnik',
					'status'      => 'pending',
					'email'       => 'jan@example.com',
					'name'        => 'Jan Kowalski',
					'token'       => 'aabbccddeeff00112233445566778899',
					'data'        => (string) wp_json_encode(
						array(
							'__type' => 'uczestnik',
							'imie'   => 'Jan Kowalski',
							'email'  => 'jan@example.com',
						)
					),
					'price_total' => 450.0,
					'expires_at'  => '2099-01-01 00:00:00',
				),
				$overrides
			)
		);

		$row = $this->registrations->findById( $id );

		$this->assertNotNull( $row );

		return $row;
	}

	public function test_builds_basic_placeholders(): void {
		$values = $this->placeholderFactory->build( $this->registration() );

		$this->assertSame( 'Jan Kowalski', $values->get( 'imie' ) );
		$this->assertSame( 'jan@example.com', $values->get( 'email' ) );
		$this->assertSame( 'Zjazd 2026', $values->get( 'event' ) );
		$this->assertSame( 'Uczestnik', $values->get( 'typ' ) );
	}

	public function test_type_falls_back_to_key_when_unknown(): void {
		$values = $this->placeholderFactory->build( $this->registration( array( 'type_key' => 'wykladowca' ) ) );

		$this->assertSame( 'wykladowca', $values->get( 'typ' ) );
	}

	public function test_confirmation_link_carries_token(): void {
		$link = $this->placeholderFactory->build( $this->registration() )->get( 'link_potwierdzenia' );

		$this->assertStringContainsString( 'evreg_confirm=aabbccddeeff00112233445566778899', $link );
		$this->assertStringStartsWith( 'http', $link );
	}

	public function test_confirmation_link_uses_form_page_when_configured(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		( new EventConfigRepository() )->save( $this->event_id, array( 'settings' => array( 'form_page_id' => $page_id ) ) );

		$link = $this->placeholderFactory->build( $this->registration() )->get( 'link_potwierdzenia' );

		$this->assertStringContainsString( (string) get_permalink( $page_id ), $link );
	}

	public function test_confirmation_link_uses_localized_page_for_registration_language(): void {
		$base_page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$en_page   = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		( new EventConfigRepository() )->save( $this->event_id, array( 'settings' => array( 'form_page_id' => $base_page ) ) );

		$captured = array();
		$filter   = static function ( int $post_id, string $lang ) use ( $en_page, &$captured ) {
			$captured[] = $lang;
			return 'en' === $lang ? $en_page : $post_id;
		};
		add_filter( 'evreg_confirmation_page_id', $filter, 10, 2 );

		$link = $this->placeholderFactory->build( $this->registration( array( 'lang' => 'en' ) ) )->get( 'link_potwierdzenia' );

		remove_filter( 'evreg_confirmation_page_id', $filter, 10 );

		$this->assertContains( 'en', $captured );
		$this->assertStringContainsString( (string) get_permalink( $en_page ), $link );
		$this->assertStringNotContainsString( (string) get_permalink( $base_page ), $link );
	}

	public function test_confirmation_link_passes_empty_language_for_base_registration(): void {
		$base_page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		( new EventConfigRepository() )->save( $this->event_id, array( 'settings' => array( 'form_page_id' => $base_page ) ) );

		$captured = array();
		$filter   = static function ( int $post_id, string $lang ) use ( &$captured ) {
			$captured[] = $lang;
			return $post_id;
		};
		add_filter( 'evreg_confirmation_page_id', $filter, 10, 2 );

		$link = $this->placeholderFactory->build( $this->registration() )->get( 'link_potwierdzenia' );

		remove_filter( 'evreg_confirmation_page_id', $filter, 10 );

		$this->assertSame( array( '' ), $captured );
		$this->assertStringContainsString( (string) get_permalink( $base_page ), $link );
	}

	public function test_summary_lists_answers(): void {
		$summary = $this->placeholderFactory->build( $this->registration() )->get( 'podsumowanie' );

		$this->assertStringContainsString( 'Imię i nazwisko: Jan Kowalski', $summary );
		$this->assertStringContainsString( 'E-mail: jan@example.com', $summary );
		$this->assertStringNotContainsString( 'Typ zgłoszenia', $summary );
	}

	public function test_accommodation_is_empty_without_booking(): void {
		$this->assertSame( '', $this->placeholderFactory->build( $this->registration() )->get( 'nocleg' ) );
	}

	public function test_accommodation_uses_labels_and_roommate(): void {
		$row = $this->registration();
		$this->registrations->insertAccommodationBooking(
			(int) $row['id'],
			new AccommodationSelection( 'n12', 'double', 'Piotr N.' ),
			180.0
		);

		$values = $this->placeholderFactory->build( $row );

		$this->assertStringContainsString( 'Noc 1–2', $values->get( 'nocleg' ) );
		$this->assertStringContainsString( 'Pokój 2-osobowy', $values->get( 'nocleg' ) );
		$this->assertStringContainsString( 'Piotr N.', $values->get( 'nocleg' ) );
		$this->assertStringContainsString( 'Noc 1–2', $values->get( 'podsumowanie' ) );
	}

	public function test_summary_is_empty_when_schema_missing(): void {
		$other  = self::factory()->post->create(
			array(
				'post_type'  => 'evreg_event',
				'post_title' => 'Bez schemy',
			)
		);
		$values = $this->placeholderFactory->build(
			$this->registration(
				array(
					'event_id' => $other,
					'email'    => 'inny@example.com',
				)
			)
		);

		$this->assertSame( '', $values->get( 'podsumowanie' ) );
		$this->assertSame( 'Bez schemy', $values->get( 'event' ) );
	}
}
