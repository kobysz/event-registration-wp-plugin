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
	 * Zapisuje nadpisania szablonów eventu. Puste pola i typy są odsiewane;
	 * pusty wynik kasuje meta.
	 *
	 * @param int                                $event_id  ID posta eventu.
	 * @param array<string,array<string,string>> $templates Surowe nadpisania z żądania.
	 */
	public function save( int $event_id, array $templates ): void {
		$clean = array();

		foreach ( $templates as $key => $template ) {
			if ( ! is_string( $key ) || ! is_array( $template ) ) {
				continue;
			}

			$fields = array();

			foreach ( array( 'subject', 'body' ) as $field ) {
				$value = $template[ $field ] ?? '';

				if ( is_string( $value ) && '' !== $value ) {
					$fields[ $field ] = $value;
				}
			}

			if ( array() !== $fields ) {
				$clean[ $key ] = $fields;
			}
		}

		if ( array() === $clean ) {
			delete_post_meta( $event_id, self::META_KEY );
			return;
		}

		update_post_meta( $event_id, self::META_KEY, wp_slash( (string) wp_json_encode( $clean ) ) );
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
