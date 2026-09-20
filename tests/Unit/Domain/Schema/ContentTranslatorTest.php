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

	private function accommodation(): array {
		return array(
			'packages'  => array(
				array( 'key' => 'n12', 'label' => 'Noc 1–2' ),
				array( 'key' => 'n23', 'label' => 'Noc 2–3' ),
			),
			'rooms'     => array(
				array( 'key' => 'double', 'label' => 'Pokój 2-osobowy' ),
				array( 'key' => 'single', 'label' => 'Pokój 1-osobowy' ),
			),
			'inventory' => array(
				array( 'package' => 'n12', 'room' => 'double', 'capacity' => 5, 'price' => 180.0 ),
			),
		);
	}

	public function test_translate_accommodation_applies_package_and_room_labels(): void {
		$overlay = array(
			'en' => array(
				'accommodation' => array(
					'packages' => array( 'n12' => 'Night 1–2' ),
					'rooms'    => array( 'double' => 'Double room' ),
				),
			),
		);

		$out = ( new ContentTranslator() )->translateAccommodation( $this->accommodation(), $overlay, 'en' );

		$this->assertSame( 'Night 1–2', $out['packages'][0]['label'] );
		$this->assertSame( 'Noc 2–3', $out['packages'][1]['label'] );      // brak override → baza
		$this->assertSame( 'Double room', $out['rooms'][0]['label'] );
		$this->assertSame( 'Pokój 1-osobowy', $out['rooms'][1]['label'] ); // brak override → baza
		// Klucze/inwentarz nietknięte.
		$this->assertSame( 'n12', $out['packages'][0]['key'] );
		$this->assertSame( 5, $out['inventory'][0]['capacity'] );
	}

	public function test_translate_accommodation_falls_back_to_base(): void {
		$empty   = ( new ContentTranslator() )->translateAccommodation( $this->accommodation(), array( 'en' => array( 'accommodation' => array( 'packages' => array( 'n12' => '' ) ) ) ), 'en' );
		$noLang  = ( new ContentTranslator() )->translateAccommodation( $this->accommodation(), array(), '' );
		$unknown = ( new ContentTranslator() )->translateAccommodation( $this->accommodation(), array( 'en' => array() ), 'de' );

		$this->assertSame( 'Noc 1–2', $empty['packages'][0]['label'] );
		$this->assertSame( 'Noc 1–2', $noLang['packages'][0]['label'] );
		$this->assertSame( 'Noc 1–2', $unknown['packages'][0]['label'] );
	}

	public function test_translate_accommodation_does_not_mutate_input(): void {
		$acc     = $this->accommodation();
		$before  = json_encode( $acc );
		$overlay = array( 'en' => array( 'accommodation' => array( 'packages' => array( 'n12' => 'Night 1–2' ) ) ) );

		( new ContentTranslator() )->translateAccommodation( $acc, $overlay, 'en' );

		$this->assertSame( $before, json_encode( $acc ) );
	}

	public function test_translates_short_label_when_present(): void {
		$schema = array(
			'version'  => 1,
			'sections' => array(
				array(
					'key'    => 'dane',
					'title'  => 'Dane',
					'fields' => array(
						array( 'key' => 'rodo', 'type' => 'checkbox', 'label' => 'Długa zgoda RODO...', 'short_label' => 'Zgoda RODO' ),
						array( 'key' => 'imie', 'type' => 'text', 'label' => 'Imię' ),
					),
				),
			),
		);
		$overlay = array( 'en' => array( 'shortLabels' => array( 'rodo' => 'GDPR consent' ) ) );

		[ $out ] = ( new ContentTranslator() )->apply( $schema, array(), $overlay, 'en' );

		$this->assertSame( 'GDPR consent', $out['sections'][0]['fields'][0]['short_label'] );
		// Pole bez short_label nie dostaje klucza.
		$this->assertArrayNotHasKey( 'short_label', $out['sections'][0]['fields'][1] );
	}

	public function test_short_label_falls_back_to_base_without_override(): void {
		$schema = array(
			'version'  => 1,
			'sections' => array(
				array(
					'key'    => 'dane',
					'title'  => 'Dane',
					'fields' => array( array( 'key' => 'rodo', 'type' => 'checkbox', 'label' => 'X', 'short_label' => 'Zgoda RODO' ) ),
				),
			),
		);
		[ $out ] = ( new ContentTranslator() )->apply( $schema, array(), array( 'en' => array( 'fields' => array( 'rodo' => 'Y' ) ) ), 'en' );

		$this->assertSame( 'Zgoda RODO', $out['sections'][0]['fields'][0]['short_label'] );
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
