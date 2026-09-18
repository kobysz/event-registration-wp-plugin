<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Schema;

use EvReg\Domain\Schema\ContentTranslator;
use PHPUnit\Framework\TestCase;

final class ContentTranslatorTest extends TestCase {

	private function schema(): array {
		return array(
			'version'  => 1,
			'sections' => array(
				array(
					'key'    => 'dane',
					'title'  => 'Dane',
					'fields' => array(
						array( 'key' => 'imie', 'type' => 'text', 'label' => 'Imię' ),
						array(
							'key'     => 'rozmiar',
							'type'    => 'select',
							'label'   => 'Rozmiar',
							'options' => array(
								array( 'value' => 's', 'label' => 'Mały' ),
								array( 'value' => 'l', 'label' => 'Duży' ),
							),
						),
					),
				),
			),
		);
	}

	private function types(): array {
		return array(
			array( 'key' => 'pacjent', 'label' => 'Pacjent' ),
			array( 'key' => 'prelegent', 'label' => 'Prelegent' ),
		);
	}

	public function test_applies_overrides_for_language(): void {
		$overlay = array(
			'en' => array(
				'sections' => array( 'dane' => 'Details' ),
				'fields'   => array( 'imie' => 'First name' ),
				'types'    => array( 'pacjent' => 'Patient' ),
				'options'  => array( 'rozmiar' => array( 's' => 'Small' ) ),
			),
		);

		[ $schema, $types ] = ( new ContentTranslator() )->apply( $this->schema(), $this->types(), $overlay, 'en' );

		$this->assertSame( 'Details', $schema['sections'][0]['title'] );
		$this->assertSame( 'First name', $schema['sections'][0]['fields'][0]['label'] );
		$this->assertSame( 'Small', $schema['sections'][0]['fields'][1]['options'][0]['label'] );
		$this->assertSame( 'Duży', $schema['sections'][0]['fields'][1]['options'][1]['label'] ); // brak override → baza
		$this->assertSame( 'Patient', $types[0]['label'] );
		$this->assertSame( 'Prelegent', $types[1]['label'] ); // brak override → baza
	}

	public function test_missing_or_empty_override_falls_back_to_base(): void {
		$overlay = array( 'en' => array( 'fields' => array( 'imie' => '' ) ) );

		[ $schema ] = ( new ContentTranslator() )->apply( $this->schema(), $this->types(), $overlay, 'en' );

		$this->assertSame( 'Imię', $schema['sections'][0]['fields'][0]['label'] );
	}

	public function test_empty_lang_or_unknown_returns_base(): void {
		$overlay = array( 'en' => array( 'fields' => array( 'imie' => 'First name' ) ) );

		[ $schema1 ] = ( new ContentTranslator() )->apply( $this->schema(), $this->types(), $overlay, '' );
		[ $schema2 ] = ( new ContentTranslator() )->apply( $this->schema(), $this->types(), $overlay, 'de' );

		$this->assertSame( 'Imię', $schema1['sections'][0]['fields'][0]['label'] );
		$this->assertSame( 'Imię', $schema2['sections'][0]['fields'][0]['label'] );
	}

	public function test_does_not_mutate_input(): void {
		$schema  = $this->schema();
		$types   = $this->types();
		$overlay = array( 'en' => array( 'fields' => array( 'imie' => 'First name' ) ) );
		$before_schema = json_encode( $schema );
		$before_types  = json_encode( $types );

		( new ContentTranslator() )->apply( $schema, $types, $overlay, 'en' );

		$this->assertSame( $before_schema, json_encode( $schema ) );
		$this->assertSame( $before_types, json_encode( $types ) );
	}
}
