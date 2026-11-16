<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Frontend;

use EvReg\Domain\Schema\FormSchema;
use EvReg\Frontend\FormRenderer;
use EvReg\Frontend\SubmitResult;
use WP_UnitTestCase;

final class RoomOccupancyRenderTest extends WP_UnitTestCase {

	/**
	 * @param bool $roommate Czy pokój dwuosobowy ma włączone pole współlokatora.
	 * @return array<string,mixed>
	 */
	private function accommodationConfig( bool $roommate = false ): array {
		return array(
			'packages'          => array( array( 'key' => 'n18', 'label' => 'Noc 18/19' ) ),
			'rooms'             => array(
				array( 'key' => 'single', 'label' => 'Jednoosobowy', 'occupancy' => 1 ),
				array( 'key' => 'double', 'label' => 'Dwuosobowy', 'occupancy' => 2, 'roommate_field' => $roommate ),
				array( 'key' => 'any', 'label' => 'Dowolny' ),
			),
			'inventory'         => array(
				array( 'package' => 'n18', 'room' => 'single', 'capacity' => 10, 'price' => 100.0 ),
				array( 'package' => 'n18', 'room' => 'double', 'capacity' => 10, 'price' => 180.0 ),
				array( 'package' => 'n18', 'room' => 'any', 'capacity' => 10, 'price' => 150.0 ),
			),
			'allow_none'        => true,
			'companion_enabled' => true,
		);
	}

	/**
	 * @param array<string,mixed>|null $config Nadpisany config noclegów.
	 */
	private function schema( ?array $config = null ): FormSchema {
		return FormSchema::fromArray(
			array(
				'version'  => 1,
				'sections' => array(
					array(
						'key'    => 'dane',
						'title'  => 'Dane',
						'fields' => array(
							array( 'key' => 'email', 'type' => 'email', 'label' => 'E-mail' ),
							array(
								'key'    => 'nocleg',
								'type'   => 'accommodation',
								'label'  => 'Nocleg',
								'config' => $config ?? $this->accommodationConfig(),
							),
						),
					),
				),
			)
		);
	}

	/**
	 * @param array<string,mixed> $values Odtwarzane odpowiedzi.
	 */
	private function result( array $values ): SubmitResult {
		return SubmitResult::invalid( array( 'email' => 'required' ), $values );
	}

	public function test_companion_checkbox_renders_before_the_accommodation_field(): void {
		$html = ( new FormRenderer() )->render( $this->schema(), 1 );

		$companion = strpos( $html, 'name="evreg_companion"' );
		$slots     = strpos( $html, 'class="evreg-accommodation"' );

		$this->assertNotFalse( $companion );
		$this->assertNotFalse( $slots );
		$this->assertLessThan( $slots, $companion, 'Checkbox towarzyszącej musi wyjść przed listą noclegów.' );
	}

	public function test_double_room_is_hidden_and_disabled_without_companion(): void {
		$html = ( new FormRenderer() )->render( $this->schema(), 1 );

		$this->assertMatchesRegularExpression( '/value="n18\|double"[^>]*hidden/', $html );
		$this->assertMatchesRegularExpression( '/value="n18\|double"[^>]*disabled/', $html );
		$this->assertDoesNotMatchRegularExpression( '/value="n18\|single"[^>]*hidden/', $html );
	}

	public function test_single_room_is_hidden_and_disabled_with_companion(): void {
		$html = ( new FormRenderer() )->render( $this->schema(), 1, $this->result( array( 'evreg_companion' => true ) ) );

		$this->assertMatchesRegularExpression( '/value="n18\|single"[^>]*hidden/', $html );
		$this->assertMatchesRegularExpression( '/value="n18\|single"[^>]*disabled/', $html );
		$this->assertDoesNotMatchRegularExpression( '/value="n18\|double"[^>]*hidden/', $html );
	}

	public function test_room_without_occupancy_shows_in_both_states(): void {
		$solo = ( new FormRenderer() )->render( $this->schema(), 1 );
		$duo  = ( new FormRenderer() )->render( $this->schema(), 1, $this->result( array( 'evreg_companion' => true ) ) );

		$this->assertDoesNotMatchRegularExpression( '/value="n18\|any"[^>]*hidden/', $solo );
		$this->assertDoesNotMatchRegularExpression( '/value="n18\|any"[^>]*hidden/', $duo );
	}

	public function test_occupancy_attribute_is_emitted(): void {
		$html = ( new FormRenderer() )->render( $this->schema(), 1 );

		$this->assertStringContainsString( 'data-evreg-occupancy="1"', $html );
		$this->assertStringContainsString( 'data-evreg-occupancy="2"', $html );
	}

	public function test_mismatched_current_selection_moves_to_none(): void {
		$html = ( new FormRenderer() )->render(
			$this->schema(),
			1,
			$this->result( array( 'nocleg' => array( 'package' => 'n18', 'room' => 'double', 'roommate' => '' ) ) )
		);

		$this->assertDoesNotMatchRegularExpression( '/value="n18\|double"[^>]*checked/', $html );
		$this->assertMatchesRegularExpression( '/name="evreg_field\[nocleg\]\[slot\]" value=""[^>]*checked/', $html );
		$this->assertStringContainsString( 'Twój wcześniejszy wybór noclegu jest już niedostępny.', $html );
	}

	public function test_roommate_field_is_suppressed_for_double_rooms(): void {
		$html = ( new FormRenderer() )->render( $this->schema( $this->accommodationConfig( true ) ), 1 );

		$this->assertStringNotContainsString( 'data-evreg-roommate-input', $html );
	}

	public function test_message_when_nothing_matches_the_filter(): void {
		$config              = $this->accommodationConfig();
		$config['rooms']     = array( array( 'key' => 'double', 'label' => 'Dwuosobowy', 'occupancy' => 2 ) );
		$config['inventory'] = array( array( 'package' => 'n18', 'room' => 'double', 'capacity' => 10, 'price' => 180.0 ) );

		$html = ( new FormRenderer() )->render( $this->schema( $config ), 1 );

		$this->assertStringContainsString( 'nie ma pasujących pokojów', $html );
	}

	public function test_mismatch_message_is_the_only_explanation_when_none_is_not_allowed(): void {
		// Wszystkie pokoje niepasujące + brak "Bez noclegu": powód to niedopasowanie,
		// nie wyczerpane miejsca — komunikat o braku miejsc byłby fałszywy.
		$config               = $this->accommodationConfig();
		$config['rooms']      = array( array( 'key' => 'double', 'label' => 'Dwuosobowy', 'occupancy' => 2 ) );
		$config['inventory']  = array( array( 'package' => 'n18', 'room' => 'double', 'capacity' => 10, 'price' => 180.0 ) );
		$config['allow_none'] = false;

		$html = ( new FormRenderer() )->render( $this->schema( $config ), 1 );

		$this->assertStringContainsString( 'nie ma pasujących pokojów', $html );
		$this->assertStringNotContainsString( 'Brak wolnych miejsc noclegowych.', $html );
	}

	public function test_mismatch_and_dropped_notes_are_always_rendered_but_hidden_when_not_applicable(): void {
		$html = ( new FormRenderer() )->render( $this->schema(), 1 );

		$this->assertMatchesRegularExpression( '/<p class="evreg-accommodation-mismatch" hidden>/', $html );
		$this->assertMatchesRegularExpression( '/<p class="evreg-accommodation-dropped" hidden>/', $html );
	}

	public function test_contradictory_config_reads_as_unset_and_offers_the_room(): void {
		// Room marked double while the event has the companion option OFF: the domain
		// zeroes occupancy, so the renderer must not hide or disable the room.
		$config                      = $this->accommodationConfig( true );
		$config['companion_enabled'] = false;

		$html = ( new FormRenderer() )->render( $this->schema( $config ), 1 );

		$this->assertDoesNotMatchRegularExpression( '/value="n18\|double"[^>]*hidden/', $html );
		$this->assertDoesNotMatchRegularExpression( '/value="n18\|double"[^>]*disabled/', $html );
		$this->assertStringContainsString( 'data-evreg-roommate-input', $html );
	}

	public function test_css_hides_hidden_choices(): void {
		// `hidden` samo nie chowa wiersza: .evreg-choice { display: block; } bije
		// regułę przeglądarki dla [hidden]. Bez tej reguły atrybut jest ozdobą.
		$css = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/public/form.css' );

		$this->assertStringContainsString( '.evreg-choice[hidden]', $css );
	}
}
