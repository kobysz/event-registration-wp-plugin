<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Admin;

use EvReg\Admin\RegistrationsExporter;
use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Registration\RegistrationTypeCollection;
use EvReg\Domain\Schema\FormSchema;
use WP_UnitTestCase;

final class RegistrationsExporterTest extends WP_UnitTestCase {

	private function schema(): FormSchema {
		return FormSchema::fromArray(
			array(
				'version'  => 1,
				'sections' => array(
					array(
						'key'    => 'dane',
						'title'  => 'Dane uczestnika',
						'fields' => array(
							array( 'key' => 'mail', 'type' => 'email', 'label' => 'E-mail' ),
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
			)
		);
	}

	private function accommodation(): AccommodationConfig {
		return AccommodationConfig::fromArray(
			array(
				'packages'  => array(
					array( 'key' => 'n12', 'label' => 'Noc 1-2' ),
				),
				'rooms'     => array(
					array( 'key' => 'double', 'label' => 'Pokój 2-osobowy', 'roommate_field' => true ),
				),
				'inventory' => array(
					array( 'package' => 'n12', 'room' => 'double', 'capacity' => 10, 'price' => 180.0 ),
				),
			)
		);
	}

	private function types(): RegistrationTypeCollection {
		return RegistrationTypeCollection::fromArray(
			array(
				array( 'key' => 'std', 'label' => 'Standard', 'price' => 150.0 ),
			)
		);
	}

	private function row( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'           => 1,
				'event_id'     => 1,
				'type_key'     => 'std',
				'status'       => 'confirmed',
				'email'        => 'a@b.pl',
				'name'         => 'Jan Kowalski',
				'data'         => wp_json_encode(
					array(
						'mail' => 'a@b.pl',
						'dni'  => array( 'sob', 'ndz' ),
					)
				),
				'price_total'  => '150.00',
				'created_at'   => '2026-08-01 10:00:00',
				'confirmed_at' => '2026-08-02 10:00:00',
				'note'         => '',
			),
			$overrides
		);
	}

	public function test_buildCsv_full_row_header_and_values(): void {
		$row     = $this->row();
		$booking = array(
			'package_key'   => 'n12',
			'room_type_key' => 'double',
			'roommate_pref' => 'Ewa',
		);

		$csv = ( new RegistrationsExporter() )->buildCsv(
			$this->schema(),
			$this->accommodation(),
			$this->types(),
			array( $row ),
			array( $row['id'] => $booking )
		);

		$this->assertStringStartsWith( "\xEF\xBB\xBF", $csv );

		$lines = explode( "\n", trim( substr( $csv, 3 ) ) );

		$this->assertStringContainsString( 'E-mail', $lines[0] );
		$this->assertStringContainsString( 'Nocleg', $lines[0] );

		$this->assertStringContainsString( 'Potwierdzone', $lines[1] );
		$this->assertStringContainsString( 'Standard', $lines[1] );
		$this->assertStringContainsString( 'sob, ndz', $lines[1] );
	}

	public function test_buildCsv_neutralizes_injection_in_data_but_not_header(): void {
		$row = $this->row(
			array(
				'data' => wp_json_encode(
					array(
						'mail' => '=CMD()',
						'dni'  => array(),
					)
				),
			)
		);

		$csv = ( new RegistrationsExporter() )->buildCsv(
			$this->schema(),
			$this->accommodation(),
			$this->types(),
			array( $row ),
			array()
		);

		$lines = explode( "\n", trim( substr( $csv, 3 ) ) );

		$this->assertStringNotContainsString( '=CMD()', $lines[0] );
		$this->assertStringContainsString( "'=CMD()", $lines[1] );
	}
}
