<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Registration;

use EvReg\Domain\Registration\RegistrationTypeCollection;
use EvReg\Domain\Schema\SchemaException;
use PHPUnit\Framework\TestCase;

final class RegistrationTypeCollectionTest extends TestCase {

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function raw(): array {
		return array(
			array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 450.0, 'capacity' => 100 ),
			array( 'key' => 'online', 'label' => 'Uczestnik on-line', 'price' => 150.0, 'capacity' => null ),
			array( 'key' => 'wykladowca', 'label' => 'Wykładowca', 'price' => 0.0, 'capacity' => 12, 'active' => false ),
		);
	}

	public function test_parses_types(): void {
		$types = RegistrationTypeCollection::fromArray( $this->raw() );

		$this->assertTrue( $types->has( 'uczestnik' ) );
		$this->assertSame( 450.0, $types->get( 'uczestnik' )->price );
		$this->assertNull( $types->get( 'online' )->capacity );
		$this->assertCount( 3, $types->all() );
	}

	public function test_active_excludes_inactive_types(): void {
		$types = RegistrationTypeCollection::fromArray( $this->raw() );
		$keys  = array_map( static fn ( $type ) => $type->key, $types->active() );

		$this->assertSame( array( 'uczestnik', 'online' ), $keys );

		// Inactive type sits in the MIDDLE here (not last, as in raw()), so filtering
		// without array_values() would leave gapped keys [0, 2] instead of [0, 1].
		$reordered = RegistrationTypeCollection::fromArray(
			array(
				array( 'key' => 'a', 'label' => 'A', 'price' => 1.0, 'active' => true ),
				array( 'key' => 'b', 'label' => 'B', 'price' => 1.0, 'active' => false ),
				array( 'key' => 'c', 'label' => 'C', 'price' => 1.0, 'active' => true ),
			)
		);
		$reorderedActive = $reordered->active();

		$this->assertSame(
			array( 'a', 'c' ),
			array_map( static fn ( $type ) => $type->key, $reorderedActive )
		);
		$this->assertSame( array( 0, 1 ), array_keys( $reorderedActive ) );
	}

	public function test_capacities_maps_key_to_limit(): void {
		$types = RegistrationTypeCollection::fromArray( $this->raw() );

		$this->assertSame(
			array( 'uczestnik' => 100, 'online' => null, 'wykladowca' => 12 ),
			$types->capacities()
		);
	}

	public function test_rejects_duplicate_keys(): void {
		$raw   = $this->raw();
		$raw[] = array( 'key' => 'uczestnik', 'label' => 'Duplikat', 'price' => 1.0 );

		$this->expectException( SchemaException::class );

		RegistrationTypeCollection::fromArray( $raw );
	}

	public function test_rejects_negative_price(): void {
		$this->expectException( SchemaException::class );

		RegistrationTypeCollection::fromArray(
			array( array( 'key' => 'x', 'label' => 'X', 'price' => -1.0 ) )
		);
	}

	public function test_rejects_negative_capacity(): void {
		try {
			RegistrationTypeCollection::fromArray(
				array( array( 'key' => 'x', 'label' => 'X', 'price' => 1.0, 'capacity' => -1 ) )
			);
			$this->fail( 'Expected SchemaException was not thrown for negative capacity.' );
		} catch ( SchemaException $e ) {
			$this->assertStringContainsString( 'x', $e->getMessage() );
		}
	}

	public function test_capacity_zero_is_preserved_as_zero_not_null(): void {
		$types = RegistrationTypeCollection::fromArray(
			array( array( 'key' => 'full', 'label' => 'Pełny', 'price' => 10.0, 'capacity' => 0 ) )
		);

		$this->assertSame( 0, $types->get( 'full' )->capacity );
		$this->assertSame( array( 'full' => 0 ), $types->capacities() );
	}

	public function test_round_trips(): void {
		$types = RegistrationTypeCollection::fromArray( $this->raw() );

		$this->assertSame( 'wykladowca', $types->toArray()[2]['key'] );
		$this->assertFalse( $types->toArray()[2]['active'] );
	}
}
