<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Schema;

use EvReg\Domain\Schema\FieldType;
use EvReg\Domain\Schema\SchemaAssembler;
use EvReg\Domain\Schema\SchemaException;
use PHPUnit\Framework\TestCase;

final class SchemaAssemblerTest extends TestCase {

	private SchemaAssembler $assembler;

	protected function setUp(): void {
		$this->assembler = new SchemaAssembler();
	}

	/**
	 * @return array<string,mixed>
	 */
	private function raw_schema(): array {
		return array(
			'version'  => 1,
			'sections' => array(
				array(
					'key'    => 'dane',
					'title'  => 'Dane',
					'fields' => array(
						array( 'key' => '__type', 'type' => 'radio', 'label' => 'Typ zgłoszenia' ),
						array( 'key' => 'email', 'type' => 'email', 'label' => 'E-mail', 'required' => true ),
					),
				),
				array(
					'key'    => 'noclegi',
					'title'  => 'Noclegi',
					'fields' => array(
						array( 'key' => 'nocleg', 'type' => 'accommodation', 'label' => 'Nocleg' ),
					),
				),
			),
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function raw_types(): array {
		return array(
			array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 450.0, 'capacity' => 100 ),
			array( 'key' => 'online', 'label' => 'Uczestnik on-line', 'price' => 150.0 ),
			array( 'key' => 'wykladowca', 'label' => 'Wykładowca', 'active' => false ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function raw_accommodation(): array {
		return array(
			'packages'   => array( array( 'key' => 'n12', 'label' => 'Noc 1–2' ) ),
			'rooms'      => array( array( 'key' => 'double', 'label' => 'Pokój 2-os.', 'roommate_field' => true ) ),
			'inventory'  => array(
				array( 'package' => 'n12', 'room' => 'double', 'capacity' => 20, 'price' => 180.0 ),
			),
			'allow_none' => true,
		);
	}

	public function test_injects_active_types_as_type_field_options(): void {
		$schema = $this->assembler->assemble( $this->raw_schema(), $this->raw_types(), $this->raw_accommodation() );

		$type_field = $schema->findField( '__type' );

		$this->assertNotNull( $type_field );
		$values = array_map( static fn ( $option ) => $option->value, $type_field->options );
		$this->assertSame( array( 'uczestnik', 'online' ), $values );
		$labels = array_map( static fn ( $option ) => $option->label, $type_field->options );
		$this->assertSame( array( 'Uczestnik', 'Uczestnik on-line' ), $labels );
	}

	public function test_injects_accommodation_config_into_accommodation_field(): void {
		$schema = $this->assembler->assemble( $this->raw_schema(), $this->raw_types(), $this->raw_accommodation() );

		$field = $schema->findField( 'nocleg' );

		$this->assertNotNull( $field );
		$this->assertSame( FieldType::Accommodation, $field->type );
		$this->assertSame( $this->raw_accommodation(), $field->config );
	}

	public function test_assembled_schema_passes_integrity_check(): void {
		$this->assembler->validate( $this->raw_schema(), $this->raw_types(), $this->raw_accommodation() );

		$this->addToAssertionCount( 1 );
	}

	public function test_validate_throws_when_no_active_types_leave_type_field_optionless(): void {
		$types = array(
			array( 'key' => 'wykladowca', 'label' => 'Wykładowca', 'active' => false ),
		);

		$this->expectException( SchemaException::class );

		$this->assembler->validate( $this->raw_schema(), $types, $this->raw_accommodation() );
	}

	public function test_validate_throws_when_type_field_missing(): void {
		$schema = $this->raw_schema();
		// Usuń pole __type — złożona schema łamie regułę integralności.
		array_shift( $schema['sections'][0]['fields'] );

		$this->expectException( SchemaException::class );

		$this->assembler->validate( $schema, $this->raw_types(), $this->raw_accommodation() );
	}

	public function test_accommodation_field_without_config_gets_empty_when_no_accommodation_data(): void {
		$schema = $this->assembler->assemble( $this->raw_schema(), $this->raw_types(), array() );

		$field = $schema->findField( 'nocleg' );

		$this->assertNotNull( $field );
		$this->assertSame( array(), $field->config );
	}

	public function test_assemble_ignores_scalar_section_entry(): void {
		$schema = array(
			'version'  => 1,
			'sections' => array( 'foo' ),
		);

		$this->expectException( SchemaException::class );

		$this->assembler->validate( $schema, $this->raw_types(), $this->raw_accommodation() );
	}

	public function test_assemble_ignores_scalar_field_entry(): void {
		$schema = array(
			'version'  => 1,
			'sections' => array(
				array(
					'key'    => 'dane',
					'title'  => 'Dane',
					'fields' => array(
						'garbage',
						array( 'key' => '__type', 'type' => 'radio', 'label' => 'Typ' ),
					),
				),
			),
		);

		$result = $this->assembler->assemble( $schema, $this->raw_types(), $this->raw_accommodation() );

		$type_field = $result->findField( '__type' );

		$this->assertNotNull( $type_field );
		$values = array_map( static fn ( $option ) => $option->value, $type_field->options );
		$this->assertSame( array( 'uczestnik', 'online' ), $values );
	}
}
