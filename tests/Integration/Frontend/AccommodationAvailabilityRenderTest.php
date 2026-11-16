<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Frontend;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Accommodation\SlotAvailability;
use EvReg\Domain\Capacity\OccupancySnapshot;
use EvReg\Domain\Schema\FormSchema;
use EvReg\Frontend\FormRenderer;
use EvReg\Frontend\SubmitResult;
use WP_UnitTestCase;

final class AccommodationAvailabilityRenderTest extends WP_UnitTestCase {

	/**
	 * @return array<string,mixed>
	 */
	private function accommodationConfig(): array {
		return array(
			'packages'          => array( array( 'key' => 'n18', 'label' => 'Noc 18/19' ) ),
			'rooms'             => array(
				array( 'key' => 'single', 'label' => 'Jednoosobowy' ),
				array( 'key' => 'double', 'label' => 'Dwuosobowy' ),
			),
			'inventory'         => array(
				array( 'package' => 'n18', 'room' => 'single', 'capacity' => 1, 'price' => 100.0 ),
				array( 'package' => 'n18', 'room' => 'double', 'capacity' => 5, 'price' => 180.0 ),
			),
			'allow_none'        => true,
			'companion_enabled' => true,
		);
	}

	private function schema(): FormSchema {
		return FormSchema::fromArray(
			array(
				'version'  => 1,
				'sections' => array(
					array(
						'key'    => 'dane',
						'title'  => 'Dane',
						'fields' => array(
							array(
								'key'    => 'nocleg',
								'type'   => 'accommodation',
								'label'  => 'Nocleg',
								'config' => $this->accommodationConfig(),
							),
						),
					),
				),
			)
		);
	}

	/**
	 * @param array<string,int> $perSlot
	 */
	private function availability( array $perSlot ): SlotAvailability {
		return SlotAvailability::fromSnapshot(
			AccommodationConfig::fromArray( $this->accommodationConfig() ),
			new OccupancySnapshot( 0, array(), $perSlot )
		);
	}

	public function test_exhausted_slot_is_disabled_and_annotated(): void {
		$html = ( new FormRenderer( $this->availability( array( 'n18|single' => 1 ) ) ) )
			->render( $this->schema(), 1 );

		$this->assertMatchesRegularExpression( '/value="n18\|single"[^>]*disabled/', $html );
		$this->assertStringContainsString( '(brak miejsc)', $html );
		$this->assertStringContainsString( 'evreg-choice form-check is-full', $html );
	}

	public function test_slot_with_room_left_stays_enabled(): void {
		$html = ( new FormRenderer( $this->availability( array( 'n18|single' => 1 ) ) ) )
			->render( $this->schema(), 1 );

		$this->assertDoesNotMatchRegularExpression( '/value="n18\|double"[^>]*disabled/', $html );
		$this->assertStringContainsString( 'value="n18|double" data-evreg-seats-left="5"', $html );
	}

	public function test_single_free_seat_is_too_small_for_companion(): void {
		$result = SubmitResult::invalid(
			array( 'nocleg' => 'invalid_accommodation' ),
			array( 'evreg_companion' => true )
		);

		$html = ( new FormRenderer( $this->availability( array( 'n18|double' => 4 ) ) ) )
			->render( $this->schema(), 1, $result );

		$this->assertMatchesRegularExpression( '/value="n18\|double"[^>]*disabled/', $html );
		$this->assertStringContainsString( '(za mało miejsc)', $html );
	}

	public function test_exhausted_slot_is_never_rendered_as_checked(): void {
		$result = SubmitResult::invalid(
			array( 'email' => 'required' ),
			array( 'nocleg' => array( 'package' => 'n18', 'room' => 'single', 'roommate' => '' ) )
		);

		$html = ( new FormRenderer( $this->availability( array( 'n18|single' => 1 ) ) ) )
			->render( $this->schema(), 1, $result );

		$this->assertDoesNotMatchRegularExpression( '/value="n18\|single"[^>]*checked/', $html );
	}

	public function test_renderer_without_availability_renders_everything_enabled(): void {
		$html = ( new FormRenderer() )->render( $this->schema(), 1 );

		$this->assertStringNotContainsString( 'disabled', $html );
		$this->assertStringNotContainsString( 'data-evreg-seats-left', $html );
		$this->assertStringNotContainsString( '(brak miejsc)', $html );
	}
}
