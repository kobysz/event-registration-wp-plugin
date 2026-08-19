<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Mail;

use EvReg\Domain\Mail\Placeholders;
use PHPUnit\Framework\TestCase;

final class PlaceholdersTest extends TestCase {

	public function test_get_returns_value_for_known_key(): void {
		$values = new Placeholders( array( 'imie' => 'Jan' ) );

		$this->assertTrue( $values->has( 'imie' ) );
		$this->assertSame( 'Jan', $values->get( 'imie' ) );
	}

	public function test_get_returns_empty_string_for_unknown_key(): void {
		$values = new Placeholders();

		$this->assertFalse( $values->has( 'imie' ) );
		$this->assertSame( '', $values->get( 'imie' ) );
	}

	public function test_with_returns_new_instance_and_leaves_original_untouched(): void {
		$original = new Placeholders( array( 'imie' => 'Jan' ) );

		$extended = $original->with( 'email', 'jan@example.com' );

		$this->assertNotSame( $original, $extended );
		$this->assertFalse( $original->has( 'email' ) );
		$this->assertSame( 'jan@example.com', $extended->get( 'email' ) );
		$this->assertSame( 'Jan', $extended->get( 'imie' ) );
	}

	public function test_to_array_returns_all_values(): void {
		$values = new Placeholders( array( 'imie' => 'Jan', 'email' => 'jan@example.com' ) );

		$this->assertSame( array( 'imie' => 'Jan', 'email' => 'jan@example.com' ), $values->toArray() );
	}
}
