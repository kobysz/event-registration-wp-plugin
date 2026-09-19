<?php
/**
 * Wejście rezerwacji — zwalidowane dane zgłoszenia.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Services;

use EvReg\Domain\Accommodation\AccommodationSelection;

defined( 'ABSPATH' ) || exit;

/**
 * Wejście rezerwacji — zwalidowane dane zgłoszenia.
 */
final class ReservationRequest {

	/**
	 * Tworzy żądanie rezerwacji.
	 *
	 * @param string                      $email     Adres e-mail zgłaszającego się.
	 * @param string                      $name      Imię i nazwisko zgłaszającego się.
	 * @param string                      $typeKey   Klucz wybranego typu zgłoszenia.
	 * @param array<string,mixed>         $data      Znormalizowane odpowiedzi z Validatora.
	 * @param AccommodationSelection|null $selection      Wybór zakwaterowania, jeśli dotyczy.
	 * @param string                      $lang           Slug języka zgłoszenia ('' gdy brak).
	 * @param bool                        $companion      Czy zgłoszenie obejmuje osobę towarzyszącą.
	 * @param string                      $companionName  Imię i nazwisko osoby towarzyszącej ('' gdy brak/nie dotyczy).
	 */
	public function __construct(
		public readonly string $email,
		public readonly string $name,
		public readonly string $typeKey,
		public readonly array $data,
		public readonly ?AccommodationSelection $selection = null,
		public readonly string $lang = '',
		public readonly bool $companion = false,
		public readonly string $companionName = ''
	) {
	}
}
