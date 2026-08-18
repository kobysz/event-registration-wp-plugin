# Reservation Backend (Plan 3A) — projekt techniczny

Data: 2026-08-18
Status: zatwierdzony do planowania implementacji
Buduje na: [Plan 1 — domena](2026-08-18-event-registration-design.md) (`CapacityCalculator`, `Validator`, `RegistrationTypeCollection`, `AccommodationConfig`), [Plan 2A](2026-08-18-event-config-admin-design.md) (`SchemaAssembler`, `EventConfigRepository`)

## 1. Cel i kontekst

Backend cyklu życia zgłoszenia: transakcyjna rezerwacja miejsc, potwierdzanie (double opt-in),
wygasanie niepotwierdzonych rezerwacji. To rdzeń poprawności całej wtyczki — tu występują wyścigi
o ostatnie miejsce. Warstwa jest w pełni testowalna bez UI: rezerwacja, potwierdzenie i wygasanie
są wywoływalne programowo i sprawdzalne testami integracyjnymi.

Frontend (renderowanie formularza, submisja przez `admin-post.php`, blok, shortcode, JS, E2E) to
**Plan 3B**, który konsumuje `ReservationService` z tego planu. Wysyłka maili niosących link
potwierdzający to **Plan 4**; tutaj token i endpoint potwierdzenia istnieją i działają, brakuje tylko
maila który link dostarcza.

## 2. Zakres

### v1 (Plan 3A)
- `RegistrationStatus` — enum stanu zgłoszenia (czysta domena)
- `RegistrationRepository` — zapis/odczyt zgłoszeń i rezerwacji noclegowych; liczenie zajętości; blokada per event
- `ReservationService` — transakcyjna rezerwacja (blokada → zajętość → `CapacityCalculator` → zapis); potwierdzanie po tokenie
- Tabela `evreg_locks` (migracja, bump `DB_VERSION` 1 → 2)
- `ExpirePending` — zadanie cron wygaszające niepotwierdzone rezerwacje
- Test współbieżności dowodzący serializacji rezerwacji jednego eventu

### Poza Planem 3A
- Renderowanie formularza, submisja, blok, shortcode, JS, E2E → Plan 3B
- Wysyłka maili (opt-in, powiadomienia) → Plan 4
- Automatyczna promocja z listy rezerwowej → Plan 5 (ręczna)
- Płatności → v2

### Świadomie pominięte (YAGNI)
- Tabela liczników zajętości (zajętość liczona świeżo z tabeli zgłoszeń — zero dryfu)
- Granularna blokada per slot (blokada per event wystarcza przy tej skali)

## 3. Decyzje projektowe

| # | Decyzja | Wybór | Uzasadnienie |
|---|---------|-------|--------------|
| 1 | Granica 3A/3B | Pełny cykl miejsc bez wysyłki maili | Rezerwacja, potwierdzenie, wygasanie, waitlist to jeden transakcyjny mechanizm; rozbicie zostawia półmartwe `pending` |
| 2 | Współbieżność | Blokada per event (`SELECT … FOR UPDATE` wiersza-zamka), zajętość liczona świeżo | Poprawna, prosta, bez dryfu; przepustowość „jedna naraz per event" wystarcza |
| 3 | Źródło zajętości | `COUNT(*)` z tabeli zgłoszeń w transakcji | Prawda zawsze świeża; brak osobnego licznika do utrzymania |

## 4. Architektura

```
src/
├─ Domain/Registration/RegistrationStatus.php   CZYSTA: enum pending|confirmed|waitlist|cancelled
├─ Persistence/
│  ├─ RegistrationRepository.php                 zapis/odczyt zgłoszeń + accommodation_bookings; COUNT zajętości; lock
│  └─ Migrations.php                             MODYFIKACJA: DB_VERSION 2, tabela evreg_locks
├─ Services/
│  ├─ ReservationService.php                     transakcja rezerwacji + potwierdzanie
│  └─ ReservationResult.php                      wynik rezerwacji (wartość)
└─ Cron/ExpirePending.php                        cron: pending po expires_at → cancelled
```

`CapacityCalculator`, `Validator`, `SchemaAssembler`, `AccommodationConfig`, `RegistrationTypeCollection`
z Planów 1–2A pozostają nietknięte i czyste. Jedyna nowa czysta jednostka to `RegistrationStatus`.
`ReservationService` jest adapterem (dotyka `$wpdb` przez repozytorium), ale DECYZJĘ deleguje do
czystego `CapacityCalculator`. Test czystości domeny z Planu 1 obejmuje nowy enum automatycznie.

## 5. Model danych

Tabele `evreg_registrations`, `evreg_accommodation_bookings` z Planu 1 są gotowe (mają wszystkie
potrzebne kolumny: `status`, `token`, `data`, `price_total`, `expires_at`, `confirmed_at`, `name`,
`email`, `type_key`). Plan 3A dodaje jedną tabelę:

```sql
{prefix}evreg_locks
  event_id BIGINT UNSIGNED NOT NULL
  PRIMARY KEY (event_id)
  ENGINE=InnoDB
```

Jeden wiersz per event, wyłącznie do `SELECT … FOR UPDATE`. Migracja podbija `DB_VERSION` do 2;
`maybe_upgrade()` (Plan 1) uruchamia `dbDelta`. Istniejące instalacje dostają tabelę bez reaktywacji.

### Stany zgłoszenia i zajętość

```
rezerwacja → pending   (blokuje miejsce; expires_at = now + 48h)
             waitlist  (NIE blokuje; brak miejsca w cap globalnym/typie, waitlist włączona)
             [rejected — nic nie zapisane; brak miejsca, waitlist wyłączona]

pending → confirmed    (klik w link z tokenem)
pending → cancelled    (upływ expires_at; cron)
```

Zajętość = `COUNT(*)` zgłoszeń o statusie `pending` LUB `confirmed` (per event / per `type_key` /
per slot noclegowy). `waitlist` i `cancelled` nie liczą się do zajętości — zgodnie z założeniem
`CapacityCalculator` (Plan 1).

## 6. RegistrationStatus (czysta domena)

```
enum RegistrationStatus: string {
    case Pending   = 'pending';
    case Confirmed = 'confirmed';
    case Waitlist  = 'waitlist';
    case Cancelled = 'cancelled';

    public function occupiesSeat(): bool;   // true dla Pending, Confirmed
}
```

`occupiesSeat()` jest jedynym źródłem prawdy o tym, które statusy blokują miejsce — używane przy
budowaniu zapytań `COUNT` i w testach.

## 7. RegistrationRepository (adapter persystencji)

Zależność: globalne `$wpdb`. Nazwy tabel z `Migrations::table(...)`.

Metody:
- `lockEvent(int $eventId): void` — `INSERT IGNORE` wiersza-zamka, potem `SELECT … FOR UPDATE`.
  Wywoływane wyłącznie wewnątrz transakcji.
- `activeRegistrationExists(int $eventId, string $email): bool` — istnieje zgłoszenie o statusie
  zajmującym miejsce lub `waitlist` dla `(event_id, email)`? (guard duplikatu)
- `occupancy(int $eventId): OccupancySnapshot` — buduje `OccupancySnapshot` (Plan 1): globalna liczba
  zajmujących miejsce, mapa per `type_key`, mapa per slot noclegowy (`"pakiet|pokój"`).
- `insertRegistration(array $row): int` — wstawia wiersz zgłoszenia, zwraca `id`.
- `insertAccommodationBooking(int $registrationId, AccommodationSelection $selection, float $price): void`
- `findByToken(string $token): ?array` — zgłoszenie po tokenie (do potwierdzania).
- `markConfirmed(int $registrationId): void` — status `confirmed`, `confirmed_at = now`.
- `expirePending(string $now): int` — `pending` z `expires_at < now` → `cancelled`; zwraca liczbę
  wygaszonych (do logu crona).

Repozytorium nie decyduje o limitach — to zadanie `ReservationService` + `CapacityCalculator`.

## 8. ReservationService (transakcja — rdzeń)

Zależności: `RegistrationRepository`, `EventConfigRepository` (Plan 2A), `CapacityCalculator` (Plan 1),
`PriceCalculator` (Plan 1). `SchemaAssembler`/`Validator` NIE tutaj — walidacja odpowiedzi to zadanie 3B
przed wywołaniem rezerwacji; `ReservationService` dostaje już zwalidowane dane.

### `reserve(int $eventId, ReservationRequest $request): ReservationResult`

`ReservationRequest` to wartość niosąca: `email`, `name`, `typeKey`, znormalizowane `data` (odpowiedzi
z `Validator`), opcjonalny `AccommodationSelection`. **Ceny nie niesie** — `ReservationService` liczy ją
sam przez `PriceCalculator` z typu i wyboru noclegu (ma już config), żeby 3B nie musiał znać cennika.

Przebieg (jedna transakcja InnoDB):
1. `START TRANSACTION`
2. `repository->lockEvent($eventId)` — serializuje rezerwacje tego eventu
3. `if activeRegistrationExists(...)` → `ReservationResult::duplicate()`, `ROLLBACK`
4. `occupancy = repository->occupancy($eventId)`
5. `limits = CapacityLimits` z configu eventu (`EventConfigRepository`): cap globalny + waitlist z
   `_evreg_settings`, limity per typ z `_evreg_types`, limity per slot z `_evreg_accommodation`
6. `decision = CapacityCalculator::decide($limits, $occupancy, $typeKey, $selection)`
7. map `Outcome`:
   - `Rejected` → `ReservationResult::rejected($reason)`, `ROLLBACK` (nic nie zapisane)
   - `Waitlisted` → status `waitlist`
   - `Accepted` → status `pending`, `expires_at = now + 48h`
8. `priceTotal = PriceCalculator::total(typ, accommodationConfig, selection)`; `id = insertRegistration(...)`
   (status, token 32-hex CSPRNG, `data` JSON, `price_total`, `expires_at`)
9. `if decision->accommodationGranted && selection` → `insertAccommodationBooking(...)`
10. `COMMIT`
11. zwróć `ReservationResult` (kod, `registrationId`, `token`, `accommodationGranted`, `reason`)

Wyjątek w transakcji → `ROLLBACK` i rzucenie dalej (submisja w 3B pokaże błąd ogólny).

### `confirm(string $token): ConfirmationResult`

- `row = findByToken($token)`; brak → `ConfirmationResult::notFound()`
- status `confirmed` → `ConfirmationResult::alreadyConfirmed()`
- status `cancelled` (wygasłe) → `ConfirmationResult::expired()`
- status `pending` → `markConfirmed(id)` → `ConfirmationResult::confirmed()`
- status `waitlist` → `ConfirmationResult::onWaitlist()` (potwierdzenie nie dotyczy waitlisty)

Porównanie tokenu przez `hash_equals`. Token 32 znaki hex z `random_bytes(16)`.

### ReservationResult (wartość)

```
kod: 'reserved' | 'waitlisted' | 'rejected' | 'duplicate'
registrationId: ?int      (null dla rejected/duplicate)
token: ?string
accommodationGranted: bool
reason: ?string           (event_full | type_full | accommodation_full — dla waitlisted/rejected)
```

## 9. ExpirePending (cron)

- Rejestracja zadania cron `evreg_expire_pending` przy aktywacji/`maybe_upgrade`; harmonogram co 15 min
  (`wp_schedule_event`, interwał własny `evreg_15min`).
- Handler: `repository->expirePending(current_time)` → `pending` z `expires_at < now` → `cancelled`.
  Miejsce wraca do puli automatycznie (zajętość liczona ze statusu).
- Log liczby wygaszonych (na razie `error_log`/hook; pełny log wysyłek to Plan 4).
- Dokumentacja: WP-Cron zawodny przy małym ruchu — instrukcja crona systemowego (jak w oryginalnym specie).

## 10. Bezpieczeństwo

- Token 32-hex z `random_bytes` (CSPRNG); porównanie `hash_equals`; token w URL to token-uprawnienie
  (jak reset hasła — losowy, nie PII), dopuszczalny w query.
- Wyłącznie `$wpdb->prepare`; zero konkatenacji SQL.
- Transakcje InnoDB; `ReservationService` jest jedyną drogą zmiany zajętości (guard duplikatu i limity
  w tej samej transakcji co `FOR UPDATE`).
- Guard `defined( 'ABSPATH' )` we wszystkich plikach PHP poza `src/Domain/`.
- `RegistrationStatus` w `src/Domain/` — bez WordPressa (test czystości).

## 11. Testy

- **Test współbieżności (obowiązkowy):** dwa połączenia `$wpdb`/mysqli, oba `BEGIN`, oba wołają
  `lockEvent(same_event)`; drugie blokuje się aż pierwsze `COMMIT` → dowód serializacji. Integracyjny.
- **Sekwencyjne limity:** N rezerwacji na event z `global_cap=N-1` → pierwsze N-1 `pending`, reszta
  `waitlist`; brak pokoju → `Accepted` bez noclegu (`accommodationGranted=false`); duplikat e-mail →
  `duplicate`, nic nie zapisane; waitlist wyłączona i pełno → `rejected`.
- **Potwierdzanie:** token → `confirmed`; ponowne → `alreadyConfirmed`; wygasłe → `expired`; zły token →
  `notFound`.
- **Wygasanie:** `pending` z `expires_at` w przeszłości → `cancelled` po `expirePending`; miejsce zwolnione
  (kolejna rezerwacja przechodzi).
- `RegistrationStatus::occupiesSeat()` → jednostkowy.
- `ReservationResult`/`ConfirmationResult` → jednostkowe.
- Wszystko poza enumami/wartościami: integracyjne w wp-env (transakcje wymagają realnej bazy InnoDB).

## 12. Podział na taski (wysokopoziomowo)

1. `RegistrationStatus` + migracja `evreg_locks` (DB_VERSION 2)
2. `RegistrationRepository` (insert/count/lock/find/confirm/expire)
3. `ReservationResult` + `ConfirmationResult` (wartości)
4. `ReservationService::reserve` + test współbieżności i limitów
5. `ReservationService::confirm`
6. `ExpirePending` cron + interwał + rejestracja

## 13. Ryzyka

| Ryzyko | Skutek | Przeciwdziałanie |
|--------|--------|------------------|
| Wyścig o ostatnie miejsce | Nadkomplet | Blokada per event `FOR UPDATE`, test współbieżności dwoma połączeniami |
| Prawdziwa równoległość trudna w PHPUnit | Test współbieżności pozorny | Test dwoma realnymi połączeniami DB dowodzi serializacji zamka; N-parallel HTTP to domena E2E (3B) |
| WP-Cron nie odpala | Rezerwacje nie wygasają, miejsca wiszą | Cron systemowy w dokumentacji; wygasanie idempotentne |
| Dryf liczników | Zła zajętość | Brak liczników — zajętość liczona świeżo z tabeli zgłoszeń |
| Migracja na istniejącej instalacji | Brak tabeli locks | `maybe_upgrade` + bump DB_VERSION; `dbDelta` idempotentny |
