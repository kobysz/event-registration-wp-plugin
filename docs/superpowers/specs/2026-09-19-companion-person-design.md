# Spec: Osoba towarzysząca + podwójne zajęcie noclegu (B5)

Data: 2026-09-19. Backlog: B5 (`docs/superpowers/backlog.md`). Feature architektoniczny — dotyka transakcyjnego rdzenia rezerwacji (`ReservationService`) i modelu danych noclegu.

## Cel

Formularz dostaje opcję **osoby towarzyszącej**: checkbox „Osoba towarzysząca", po zaznaczeniu pokazuje pole tekstowe „Imię i nazwisko osoby towarzyszącej". Jeśli osoba towarzysząca występuje **i** wybrany jest nocleg → z puli noclegu (slot pakiet|pokój) zdejmowane są **2 miejsca zamiast 1**, a cena noclegu liczona jest za 2 miejsca. Konfigurowalnie: osoba towarzysząca może dodatkowo zajmować miejsce w limicie wydarzenia (`global_cap`). Nigdy nie liczy się do limitu typu zgłoszenia.

## Decyzje (z brainstormingu)

- **Umiejscowienie:** globalny przełącznik na poziomie konfiguracji noclegów eventu (`_evreg_accommodation`), nie pole buildera — silnik rezerwacji rozpoznaje companion semantycznie.
- **Limity:** konfigurowalne. `companion_enabled` włącza opcję; `companion_counts_event` decyduje, czy companion zajmuje też miejsce w `global_cap`. Typ zgłoszenia — nigdy.
- **Cena:** drugie miejsce noclegowe = `2 × cena pozycji noclegu` (cena typu bez zmian).
- **Model danych:** kolumna `seats` na bookingu (1/2) + flaga `companion` na zgłoszeniu. (Alternatywa „dwa wiersze bookingu" odrzucona — psuje współlokatora/eksport/anonimizację.)
- **Nocleg all-or-nothing dla 2:** gdy w slocie zostało tylko 1 miejsce, a companion wymaga 2 → nocleg NIE przyznany (`accommodation_full`, granted=false); zgłoszenie wchodzi bez noclegu (jak dziś przy pełnym slocie). Brak trybu „daj 1, towarzysza odrzuć".

## Zakres

W zakresie: model danych (migracja), domena pojemności/ceny, rezerwacja publiczna + edycja admina, formularz publiczny (render + JS toggle + ekstrakcja), konfiguracja w AccommodationTab, panel szczegółów, eksport CSV, mail (placeholder + podsumowanie), Privacy eraser.

Poza zakresem (YAGNI / inne punkty): tłumaczenie labeli companion per język (spójne z B6 — labele companion to stałe i18n stringi, pl źródło); >1 osoba towarzysząca (tylko 0 lub 1); osobna konfigurowalna opłata za companion (wybrano „drugie miejsce noclegowe", nie osobną kwotę); companion bez powiązania z konkretnym typem.

## Model danych

### Migracja (`Migrations`, DB_VERSION 4 → 5)

`{prefix}evreg_registrations` — dwie kolumny (dopisać do `CREATE TABLE`, `dbDelta` doda przez ALTER):
```
companion tinyint(1) NOT NULL DEFAULT 0,
companion_name varchar(191) NOT NULL DEFAULT '',
```
`{prefix}evreg_accommodation_bookings` — jedna kolumna:
```
seats tinyint unsigned NOT NULL DEFAULT 1,
```
- `seats` domyślnie 1 → istniejące bookingi liczą się jak dziś (zero regresji occupancy).
- `companion` domyślnie 0 → istniejące zgłoszenia bez companiona.
- Bump `DB_VERSION` 4→5. `MailQueueMigrationTest` i inne testy migracji zakładające konkretną wersję dostają aktualizację (zakres pre-approved jak przy B3c-1).

### Konfiguracja noclegu (`_evreg_accommodation`)

Nowe klucze na poziomie configu noclegu (obok `packages`/`rooms`/`inventory`):
```json
{ "companion_enabled": true, "companion_counts_event": false }
```
- Oba bool, default false. `companion_enabled=false` → cała funkcja nieaktywna (render bez checkboxa, silnik ignoruje).

## Domena (czysta, `src/Domain/`)

### `AccommodationConfig`
- `fromArray` czyta `companion_enabled`/`companion_counts_event` (cast bool).
- Nowe gettery: `companionEnabled(): bool`, `companionCountsEvent(): bool`.

### `OccupancySnapshot`
- Nowy wymiar `companions` (int) w konstruktorze: `__construct(int $global, array $perType, array $perSlot, int $companions = 0)` (domyślnie 0 = kompatybilność wsteczna z istniejącymi wywołaniami/testami).
- Nowy getter `companions(): int`.
- `perSlot` teraz niesie **sumę miejsc** (seats), nie liczbę bookingów — zmiana po stronie repo (SQL `SUM(seats)`), sam snapshot bez zmian semantyki (nadal `forSlot()` = liczba zajętych miejsc).

### `CapacityLimits`
- Nowy param `public readonly bool $companionCountsEvent = false` (na końcu, domyślnie false).

### `CapacityCalculator::decide`
Sygnatura: `decide(CapacityLimits $limits, OccupancySnapshot $taken, string $type_key, ?AccommodationSelection $selection = null, bool $companion = false): CapacityDecision`.

Logika (zmiany względem obecnej):
1. **Global:**
   - `eventSeatsRequested = 1 + ( $companion && $limits->companionCountsEvent ? 1 : 0 )`.
   - `effectiveGlobalTaken = $taken->global() + ( $limits->companionCountsEvent ? $taken->companions() : 0 )`.
   - Full gdy `null !== globalLimit && effectiveGlobalTaken + eventSeatsRequested > globalLimit`.
   - (Dla `companion=false`/`companionCountsEvent=false`: `taken.global() + 1 > limit` ⟺ dzisiejsze `taken.global() >= limit` — zero regresji.)
2. **Typ:** bez zmian (`taken.forType >= type_limit`). Companion nie liczony.
3. **Nocleg** (gdy `null !== $selection`):
   - `accSeatsRequested = $companion ? 2 : 1`.
   - Grant gdy `null !== slot_limit && $taken->forSlot(slot) + accSeatsRequested <= slot_limit`; inaczej `CapacityDecision(Accepted, false, 'accommodation_full')`.
   - (Dla `companion=false`: `forSlot + 1 <= limit` ⟺ dzisiejsze `forSlot < limit` — zero regresji.)
- `CapacityDecision` bez zmian struktury.

> Uwaga altitude: cała arytmetyka miejsc w domenie. Repo tylko dostarcza surowe liczby (global/companions/sum seats). Warstwa WP nie liczy.

### `PriceCalculator::total`
Sygnatura: `total(RegistrationType $type, ?AccommodationConfig $config = null, ?AccommodationSelection $selection = null, bool $companion = false): float`.
- Gdy jest nocleg: `total += $item->price * ( $companion ? 2 : 1 )`.
- `companion=false` → dzisiejsze zachowanie.

## Persystencja (`RegistrationRepository`, `src/Persistence/`)

### `occupancy` / `occupancyExcluding`
- Global/typ bez zmian (COUNT(*)).
- **Slot:** `SELECT CONCAT(...) AS slot, SUM(b.seats) AS c ... GROUP BY slot` (było `COUNT(*)`).
- **Companions:** dodatkowe zapytanie `SELECT COALESCE(SUM(companion),0) FROM registrations WHERE event_id=%d AND status IN (...)` (w `occupancyExcluding` + `AND id != %d`). Przekazać do `OccupancySnapshot`.

### `insertRegistration` / `updateRegistration`
- `insertRegistration`: dopisać `companion` (%d) i `companion_name` (%s) do mapy insertu (analogicznie do `lang` z B3c-1). Companion_name pusty gdy `!companion`.
- `updateRegistration`: dopisać `companion` + `companion_name` do aktualizowanych kolumn (edycja admina). NADAL nie tyka status/token/dat.

### `insertAccommodationBooking`
- Nowy param `int $seats = 1`; dopisać do insertu (`%d`). Cena bookingu wyliczana przez wywołującego (`item.price * seats`).

## Rezerwacja (`ReservationService`, `src/Services/`)

### `ReservationRequest`
- Nowe pola: `public readonly bool $companion = false`, `public readonly string $companionName = ''` (na końcu, po `lang`).

### `reserve`
- `decide(..., $request->companion)`.
- `price = $this->pricing->total( $type, $accommodation, $request->selection, $request->companion )`.
- `insertRegistration(... 'companion' => $request->companion ? 1 : 0, 'companion_name' => $request->companion ? $request->companionName : '' )`.
- Gdy grant noclegu: `seats = $request->companion ? 2 : 1`; `acc_price = $item->price * $seats`; `insertAccommodationBooking( $id, $selection, $acc_price, $seats )`.
- **Kolejność lockEvent→occupancy→decide NIENARUSZONA.**

### `editAnswers`
- Analogicznie: `decide(..., $request->companion)`, `pricing->total(..., $companion)`, `updateRegistration` pisze companion+name, rebuild booking z seats. `occupancyExcluding` już liczy SUM(seats)+companions bez self.

## Formularz publiczny

### `FormRenderer::renderAccommodation` (lub sekcja obok)
- Gdy `companionEnabled`: po bloku noclegu wyrenderuj:
  - checkbox `name="evreg_companion"` value=1, label „Osoba towarzysząca" (klasy BS5 `form-check`).
  - pole tekstowe `name="evreg_companion_name"` label „Imię i nazwisko osoby towarzyszącej", `data-evreg-companion-input`, `hidden` gdy checkbox niezaznaczony (prefill przy re-renderze błędu).
- Nazwy `evreg_companion` / `evreg_companion_name` — kontrolki `evreg_*` (jak nonce/ts/hp), NIE `evreg_field[...]`. Bezpieczne wobec POST-to-self (nie kolidują z WP query-vars).
- Re-render błędu zachowuje stan checkboxa i wartość imienia.

### `assets/public/form.js`
- Nowy `applyCompanion` (wzór jak istniejący `applyRoommate`): nasłuch zmiany `input[name="evreg_companion"]`, toggle `hidden` na `[data-evreg-companion-input]`. Wpięty w init obok roommate.

### `SubmissionAssembler`
- Ekstrakcja: `$companion = ! empty( $_POST['evreg_companion'] )`; `$companionName = $companion ? sanitize_text_field( wp_unslash( $_POST['evreg_companion_name'] ?? '' ) ) : ''`.
- **Walidacja:** companion zaznaczony + puste imię → błąd walidacji z kodem `companion_name_required` (komunikat „Podaj imię i nazwisko osoby towarzyszącej"). Spójne z pipeline `AssembledSubmission{isValid,errors}`.
- Przekazać `companion`/`companionName` do `ReservationRequest`.
- Companion czytany tylko gdy event ma `companionEnabled` (inaczej ignoruj POST — nie ufaj klientowi).

## Admin — konfiguracja (`AccommodationTab` + ops)

- Dwa `ToggleControl`:
  - „Opcja osoby towarzyszącej" → `companion_enabled`.
  - „Osoba towarzysząca zajmuje miejsce w limicie wydarzenia" → `companion_counts_event` (render tylko gdy `companion_enabled`).
- Logika mutacji w czystym ops (`accommodationOps.js` lub istniejący moduł konfiguracji noclegu) — immutable, testowane `jest`.
- `EventConfigController::sanitize` przepuszcza `companion_enabled`/`companion_counts_event` jako bool.

## Admin — panel / eksport / mail / privacy

- **Szczegóły zgłoszenia** (`RegistrationsScreen` view): wiersz „Osoba towarzysząca: {imię}" gdy companion.
- **`RegistrationEditForm`**: kontrolki companion (checkbox) + name, prefill z wiersza, reuse `applyCompanion` (handle `evreg-public`). Walidacja jak public.
- **CSV** (`RegistrationsExporter::buildCsv`): nowa kolumna tożsamości „Osoba towarzysząca" (po „Imię" albo na końcu tożsamości), wartość = companion_name (pusta gdy brak). Neutralizacja CSV injection jak inne komórki danych.
- **Mail** (`PlaceholderFactory`): placeholder `{osoba_towarzyszaca}` = companion_name (pusty gdy brak); linia w `podsumowanie` „Osoba towarzysząca: {imię}" gdy companion (jak linia noclegu). `DefaultTemplates` mogą, ale nie muszą go używać (fallback pusty bezpieczny).
- **Privacy eraser** (`PrivacyProvider`/`RegistrationRepository::anonymize*`): `companion_name` → `''` (dopisać do anonimizacji, obok name/data/note). `companion` flaga ZOSTAJE (nie zmienia zajętości/liczników — spójne z „status/typ/cena/daty zostają").

## Testy

- **Unit (domena):**
  - `CapacityCalculator`: companion + `companionCountsEvent=true` → +1 do eventu; `false` → event bez companiona. Nocleg: companion żąda 2, grant gdy `slot+2<=limit`, deny gdy zostało 1. Parytet: `companion=false` = dzisiejsze decyzje (regresja).
  - `PriceCalculator`: companion → `+2×accPrice`; bez companiona bez zmian.
  - `OccupancySnapshot`: `companions()` + `forSlot()` jako suma miejsc.
- **Integration:**
  - Migracja: świeża instalacja ma kolumny `companion`/`companion_name`/`seats`; DB_VERSION=5.
  - `reserve`: companion+nocleg → wiersz `companion=1`, booking `seats=2`, cena = typ + 2×nocleg; occupancy slotu rośnie o 2. Companion bez noclegu → `companion=1`, brak bookingu; global +1 tylko gdy `companion_counts_event`.
  - `reserve` deny: slot z 1 wolnym miejscem + companion → `accommodation_full`, brak bookingu, zgłoszenie bez noclegu.
  - Walidacja: companion bez imienia → błąd `companion_name_required`, brak zapisu.
  - `editAnswers`: zmiana companion przelicza seats/cenę/occupancy bez self.
  - Eksport: kolumna „Osoba towarzysząca" z imieniem.
  - Privacy erase: companion_name wyczyszczone, companion/seats/status zostają.
- **jest:** ops toggle companion (immutable, set/clear obu flag).
- **e2e:** opcjonalnie (checkbox pokazuje pole imienia) — może być pominięte, ścieżka pokryta integracyjnie/jest.

## Inwarianty / uwagi

- `src/Domain/**` bez WP — cała arytmetyka miejsc/ceny w domenie.
- Lock→count w `reserve` NIENARUSZONY.
- `seats=1`/`companion=0`/`companion_counts_event=false` jako defaulty = **zero regresji** dla istniejących instalacji i eventów bez companiona.
- Hooki cyklu życia bez zmian (companion nie tworzy nowych zdarzeń).
- Data-safety: anonimizacja czyści imię towarzysza, ale companion/seats zostają (spójność liczników).
- i18n: labele companion to stałe stringi (pl źródło, text domain `event-registration`); tłumaczenie per język = przyszłe B6.

## Kolejność implementacji (dla planu)

1. Migracja: kolumny `companion`/`companion_name`/`seats` + bump DB_VERSION + test istnienia. Aktualizacja testów migracji zakładających wersję.
2. Domena: `AccommodationConfig` gettery; `OccupancySnapshot.companions` + slot-as-seats; `CapacityLimits.companionCountsEvent`; `CapacityCalculator::decide(...,$companion)`; `PriceCalculator::total(...,$companion)`. Testy unit.
3. Repo: `occupancy`/`occupancyExcluding` (SUM seats + companions); `insertRegistration`/`updateRegistration` (companion+name); `insertAccommodationBooking($seats)`. Testy integration.
4. `ReservationRequest` + `reserve`/`editAnswers` threadują companion. Testy integration reserve/edit.
5. Formularz: `FormRenderer` companion controls; `form.js` `applyCompanion`; `SubmissionAssembler` ekstrakcja + walidacja. Testy.
6. Admin config: `AccommodationTab` toggle + ops (jest) + `EventConfigController` sanitize.
7. Panel/edycja/eksport/mail/privacy: szczegóły, `RegistrationEditForm`, CSV kolumna, placeholder+summary, eraser. Testy.
8. Pełne testy, build, paczka, backlog.
