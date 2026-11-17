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

	/**
	 * Czy ta submisja PODAŁA odpowiedź na pole noclegu — rozstrzygnięcie zależne od trybu.
	 *
	 * Jedno pytanie, dwóch nosicieli faktu: tryb klasyczny niesie odpowiedź flagą
	 * `accommodationSupplied`, tryb „doby” w samym `nights` (null = nie podano, PUSTY
	 * zbiór = świadoma rezygnacja). Oba rozstrzygnięcia mieszkają TUTAJ, przy polach,
	 * których dotyczą: ścieżka dobowa wyprowadzała swoje u siebie, więc żądanie zbudowane
	 * z `nights = null` i `accommodationSupplied = true` odpowiadało na to jedno pytanie
	 * różnie, zależnie od tego, kto pyta. SubmissionAssembler trzyma oba nosicielstwa
	 * zgodne (`nights` powstaje wyłącznie dla PODANEJ odpowiedzi), a ten akcesor pilnuje,
	 * żeby czytający nie rozeszli się z nim ani ze sobą.
	 *
	 * @param bool $nights_mode Czy event jest w trybie „doby”.
	 */
	public function accommodationAnswered( bool $nights_mode ): bool {
		return $nights_mode ? null !== $this->nights : $this->accommodationSupplied;
	}
}
