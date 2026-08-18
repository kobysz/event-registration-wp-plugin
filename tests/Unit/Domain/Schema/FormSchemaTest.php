<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Schema;

use EvReg\Domain\Conditions\Operator;
use EvReg\Domain\Schema\FieldType;
use EvReg\Domain\Schema\FormSchema;
use EvReg\Domain\Schema\SchemaException;
use PHPUnit\Framework\TestCase;

final class FormSchemaTest extends TestCase {

	/**
	 * @return array<string,mixed>
	 */
	private function valid_schema(): array {
		return array(
			'version'  => 1,
			'sections' => array(
				array(
					'key'    => 'dane',
					'title'  => 'Dane uczestnika',
					'fields' => array(
						array(
							'key'     => '__type',
							'type'    => 'radio',
							'label'   => 'Typ zgłoszenia',
							'options' => array(
								array(
									'value' => 'uczestnik',
									'label' => 'Uczestnik',
								),
							),
						),
						array(
							'key'      => 'email',
							'type'     => 'email',
							'label'    => 'E-mail',
							'required' => true,
						),
					),
				),
				array(
					'key'       => 'noclegi',
					'title'     => 'Noclegi',
					'condition' => array(
						'field'    => '__type',
						'operator' => 'in',
						'value'    => array( 'uczestnik' ),
					),
					'fields'    => array(
						array(
							'key'   => 'nocleg',
							'type'  => 'accommodation',
							'label' => 'Nocleg',
						),
					),
				),
			),
		);
	}

	public function test_parses_sections_and_fields(): void {
		$schema = FormSchema::fromArray( $this->valid_schema() );

		$this->assertCount( 2, $schema->sections() );
		$this->assertCount( 3, $schema->allFields() );
		$this->assertSame( FieldType::Email, $schema->findField( 'email' )->type );
		$this->assertTrue( $schema->findField( 'email' )->required );
	}

	public function test_parses_section_condition(): void {
		$schema    = FormSchema::fromArray( $this->valid_schema() );
		$condition = $schema->sections()[1]->condition;

		$this->assertNotNull( $condition );
		$this->assertSame( '__type', $condition->field );
		$this->assertSame( Operator::In, $condition->operator );
		$this->assertSame( array( 'uczestnik' ), $condition->value );
	}

	public function test_round_trips_through_array(): void {
		$input = $this->valid_schema();

		$this->assertSame( $input, FormSchema::fromArray( $input )->toArray() );
	}

	public function test_rejects_unknown_field_type(): void {
		$data = $this->valid_schema();
		$data['sections'][0]['fields'][1]['type'] = 'signature';

		$this->expectException( SchemaException::class );
		$this->expectExceptionMessage( 'signature' );

		FormSchema::fromArray( $data );
	}

	public function test_rejects_field_without_key(): void {
		$data = $this->valid_schema();
		unset( $data['sections'][0]['fields'][1]['key'] );

		$this->expectException( SchemaException::class );

		FormSchema::fromArray( $data );
	}

	public function test_rejects_unknown_operator(): void {
		$data = $this->valid_schema();
		$data['sections'][1]['condition']['operator'] = 'matches';

		$this->expectException( SchemaException::class );

		FormSchema::fromArray( $data );
	}

	public function test_rejects_choice_field_without_options(): void {
		$data = $this->valid_schema();
		unset( $data['sections'][0]['fields'][0]['options'] );

		$this->expectException( SchemaException::class );
		$this->expectExceptionMessage( '__type' );

		FormSchema::fromArray( $data );
	}

	public function test_rejects_condition_operator_without_value(): void {
		$data = $this->valid_schema();
		$data['sections'][1]['condition']['operator'] = 'equals';
		unset( $data['sections'][1]['condition']['value'] );

		$this->expectException( SchemaException::class );
		$this->expectExceptionMessage( 'equals' );

		FormSchema::fromArray( $data );
	}

	public function test_section_of_finds_owning_section_or_null(): void {
		$schema = FormSchema::fromArray( $this->valid_schema() );

		$this->assertSame( 'noclegi', $schema->sectionOf( 'nocleg' )->key );
		$this->assertNull( $schema->sectionOf( 'brak-takiego-pola' ) );
	}

	public function test_empty_schema_has_no_sections(): void {
		$this->assertSame( array(), FormSchema::empty()->sections() );
	}

	public function test_field_type_knows_whether_it_collects_input(): void {
		$this->assertTrue( FieldType::Text->isInput() );
		$this->assertFalse( FieldType::Heading->isInput() );
		$this->assertTrue( FieldType::Select->hasOptions() );
		$this->assertFalse( FieldType::Text->hasOptions() );
	}
}
