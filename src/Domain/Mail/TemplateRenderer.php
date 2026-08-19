<?php
/**
 * Podstawianie placeholderów w szablonie maila.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Mail;

/**
 * Podstawianie placeholderów w szablonie maila.
 *
 * Jeden przebieg: wartość zawierająca {klucz} nie jest rozwijana ponownie.
 * Nieznany placeholder zostaje w treści dosłownie, żeby literówka w szablonie
 * była widoczna zamiast zamieniać się w pustkę.
 */
final class TemplateRenderer {

	/**
	 * Renderuje szablon, podstawiając znane placeholdery.
	 *
	 * @param string       $template Szablon z placeholderami w klamrach.
	 * @param Placeholders $values   Wartości do podstawienia.
	 */
	public function render( string $template, Placeholders $values ): string {
		$rendered = preg_replace_callback(
			'/\{([a-z0-9_]+)\}/i',
			static function ( array $matches ) use ( $values ): string {
				return $values->has( $matches[1] ) ? $values->get( $matches[1] ) : $matches[0];
			},
			$template
		);

		return null === $rendered ? $template : $rendered;
	}
}
