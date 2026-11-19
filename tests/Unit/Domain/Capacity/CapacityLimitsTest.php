<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Capacity;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Accommodation\NightsConfig;
use EvReg\Domain\Capacity\CapacityLimits;
use EvReg\Domain\Registration\RegistrationTypeCollection;
use PHPUnit\Framework\TestCase;

final class CapacityLimitsTest extends TestCase {

	private function types(): RegistrationTypeCollection {
		return RegistrationTypeCollection::fromArray(
			array(
				array( 'key' => 'std', 'label' => 'Standard', 'price' => 10, 'capacity' => 40 ),
				array( 'key' => 'free', 'label' => 'Free', 'price' => 0, 'capacity' => null ),
			)
		);
	}

	/**
	 * @param array<mixed> $settings Ustawienia eventu.
	 */
	private function classic( array $settings, ?AccommodationConfig $accommodation = null ): CapacityLimits {
		return CapacityLimits::forClassic( $settings, $this->types(), $accommodation ?? AccommodationConfig::fromArray( array() ) );
	}

	public function test_global_cap_absent_or_null_means_unlimited(): void {
		$this->assertNull( $this->classic( array() )->globalLimit );
		$this->assertNull( $this->classic( array( 'global_cap' => null ) )->globalLimit );
	}

	public function test_global_cap_numeric_string_cast_to_int(): void {
		$this->assertSame( 5, $this->classic( array( 'global_cap' => '5' ) )->globalLimit );
		$this->assertSame( 0, $this->classic( array( 'global_cap' => 0 ) )->globalLimit );
	}

	public function test_waitlist_defaults_to_true(): void {
		$this->assertTrue( $this->classic( array() )->waitlistEnabled );
	}

	public function test_waitlist_false_respected(): void {
		$this->assertFalse( $this->classic( array( 'waitlist_enabled' => false ) )->waitlistEnabled );
	}

	public function test_type_limits_from_collection(): void {
		$limits = $this->classic( array() );

		$this->assertSame( 40, $limits->forType( 'std' ) );
		$this->assertNull( $limits->forType( 'free' ) );
	}

	public function test_classic_takes_slot_caps_and_companion_flag_from_accommodation(): void {
		$accommodation = AccommodationConfig::fromArray(
			array(
				'packages'               => array( array( 'key' => 'pkg', 'label' => 'Pakiet' ) ),
				'rooms'                  => array( array( 'key' => 'room', 'label' => 'Pokój' ) ),
				'inventory'              => array( array( 'package' => 'pkg', 'room' => 'room', 'capacity' => 7, 'price' => 100 ) ),
				'companion_enabled'      => true,
				'companion_counts_event' => true,
			)
		);

		$limits = $this->classic( array(), $accommodation );

		$this->assertSame( 7, $limits->forSlot( 'pkg|room' ) );
		$this->assertTrue( $limits->companionCountsEvent );
	}

	public function test_nights_has_no_slots_and_companion_flag_from_nights_config(): void {
		$nights = NightsConfig::fromArray(
			array(
				'mode'                   => 'nights',
				'companion_enabled'      => true,
				'companion_counts_event' => true,
				'nights'                 => array( array( 'key' => 'n1', 'label' => 'N1', 'seats' => 10 ) ),
			)
		);

		$limits = CapacityLimits::forNights( array( 'global_cap' => 9 ), $this->types(), $nights );

		$this->assertNull( $limits->forSlot( 'pkg|room' ) );
		$this->assertTrue( $limits->companionCountsEvent );
		$this->assertSame( 9, $limits->globalLimit );
	}

	public function test_classic_takes_room_occupancy_into_slot_seats(): void {
		$accommodation = AccommodationConfig::fromArray(
			array(
				'packages'          => array( array( 'key' => 'pkg', 'label' => 'Pakiet' ) ),
				'rooms'             => array(
					array( 'key' => 'two', 'label' => 'Dwójka', 'occupancy' => 2 ),
					array( 'key' => 'unset', 'label' => 'Nieustawiony' ),
				),
				'inventory'         => array(
					array( 'package' => 'pkg', 'room' => 'two', 'capacity' => 3, 'price' => 100 ),
					array( 'package' => 'pkg', 'room' => 'unset', 'capacity' => 3, 'price' => 100 ),
				),
				'companion_enabled' => true,
			)
		);

		$limits = $this->classic( array(), $accommodation );

		$this->assertSame( 2, $limits->seatsForSlot( 'pkg|two', false ) );
		$this->assertSame( 1, $limits->seatsForSlot( 'pkg|unset', false ) );
		$this->assertSame( 2, $limits->seatsForSlot( 'pkg|unset', true ) );
	}

	/**
	 * @return array<string,array{0:bool}>
	 */
	public static function companion_flags(): array {
		return array(
			'true'  => array( true ),
			'false' => array( false ),
		);
	}

	/**
	 * @dataProvider companion_flags
	 */
	public function test_classic_companion_counts_event_follows_config( bool $flag ): void {
		$accommodation = AccommodationConfig::fromArray(
			array(
				'companion_enabled'      => true,
				'companion_counts_event' => $flag,
			)
		);

		$this->assertSame( $flag, $this->classic( array(), $accommodation )->companionCountsEvent );
	}

	/**
	 * @dataProvider companion_flags
	 */
	public function test_nights_companion_counts_event_follows_config( bool $flag ): void {
		$nights = NightsConfig::fromArray(
			array(
				'mode'                   => 'nights',
				'companion_enabled'      => true,
				'companion_counts_event' => $flag,
			)
		);

		$this->assertSame( $flag, CapacityLimits::forNights( array(), $this->types(), $nights )->companionCountsEvent );
	}
}
