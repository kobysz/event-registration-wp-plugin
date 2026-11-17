<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Accommodation;

use EvReg\Domain\Accommodation\Night;
use EvReg\Domain\Schema\SchemaException;
use PHPUnit\Framework\TestCase;

final class NightTest extends TestCase {

	public function test_from_array_reads_every_field(): void {
		$night = Night::fromArray(
			array( 'key' => 'n1819', 'label' => '18/19 grudnia', 'price' => 150.0, 'seats' => 72, 'companion_cap' => 31 )
		);

		$this->assertSame( 'n1819', $night->key );
		$this->assertSame( '18/19 grudnia', $night->label );
		$this->assertSame( 150.0, $night->price );
		$this->assertSame( 72, $night->seats );
		$this->assertSame( 31, $night->companionCap );
	}

	public function test_empty_companion_cap_means_no_limit(): void {
		$this->assertNull( Night::fromArray( array( 'key' => 'n1', 'companion_cap' => '' ) )->companionCap );
		$this->assertNull( Night::fromArray( array( 'key' => 'n1' ) )->companionCap );
	}

	public function test_zero_companion_cap_is_a_limit_of_zero(): void {
		// Dwie różne konwencje pustki: tu 0 zamyka dobę dla par, ale zostawia ją singlom.
		$this->assertSame( 0, Night::fromArray( array( 'key' => 'n1', 'companion_cap' => 0 ) )->companionCap );
	}

	public function test_zero_seats_closes_the_night(): void {
		$this->assertSame( 0, Night::fromArray( array( 'key' => 'n1', 'seats' => 0 ) )->seats );
	}

	public function test_empty_key_is_rejected(): void {
		$this->expectException( SchemaException::class );
		Night::fromArray( array( 'key' => '' ) );
	}

	public function test_negative_numbers_are_rejected(): void {
		$this->expectException( SchemaException::class );
		Night::fromArray( array( 'key' => 'n1', 'seats' => -1 ) );
	}

	public function test_negative_price_is_rejected(): void {
		$this->expectException( SchemaException::class );
		Night::fromArray( array( 'key' => 'n1', 'price' => -1 ) );
	}

	public function test_negative_companion_cap_is_rejected(): void {
		$this->expectException( SchemaException::class );
		Night::fromArray( array( 'key' => 'n1', 'companion_cap' => -1 ) );
	}

	public function test_to_array_round_trips(): void {
		$data = array( 'key' => 'n1', 'label' => 'Noc', 'price' => 1.5, 'seats' => 2, 'companion_cap' => null );

		$this->assertSame( $data, Night::fromArray( $data )->toArray() );
	}
}
