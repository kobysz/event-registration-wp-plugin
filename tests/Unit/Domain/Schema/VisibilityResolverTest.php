<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Schema;

use EvReg\Domain\Conditions\ConditionEngine;
use EvReg\Domain\Schema\FormSchema;
use EvReg\Domain\Schema\VisibilityResolver;
use PHPUnit\Framework\TestCase;

final class VisibilityResolverTest extends TestCase {

	private VisibilityResolver $resolver;

	protected function setUp(): void {
		$this->resolver = new VisibilityResolver( new ConditionEngine() );
	}

	private function schema(): FormSchema {
		return FormSchema::fromArray(
			array(
				'version'  => 1,
				'sections' => array(
					array(
						'key'    => 'dane',
						'title'  => 'Dane',
						'fields' => array(
							array(
								'key'     => '__type',
								'type'    => 'radio',
								'label'   => 'Typ',
								'options' => array(
									array( 'value' => 'uczestnik', 'label' => 'Uczestnik' ),
									array( 'value' => 'online', 'label' => 'Online' ),
								),
							),
						),
					),
					array(
						'key'       => 'noclegi',
						'title'     => 'Noclegi',
						'condition' => array( 'field' => '__type', 'operator' => 'in', 'value' => array( 'uczestnik' ) ),
						'fields'    => array(
							array( 'key' => 'nocleg', 'type' => 'accommodation', 'label' => 'Nocleg' ),
							array(
								'key'       => 'uwagi_hotel',
								'type'      => 'textarea',
								'label'     => 'Uwagi',
								'condition' => array( 'field' => 'nocleg', 'operator' => 'not_empty' ),
							),
						),
					),
				),
			)
		);
	}

	public function test_section_hidden_when_condition_not_met(): void {
		$set = $this->resolver->resolve( $this->schema(), array( '__type' => 'online' ) );

		$this->assertTrue( $set->isSectionVisible( 'dane' ) );
		$this->assertFalse( $set->isSectionVisible( 'noclegi' ) );
		$this->assertFalse( $set->isFieldVisible( 'nocleg' ) );
	}

	public function test_field_hidden_when_own_condition_not_met(): void {
		$set = $this->resolver->resolve( $this->schema(), array( '__type' => 'uczestnik' ) );

		$this->assertTrue( $set->isFieldVisible( 'nocleg' ) );
		$this->assertFalse( $set->isFieldVisible( 'uwagi_hotel' ) );
	}

	public function test_field_visible_when_both_conditions_met(): void {
		$set = $this->resolver->resolve(
			$this->schema(),
			array(
				'__type' => 'uczestnik',
				'nocleg' => array( 'package' => 'n12', 'room' => 'single' ),
			)
		);

		$this->assertTrue( $set->isFieldVisible( 'uwagi_hotel' ) );
	}

	public function test_visible_fields_returns_only_visible(): void {
		$set  = $this->resolver->resolve( $this->schema(), array( '__type' => 'online' ) );
		$keys = array_map( static fn ( $field ) => $field->key, $set->visibleFields() );

		$this->assertSame( array( '__type' ), $keys );
	}
}
