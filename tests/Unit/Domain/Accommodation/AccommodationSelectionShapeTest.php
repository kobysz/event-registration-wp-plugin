<?php
/**
 * Kształt selekcji noclegu po serializacji do JSON-a odpowiedzi (B15).
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Accommodation;

use EvReg\Domain\Accommodation\AccommodationSelection;
use PHPUnit\Framework\TestCase;

/**
 * Selekcja trafia do kolumny `data` przez `wp_json_encode`, a odczytują ją ekrany
 * admina po kluczach `package`/`room`/`roommate`. Bez `JsonSerializable` zapisywały
 * się nazwy właściwości obiektu i edycja zgłoszenia renderowała nocleg jako
 * niezaznaczony.
 */
final class AccommodationSelectionShapeTest extends TestCase {

	public function test_json_encoding_uses_the_canonical_answer_keys(): void {
		$selection = new AccommodationSelection( 'p1', 'single', 'Jan' );

		$this->assertSame(
			array(
				'package'  => 'p1',
				'room'     => 'single',
				'roommate' => 'Jan',
			),
			json_decode( (string) json_encode( $selection ), true )
		);
	}

	public function test_json_encoding_round_trips_through_from_array(): void {
		$selection = new AccommodationSelection( 'p1', 'single', 'Jan' );

		$restored = AccommodationSelection::fromArray(
			(array) json_decode( (string) json_encode( $selection ), true )
		);

		$this->assertNotNull( $restored );
		$this->assertSame( 'p1', $restored->packageKey );
		$this->assertSame( 'single', $restored->roomKey );
		$this->assertSame( 'Jan', $restored->roommatePref );
	}

	public function test_normalize_passes_the_canonical_shape_through(): void {
		$this->assertSame(
			array(
				'package'  => 'p1',
				'room'     => 'single',
				'roommate' => 'Jan',
			),
			AccommodationSelection::normalizeArray(
				array(
					'package'  => 'p1',
					'room'     => 'single',
					'roommate' => 'Jan',
				)
			)
		);
	}

	public function test_normalize_reads_the_legacy_property_name_shape(): void {
		// Wiersze zapisane przed naprawą: json_encode obiektu zapisywał nazwy właściwości.
		$this->assertSame(
			array(
				'package'  => 'p1',
				'room'     => 'single',
				'roommate' => 'Jan',
			),
			AccommodationSelection::normalizeArray(
				array(
					'packageKey'   => 'p1',
					'roomKey'      => 'single',
					'roommatePref' => 'Jan',
				)
			)
		);
	}

	public function test_canonical_keys_win_over_the_legacy_ones(): void {
		$normalized = AccommodationSelection::normalizeArray(
			array(
				'package'    => 'nowy',
				'packageKey' => 'stary',
			)
		);

		$this->assertSame( 'nowy', $normalized['package'] );
	}

	public function test_normalize_returns_empty_strings_for_anything_else(): void {
		$this->assertSame(
			array(
				'package'  => '',
				'room'     => '',
				'roommate' => '',
			),
			AccommodationSelection::normalizeArray( array( 'cokolwiek' => 1 ) )
		);
	}

	public function test_normalize_casts_scalars_to_strings(): void {
		$normalized = AccommodationSelection::normalizeArray( array( 'package' => 7, 'room' => 1 ) );

		$this->assertSame( '7', $normalized['package'] );
		$this->assertSame( '1', $normalized['room'] );
	}

	public function test_normalize_ignores_nested_arrays(): void {
		// Zabezpieczenie przed „Array to string conversion" na uszkodzonym wierszu.
		$normalized = AccommodationSelection::normalizeArray( array( 'package' => array( 'p1' ) ) );

		$this->assertSame( '', $normalized['package'] );
	}
}
