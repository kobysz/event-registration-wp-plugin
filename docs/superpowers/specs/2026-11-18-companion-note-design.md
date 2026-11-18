# Przypis pod checkboxem „Osoba towarzysząca" — projekt

## Intencja

Zgłaszający się, który przyprowadza osobę towarzyszącą, nie wie, czy ma dla niej
wypełnić drugi formularz. Organizator potrzebuje miejsca na jedno zdanie, które to
rozstrzyga, **pod samym checkboxem** — tam, gdzie pytanie powstaje.

Dziś takiego miejsca nie ma. Checkbox osoby towarzyszącej nie jest polem schematu:
rysuje go `FormRenderer::renderCompanion` z konfiguracji noclegu (`companion_enabled`),
więc builder nie ma dla niego wiersza. Jedyny dostępny opis należy do POLA noclegu
i renderuje się po kontrolce (`FormRenderer.php:184`), czyli pod całą listą dób albo
pokojów — kilkanaście centymetrów od checkboxa, do którego się odnosi.

## Cele

- Treść redagowana **per wydarzenie**, nie zaszyta w kodzie.
- **Pusta = nie renderuje się nic.** Wydarzenie bez potrzeby takiego zdania nie dostaje
  ani pustego akapitu, ani wiersza w macierzy tłumaczeń.
- Tłumaczalna per język tą samą drogą co reszta treści wydarzenia (Polylang, B3b/B6).
- Działa w OBU trybach noclegu (`classic` i `nights`).

## Nie-cele

- Nie zmieniamy miejsca renderu opisu pola noclegu — zostaje pod datami/pokojami,
  bo to sensowne miejsce na przypis o samym noclegu.
- Bez przypisu w adminie (`RegistrationEditForm::renderCompanionRow`) — to wskazówka
  dla zgłaszającego się, nie dla organizatora, który zna swoją politykę.
- Bez przypisów per doba/pakiet. Jeden przypis na wydarzenie.
- Bez migracji bazy. Klucz wchodzi do istniejącego `_evreg_accommodation`.

## Anty-cele

- Przypis, który renderuje pusty `<p>` i przesuwa layout, gdy organizator nic nie wpisał.
- Tłumaczenie, które ginie po cichu przy zapisie innego bucketu — pułapka `langBucket`
  z CLAUDE.md. Rozwiązanie poniżej jej nie dotyka, bo nie dodaje bucketu.
- Zdanie zaszyte w kodzie, mówiące politykę w imieniu każdej instalacji wtyczki.

## Ograniczenia

- **W trybie `nights` nic nie konstruuje `AccommodationConfig`.** Akcesor musi istnieć na
  obu obiektach konfiguracji, bo `renderCompanion` rozgałęzia się po trybie.
- `src/Domain/**`: zero WordPressa, zero `$wpdb`, zero stringów dla użytkownika.
- Wszystkie stringi UI przez i18n, text domain `event-registration`; w JS `__()`.
- Logika mutacji konfiguracji w czystych `assets/admin/ops/*`, bez mutowania wejścia.
- PHPStan 6 bez obniżania, `phpcs` czysto.

## Rozważane drogi

1. **Klucz `companion_note` w `_evreg_accommodation`** — wybrana. Tekst leży obok flagi,
   która go włącza: razem konfigurowane, razem tłumaczone, razem znikają przy wyłączeniu
   towarzyszącej. Wszystkie potrzebne szwy już istnieją.
2. **Klucz w `_evreg_settings` + nowy bucket overlayu** — odrzucona. `SettingsTab` to ekran
   maili, strony i powiadomień, a to treść formularza. Nowy bucket w `langBucket` to
   dokładnie miejsce, w którym pominięcie na whiteliście kasuje tłumaczenia przy zapisie
   innego bucketu. Zero zysku względem 1.
3. **Przejąć opis pola noclegu i zmienić miejsce renderu** — odrzucona. Zero nowej
   konfiguracji i zero nowego i18n, ale zabiera jedyne miejsce na przypis pod datami,
   a opis renderowany raz nad, raz pod kontrolką byłby niespodzianką dla autora.

## Rozstrzygnięcia

**Przechowywanie.** Skalarny `companion_note` w `_evreg_accommodation`. Default `''`.

**Dostęp z domeny.** `companionNote(): string` na `AccommodationConfig` ORAZ `NightsConfig`
(wzór: istniejące `companionEnabled()`/`companionCountsEvent()` na obu). Renderer czyta
przez obiekt, nie z surowego `$field->config` — ta sama zasada, którą CLAUDE.md zapisał
dla `occupancy`.

**Sanityzacja.** `companion_note` dochodzi do `EventConfigController::RICH_TEXT_KEYS`
(dziś: `description`). Przez `wp_kses_post` link i pogrubienie zostają, skrypty i atrybuty
zdarzeń odpadają. `sanitize_text_field` wycinałby tagi i zjadał znaki nowej linii.

**Render.** `renderCompanion` emituje `<p class="evreg-companion-note form-text">` zaraz po
`<div class="evreg-companion form-check">`, czyli **pod checkboxem i nad etykietą imienia**.
Pusty `companion_note` → pusty string, żadnego znacznika. **Widoczny zawsze**, niezależnie
od stanu checkboxa: to zaproszenie do jego zaznaczenia, nie opis zaznaczonego stanu.

**Tłumaczenie — reuse istniejącego szwu.** Overlay trzyma treść jako
`accommodation.companion.note`, czyli w kształcie `accommodation[accKind][key]`, który
`getAccommodationTranslation`/`setAccommodationTranslation` już obsługują, a `TranslationsTab`
renderuje generycznie po `item.accKind`/`item.key`. Dzięki temu:
- `langBucket` bez zmian (`accommodation` już na whiteliście),
- `TranslationsTab` bez zmian,
- `i18nOps` dostaje tylko jeden wiersz w `translatableItems` — **wypychany wyłącznie gdy
  `companion_note` jest niepusty**,
- `ContentTranslator::translateAccommodation` nakłada `$over['companion']['note']` na
  `companion_note` przez istniejące `pick()`, więc puste tłumaczenie spada na bazę.

**UI buildera.** `TextareaControl` w `AccommodationTab`, w istniejącym bloku
`acc.companion_enabled && (...)`, plus czysty `setCompanionNote` w `accommodationOps.js`
(zwraca nowy obiekt, nie mutuje wejścia).

**Treść domyślna.** Pusta. Zdanie organizatora wpisuje się raz per wydarzenie w ustawieniach
noclegu — nie jest częścią wtyczki.

## Testy, które to przypinają

- Unit: akcesor na obu obiektach konfiguracji + default `''` z `fromArray`.
- Unit: `ContentTranslator` nakłada przypis i spada na bazę przy pustym tłumaczeniu.
- Jest: `accommodationOps.setCompanionNote` (nie mutuje wejścia); `i18nOps.translatableItems`
  — wiersz JEST przy niepustym przypisie, NIE MA przy pustym.
- Integracja `FormRenderer`, oba tryby: przypis renderuje się pod checkboxem i nad etykietą
  imienia; nie renderuje się przy pustym; nie renderuje się przy wyłączonej towarzyszącej;
  dozwolony link przechodzi, `<script>` nie.
- Integracja REST: zapis przypisu z linkiem zachowuje link, ze skryptem go zdejmuje.

## Zostawione wykonawcy

- Dokładne brzmienie etykiety i podpowiedzi przy `TextareaControl` w `AccommodationTab`.
- Liczba wierszy `TextareaControl`.
- Czy wiersz w macierzy tłumaczeń pokazuje całe zdanie w kolumnie „Oryginał" (tak robią
  dziś opisy pól), czy skrót.
