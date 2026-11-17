<?php
/**
 * Wejście rezerwacji — zwalidowane dane zgłoszenia.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Services;

use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Domain\Accommodation\NightSelection;

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
	 * @param NightSelection|null         $nights         Wybrane doby w trybie „doby”; null poza tym trybem (domyślny —
	 *                                                    istniejące wywołania ścieżki klasycznej zostają bez zmian).
	 * @param bool                        $accommodationSupplied Czy ta submisja w ogóle PODAŁA odpowiedź na pole
	 *                                                    noclegu. False znaczy „pola nie było w grze” — tak składa się
	 *                                                    submisja, w której warunkowa widoczność ukryła pole noclegu,
	 *                                                    więc Validator jego wartości nie zwrócił. Rozróżnienia
	 *                                                    potrzebuje ścieżka KLASYCZNA, gdzie `selection === null`
	 *                                                    znaczyło jednocześnie „zrezygnowano z noclegu” i „nie
	 *                                                    podano”; tryb „doby” ten sam fakt niesie już w `nights`
	 *                                                    (null = nie podano, PUSTY zbiór = rezygnacja). Domyślne
	 *                                                    true zostawia każde istniejące wywołanie bez zmian.
	 */
	public function __construct(
		public readonly string $email,
		public readonly string $name,
		public readonly string $typeKey,
		public readonly array $data,
		public readonly ?AccommodationSelection $selection = null,
		public readonly string $lang = '',
		public readonly bool $companion = false,
		public readonly string $companionName = '',
		public readonly ?NightSelection $nights = null,
		public readonly bool $accommodationSupplied = true
	) {
	}
}
