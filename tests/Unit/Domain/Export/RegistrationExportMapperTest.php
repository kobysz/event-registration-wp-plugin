<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Export;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Export\RegistrationExportMapper;
use EvReg\Domain\Schema\FormSchema;
use PHPUnit\Framework\TestCase;

final class RegistrationExportMapperTest extends TestCase {

	public function test_answerColumns_skips_non_input_fields_and_preserves_order(): void {
		// Construct schema with text, heading, email
		$schema = FormSchema::fromArray(
			array(
				'version'  => 1,
				'sections' => array(
					array(
						'key'    => 'main',
						'title'  => 'Main',
						'fields' => array(
							array( 'key' => 'imie', 'type' => 'text', 'label' => 'Imię' ),
							array( 'key' => 'section', 'type' => 'heading', 'label' => 'Sekcja' ),
							array( 'key' => 'mail', 'type' => 'email', 'label' => 'E-mail' ),
						),
					),
				),
			)
		);

		$cols = ( new RegistrationExportMapper() )->answerColumns( $schema );

		$this->assertSame(
			array(
				array( 'key' => 'imie', 'label' => 'Imię' ),
				array( 'key' => 'mail', 'label' => 'E-mail' ),
			),
			$cols
		);
	}

	public function test_answerCells_converts_values_to_strings(): void {
		$schema = FormSchema::fromArray(
			array(
				'version'  => 1,
				'sections' => array(
					array(
						'key'    => 'main',
						'title'  => 'Main',
						'fields' => array(
							array( 'key' => 'name', 'type' => 'text', 'label' => 'Name' ),
							array( 'key' => 'email', 'type' => 'email', 'label' => 'Email' ),
							array( 'key' => 'age', 'type' => 'number', 'label' => 'Age' ),
						),
					),
				),
			)
		);

		$mapper = new RegistrationExportMapper();
		$data   = array(
			'name'  => 'John',
			'email' => 'john@example.com',
			'age'   => 30,
		);

		$cells = $mapper->answerCells( $data, $schema );

		$this->assertSame(
			array( 'John', 'john@example.com', '30' ),
			$cells
		);
	}

	public function test_answerCells_converts_null_to_empty_string(): void {
		$schema = FormSchema::fromArray(
			array(
				'version'  => 1,
				'sections' => array(
					array(
						'key'    => 'main',
						'title'  => 'Main',
						'fields' => array(
							array( 'key' => 'name', 'type' => 'text', 'label' => 'Name' ),
							array( 'key' => 'email', 'type' => 'email', 'label' => 'Email' ),
						),
					),
				),
			)
		);

		$mapper = new RegistrationExportMapper();
		$data   = array(
			'name' => 'John',
		);

		$cells = $mapper->answerCells( $data, $schema );

		$this->assertSame(
			array( 'John', '' ),
			$cells
		);
	}

	public function test_answerCells_implodes_array_values(): void {
		$schema = FormSchema::fromArray(
			array(
				'version'  => 1,
				'sections' => array(
					array(
						'key'    => 'main',
						'title'  => 'Main',
						'fields' => array(
							array( 'key' => 'interests', 'type' => 'checkbox-group', 'label' => 'Interests', 'options' => array(
								array( 'value' => 'opt1', 'label' => 'Option 1' ),
								array( 'value' => 'opt2', 'label' => 'Option 2' ),
								array( 'value' => 'opt3', 'label' => 'Option 3' ),
							) ),
						),
					),
				),
			)
		);

		$mapper = new RegistrationExportMapper();
		$data   = array(
			'interests' => array( 'opt1', 'opt3' ),
		);

		$cells = $mapper->answerCells( $data, $schema );

		$this->assertSame(
			array( 'opt1, opt3' ),
			$cells
		);
	}

	public function test_accommodationCells_returns_empty_when_booking_is_null(): void {
		$config = AccommodationConfig::fromArray(
			array(
				'packages'  => array(
					array( 'key' => 'p1', 'label' => 'Package 1' ),
				),
				'rooms'     => array(
					array( 'key' => 'r1', 'label' => 'Room 1' ),
				),
				'inventory' => array(
					array( 'package' => 'p1', 'room' => 'r1', 'capacity' => 10, 'price' => 100.0 ),
				),
			)
		);

		$mapper = new RegistrationExportMapper();
		$result = $mapper->accommodationCells( null, $config );

		$this->assertSame(
			array( 'package' => '', 'room' => '', 'roommate' => '' ),
			$result
		);
	}

	public function test_accommodationCells_resolves_known_package_and_room(): void {
		$config = AccommodationConfig::fromArray(
			array(
				'packages'  => array(
					array( 'key' => 'n12', 'label' => 'Noc 1–2' ),
					array( 'key' => 'n13', 'label' => 'Noce 1–3' ),
				),
				'rooms'     => array(
					array( 'key' => 'single', 'label' => 'Pokój 1-osobowy' ),
					array( 'key' => 'double', 'label' => 'Pokój 2-osobowy', 'roommate_field' => true ),
				),
				'inventory' => array(
					array( 'package' => 'n12', 'room' => 'single', 'capacity' => 10, 'price' => 250.0 ),
					array( 'package' => 'n12', 'room' => 'double', 'capacity' => 20, 'price' => 180.0 ),
					array( 'package' => 'n13', 'room' => 'double', 'capacity' => 5, 'price' => 340.0 ),
				),
			)
		);

		$mapper  = new RegistrationExportMapper();
		$booking = array(
			'package_key'      => 'n12',
			'room_type_key'    => 'double',
			'roommate_pref'    => 'Jan',
		);
		$result  = $mapper->accommodationCells( $booking, $config );

		$this->assertSame(
			array( 'package' => 'Noc 1–2', 'room' => 'Pokój 2-osobowy', 'roommate' => 'Jan' ),
			$result
		);
	}

	public function test_accommodationCells_fallback_unknown_package_key(): void {
		$config = AccommodationConfig::fromArray(
			array(
				'packages'  => array(
					array( 'key' => 'n12', 'label' => 'Noc 1–2' ),
				),
				'rooms'     => array(
					array( 'key' => 'single', 'label' => 'Pokój 1-osobowy' ),
				),
				'inventory' => array(
					array( 'package' => 'n12', 'room' => 'single', 'capacity' => 10, 'price' => 250.0 ),
				),
			)
		);

		$mapper  = new RegistrationExportMapper();
		$booking = array(
			'package_key'      => 'unknown_pkg',
			'room_type_key'    => 'single',
			'roommate_pref'    => '',
		);
		$result  = $mapper->accommodationCells( $booking, $config );

		$this->assertSame(
			array( 'package' => 'unknown_pkg', 'room' => 'Pokój 1-osobowy', 'roommate' => '' ),
			$result
		);
	}

	public function test_accommodationCells_fallback_unknown_room_key(): void {
		$config = AccommodationConfig::fromArray(
			array(
				'packages'  => array(
					array( 'key' => 'n12', 'label' => 'Noc 1–2' ),
				),
				'rooms'     => array(
					array( 'key' => 'single', 'label' => 'Pokój 1-osobowy' ),
				),
				'inventory' => array(
					array( 'package' => 'n12', 'room' => 'single', 'capacity' => 10, 'price' => 250.0 ),
				),
			)
		);

		$mapper  = new RegistrationExportMapper();
		$booking = array(
			'package_key'      => 'n12',
			'room_type_key'    => 'unknown_room',
			'roommate_pref'    => '',
		);
		$result  = $mapper->accommodationCells( $booking, $config );

		$this->assertSame(
			array( 'package' => 'Noc 1–2', 'room' => 'unknown_room', 'roommate' => '' ),
			$result
		);
	}
}
