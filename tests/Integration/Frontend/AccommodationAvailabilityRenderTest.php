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
		$this->assertMatchesRegularExpression( '/value="n18\|single"[^>]*data-evreg-seats-left="0"/', $html );
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

	public function test_dropped_selection_moves_to_none_and_is_announced(): void {
		$result = SubmitResult::invalid(
			array( 'email' => 'required' ),
			array( 'nocleg' => array( 'package' => 'n18', 'room' => 'single', 'roommate' => '' ) )
		);

		$html = ( new FormRenderer( $this->availability( array( 'n18|single' => 1 ) ) ) )
			->render( $this->schema(), 1, $result );

		$this->assertMatchesRegularExpression( '/name="evreg_field\[nocleg\]\[slot\]" value=""[^>]*checked/', $html );
		$this->assertStringContainsString( 'Twój wcześniejszy wybór noclegu jest już niedostępny.', $html );
	}

	public function test_vanished_slot_moves_to_none_and_is_announced(): void {
		$result = SubmitResult::invalid(
			array( 'email' => 'required' ),
			array( 'nocleg' => array( 'package' => 'n18', 'room' => 'suite', 'roommate' => '' ) )
		);

		$html = ( new FormRenderer( $this->availability( array() ) ) )
			->render( $this->schema(), 1, $result );

		$this->assertStringNotContainsString( 'n18|suite', $html );
		$this->assertMatchesRegularExpression( '/name="evreg_field\[nocleg\]\[slot\]" value=""[^>]*checked/', $html );
		$this->assertStringContainsString( 'Twój wcześniejszy wybór noclegu jest już niedostępny.', $html );
	}

	public function test_kept_selection_is_not_announced_as_dropped(): void {
		$result = SubmitResult::invalid(
			array( 'email' => 'required' ),
			array( 'nocleg' => array( 'package' => 'n18', 'room' => 'double', 'roommate' => '' ) )
		);

		$html = ( new FormRenderer( $this->availability( array( 'n18|single' => 1 ) ) ) )
			->render( $this->schema(), 1, $result );

		$this->assertMatchesRegularExpression( '/value="n18\|double"[^>]*checked/', $html );
		$this->assertStringContainsString( 'evreg-accommodation-dropped" hidden', $html );
	}

	public function test_empty_submitted_selection_checks_none(): void {
		$result = SubmitResult::invalid(
			array( 'email' => 'required' ),
			array( 'nocleg' => array( 'package' => '', 'room' => '', 'roommate' => '' ) )
		);

		$html = ( new FormRenderer( $this->availability( array() ) ) )->render( $this->schema(), 1, $result );

		$this->assertMatchesRegularExpression( '/name="evreg_field\[nocleg\]\[slot\]" value=""[^>]*checked/', $html );
		$this->assertStringContainsString( 'evreg-accommodation-dropped" hidden', $html );
	}

	public function test_all_slots_closed_without_allow_none_shows_message(): void {
		$config               = $this->accommodationConfig();
		$config['allow_none'] = false;

		$schema = FormSchema::fromArray(
			array(
				'version'  => 1,
				'sections' => array(
					array(
						'key'    => 'dane',
						'title'  => 'Dane',
						'fields' => array(
							array( 'key' => 'nocleg', 'type' => 'accommodation', 'label' => 'Nocleg', 'config' => $config ),
						),
					),
				),
			)
		);

		$availability = SlotAvailability::fromSnapshot(
			AccommodationConfig::fromArray( $config ),
			new OccupancySnapshot( 0, array(), array( 'n18|single' => 1, 'n18|double' => 5 ) )
		);

		$html = ( new FormRenderer( $availability ) )->render( $schema, 1 );

		$this->assertStringContainsString( 'Brak wolnych miejsc noclegowych.', $html );
	}

	public function test_companion_flag_is_ignored_when_event_disallows_companion(): void {
		$config                      = $this->accommodationConfig();
		$config['companion_enabled'] = false;

		$schema = FormSchema::fromArray(
			array(
				'version'  => 1,
				'sections' => array(
					array(
						'key'    => 'dane',
						'title'  => 'Dane',
						'fields' => array(
							array( 'key' => 'nocleg', 'type' => 'accommodation', 'label' => 'Nocleg', 'config' => $config ),
						),
					),
				),
			)
		);

		$result = SubmitResult::invalid(
			array( 'email' => 'required' ),
			array( 'evreg_companion' => true )
		);

		$html = ( new FormRenderer( $this->availability( array( 'n18|double' => 4 ) ) ) )
			->render( $schema, 1, $result );

		$this->assertDoesNotMatchRegularExpression( '/value="n18\|double"[^>]*disabled/', $html );
	}
}
