<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Schema;

use EvReg\Domain\Schema\Option;
use PHPUnit\Framework\TestCase;

final class OptionDescriptionTest extends TestCase {

	public function test_description_is_optional_and_defaults_to_empty(): void {
		$option = Option::fromArray( array( 'value' => 'tak', 'label' => 'Tak' ) );

		$this->assertSame( '', $option->description );
		$this->assertArrayNotHasKey( 'description', $option->toArray() );
	}

	public function test_description_round_trips(): void {
		$option = Option::fromArray(
			array(
				'value'       => 'warsztat',
				'label'       => 'Warsztat',
				'description' => 'Szczegóły: <a href="https://example.com">program</a>',
			)
		);

		$this->assertSame( 'Szczegóły: <a href="https://example.com">program</a>', $option->description );
		$this->assertSame( 'Szczegóły: <a href="https://example.com">program</a>', $option->toArray()['description'] );
	}
}
