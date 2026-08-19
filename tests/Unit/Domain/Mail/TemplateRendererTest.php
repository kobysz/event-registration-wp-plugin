<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Mail;

use EvReg\Domain\Mail\Placeholders;
use EvReg\Domain\Mail\TemplateRenderer;
use PHPUnit\Framework\TestCase;

final class TemplateRendererTest extends TestCase {

	private TemplateRenderer $renderer;

	protected function setUp(): void {
		$this->renderer = new TemplateRenderer();
	}

	public function test_substitutes_known_placeholders(): void {
		$out = $this->renderer->render(
			'Cześć {imie}, zapisano {email}.',
			new Placeholders( array( 'imie' => 'Jan', 'email' => 'jan@example.com' ) )
		);

		$this->assertSame( 'Cześć Jan, zapisano jan@example.com.', $out );
	}

	public function test_leaves_unknown_placeholder_literally(): void {
		$out = $this->renderer->render( 'Cześć {imie_uczestnika}.', new Placeholders( array( 'imie' => 'Jan' ) ) );

		$this->assertSame( 'Cześć {imie_uczestnika}.', $out );
	}

	public function test_does_not_expand_placeholders_coming_from_values(): void {
		$out = $this->renderer->render( 'Uwaga: {podsumowanie}', new Placeholders( array( 'podsumowanie' => 'Pole: {imie}', 'imie' => 'Jan' ) ) );

		$this->assertSame( 'Uwaga: Pole: {imie}', $out );
	}

	public function test_substitutes_repeated_placeholder_everywhere(): void {
		$out = $this->renderer->render( '{imie} i jeszcze raz {imie}', new Placeholders( array( 'imie' => 'Jan' ) ) );

		$this->assertSame( 'Jan i jeszcze raz Jan', $out );
	}

	public function test_returns_template_unchanged_without_placeholders(): void {
		$this->assertSame( 'Zwykły tekst.', $this->renderer->render( 'Zwykły tekst.', new Placeholders() ) );
	}
}
