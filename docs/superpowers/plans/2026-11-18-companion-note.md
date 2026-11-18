# Przypis pod checkboxem „Osoba towarzysząca" — plan

> **Dla pracujących agentowo:** WYMAGANY SUB-SKILL: superpowers:subagent-driven-development.

**Cel:** organizator wpisuje per wydarzenie jedno zdanie, które renderuje się pod
checkboxem osoby towarzyszącej, jest tłumaczalne per język, a puste nie renderuje nic.

**Architektura:** skalarny `companion_note` w istniejącym meta `_evreg_accommodation`,
akcesor `companionNote()` na obu obiektach konfiguracji noclegu (renderer rozgałęzia się
po trybie), render w `FormRenderer::renderCompanion` pod checkboxem i nad etykietą imienia.
Tłumaczenie jedzie istniejącym szwem `accommodation[accKind][key]` jako
`accommodation.companion.note`, więc `langBucket` i `TranslationsTab` zostają bez zmian.

**Tech Stack:** PHP 8.1+ (`src/Domain` czyste), WordPress REST, React (`@wordpress/components`),
PHPUnit (unit + integracja w wp-env), jest.

**Spec:** `docs/superpowers/specs/2026-11-18-companion-note-design.md`

## Global Constraints

- **W trybie `nights` nic nie konstruuje `AccommodationConfig`** — jego `fromArray` rzuca na
  zalegającym inwentarzu klasycznym. Render czyta przez obiekt właściwy dla trybu.
- `src/Domain/**`: zero WordPressa, zero `$wpdb`, zero stringów dla użytkownika
  (`DomainPurityTest`).
- Wszystkie stringi UI przez i18n, text domain `event-registration`; w JS `__()` z `@wordpress/i18n`.
- Logika mutacji konfiguracji w czystych `assets/admin/ops/*`, bez mutowania wejścia.
- Każdy plik PHP poza `src/Domain/` i `tests/` zaczyna się od `defined( 'ABSPATH' ) || exit;`.
- PHPStan poziom 6 bez obniżania i bez `@phpstan-ignore`; `phpcs` czysto, bez nowych wykluczeń.
- Bez migracji bazy. Klucz wchodzi do istniejącego meta, `Uninstaller` bez zmian.
- Treść przez `wp_kses_post` (link i pogrubienie zostają, skrypty nie), NIE `sanitize_text_field`.

### Komendy

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- composer analyse
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
npm run test:js && npm run build
```

Nigdy gołe `npx wp-env`. PHPStan WYŁĄCZNIE przez `composer analyse` (niesie `--memory-limit=2G`;
goła binarka pada na 128M komunikatem wyglądającym jak błąd kodu).

## Review Focus

Pięć wejść, które spec implikuje, a o które łatwo się potknąć — każde ma test w tasku,
który jest właścicielem kodu:

1. **Osierocone tłumaczenie.** Przypis wyczyszczony w konfiguracji, tłumaczenie zostaje
   w overlayu. `pick()` zwraca niepuste tłumaczenie ponad bazę, więc naiwne nałożenie
   WSKRZESIŁOBY usunięty przypis — i tylko w obcym języku. Puste w bazie musi znaczyć
   „wyłączone we wszystkich językach". (T1)
2. **Przypis przy wyłączonej towarzyszącej.** `companion_enabled = false` → nie ma checkboxa,
   przy którym przypis mógłby stać; nie renderuje się i nie prosi o tłumaczenie. (T2, T3)
3. **Same białe znaki.** Organizator trafia spację i zapisuje. Nie renderować `<p> </p>`. (T2)
4. **Tryb `nights`.** Przypis musi dojść do renderu bez konstruowania `AccommodationConfig`. (T2)
5. **Treść wroga.** `<script>` w zapisie REST zdjęty, `<a href>` zachowany. (T1)

---

## Task 1: konfiguracja, sanityzacja i tłumaczenie przypisu

**Files:**
- Modify: `src/Domain/Accommodation/AccommodationConfig.php` (konstruktor, `fromArray`, nowy akcesor)
- Modify: `src/Domain/Accommodation/NightsConfig.php` (to samo)
- Modify: `src/Domain/Schema/ContentTranslator.php:86-115` (`translateAccommodation`)
- Modify: `src/Rest/EventConfigController.php:231` (`RICH_TEXT_KEYS`)
- Test: `tests/Unit/Domain/Accommodation/AccommodationConfigTest.php`,
  `tests/Unit/Domain/Accommodation/NightsConfigTest.php`,
  `tests/Unit/Domain/Schema/ContentTranslatorTest.php`,
  `tests/Integration/Rest/EventConfigControllerTest.php`

**Interfaces:**
- Produces: `AccommodationConfig::companionNote(): string` i `NightsConfig::companionNote(): string`
  (default `''`, czytane z klucza `companion_note`); overlay w kształcie
  `_evreg_i18n[<lang>]['accommodation']['companion']['note']`.

- [ ] **Step 1: testy jednostkowe obu obiektów konfiguracji**

```php
// AccommodationConfigTest / NightsConfigTest — po jednym w każdym
public function test_companion_note_defaults_to_empty_string(): void;   // fromArray( array() )
public function test_companion_note_comes_from_config(): void;          // 'companion_note' => 'Przypis.'
```

Dla `NightsConfigTest` konfiguracja wejściowa niesie `'mode' => 'nights'`.

- [ ] **Step 2: test tłumaczenia przypisu**

W `ContentTranslatorTest`, przez `translateAccommodation( $accommodation, $overlay, $lang )`:

```php
public function test_companion_note_overlay_replaces_base(): void;
// base 'Przypis PL', overlay ['en' => ['accommodation' => ['companion' => ['note' => 'Note EN']]]]
// → ['companion_note'] === 'Note EN'

public function test_empty_companion_note_translation_falls_back_to_base(): void;
// overlay ... ['note' => ''] → 'Przypis PL'

public function test_orphaned_companion_note_translation_stays_inert(): void;
// base companion_note '' (albo brak klucza), overlay ['note' => 'Note EN'] → '' (NIE 'Note EN')
```

Trzeci test to punkt 1 z Review Focus — jedyny, który wyłapie wskrzeszenie usuniętego przypisu.

- [ ] **Step 3: test sanityzacji w REST**

W `EventConfigControllerTest`, PUT configu z `accommodation.companion_note`:

```php
public function test_companion_note_keeps_a_link_and_drops_a_script(): void;
// '<a href="https://example.com">x</a><script>alert(1)</script>'
// → zapisane meta zawiera <a href=, nie zawiera <script
```

- [ ] **Step 4: uruchom testy — muszą padać z właściwego powodu**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
```

Oczekiwane: `Call to undefined method ...::companionNote()`. **Konsola PHPUnit w tym kontenerze
zniekształca komunikat niezłapanego `Error`** — fazę RED potwierdź jawnym try/catch drukującym
`get_class($e)` i `getMessage()`, nie podsumowaniem PHPUnit.

- [ ] **Step 5: implementacja**

- `AccommodationConfig`: siódmy parametr konstruktora `private readonly string $companionNote = ''`,
  w `fromArray` przekazany jako `(string) ( $config['companion_note'] ?? '' )`, akcesor
  `companionNote(): string`. Wzór: `companionCountsEvent`.
- `NightsConfig`: to samo (szósty parametr, bez wartości domyślnej — ten konstruktor jej nie używa).
- `ContentTranslator::translateAccommodation`: nałóż `$over['companion']['note']` na
  `$accommodation['companion_note']` **tylko gdy baza jest niepusta** — inaczej usunięty przypis
  wracałby w obcym języku. Użyj istniejącego `pick()`.
- `EventConfigController::RICH_TEXT_KEYS`: dopisz `'companion_note'`. Komentarz nad stałą mówi
  dziś „opisy (pola i opcji)" — rozszerz go o przypis.

- [ ] **Step 6: testy przechodzą + pełne bramki**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- composer analyse
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
```

- [ ] **Step 7: commit**

## Task 2: render przypisu pod checkboxem

**Files:**
- Modify: `src/Frontend/FormRenderer.php:206-241` (`renderCompanion`)
- Modify: `assets/public/form.css` (jeśli przypis potrzebuje odstępu — reszta klas z Bootstrapa)
- Test: `tests/Integration/Frontend/CompanionNoteRenderTest.php` (nowy; wzór instalacji eventu
  i renderu wziąć z `CompanionSubmissionTest` i `NightFormTest`)

**Interfaces:**
- Consumes: `AccommodationConfig::companionNote(): string`, `NightsConfig::companionNote(): string` (Task 1).

- [ ] **Step 1: testy renderu**

```php
public function test_note_renders_between_checkbox_and_name_label(): void;
// tryb classic; HTML zawiera <p class="evreg-companion-note ...
// i pozycja tego <p> jest PO 'data-evreg-companion' i PRZED 'data-evreg-companion-label'
public function test_note_renders_in_nights_mode(): void;            // mode=nights, przypis obecny
public function test_empty_note_renders_no_paragraph(): void;        // brak 'evreg-companion-note'
public function test_whitespace_only_note_renders_no_paragraph(): void;  // companion_note => '   '
public function test_note_absent_when_companion_disabled(): void;    // companion_enabled => false
public function test_note_keeps_allowed_markup(): void;              // <a href> i <strong> w wyjściu
```

Pozycję sprawdzaj przez `strpos` trzech znaczników, nie przez dopasowanie całego HTML —
asercja na cały blok pękałaby przy każdej zmianie klas Bootstrapa.

- [ ] **Step 2: uruchom — padają na braku `evreg-companion-note`**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter CompanionNote
```

- [ ] **Step 3: implementacja w `renderCompanion`**

Po `</div>` zamykającym `<div class="evreg-companion form-check">`, przed etykietą imienia,
wstaw `<p class="evreg-companion-note form-text">` z treścią przez `wp_kses_post()`.
Przypis czytaj z tego samego obiektu, z którego czytasz już `$enabled` — gałąź `nights`
z `$this->nights`, klasyczna z `AccommodationConfig::fromArray( $field->config )`; **nie dodawaj
drugiego rozgałęzienia po trybie i nie czytaj surowego `$field->config['companion_note']`**.
Pusty po `trim()` → nie emituj niczego. Przypis renderuje się **niezależnie od stanu checkboxa**
(bez `hidden`, bez `data-evreg-*`): to zaproszenie do zaznaczenia, nie opis zaznaczonego stanu.

- [ ] **Step 4: testy przechodzą + pełne bramki (jak w Tasku 1, Step 6)**

- [ ] **Step 5: commit**

## Task 3: redakcja przypisu w adminie i wiersz w macierzy tłumaczeń

**Files:**
- Modify: `assets/admin/ops/accommodationOps.js:103` (obok `setCompanionEnabled`)
- Modify: `assets/admin/ops/i18nOps.js:17-66` (`translatableItems`)
- Modify: `assets/admin/tabs/AccommodationTab.jsx:282-291` (blok `acc.companion_enabled && (...)`)
- Test: `assets/admin/ops/accommodationOps.test.js`, `assets/admin/ops/i18nOps.test.js`

**Interfaces:**
- Produces: `setCompanionNote( acc, value )` → nowy obiekt z `companion_note`;
  wiersz `{ kind: 'accommodation', accKind: 'companion', key: 'note', base: <treść> }`
  w `translatableItems`.
- Consumes: istniejące `getAccommodationTranslation( overlay, lang, accKind, key )`
  i `setAccommodationTranslation( overlay, lang, accKind, key, value )` — **bez zmian**;
  `TranslationsTab` renderuje wiersze `kind === 'accommodation'` generycznie po `accKind`/`key`,
  więc ten plik też zostaje bez zmian.

- [ ] **Step 1: testy jest**

```js
// accommodationOps.test.js
it( 'sets companion_note without mutating the input' )       // setCompanionNote( acc, 'Przypis.' )
it( 'coerces a missing note to a string' )                   // setCompanionNote( acc, undefined ) → ''

// i18nOps.test.js — translatableItems
it( 'offers the companion note for translation when it is set' )
// accommodation { companion_enabled: true, companion_note: 'Przypis.' }
// → zawiera { kind: 'accommodation', accKind: 'companion', key: 'note', base: 'Przypis.' }
it( 'offers the companion note in nights mode too' )          // mode: 'nights'
it( 'omits the row when the note is empty' )
it( 'omits the row when the companion option is off' )        // companion_enabled: false
```

Dwa ostatnie to punkty 1 i 2 z Review Focus po stronie admina: wiersz bez treści byłby
zaproszeniem do wpisania tłumaczenia, które nigdzie się nie wyrenderuje.

- [ ] **Step 2: `npm run test:js` — padają**

- [ ] **Step 3: implementacja**

- `accommodationOps.js`: `setCompanionNote( acc, value )` wzorem `setCompanionEnabled`
  (`ensure( acc )`, `next.companion_note = String( value ?? '' )`).
- `i18nOps.js`: wiersz przypisu wypychaj **poza rozgałęzieniem po trybie** (towarzysząca istnieje
  w obu), gated na `acc.companion_enabled && acc.companion_note`.
- `AccommodationTab.jsx`: `TextareaControl` w istniejącym bloku `acc.companion_enabled && (...)`,
  `value={ acc.companion_note || '' }`, `onChange` → `setAcc( setCompanionNote( acc, value ) )`.
  Etykieta i podpowiedź po polsku przez `__()`; dobierz brzmienie sam, wzór w `FieldRow`.

- [ ] **Step 4: `npm run test:js && npm run build` — zielone**

- [ ] **Step 5: commit**

---

## Samoprzegląd

Pokrycie spec: przechowywanie + akcesory + sanityzacja + tłumaczenie → T1; render i jego
położenie → T2; redakcja i wiersz tłumaczeń → T3. Nie-cele spec (opis pola noclegu bez zmian,
brak przypisu w adminie edycji zgłoszenia, brak migracji) nie mają tasków — i nie powinny mieć.

Review Focus: (1) T1 Step 2 trzeci test, (2) T2 Step 1 piąty test + T3 Step 1 ostatni,
(3) T2 Step 1 czwarty test, (4) T2 Step 1 drugi test, (5) T1 Step 3.

Zależności: T2 potrzebuje akcesorów z T1. T3 jest niezależny od T2 (inne pliki, inny język),
ale idzie po nim, żeby review T3 widziało już kształt, który admin redaguje.

Ryzyka: T1 rusza `ContentTranslator`, wspólny dla wszystkich tłumaczeń treści — pełna siatka
integracyjna jest tu siatką bezpieczeństwa. T2 rusza `renderCompanion`, w którym mieszka
pułapka `required`/widoczności; przypis nie ma `required` ani warunku, więc jej nie dotyka,
ale nie wolno przy okazji zmienić kolejności etykiety i inputu imienia.
