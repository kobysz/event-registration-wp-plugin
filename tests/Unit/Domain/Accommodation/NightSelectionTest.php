<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Accommodation;

use EvReg\Domain\Accommodation\NightSelection;
use EvReg\Domain\Accommodation\NightsConfig;
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

	public function test_json_encodes_as_a_plain_list_of_keys(): void {
		// Bez JsonSerializable instancja zakodowałaby się jako `{}` (same prywatne
		// właściwości), czyli po cichu bez danych — dokładnie klasa błędu B15.
		$selection = NightSelection::fromKeys( array( 'n2', 'n1' ), $this->config( array( 'n1', 'n2' ) ) );

		$this->assertSame( '["n1","n2"]', (string) json_encode( $selection ) );
		$this->assertSame( '[]', (string) json_encode( NightSelection::fromKeys( array(), $this->config( array( 'n1' ) ) ) ) );
	}
}
