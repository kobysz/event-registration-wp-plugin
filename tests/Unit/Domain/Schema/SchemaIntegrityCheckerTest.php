<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Schema;

use EvReg\Domain\Schema\FormSchema;
use EvReg\Domain\Schema\SchemaException;
use EvReg\Domain\Schema\SchemaIntegrityChecker;
use PHPUnit\Framework\TestCase;

final class SchemaIntegrityCheckerTest extends TestCase {

	private SchemaIntegrityChecker $checker;

	protected function setUp(): void {
		$this->checker = new SchemaIntegrityChecker();
	}

	/**
	 * @param array<int,array<string,mixed>> $sections
	 */
	private function schema( array $sections ): FormSchema {
		return FormSchema::fromArray(
			array(
				'version'  => 1,
				'sections' => $sections,
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function type_field(): array {
		return array(
			'key'     => '__type',
			'type'    => 'radio',
			'label'   => 'Typ',
			'options' => array( array( 'value' => 'uczestnik', 'label' => 'Uczestnik' ) ),
		);
	}

	public function test_accepts_valid_schema(): void {
		$schema = $this->schema(
			array(
				array(
					'key'    => 'a',
					'title'  => 'A',
					'fields' => array( $this->type_field() ),
				),
				array(
					'key'       => 'b',
					'title'     => 'B',
					'condition' => array( 'field' => '__type', 'operator' => 'in', 'value' => array( 'uczestnik' ) ),
					'fields'    => array( array( 'key' => 'x', 'type' => 'text', 'label' => 'X' ) ),
				),
			)
		);

		$this->checker->check( $schema );

		$this->addToAssertionCount( 1 );
	}

	public function test_rejects_duplicate_field_keys(): void {
		$schema = $this->schema(
			array(
				array(
					'key'    => 'a',
					'title'  => 'A',
					'fields' => array(
						$this->type_field(),
						array( 'key' => 'x', 'type' => 'text', 'label' => 'X' ),
						array( 'key' => 'x', 'type' => 'text', 'label' => 'X2' ),
					),
				),
			)
		);

		$this->expectException( SchemaException::class );
		$this->expectExceptionMessage( 'x' );

		$this->checker->check( $schema );
	}

	public function test_rejects_missing_type_field(): void {
		$schema = $this->schema(
			array(
				array(
					'key'    => 'a',
					'title'  => 'A',
					'fields' => array( array( 'key' => 'x', 'type' => 'text', 'label' => 'X' ) ),
				),
			)
		);

		$this->expectException( SchemaException::class );
		$this->expectExceptionMessage( '__type' );

		$this->checker->check( $schema );
	}

	public function test_rejects_condition_pointing_at_unknown_field(): void {
		$schema = $this->schema(
			array(
				array(
					'key'    => 'a',
					'title'  => 'A',
					'fields' => array( $this->type_field() ),
				),
				array(
					'key'       => 'b',
					'title'     => 'B',
					'condition' => array( 'field' => 'nieistnieje', 'operator' => 'equals', 'value' => 'x' ),
					'fields'    => array( array( 'key' => 'x', 'type' => 'text', 'label' => 'X' ) ),
				),
			)
		);

		$this->expectException( SchemaException::class );
		$this->expectExceptionMessage( 'nieistnieje' );

		$this->checker->check( $schema );
	}

	public function test_rejects_condition_pointing_at_later_field(): void {
		$schema = $this->schema(
			array(
				array(
					'key'       => 'a',
					'title'     => 'A',
					'condition' => array( 'field' => 'pozniejsze', 'operator' => 'equals', 'value' => 'x' ),
					'fields'    => array( $this->type_field() ),
				),
				array(
					'key'    => 'b',
					'title'  => 'B',
					'fields' => array( array( 'key' => 'pozniejsze', 'type' => 'text', 'label' => 'P' ) ),
				),
			)
		);

		$this->expectException( SchemaException::class );
		$this->expectExceptionMessage( 'pozniejsze' );

		$this->checker->check( $schema );
	}
}
