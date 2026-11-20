<?php
/**
 * Składanie nazwy zgłaszającego się na potrzeby WYŚWIETLANIA.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Registration;

/**
 * Składa czytelną nazwę zgłaszającego się z odpowiedzi formularza.
 *
 * Kolumna `name` w bazie trzyma snapshot JEDNEGO pola (heurystyka
 * `SubmissionAssembler::extractName`), więc formularz z osobnym polem imienia i
 * osobnym nazwiska zapisuje tam samo imię. Ta klasa nie rusza zapisu — składa
 * nazwę przy KAŻDYM wyświetleniu, dzięki czemu działa też na zgłoszeniach już
 * zapisanych i nie wymaga migracji.
 *
 * Rozpoznanie idzie po PIERWSZYM członie klucza pola (patrz GIVEN/SURNAME):
 * `imie_uczestnika` to imię, a `company_name` albo `nazwa_firmy` już nie —
 * dopasowanie fragmentu w środku klucza dokleiłoby do nazwy dowolne pole,
 * któremu autor dał w kluczu słowo „name”.
 *
 * Kolejność wyniku bierze się z KLASYFIKACJI, nie z kolejności pól w
 * odpowiedziach: imiona zawsze przed nazwiskami, więc przestawienie pól w
 * builderze (albo inna kolejność kluczy w zapisanym JSON-ie) nie może dać
 * „Kowalski Jan”.
 */
final class DisplayName {

	/**
	 * Pierwsze członu klucza znaczące „imię”.
	 */
	private const GIVEN = array( 'imie', 'imi', 'first', 'firstname', 'fname', 'given', 'name' );

	/**
	 * Pierwsze członu klucza znaczące „nazwisko”.
	 */
	private const SURNAME = array( 'nazwisko', 'nazwisk', 'last', 'lastname', 'lname', 'surname', 'family' );

	/**
	 * Składa nazwę do wyświetlenia; bez trafienia zwraca zapisany snapshot.
	 *
	 * @param array<string,mixed> $answers Odpowiedzi zgłoszenia (zdekodowany JSON kolumny `data`).
	 * @param string              $stored  Snapshot z kolumny `name` — wartość zastępcza.
	 */
	public static function compose( array $answers, string $stored ): string {
		$given    = array();
		$surnames = array();

		foreach ( $answers as $key => $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$text = trim( (string) $value );
			if ( '' === $text ) {
				continue;
			}

			$token = self::firstToken( (string) $key );

			if ( in_array( $token, self::SURNAME, true ) ) {
				$surnames[] = $text;
			} elseif ( in_array( $token, self::GIVEN, true ) ) {
				$given[] = $text;
			}
		}

		$parts = self::deduplicate( array_merge( $given, $surnames ) );

		if ( array() === $parts ) {
			return $stored;
		}

		return implode( ' ', $parts );
	}

	/**
	 * Zwraca pierwszy alfanumeryczny człon klucza (małymi literami).
	 *
	 * Diakrytyki i podkreślenia są separatorami, więc `imię` daje `imi`,
	 * a `__type` daje `type`.
	 *
	 * @param string $key Klucz pola.
	 */
	private static function firstToken( string $key ): string {
		$tokens = preg_split( '/[^a-z0-9]+/', strtolower( $key ), -1, PREG_SPLIT_NO_EMPTY );

		return is_array( $tokens ) && array() !== $tokens ? (string) $tokens[0] : '';
	}

	/**
	 * Usuwa powtórzenia bez względu na wielkość liter, zachowując kolejność i pisownię pierwszego wystąpienia.
	 *
	 * Formularz z polem `name` ORAZ `imie` o tej samej treści nie może dać „Jan Jan”.
	 *
	 * @param array<int,string> $parts Części nazwy.
	 * @return array<int,string>
	 */
	private static function deduplicate( array $parts ): array {
		$unique = array();
		$seen   = array();

		foreach ( $parts as $part ) {
			$fold = strtolower( $part );
			if ( isset( $seen[ $fold ] ) ) {
				continue;
			}
			$seen[ $fold ] = true;
			$unique[]      = $part;
		}

		return $unique;
	}
}
