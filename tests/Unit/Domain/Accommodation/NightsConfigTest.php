<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Accommodation;

use EvReg\Domain\Accommodation\NightsConfig;
use EvReg\Domain\Schema\SchemaException;
use PHPUnit\Framework\TestCase;

final class NightsConfigTest extends TestCase {

	public function test_absent_mode_is_classic(): void {
		$this->assertFalse( NightsConfig::fromArray( array() )->isNights() );
		$this->assertFalse( NightsConfig::fromArray( array( 'mode' => 'classic' ) )->isNights() );
	}

	public function test_nights_mode_exposes_its_nights(): void {
		$config = NightsConfig::fromArray(
			array(
				'mode'   => 'nights',
				'nights' => array(
					array( 'key' => 'n1819', 'label' => '18/19', 'seats' => 72 ),
					array( 'key' => 'n1920', 'label' => '19/20', 'seats' => 65 ),
				),
			)
		);

		$this->assertTrue( $config->isNights() );
		$this->assertCount( 2, $config->nights() );
		$this->assertSame( '18/19', $config->nightLabel( 'n1819' ) );
		$this->assertSame( 72, $config->night( 'n1819' )?->seats );
	}

	public function test_unknown_night_label_falls_back_to_the_raw_key(): void {
		$this->assertSame( 'n9', NightsConfig::fromArray( array( 'mode' => 'nights' ) )->nightLabel( 'n9' ) );
	}

	public function test_defaults_match_the_classic_config(): void {
		$config = NightsConfig::fromArray( array( 'mode' => 'nights' ) );

		$this->assertTrue( $config->allowsNone() );
		$this->assertFalse( $config->companionEnabled() );
		$this->assertFalse( $config->companionCountsEvent() );
	}

	public function test_shared_flags_are_read_without_touching_the_classic_inventory(): void {
		// NOŚNY: inwentarz jest tu NIESPÓJNY (pozycja wskazuje nieznany pakiet),
		// co wysadziłoby AccommodationConfig::fromArray. NightsConfig go nie czyta.
		$config = NightsConfig::fromArray(
			array(
				'mode'              => 'nights',
				'companion_enabled' => true,
				'allow_none'        => false,
				'inventory'         => array( array( 'package' => 'widmo', 'room' => 'widmo', 'capacity' => 1 ) ),
			)
		);

		$this->assertTrue( $config->companionEnabled() );
		$this->assertFalse( $config->allowsNone() );
	}

	public function test_duplicate_night_key_is_rejected(): void {
		$this->expectException( SchemaException::class );
		NightsConfig::fromArray(
			array( 'mode' => 'nights', 'nights' => array( array( 'key' => 'n1' ), array( 'key' => 'n1' ) ) )
		);
	}

	public function test_classic_mode_ignores_the_nights_list(): void {
		$config = NightsConfig::fromArray( array( 'nights' => array( array( 'key' => 'n1' ) ) ) );

		$this->assertFalse( $config->isNights() );
		$this->assertSame( array(), $config->nights() );
	}

	public function test_companion_note_defaults_to_empty_string(): void {
		$this->assertSame( '', NightsConfig::fromArray( array( 'mode' => 'nights' ) )->companionNote() );
	}

	public function test_companion_note_comes_from_config(): void {
		$c = NightsConfig::fromArray( array( 'mode' => 'nights', 'companion_note' => 'Przypis.' ) );
		$this->assertSame( 'Przypis.', $c->companionNote() );
	}
}
