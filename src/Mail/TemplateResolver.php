<?php
/**
 * Rozstrzyganie szablonu maila: własny szablon eventu z fallbackiem na domyślny.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Mail;

use EvReg\Persistence\MailTemplateRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Rozstrzyganie szablonu maila: własny szablon eventu z fallbackiem na domyślny.
 *
 * Fallback działa per pole — event może nadpisać sam temat i zostać przy
 * domyślnej treści.
 */
final class TemplateResolver {

	/**
	 * Tworzy resolver.
	 *
	 * @param MailTemplateRepository $templates Repozytorium szablonów eventu.
	 */
	public function __construct( private readonly MailTemplateRepository $templates ) {
	}

	/**
	 * Sprowadza klucz wariantu (np. admin_new:<hash>) do klucza bazowego.
	 *
	 * @param string $template_key Klucz szablonu, ewentualnie z wariantem po dwukropku.
	 */
	public static function baseKey( string $template_key ): string {
		$position = strpos( $template_key, ':' );

		return false === $position ? $template_key : substr( $template_key, 0, $position );
	}

	/**
	 * Zwraca temat i treść szablonu dla eventu.
	 *
	 * @param int    $event_id     ID posta eventu.
	 * @param string $template_key Klucz szablonu (może zawierać wariant po dwukropku).
	 *
	 * @return array{subject: string, body: string}
	 */
	public function resolve( int $event_id, string $template_key ): array {
		$key      = self::baseKey( $template_key );
		$default  = DefaultTemplates::get( $key );
		$override = $this->templates->get( $event_id )[ $key ] ?? array();

		$subject = isset( $override['subject'] ) && '' !== $override['subject'] ? $override['subject'] : $default['subject'];
		$body    = isset( $override['body'] ) && '' !== $override['body'] ? $override['body'] : $default['body'];

		return array(
			'subject' => $subject,
			'body'    => $body,
		);
	}
}
