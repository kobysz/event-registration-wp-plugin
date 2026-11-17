<?php
/**
 * Bramka pojemności w trybie „doby”.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Capacity;

use EvReg\Domain\Accommodation\NightSelection;
use EvReg\Domain\Accommodation\NightsConfig;
use EvReg\Domain\Capacity\NightCapacity;
use EvReg\Domain\Capacity\NightOccupancy;
use PHPUnit\Framework\TestCase;

/**
 * Bramka wskazuje pierwszą dobę bez miejsca albo bez limitu par.
 */
final class NightCapacityTest extends TestCase {

	/**
	 * Buduje konfigurację trybu „doby” z mapy klucz => specyfikacja.
	 *
	 * @param array<string,array<string,mixed>> $specs Specyfikacje dób.
	 */
	private function configWith( array $specs ): NightsConfig {
		$nights = array();

		foreach ( $specs as $key => $spec ) {
			$nights[] = array_merge( array( 'key' => $key ), $spec );
		}

		return NightsConfig::fromArray( array( 'mode' => 'nights', 'nights' => $nights ) );
	}

	/**
	 * Normalizuje wybór względem konfiguracji.
	 *
	 * @param NightsConfig $config Konfiguracja.
	 * @param string[]     $keys   Klucze dób.
	 */
	private function sel( NightsConfig $config, array $keys ): NightSelection {
		return NightSelection::fromKeys( $keys, $config );
	}

	/**
	 * Skrót: miejsca per doba, zajęte per doba, wybór, towarzysząca.
	 *
	 * @param array<string,int> $seats    Miejsca per doba.
	 * @param array<string,int> $taken    Zajęte miejsca per doba.
	 * @param string[]          $selected Wybrane doby.
	 * @param bool              $companion Czy z osobą towarzyszącą.
	 */
	private function decide( array $seats, array $taken, array $selected, bool $companion ): ?string {
		$specs = array();

		foreach ( $seats as $key => $count ) {
			$specs[ $key ] = array( 'seats' => $count );
		}

		$config = $this->configWith( $specs );

		return NightCapacity::blockingNight(
			$config,
			new NightOccupancy( $taken, array() ),
			$this->sel( $config, $selected ),
			$companion
		);
	}

	public function test_empty_nights_fit_anywhere(): void {
		$this->assertNull( $this->decide( array( 'n1' => 72 ), array(), array( 'n1' ), false ) );
	}

	public function test_a_lone_registrant_takes_one_seat(): void {
		$this->assertNull( $this->decide( array( 'n1' => 1 ), array( 'n1' => 0 ), array( 'n1' ), false ) );
	}

	public function test_a_companion_takes_two_seats(): void {
		$this->assertSame( 'n1', $this->decide( array( 'n1' => 1 ), array( 'n1' => 0 ), array( 'n1' ), true ) );
	}

	public function test_zero_seats_closes_the_night(): void {
		$this->assertSame( 'n1', $this->decide( array( 'n1' => 0 ), array(), array( 'n1' ), false ) );
	}

	public function test_the_second_night_can_be_the_blocking_one(): void {
		$blocking = $this->decide(
			array( 'n1' => 10, 'n2' => 1 ),
			array( 'n1' => 0, 'n2' => 1 ),
			array( 'n1', 'n2' ),
			false
		);

		$this->assertSame( 'n2', $blocking );
	}

	public function test_companion_cap_blocks_a_pair_while_seats_remain(): void {
		$config = $this->configWith( array( 'n1' => array( 'seats' => 72, 'companion_cap' => 2 ) ) );
		$taken  = new NightOccupancy( array( 'n1' => 4 ), array( 'n1' => 2 ) );

		$this->assertNull( NightCapacity::blockingNight( $config, $taken, $this->sel( $config, array( 'n1' ) ), false ) );
		$this->assertSame( 'n1', NightCapacity::blockingNight( $config, $taken, $this->sel( $config, array( 'n1' ) ), true ) );
	}

	public function test_empty_companion_cap_means_no_limit_on_pairs(): void {
		$config = $this->configWith( array( 'n1' => array( 'seats' => 72, 'companion_cap' => '' ) ) );
		$taken  = new NightOccupancy( array( 'n1' => 40 ), array( 'n1' => 20 ) );

		$this->assertNull( NightCapacity::blockingNight( $config, $taken, $this->sel( $config, array( 'n1' ) ), true ) );
	}

	public function test_companion_cap_of_zero_closes_the_night_for_pairs_only(): void {
		$config = $this->configWith( array( 'n1' => array( 'seats' => 72, 'companion_cap' => 0 ) ) );
		$taken  = new NightOccupancy( array(), array() );

		$this->assertNull( NightCapacity::blockingNight( $config, $taken, $this->sel( $config, array( 'n1' ) ), false ) );
		$this->assertSame( 'n1', NightCapacity::blockingNight( $config, $taken, $this->sel( $config, array( 'n1' ) ), true ) );
	}

	public function test_companion_cap_blocks_on_the_second_night_of_a_selection(): void {
		$config = $this->configWith(
			array(
				'n1' => array( 'seats' => 72, 'companion_cap' => 31 ),
				'n2' => array( 'seats' => 65, 'companion_cap' => 28 ),
			)
		);
		$taken  = new NightOccupancy( array( 'n1' => 10, 'n2' => 10 ), array( 'n1' => 5, 'n2' => 28 ) );

		$this->assertSame( 'n2', NightCapacity::blockingNight( $config, $taken, $this->sel( $config, array( 'n1', 'n2' ) ), true ) );
	}

	public function test_leading_example(): void {
		$config = $this->configWith(
			array(
				'n1819' => array( 'seats' => 72, 'companion_cap' => 31 ),
				'n1920' => array( 'seats' => 65, 'companion_cap' => 28 ),
			)
		);
		$taken  = new NightOccupancy( array( 'n1819' => 70, 'n1920' => 10 ), array( 'n1819' => 5, 'n1920' => 5 ) );

		$this->assertNull( NightCapacity::blockingNight( $config, $taken, $this->sel( $config, array( 'n1819', 'n1920' ) ), true ) );

		$fuller = new NightOccupancy( array( 'n1819' => 71, 'n1920' => 10 ), array( 'n1819' => 5, 'n1920' => 5 ) );
		$this->assertSame( 'n1819', NightCapacity::blockingNight( $config, $fuller, $this->sel( $config, array( 'n1819', 'n1920' ) ), true ) );
	}

	public function test_unknown_night_is_filtered_out_before_the_gate(): void {
		$config = $this->configWith( array( 'n1' => array( 'seats' => 10 ) ) );

		$this->assertNull( NightCapacity::blockingNight( $config, new NightOccupancy( array(), array() ), NightSelection::fromKeys( array( 'widmo' ), $config ), false ) );
	}
}
