<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Frontend;

use EvReg\Frontend\EventFormLoader;
use EvReg\Frontend\FormRenderer;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\Migrations;
use WP_UnitTestCase;

/**
 * Przypis pod checkboxem osoby towarzyszącej na formularzu publicznym.
 */
final class CompanionNoteRenderTest extends WP_UnitTestCase {

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		$this->event_id = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
	}

	/**
	 * Zapisuje event i renderuje formularz tą samą drogą co wejścia produkcyjne.
	 *
	 * @param string              $mode          classic|nights.
	 * @param array<string,mixed> $accommodation Nadpisania konfiguracji noclegów.
	 */
	private function render( string $mode, array $accommodation ): string {
		$base = 'nights' === $mode
			? array(
				'mode'   => 'nights',
				'nights' => array( array( 'key' => 'n1', 'label' => '18/19 XI', 'price' => 80.0, 'seats' => 4 ) ),
			)
			: array(
				'packages'  => array( array( 'key' => 'std', 'label' => 'Standard' ) ),
				'rooms'     => array( array( 'key' => 'double', 'label' => 'Dwuosobowy' ) ),
				'inventory' => array( array( 'package' => 'std', 'room' => 'double', 'capacity' => 10, 'price' => 0 ) ),
			);

		$repo = new EventConfigRepository();
		$repo->save(
			$this->event_id,
			array(
				'schema'        => array(
					'version'  => 1,
					'sections' => array(
						array(
							'key'    => 'dane',
							'title'  => 'Dane',
							'fields' => array(
								array( 'key' => '__type', 'type' => 'radio', 'label' => 'Typ' ),
								array( 'key' => 'email', 'type' => 'email', 'label' => 'E-mail', 'required' => true ),
								array( 'key' => 'nocleg', 'type' => 'accommodation', 'label' => 'Nocleg' ),
							),
						),
					),
				),
				'types'         => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 0.0 ) ),
				'accommodation' => array_merge( $base, array( 'allow_none' => true, 'companion_enabled' => true ), $accommodation ),
				'settings'      => array( 'global_cap' => null, 'waitlist_enabled' => true ),
			)
		);

		$loader = new EventFormLoader( $repo );
		$schema = $loader->load( $this->event_id );
		$nights = 'nights' === $mode ? $loader->nights( $this->event_id ) : null;

		return ( new FormRenderer( null, null, $nights ) )->render( $schema, $this->event_id, null );
	}

	public function test_note_renders_between_checkbox_and_name_label(): void {
		$html = $this->render( 'classic', array( 'companion_note' => 'Dopłata za osobę.' ) );

		$checkbox = strpos( $html, 'data-evreg-companion>' );
		$note     = strpos( $html, '<p class="evreg-companion-note' );
		$label    = strpos( $html, 'data-evreg-companion-label' );

		$this->assertNotFalse( $checkbox );
		$this->assertNotFalse( $note );
		$this->assertNotFalse( $label );
		$this->assertGreaterThan( $checkbox, $note );
		$this->assertLessThan( $label, $note );
		$this->assertStringContainsString( 'Dopłata za osobę.', $html );
	}

	public function test_note_renders_in_nights_mode(): void {
		$html = $this->render( 'nights', array( 'companion_note' => 'Dopłata za osobę.' ) );

		$this->assertStringContainsString( '<p class="evreg-companion-note', $html );
		$this->assertStringContainsString( 'Dopłata za osobę.', $html );
	}

	public function test_empty_note_renders_no_paragraph(): void {
		$this->assertStringNotContainsString( 'evreg-companion-note', $this->render( 'classic', array( 'companion_note' => '' ) ) );
		$this->assertStringNotContainsString( 'evreg-companion-note', $this->render( 'nights', array() ) );
	}

	public function test_whitespace_only_note_renders_no_paragraph(): void {
		$this->assertStringNotContainsString( 'evreg-companion-note', $this->render( 'classic', array( 'companion_note' => '   ' ) ) );
		$this->assertStringNotContainsString( 'evreg-companion-note', $this->render( 'nights', array( 'companion_note' => '   ' ) ) );
	}

	public function test_note_absent_when_companion_disabled(): void {
		$html = $this->render( 'classic', array( 'companion_enabled' => false, 'companion_note' => 'Dopłata.' ) );

		$this->assertStringNotContainsString( 'evreg-companion-note', $html );
		$this->assertStringNotContainsString( 'Dopłata.', $html );
	}

	public function test_note_keeps_allowed_markup(): void {
		$html = $this->render( 'classic', array( 'companion_note' => '<strong>Uwaga</strong> <a href="https://example.com/regulamin">regulamin</a>' ) );

		$this->assertStringContainsString( '<strong>Uwaga</strong>', $html );
		$this->assertStringContainsString( '<a href="https://example.com/regulamin">regulamin</a>', $html );
	}
}
