# Spec: Overlay tłumaczeń treści per event (B3b / B3 Plan 2)

Data: 2026-09-18. Backlog: B3 (`docs/superpowers/backlog.md`), drugi z trzech planów. Poprzednik: B3a (fundament i18n stringów PHP, scalony). Następnik: B3c (mail/link/formaty locale).

## Cel

Umożliwić tłumaczenie **treści konfigurowanej per event** — etykiet pól, tytułów sekcji, nazw typów zgłoszenia i etykiet opcji pól wyboru — na języki Polylang. Publiczny formularz renderuje treść w języku bieżącej strony (Polylang). Model: „jeden event, wiele języków" przez overlay; wspólna pula zgłoszeń/limit bez zmian.

## Zakres

W zakresie (tłumaczalne):
- Etykiety pól (`field.label`).
- Tytuły sekcji (`section.title`).
- Nazwy typów zgłoszenia (`type.label`).
- Etykiety opcji pól wyboru (`field.options[].label`, po `option.value`).

Poza zakresem (YAGNI / inne plany):
- Szablony maili (`_evreg_mail_templates`) — **B3c**.
- Etykiety noclegów (pakiety/pokoje, `_evreg_accommodation`).
- Placeholdery/opisy pól (pola ich nie mają w obecnym modelu).
- Statyczne stringi wtyczki (przyciski, walidacja) — **B3a, gotowe**.
- Tłumaczenie stringów React-buildera (JS) — B3a poza zakresem, nie tu.
- Wartości pól/typów (`option.value`, `type.key`) — to identyfikatory, NIE tłumaczone. Tłumaczymy tylko labele.

## Model danych

Nowa meta posta eventu **`_evreg_i18n`** (JSON string, jak pozostałe meta configu). Kształt:

```json
{
  "en": {
    "sections": { "<sectionKey>": "Section title" },
    "fields":   { "<fieldKey>":   "Field label" },
    "types":    { "<typeKey>":    "Type label" },
    "options":  { "<fieldKey>": { "<optionValue>": "Option label" } }
  }
}
```

- Klucz najwyższego poziomu = **slug języka Polylang** (np. `en`, `de`), NIE locale. Język domyślny Polylang NIE ma wpisu (baza = `_evreg_schema`/`_evreg_types`).
- Brakujący lub pusty string → **fallback do bazy**. Nigdy nie renderujemy pustej etykiety.
- Klucze identyfikatorów (`sectionKey`, `fieldKey`, `typeKey`, `optionValue`) referują bazę; nie są tłumaczone.

## Resolver domenowy (czysty)

`src/Domain/Schema/ContentTranslator.php` — czysty PHP, zero WP/`$wpdb`.

- `apply( array $schema, array $types, array $overlay, string $lang ): array` → zwraca `[ $schema, $types ]` z podmienionymi:
  - `section.title` ← `overlay[$lang]['sections'][sectionKey]` (jeśli niepuste),
  - `field.label` ← `overlay[$lang]['fields'][fieldKey]`,
  - `field.options[i].label` ← `overlay[$lang]['options'][fieldKey][optionValue]`,
  - `type.label` ← `overlay[$lang]['types'][typeKey]`.
- `'' === $lang` lub brak `overlay[$lang]` → zwraca `[$schema, $types]` bez zmian (baza).
- Nie mutuje wejścia (zwraca nowe tablice).
- Unit-testowany bez WP.

Uwaga: resolver działa na SUROWYCH tablicach configu (przed `SchemaAssembler`), więc `__type` (opcje z typów) i accommodation są nietknięte — typy tłumaczymy osobno przez `type.label`, a `SchemaAssembler` i tak wstrzykuje opcje `__type` z (już przetłumaczonych) typów.

## Wpięcie na froncie

`src/Frontend/EventFormLoader::load()` — po `$config = $this->config->get( $event_id )` i przed `assemble`:

1. Wczytaj overlay: `$overlay = $this->config->getI18n( $event_id )` (nowa metoda repo zwracająca zdekodowaną tablicę lub `[]`).
2. Ustal bieżący język: `$lang = CurrentLanguage::get()`.
3. `[ $schema, $types ] = ( new ContentTranslator() )->apply( $schema, $types, $overlay, $lang );`
4. `assemble( $schema, $types, $accommodation )` jak dziś.

`src/Frontend/CurrentLanguage.php` (adapter WP):
- `get(): string` → jeśli `function_exists( 'pll_current_language' )` zwróć `(string) pll_current_language( 'slug' )`; inaczej `''`.
- Wynik przepuszczony przez filtr: `return (string) apply_filters( 'evreg_current_language', $lang );` — pozwala (a) testować bez Polylang, (b) integrować inne wtyczki wielojęzyczne.
- Pusty string → resolver no-op (baza). Slug języka domyślnego też powinien dać bazę: overlay nie ma wpisu dla domyślnego → fallback do bazy automatycznie (nie trzeba znać domyślnego na froncie).

## REST — zapis/odczyt overlay

Dedykowany `src/Rest/I18nController.php` (wzorem `MailTemplateController`, NIE przez `EventConfigController`):
- Trasa `evreg/v1/events/<id>/i18n`, GET (zwraca overlay) + POST (zapis).
- `permission_callback` = cap `edit_evreg_events` (jak inne kontrolery configu).
- Sanityzacja: overlay to zagnieżdżona mapa stringów; sanitize każdy label (`sanitize_text_field`) przy zapisie; klucze walidowane jako niepuste stringi.
- Repo: `EventConfigRepository::getI18n(int)` / `saveI18n(int, array)` — meta `_evreg_i18n`, zapis owinięty `wp_slash( wp_json_encode( ... ) )` (pułapka `wp_unslash`, jak `MailTemplateRepository`).

## Admin (React)

### Lista języków do buildera

`EventConfigAssets::enqueue()` rozszerza `wp_localize_script( 'evregAdmin', ... )` o:
- `languages`: gdy `function_exists('pll_languages_list')` → `[ { slug, name } ]` dla języków **nie-domyślnych** (`pll_languages_list()` minus `pll_default_language()`, nazwy z `pll_the_languages`/`PLL()`), inaczej `[]`.
- `defaultLanguage`: `pll_default_language('slug')` lub `''`.

### Zakładka `TranslationsTab`

- Szósta zakładka (`App.jsx` `tabs` + `TabRouter`).
- Buduje listę tłumaczalnych pozycji z bieżącego `config.schema` + `config.types` przez czysty `translatableItems(schema, types)` → lista `{ kind: 'section'|'field'|'type'|'option', key, base, fieldKey?, optionValue? }`. Kolejność czytelna (sekcja → jej pola → opcje pól; potem typy).
- Render: dla każdej pozycji baza (read-only) + input tłumaczenia per język nie-domyślny; placeholder = baza. Wartość z overlay, `onChange` → ops.
- Gdy `languages` puste: notka i18n „Włącz Polylang i dodaj języki, aby tłumaczyć treść." (bez inputów).

### Ops (czyste, jest)

`assets/admin/ops/i18nOps.js`:
- `translatableItems( schema, types ) -> item[]` (enumeracja; pomija `__type` jako pole-label? — `__type` ma label „Typ zgłoszenia" ustawiany w schemacie; JEST tłumaczalny jako field-label; jego OPCJE pochodzą z typów, więc opcje `__type` NIE są enumerowane jako options — tłumaczy je `type.label`).
- `setTranslation( overlay, lang, kind, key, value ) -> overlay` dla `section|field|type`.
- `setOptionTranslation( overlay, lang, fieldKey, optionValue, value ) -> overlay`.
- `getTranslation( overlay, lang, kind, key )` / `getOptionTranslation(...)` — odczyt do wartości inputu.
- Immutable; puste `value` może czyścić wpis (albo zostać pustym stringiem → fallback). MVP: pusty string zapisany = fallback (resolver traktuje puste jak brak).

### App — load/save

- Load: obok `loadConfig`/`loadTemplates` dołóż `loadI18n( eventId )` → `config.i18n`.
- Save: „Zapisz" w `App.jsx` dorzuca `saveI18n( eventId, config.i18n )` do `Promise.allSettled` (jak szablony — nie zapisuj i18n przed udanym initial-load, by nie nadpisać pustym).
- `api.js`: `loadI18n`/`saveI18n` na `evreg/v1/events/<id>/i18n`.

## Testy

- **Unit (PHP)** `ContentTranslator`: override etykiet pól/sekcji/typów/opcji dla języka; fallback do bazy przy braku/pustym; `''`/nieznany język = baza; brak mutacji wejścia.
- **Integration (PHP)** `EventFormLoader`: z ustawionym `add_filter('evreg_current_language', fn()=>'en')` i overlay w meta → `load()` zwraca `FormSchema` z przetłumaczonymi labelami; bez filtra (baza) → polskie. `I18nController` GET/POST round-trip (zapis→odczyt, sanitize). `CurrentLanguage` respektuje filtr.
- **jest** `i18nOps`: `translatableItems` (poprawna enumeracja z przykładowej schemy+typów, w tym opcje i `__type` jako field), `setTranslation`/`setOptionTranslation`/`getTranslation` (immutable, kształt overlay).
- **e2e**: pominięte — Polylang nieobecny w wp-env; ścieżkę front pokrywa test integracyjny przez filtr `evreg_current_language`.

## Inwarianty / uwagi

- `src/Domain/**` czysty (ContentTranslator bez WP). Logika mutacji buildera w `ops/*` (jest). Komponenty cienkie.
- Wszystkie nowe stringi UI przez i18n `event-registration` (PL źródło).
- Serwer arbitrem: overlay to tylko prezentacja; walidacja/ekstrakcja submisji działa po KLUCZACH pól (niezmienne), więc tłumaczenie labeli nie wpływa na zapisywane dane ani walidację. Namespacing `evreg_field[<key>]` bez zmian.
- Escaping: `FormRenderer` już escape'uje `field.label`/`section.title`/opcje — przetłumaczone stringi też przejdą przez ten sam escaping.
- Bezpieczeństwo danych: tłumaczenie NIE zmienia `value`/`key` — zgłoszenia i liczniki spójne między językami (wspólna pula).

## Kolejność implementacji (dla planu)

1. `ContentTranslator` (domena) + unit testy (RED→GREEN).
2. `EventConfigRepository::getI18n/saveI18n` + `CurrentLanguage` (adapter + filtr) + integracja w `EventFormLoader` + testy integracyjne.
3. `I18nController` (REST GET/POST) + rejestracja + testy round-trip.
4. `i18nOps.js` (ops + `translatableItems`) + jest.
5. `EventConfigAssets` localize `languages`/`defaultLanguage`; `api.js` load/save; `App.jsx` load/save/tab; `TranslationsTab` komponent.
6. Build, pełne testy, wizualna weryfikacja w wp-env (bez Polylang: zakładka pokazuje notkę „włącz Polylang"; z filtrem/symulacją potwierdź render), paczka.
