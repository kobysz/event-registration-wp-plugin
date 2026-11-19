# B39 + B40 — komunikat o braku miejsc przed formularzem Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Formularz publiczny mówi PRZED wypełnieniem, że wydarzenie jest pełne (lista rezerwowa → komunikat + formularz; bez listy → sam komunikat), a odmowa `rejected` przestaje czyścić wpisane dane.

**Architecture:** Składanie `CapacityLimits` przenosi się do konstruktorów nazwanych w domenie (`forClassic`/`forNights`), wołanych przez `ReservationService`, `RegistrationsOverview` i render. Nowy czysty `EventAvailability` pyta `CapacityCalculator::decide` o każdy aktywny typ — zero kopii reguł. Teksty: baza w `_evreg_settings`, overlay `_evreg_i18n[lang].notices`, domyślne `__()` w `Shortcode`.

**Tech Stack:** PHP 8 (WordPress plugin, PHPUnit unit+integration w wp-env), React admin (`@wordpress/components`, jest).

**Spec:** `docs/superpowers/specs/2026-11-19-event-capacity-notice-design.md` — czytaj razem z planem.

## Global Constraints

- Gałąź `feature/b39-capacity-notice` od `master`. Repo bywa współdzielone przez inne sesje — przed każdym taskiem `git branch --show-current`.
- `src/Domain/**` bez WordPressa i `$wpdb` (pilnuje `tests/Unit/Architecture/DomainPurityTest.php`).
- Domena nie zna tekstów dla użytkownika; domyślne komunikaty tylko w `Shortcode` przez `__( …, 'event-registration' )`.
- Każdy string UI (PHP i JS) przez i18n, text domain `event-registration`.
- `global_cap` i `waitlist_enabled` czyta WYŁĄCZNIE `CapacityLimits::fromSettings()` (po Tasku 2 — żadnego innego czytelnika w `src/`).
- Klucze settings: `full_waitlist_notice`, `full_closed_notice`. Bucket overlayu: `notices` z liśćmi `full_waitlist`, `full_closed`.
- Domyślne teksty (dokładnie):
  - waitlist: `Wszystkie miejsca są zajęte. Możesz się zapisać — trafisz na listę rezerwową i damy znać, gdy zwolni się miejsce.`
  - closed: `Rejestracja zamknięta — brak wolnych miejsc.`
- Teksty organizatora = zwykły tekst (`sanitize_text_field`), NIE dopisywać do `EventConfigController::RICH_TEXT_KEYS`, NIE ruszać `I18nController::sanitize`.
- PHPStan poziom 6 bez nowych ignore; `phpcs` czysto.
- Komendy PHP przez `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- <cmd>` (dalej: `WPENV <cmd>`). Nigdy gołe `npx wp-env`.

## Review Focus

1. `global_cap = 0` — event zamknięty od startu; musi dać Waitlist/Closed, nie Open (test w Tasku 3).
2. POST na event, który właśnie stał się Closed (wyścig, wynik `rejected`) — strona pokazuje TYLKO komunikat closed, bez `<form` i bez drugiego alertu „Brak wolnych miejsc.” (test w Tasku 5).
3. Event klasyczny z niespójnym inwentarzem (`AccommodationConfig::fromArray` rzuca) — formularz renderuje się jak dziś, bez komunikatu, bez fatala (test w Tasku 5).
4. Event w trybie „doby” z pełnymi typami — komunikat działa (ścieżka `forNights`), a zalegające dane klasyczne nie są budowane (test w Tasku 5).
5. Nieaktywny typ z wolnym miejscem nie trzyma eventu otwartego (test w Tasku 5).

---

### Task 1: B40 — odmowa `rejected` zachowuje wpisane wartości

**Files:**
- Modify: `src/Frontend/SubmitResult.php:78-80`
- Modify: `src/Frontend/SubmitHandler.php` (~213, ~248, ~259)
- Test: `tests/Integration/Frontend/SubmitHandlerTest.php`

**Interfaces:**
- Produces: `SubmitResult::rejected( array $values = array() ): self` — kod `'rejected'`, wartości dostępne przez istniejące `submittedValue()`.

- [ ] **Step 1: Utwórz gałąź**

```bash
git checkout master && git pull --ff-only 2>/dev/null; git checkout -b feature/b39-capacity-notice
```

- [ ] **Step 2: Failing test** `test_rejected_keeps_submitted_values` w `SubmitHandlerTest`: event z typem `std` o `capacity => 1`, `waitlist_enabled => false`, jedno zgłoszenie już zajmuje miejsce (wzór istniejących testów odrzucenia w pliku). `process()` z odpowiedziami zawierającymi tekstowe pole imienia `'Jan'`. Asercje: `'rejected' === $result->code()`, `'Jan' === $result->submittedValue( '<klucz pola imienia>' )`. Drugi test: render `FormRenderer` z tym wynikiem zawiera `value="Jan"`.

- [ ] **Step 3: Uruchom — FAIL**

Run: `WPENV vendor/bin/phpunit -c phpunit-integration.xml.dist --filter test_rejected_keeps_submitted_values`
Expected: FAIL (`submittedValue` zwraca null).

- [ ] **Step 4: Implementacja.** `rejected( array $values = array() )` → `new self( 'rejected', false, array(), $values )`. Wszystkie trzy wołania w `SubmitHandler::process` → `SubmitResult::rejected( $assembled->rawAnswers() )` (NIE `values()`). Jeśli `FormRenderer` odtwarza wartości tylko dla wybranych kodów — poszerz o `rejected`.

- [ ] **Step 5: Uruchom — PASS** (ten sam filter + cały `SubmitHandlerTest`).

- [ ] **Step 6: Commit**

```bash
git add src/Frontend/SubmitResult.php src/Frontend/SubmitHandler.php tests/Integration/Frontend/SubmitHandlerTest.php
git commit -m "fix(form): keep typed answers when a registration is refused for capacity"
```

---

### Task 2: Jeden dom składania limitów — `CapacityLimits::forClassic/forNights`

**Files:**
- Modify: `src/Domain/Capacity/CapacityLimits.php`
- Modify: `src/Services/ReservationService.php:66-138` (usunąć `limits`, `classicLimits`, `nightsLimits`; podmienić 6 wołań: ~455, ~607, ~1021, ~1154, ~1470, ~1684)
- Modify: `src/Admin/RegistrationsOverview.php:85-121`
- Test: `tests/Unit/Domain/Capacity/CapacityLimitsTest.php` (nowy)

**Interfaces:**
- Produces:
  - `CapacityLimits::forClassic( array $settings, RegistrationTypeCollection $types, AccommodationConfig $accommodation ): self`
  - `CapacityLimits::forNights( array $settings, RegistrationTypeCollection $types, NightsConfig $nights ): self`
  - `private static CapacityLimits::fromSettings( array $settings, RegistrationTypeCollection $types, array $slot_caps, bool $companion_counts_event, array $slot_seats ): self` — logika i docblock (z historią B5) przeniesione 1:1 z `ReservationService::limits()`.

- [ ] **Step 1: Failing tests** w `CapacityLimitsTest`:
  - `test_global_cap_absent_or_null_means_unlimited` — `forClassic( array(), …, AccommodationConfig::fromArray( array() ) )->globalLimit === null`, to samo dla `'global_cap' => null`.
  - `test_global_cap_numeric_string_cast_to_int` — `'global_cap' => '5'` → `5`; `'global_cap' => 0` → `0`.
  - `test_waitlist_defaults_to_true` / `test_waitlist_false_respected`.
  - `test_type_limits_from_collection` — `forType('std')` = capacity typu.
  - `test_classic_takes_slot_caps_and_companion_flag_from_accommodation` — `forSlot('pkg|room')` i `companionCountsEvent` z `AccommodationConfig` (`companion_counts_event => true`, `companion_enabled => true`).
  - `test_nights_has_no_slots_and_companion_flag_from_nights_config` — `forSlot('pkg|room') === null`, `companionCountsEvent` z `NightsConfig::fromArray( array( 'mode' => 'nights', 'companion_counts_event' => true, … ) )`.

- [ ] **Step 2: Uruchom — FAIL**

Run: `WPENV vendor/bin/phpunit --testsuite unit --filter CapacityLimitsTest`
Expected: FAIL (`Call to undefined method …forClassic()`).

- [ ] **Step 3: Implementacja konstruktorów** w `CapacityLimits` (importy z `EvReg\Domain\Accommodation\…` i `EvReg\Domain\Registration\RegistrationTypeCollection`).

- [ ] **Step 4: Przepięcie `ReservationService`** — sześć wołań `$this->classicLimits(...)`/`$this->nightsLimits(...)` → `CapacityLimits::forClassic(...)`/`::forNights(...)` z tymi samymi argumentami; trzy prywatne metody usunięte. Komentarze przy wołaniach odsyłające do `nightsLimits()` przepisz na `CapacityLimits::forNights()`.

- [ ] **Step 5: Przepięcie `RegistrationsOverview::report`** — usuń lokalne `$global_cap`; `$limits = $nights->isNights() ? CapacityLimits::forNights( $settings, $types, $nights ) : CapacityLimits::forClassic( $settings, $types, $accommodation );` przekazane do `OccupancyReport`. Linia `AccommodationConfig::fromArray( $nights->isNights() ? array() : $accommodation_raw )` zostaje.

- [ ] **Step 6: Sprawdź jedynego czytelnika**

Run: `git grep -n "global_cap'\]\|waitlist_enabled'\]" -- src`
Expected: trafienia wyłącznie w `src/Domain/Capacity/CapacityLimits.php` (i ewentualnie sanitizer w `src/Rest/EventConfigController.php`, jeśli tam istnieje — nie jest czytelnikiem reguły).

- [ ] **Step 7: Pełne suity — PASS**

Run: `WPENV vendor/bin/phpunit --testsuite unit` oraz `WPENV vendor/bin/phpunit -c phpunit-integration.xml.dist` oraz `WPENV composer analyse` oraz `WPENV vendor/bin/phpcs`
Expected: wszystko zielone (zachowanie rezerwacji identyczne; `DomainPurityTest` zielony).

- [ ] **Step 8: Commit**

```bash
git add src/Domain/Capacity/CapacityLimits.php src/Services/ReservationService.php src/Admin/RegistrationsOverview.php tests/Unit/Domain/Capacity/CapacityLimitsTest.php
git commit -m "refactor(capacity): build CapacityLimits in one domain home"
```

---

### Task 3: Czysty sąd `EventAvailability`

**Files:**
- Create: `src/Domain/Capacity/EventAvailability.php`
- Create: `src/Domain/Capacity/EventAvailabilityState.php` (enum: `Open`, `Waitlist`, `Closed`)
- Test: `tests/Unit/Domain/Capacity/EventAvailabilityTest.php`

**Interfaces:**
- Consumes: `CapacityLimits` (Task 2), `OccupancySnapshot`, `CapacityCalculator::decide`.
- Produces: `EventAvailability::decide( CapacityLimits $limits, OccupancySnapshot $taken, array $typeKeys ): EventAvailabilityState` (`$typeKeys` = `string[]`). Statyczna funkcja zwracająca enum — osobny obiekt-wynik jest zbędny.

- [ ] **Step 1: Failing tests** (limity budowane `new CapacityLimits(...)` wprost):
  - `test_one_type_open_keeps_event_open` — `std` cap 1 zajęte 1, `vip` cap 2 zajęte 0 → `Open`.
  - `test_all_types_full_without_global_cap_is_waitlist_when_enabled` → `Waitlist`.
  - `test_all_types_full_without_global_cap_is_closed_when_waitlist_disabled` → `Closed`.
  - `test_uncapped_type_keeps_event_open` — `std` cap null → `Open` mimo zajętości 100.
  - `test_global_cap_exhausted_closes_regardless_of_types` — global 10, zajęte 10, typ bez limitu → `Closed` (waitlist off).
  - `test_global_cap_zero_is_full` — global 0, nic zajęte → `Waitlist` (waitlist on).
  - `test_companions_count_toward_global_when_flag_set` — global 10, `globalCount` 8 + `companions` 2, `companionCountsEvent = true` → pełny; z `false` → `Open`.
  - `test_single_fits_even_if_pair_would_not` — global 10, zajęte 9, flaga companion true → `Open`.
  - `test_no_types_falls_back_to_global_cap` — `$typeKeys = array()`: global 5 zajęte 5 → pełny; global null → `Open`.

- [ ] **Step 2: Uruchom — FAIL**

Run: `WPENV vendor/bin/phpunit --testsuite unit --filter EventAvailabilityTest`
Expected: FAIL (klasa nie istnieje).

- [ ] **Step 3: Implementacja.** Pusta lista → sprawdzany klucz `''`. Dla każdego klucza `decide( $limits, $taken, $key, null, false )`; pierwszy `Outcome::Accepted` → `Open`; inaczej `waitlistEnabled ? Waitlist : Closed`. Docblock: odsyłacz do B34 (pusty typ świadomie nie sprawdzany, gdy typy istnieją).

- [ ] **Step 4: Uruchom — PASS** (+ `DomainPurityTest`).

- [ ] **Step 5: Commit**

```bash
git add src/Domain/Capacity/EventAvailability.php src/Domain/Capacity/EventAvailabilityState.php tests/Unit/Domain/Capacity/EventAvailabilityTest.php
git commit -m "feat(capacity): pure judgement whether an event can still take anyone"
```

---

### Task 4: Teksty komunikatów — overlay i odczyt

**Files:**
- Modify: `src/Domain/Schema/ContentTranslator.php` (nowa metoda obok `translateAccommodation`)
- Modify: `src/Frontend/EventFormLoader.php` (nowa publiczna metoda; `translated()` bez zmian)
- Test: `tests/Unit/Domain/Schema/ContentTranslatorTest.php`, `tests/Integration/Frontend/EventFormLoaderI18nTest.php`

**Interfaces:**
- Produces:
  - `ContentTranslator::translateNotices( array $settings, array $overlay, string $lang ): array` — zwraca `$settings` z `full_waitlist_notice`/`full_closed_notice` nadpisanymi przez `$overlay[$lang]['notices']['full_waitlist'|'full_closed']`, gdy niepuste po `trim`. `$lang === ''` lub brak bucketu → `$settings` bez zmian.
  - `EventFormLoader::settings( int $event_id ): array` — `_evreg_settings` eventu z nałożonym overlayem bieżącego języka (`CurrentLanguage::get()`).

- [ ] **Step 1: Failing unit tests** w `ContentTranslatorTest`: `test_translate_notices_overlay_wins`, `test_translate_notices_blank_overlay_falls_back_to_base` (`'  '` → baza), `test_translate_notices_other_language_does_not_leak`, `test_translate_notices_empty_lang_returns_settings`, `test_translate_notices_leaves_other_settings_untouched` (`global_cap` nietknięty).

- [ ] **Step 2: Failing integration test** w `EventFormLoaderI18nTest`: `test_settings_applies_notice_overlay_for_current_language` — meta settings `full_closed_notice => 'Zamknięte'`, overlay `en.notices.full_closed => 'Closed'`, filtr `evreg_current_language` → `'en'` → `settings()['full_closed_notice'] === 'Closed'`; bez filtra → `'Zamknięte'`.

- [ ] **Step 3: Uruchom — FAIL** (`--filter translate_notices` unit, `--filter test_settings_applies_notice_overlay` integration).

- [ ] **Step 4: Implementacja** obu metod (wzór `translateAccommodation`/`translated()`).

- [ ] **Step 5: Uruchom — PASS.**

- [ ] **Step 6: Commit**

```bash
git add src/Domain/Schema/ContentTranslator.php src/Frontend/EventFormLoader.php tests/Unit/Domain/Schema/ContentTranslatorTest.php tests/Integration/Frontend/EventFormLoaderI18nTest.php
git commit -m "feat(i18n): translatable full-event notices"
```

---

### Task 5: Render komunikatu w `Shortcode::renderForm`

**Files:**
- Modify: `src/Frontend/Shortcode.php:73-99` (+ prywatne helpery)
- Test: `tests/Integration/Frontend/ShortcodeTest.php`

**Interfaces:**
- Consumes: `CapacityLimits::forClassic/forNights` (Task 2), `EventAvailability::decide` + `EventAvailabilityState` (Task 3), `EventFormLoader::settings()` (Task 4), `RegistrationRepository::occupancy( int )`.
- Produces: alert HTML z klasą-hookiem `evreg-full` (wariant `info` dla Waitlist, `warning` dla Closed) przez istniejące `self::alert()`.

- [ ] **Step 1: Failing tests** w `ShortcodeTest` (wzór setUp pliku; zajętość przez wstawione zgłoszenia `pending`/`confirmed`):
  - `test_full_event_without_waitlist_renders_notice_and_no_form` — brak `<form`, jest domyślny tekst closed, jest `evreg-full`.
  - `test_full_event_with_waitlist_renders_notice_and_form` — jest domyślny tekst waitlist i `<form`.
  - `test_open_event_renders_no_full_notice` — brak `evreg-full`.
  - `test_one_full_type_with_other_open_renders_no_notice`.
  - `test_inactive_open_type_does_not_keep_event_open` — aktywny `std` pełny, nieaktywny `vip` wolny, waitlist off → Closed.
  - `test_custom_notice_from_settings_wins_over_default` — `full_closed_notice => 'Koniec zapisów'`.
  - `test_blank_custom_notice_falls_back_to_default` — `full_closed_notice => '   '`.
  - `test_closed_after_rejected_post_shows_only_closed_notice` — wymuś wynik `rejected` w magazynie `SubmitHandler` (wzór istniejących testów wyników w pliku) → brak `<form`, brak `Brak wolnych miejsc.`, jest tekst closed.
  - `test_broken_classic_inventory_renders_form_without_notice` — inwentarz klasyczny niespójny (wzór z `AccommodationAvailabilityRenderTest`/B-slots), typy pełne → jest `<form`, brak `evreg-full`.
  - `test_nights_mode_full_event_renders_notice` — `mode => 'nights'` z zalegającym niespójnym inwentarzem klasycznym, typy pełne, waitlist off → komunikat closed, bez fatala.

- [ ] **Step 2: Uruchom — FAIL**

Run: `WPENV vendor/bin/phpunit -c phpunit-integration.xml.dist --filter ShortcodeTest`

- [ ] **Step 3: Implementacja.** Po rozstrzygnięciu `$nights`, przed enqueue `evreg-public`:
  - `private static function eventAvailability( int $event_id, ?NightsConfig $nights ): EventAvailabilityState` — config z `EventConfigRepository`, `$types` z `RegistrationTypeCollection::fromArray`, limity `forNights` gdy `$nights !== null`, inaczej `forClassic` z `AccommodationConfig::fromArray` w `try` (`SchemaException` → `Open`); klucze = `array_map( fn( $t ) => $t->key, $types->active() )`; zajętość `( new RegistrationRepository() )->occupancy( $event_id )`.
  - `private static function fullNotice( int $event_id, EventAvailabilityState $state ): string` — tekst z `( new EventFormLoader(...) )->settings( $event_id )[ klucz ]`, po `trim` pusty → domyślny `__()` z Global Constraints.
  - `Closed` → `enqueueStyles()` + `return` samego alertu (bez `resultNotice`, bez formularza, bez `evreg-public`). `Waitlist` → alert przed `$notice` i formularzem. `Open` → bez zmian.

- [ ] **Step 4: Uruchom — PASS** (cały `ShortcodeTest` + cały katalog `tests/Integration/Frontend`).

- [ ] **Step 5: Commit**

```bash
git add src/Frontend/Shortcode.php tests/Integration/Frontend/ShortcodeTest.php
git commit -m "feat(form): tell registrants the event is full before they fill the form"
```

---

### Task 6: Admin — pola w Ustawieniach i wiersze w Tłumaczeniach

**Files:**
- Modify: `assets/admin/tabs/SettingsTab.jsx`
- Modify: `assets/admin/ops/i18nOps.js`
- Modify: `assets/admin/tabs/TranslationsTab.jsx`
- Test: `assets/admin/ops/i18nOps.test.js`

**Interfaces:**
- Produces (i18nOps):
  - `langBucket` zwraca też `notices: base.notices || {}`.
  - `translatableItems( schema, types, accommodation, settings )` — czwarty argument opcjonalny; dopisuje `{ kind: 'notice', key: 'full_waitlist', base }` i `{ kind: 'notice', key: 'full_closed', base }` tylko gdy `settings` podane; `base` = tekst z `settings.full_waitlist_notice`/`full_closed_notice` albo — gdy pusty — domyślny tekst z Global Constraints przez `__()`.
  - `getNoticeTranslation( overlay, lang, key ): string`, `setNoticeTranslation( overlay, lang, key, value ): object` (niemutujące, wzór `get/setDescriptionTranslation`).

- [ ] **Step 1: Failing jest tests:**
  - `langBucket zachowuje notices przy zapisie innego bucketu` — overlay `{ en: { notices: { full_closed: 'X' } } }` → `setTranslation( …, 'en', 'field', 'imie', 'Name' )` → `en.notices.full_closed === 'X'`.
  - `get/setNoticeTranslation` round-trip i brak mutacji wejścia.
  - `translatableItems z settings dopisuje dwa wiersze notice`; `bez settings nie dopisuje` (istniejące liczniki np. `toHaveLength( 6 )` muszą zostać).
  - `notice base spada na domyślny tekst, gdy ustawienie puste`.

- [ ] **Step 2: Uruchom — FAIL**

Run: `npm run test:js -- i18nOps`

- [ ] **Step 3: Implementacja i18nOps.**

- [ ] **Step 4: `TranslationsTab`** — podaj `config.settings || {}` jako czwarty argument; gałąź `'notice' === item.kind` w odczycie i zapisie (`get/setNoticeTranslation`), wzór gałęzi `description`.

- [ ] **Step 5: `SettingsTab`** — pod przełącznikiem `waitlist_enabled` dwa `TextControl` (`full_waitlist_notice`, `full_closed_notice`) przez istniejące `set( key )`; etykiety i `help` przez `__()`, `placeholder` = domyślny tekst. Domyślne teksty trzymaj w jednym miejscu (np. eksport stałej z `i18nOps.js` albo małego modułu w `ops/`), żeby `SettingsTab` i `translatableItems` nie miały dwóch kopii.

- [ ] **Step 6: PASS + build**

Run: `npm run test:js` oraz `npm run build`
Expected: zielone, build bez błędów.

- [ ] **Step 7: Commit**

```bash
git add assets/admin
git commit -m "feat(admin): edit and translate full-event notices"
```

---

### Task 7: Dokumentacja, i18n, paczka

**Files:**
- Modify: `CLAUDE.md` (sekcja „Rdzeń rezerwacji”: `limits()/classicLimits()/nightsLimits()` → `CapacityLimits::forClassic/forNights` jako jedyny czytelnik `global_cap`/`waitlist_enabled`; sekcja formularza publicznego: `EventAvailability`, stany, teksty, bucket `notices` w opisie `_evreg_i18n` i liście `langBucket`; scalone: B39/B40)
- Modify: `docs/superpowers/backlog.md` (B39, B40 → ZAMKNIĘTE z datą 2026-11-19; do B34 dopisać zdanie o pustym typie przy pełnych typach)
- Modify: `CHANGELOG.md` (`## [Unreleased]`: Added — full-event notice before the form, editable and translatable; Fixed — refused registration keeps typed answers)
- Modify: `languages/event-registration.pot`

- [ ] **Step 1: Wpisy w trzech plikach dokumentacji** jak wyżej.
- [ ] **Step 2: `npm run i18n:pot`** — Expected: `.pot` zawiera oba domyślne teksty.
- [ ] **Step 3: Pełna weryfikacja** — unit, integration, `composer analyse`, `phpcs`, `npm run test:js`, `npm run build`; wszystko zielone.
- [ ] **Step 4: `bash scripts/build-zip.sh`** — Expected: `event-registration.zip` powstaje.
- [ ] **Step 5: Commit**

```bash
git add CLAUDE.md docs/superpowers/backlog.md CHANGELOG.md languages/event-registration.pot
git commit -m "docs: close B39 and B40"
```
