<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Accommodation;

use EvReg\Domain\Accommodation\NightSelection;
use EvReg\Domain\Accommodation\NightsConfig;
use EvReg\Domain\Capacity\NightOccupancy;
use PHPUnit\Framework\TestCase;

final class NightSelectionTest extends TestCase {

	/**
	 * Buduje prawdziwą konfigurację trybu „doby” z podanymi kluczami.
	 *
	 * @param string[] $keys Klucze dób w kolejności konfiguracji.
	 */
	private function config( array $keys ): NightsConfig {
		$nights = array();
		foreach ( $keys as $key ) {
			$nights[] = array(
				'key'   => $key,
				'label' => strtoupper( $key ),
				'price' => 10,
				'seats' => 5,
			);
		}

		return NightsConfig::fromArray(
			array(
				'mode'   => 'nights',
				'nights' => $nights,
			)
		);
	}

	public function test_selection_keeps_configuration_order_and_drops_duplicates(): void {
		$config = $this->config( array( 'n1', 'n2', 'n3' ) );

		$selection = NightSelection::fromKeys( array( 'n3', 'n1', 'n3' ), $config );

		$this->assertSame( array( 'n1', 'n3' ), $selection->keys() );
	}

	public function test_unknown_keys_are_dropped(): void {
		$selection = NightSelection::fromKeys( array( 'n1', 'widmo' ), $this->config( array( 'n1' ) ) );

		$this->assertSame( array( 'n1' ), $selection->keys() );
	}

	public function test_empty_selection_is_empty(): void {
		$this->assertTrue( NightSelection::fromKeys( array(), $this->config( array( 'n1' ) ) )->isEmpty() );
		$this->assertTrue( NightSelection::fromKeys( array( 'widmo' ), $this->config( array( 'n1' ) ) )->isEmpty() );
	}

	public function test_occupancy_returns_given_numbers_for_a_known_night(): void {
		$occupancy = new NightOccupancy( array( 'n1' => 7 ), array( 'n1' => 2 ) );

		$this->assertSame( 7, $occupancy->seatsFor( 'n1' ) );
		$this->assertSame( 2, $occupancy->pairsFor( 'n1' ) );
	}

	public function test_occupancy_returns_zero_for_an_unknown_night(): void {
		$occupancy = new NightOccupancy( array( 'n1' => 7 ), array( 'n1' => 2 ) );

		$this->assertSame( 0, $occupancy->seatsFor( 'n9' ) );
		$this->assertSame( 0, $occupancy->pairsFor( 'n9' ) );
	}
}
