<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Accommodation;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Schema\SchemaException;
use PHPUnit\Framework\TestCase;

final class AccommodationConfigTest extends TestCase {

	/**
	 * @return array<string,mixed>
	 */
	private function raw(): array {
		return array(
			'packages'   => array(
				array( 'key' => 'n12', 'label' => 'Noc 1–2' ),
				array( 'key' => 'n13', 'label' => 'Noce 1–3' ),
			),
			'rooms'      => array(
				array( 'key' => 'single', 'label' => 'Pokój 1-osobowy' ),
				array( 'key' => 'double', 'label' => 'Pokój 2-osobowy', 'roommate_field' => true ),
			),
			'inventory'  => array(
				array( 'package' => 'n12', 'room' => 'single', 'capacity' => 10, 'price' => 250.0 ),
				array( 'package' => 'n12', 'room' => 'double', 'capacity' => 20, 'price' => 180.0 ),
				array( 'package' => 'n13', 'room' => 'double', 'capacity' => 5, 'price' => 340.0 ),
			),
			'allow_none' => true,
		);
	}

	public function test_parses_config(): void {
		$config = AccommodationConfig::fromArray( $this->raw() );

		$this->assertCount( 2, $config->packages() );
		$this->assertTrue( $config->room( 'double' )->roommateField );
		$this->assertFalse( $config->room( 'single' )->roommateField );
		$this->assertTrue( $config->allowsNone() );
	}

	public function test_item_lookup_returns_price_and_capacity(): void {
		$config = AccommodationConfig::fromArray( $this->raw() );
		$item   = $config->item( 'n12', 'double' );

		$this->assertNotNull( $item );
		$this->assertSame( 20, $item->capacity );
		$this->assertSame( 180.0, $item->price );
		$this->assertSame( 'n12|double', $item->slotKey() );
	}

	public function test_item_lookup_returns_null_for_unavailable_combination(): void {
		$config = AccommodationConfig::fromArray( $this->raw() );

		$this->assertNull( $config->item( 'n13', 'single' ) );
	}

	public function test_capacities_are_keyed_by_slot(): void {
		$config = AccommodationConfig::fromArray( $this->raw() );

		$this->assertSame(
			array( 'n12|single' => 10, 'n12|double' => 20, 'n13|double' => 5 ),
			$config->capacities()
		);
	}

	public function test_rejects_inventory_pointing_at_unknown_package(): void {
		$raw                = $this->raw();
		$raw['inventory'][] = array( 'package' => 'n99', 'room' => 'single', 'capacity' => 1, 'price' => 1.0 );

		$this->expectException( SchemaException::class );
		$this->expectExceptionMessage( 'n99' );

		AccommodationConfig::fromArray( $raw );
	}

	public function test_rejects_inventory_pointing_at_unknown_room(): void {
		$raw                = $this->raw();
		$raw['inventory'][] = array( 'package' => 'n12', 'room' => 'suite', 'capacity' => 1, 'price' => 1.0 );

		$this->expectException( SchemaException::class );
		$this->expectExceptionMessage( 'suite' );

		AccommodationConfig::fromArray( $raw );
	}

	public function test_rejects_duplicate_inventory_entry(): void {
		$raw                = $this->raw();
		$raw['inventory'][] = array( 'package' => 'n12', 'room' => 'single', 'capacity' => 3, 'price' => 200.0 );

		$this->expectException( SchemaException::class );
		$this->expectExceptionMessage( 'n12|single' );

		AccommodationConfig::fromArray( $raw );
	}

	public function test_companion_flags(): void {
		$c = AccommodationConfig::fromArray(
			array(
				'companion_enabled'      => true,
				'companion_counts_event' => true,
			)
		);
		$this->assertTrue( $c->companionEnabled() );
		$this->assertTrue( $c->companionCountsEvent() );

		$d = AccommodationConfig::fromArray( array() );
		$this->assertFalse( $d->companionEnabled() );
		$this->assertFalse( $d->companionCountsEvent() );
	}
}
