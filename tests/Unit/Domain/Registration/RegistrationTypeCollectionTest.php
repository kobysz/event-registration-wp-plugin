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

	public function test_round_trips(): void {
		$types = RegistrationTypeCollection::fromArray( $this->raw() );

		$this->assertSame( 'wykladowca', $types->toArray()[2]['key'] );
		$this->assertFalse( $types->toArray()[2]['active'] );
	}
}
