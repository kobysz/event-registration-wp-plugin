<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Schema;

use EvReg\Domain\Schema\FormSchema;
use PHPUnit\Framework\TestCase;

final class AccommodationFieldKeyTest extends TestCase {

	/**
	 * @param array<int,array<string,mixed>> $fields
	 */
	private function schema( array $fields ): FormSchema {
		return FormSchema::fromArray(
			array(
				'version'  => 1,
				'sections' => array(
					array( 'key' => 'dane', 'title' => 'Dane', 'fields' => $fields ),
				),
			)
		);
	}

	public function test_returns_key_of_accommodation_field(): void {
		$schema = $this->schema(
			array(
				array( 'key' => 'email', 'type' => 'email', 'label' => 'E-mail' ),
				array( 'key' => 'nocleg', 'type' => 'accommodation', 'label' => 'Nocleg' ),
			)
		);

		$this->assertSame( 'nocleg', $schema->accommodationFieldKey() );
	}

	public function test_returns_null_without_accommodation_field(): void {
		$schema = $this->schema( array( array( 'key' => 'email', 'type' => 'email', 'label' => 'E-mail' ) ) );

		$this->assertNull( $schema->accommodationFieldKey() );
	}
}
