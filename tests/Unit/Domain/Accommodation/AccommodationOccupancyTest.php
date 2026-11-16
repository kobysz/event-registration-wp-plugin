<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Accommodation;

use EvReg\Domain\Accommodation\AccommodationConfig;
use PHPUnit\Framework\TestCase;

final class AccommodationOccupancyTest extends TestCase {

	/**
	 * @param bool $companion_enabled Czy event dopuszcza osobę towarzyszącą.
	 * @return array<string,mixed>
	 */
	private function raw( bool $companion_enabled = true ): array {
		return array(
			'packages'          => array( array( 'key' => 'n18', 'label' => 'Noc 18/19' ) ),
			'rooms'             => array(
				array( 'key' => 'single', 'label' => '1-os.', 'occupancy' => 1 ),
				array( 'key' => 'double', 'label' => '2-os.', 'occupancy' => 2 ),
				array( 'key' => 'any', 'label' => 'dowolny' ),
			),
			'inventory'         => array(
				array( 'package' => 'n18', 'room' => 'single', 'capacity' => 10, 'price' => 100.0 ),
				array( 'package' => 'n18', 'room' => 'double', 'capacity' => 4, 'price' => 180.0 ),
				array( 'package' => 'n18', 'room' => 'any', 'capacity' => 2, 'price' => 150.0 ),
			),
			'companion_enabled' => $companion_enabled,
		);
	}

	public function test_slot_occupancies_maps_every_inventory_slot(): void {
		$config = AccommodationConfig::fromArray( $this->raw() );

		$this->assertSame(
			array( 'n18|single' => 1, 'n18|double' => 2, 'n18|any' => 0 ),
			$config->slotOccupancies()
		);
	}

	public function test_seats_for_uses_room_occupancy(): void {
		$config = AccommodationConfig::fromArray( $this->raw() );

		$this->assertSame( 1, $config->seatsFor( 'single', true ) );
		$this->assertSame( 2, $config->seatsFor( 'double', false ) );
	}

	public function test_seats_for_unset_room_falls_back_to_companion(): void {
		$config = AccommodationConfig::fromArray( $this->raw() );

		$this->assertSame( 1, $config->seatsFor( 'any', false ) );
		$this->assertSame( 2, $config->seatsFor( 'any', true ) );
	}

	public function test_seats_for_unknown_room_falls_back_to_companion(): void {
		$config = AccommodationConfig::fromArray( $this->raw() );

		$this->assertSame( 1, $config->seatsFor( 'ghost', false ) );
		$this->assertSame( 2, $config->seatsFor( 'ghost', true ) );
	}

	public function test_occupancy_is_zeroed_when_companion_disabled(): void {
		// Sprzeczna konfiguracja (pokój na 2 osoby przy wyłączonej towarzyszącej)
		// czyta się jak „nieustawione" — inaczej formularz nie oferowałby żadnego
		// pokoju dwuosobowego, a companion_required byłoby niespełnialne.
		$config = AccommodationConfig::fromArray( $this->raw( false ) );

		$this->assertSame(
			array( 'n18|single' => 0, 'n18|double' => 0, 'n18|any' => 0 ),
			$config->slotOccupancies()
		);
		$this->assertSame( 0, $config->room( 'double' )->occupancy );
		$this->assertNull( $config->room( 'double' )->companionMismatch( false ) );
	}
}
