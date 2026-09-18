<?php
/**
 * Rozstrzyganie szablonu maila: własny szablon eventu z fallbackiem na domyślny.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Mail;

use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\MailTemplateRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Rozstrzyganie szablonu maila: własny szablon eventu z fallbackiem na domyślny.
 *
 * Fallback działa per pole — event może nadpisać sam temat i zostać przy
 * domyślnej treści. Opcjonalny język konsultuje overlay tłumaczeń (B3b)
 * przed nadpisaniem eventu i domyślnym szablonem — też per pole.
 */
final class TemplateResolver {

	/**
	 * Tworzy resolver.
	 *
	 * @param MailTemplateRepository $templates Repozytorium szablonów eventu.
	 * @param EventConfigRepository  $config    Repozytorium konfiguracji eventu (overlay i18n).
	 */
	public function __construct( private readonly MailTemplateRepository $templates, private readonly EventConfigRepository $config ) {
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
	 * @param string $lang         Kod języka overlay tłumaczeń; pusty = pomiń overlay (zachowanie bazowe).
	 *
	 * @return array{subject: string, body: string}
	 */
	public function resolve( int $event_id, string $template_key, string $lang = '' ): array {
		$key      = self::baseKey( $template_key );
		$default  = DefaultTemplates::get( $key );
		$override = $this->templates->get( $event_id )[ $key ] ?? array();

		$overlay = array();
		if ( '' !== $lang ) {
			$mail    = $this->config->getI18n( $event_id )[ $lang ]['mail'][ $key ] ?? array();
			$overlay = is_array( $mail ) ? $mail : array();
		}

		$subject = self::field( $overlay, $override, $default, 'subject' );
		$body    = self::field( $overlay, $override, $default, 'body' );

		return array(
			'subject' => $subject,
			'body'    => $body,
		);
	}

	/**
	 * Wybiera pole z fallbackiem: overlay (język) → override eventu → domyślny.
	 *
	 * @param array<string,mixed> $overlay  Nadpisanie językowe.
	 * @param array<string,mixed> $override Szablon eventu (baza).
	 * @param array<string,mixed> $defaults Szablon domyślny.
	 * @param string              $field    'subject' albo 'body'.
	 */
	private static function field( array $overlay, array $override, array $defaults, string $field ): string {
		if ( isset( $overlay[ $field ] ) && '' !== $overlay[ $field ] ) {
			return (string) $overlay[ $field ];
		}
		if ( isset( $override[ $field ] ) && '' !== $override[ $field ] ) {
			return (string) $override[ $field ];
		}
		return (string) ( $defaults[ $field ] ?? '' );
	}
}
