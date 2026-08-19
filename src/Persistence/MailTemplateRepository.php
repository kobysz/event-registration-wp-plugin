<?php
/**
 * Odczyt szablonów maili zapisanych przy evencie.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Persistence;

defined( 'ABSPATH' ) || exit;

/**
 * Odczyt szablonów maili zapisanych przy evencie.
 *
 * Plan 4A tylko czyta. Zapis (edytor w adminie) dochodzi w Planie 4B.
 */
final class MailTemplateRepository {

	public const META_KEY = '_evreg_mail_templates';

	/**
	 * Zwraca szablony eventu: klucz szablonu => tablica z polami subject/body.
	 *
	 * @param int $event_id ID posta eventu.
	 *
	 * @return array<string,array<string,string>>
	 */
	public function get( int $event_id ): array {
		$raw = get_post_meta( $event_id, self::META_KEY, true );

		if ( is_array( $raw ) ) {
			return $this->normalize( $raw );
		}

		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $this->normalize( $decoded ) : array();
	}

	/**
	 * Sprowadza surowe meta do mapy string => array<string,string>.
	 *
	 * @param array<mixed> $raw Surowa zawartość meta.
	 *
	 * @return array<string,array<string,string>>
	 */
	private function normalize( array $raw ): array {
		$templates = array();

		foreach ( $raw as $key => $template ) {
			if ( ! is_string( $key ) || ! is_array( $template ) ) {
				continue;
			}

			$fields = array();

			foreach ( array( 'subject', 'body' ) as $field ) {
				if ( isset( $template[ $field ] ) && is_string( $template[ $field ] ) ) {
					$fields[ $field ] = $template[ $field ];
				}
			}

			$templates[ $key ] = $fields;
		}

		return $templates;
	}
}
