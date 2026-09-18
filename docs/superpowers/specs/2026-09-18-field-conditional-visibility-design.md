# Spec: Zależności pól i sekcji (warunkowa widoczność) — B1

Data: 2026-09-18. Backlog: B1 (`docs/superpowers/backlog.md`).

## Cel

Umożliwić w builderze formularza ustawienie warunku widoczności na **polu**
oraz na **sekcji**: element pokazuje się/ukrywa zależnie od wartości innego
pola. Np. „pokaż pole *nr PWZ* gdy *Typ zgłoszenia* = *prelegent/ekspert*".

## Stan obecny (co już jest)

Silnik domeny jest kompletny i generyczny — brakuje tylko UI, renderu pól i
generycznego klienta:

- `src/Domain/Conditions/` — `Condition{field, operator, value}`, `Operator`
  (`equals`/`not_equals`/`in`/`not_in`/`empty`/`not_empty`), `ConditionEngine::isMet`.
  Semantyka listowa: `equals`/`not_equals` porównują `toList(answer) === toList(expected)`;
  `in`/`not_in` = `array_intersect` list; `empty`/`not_empty` = pusta lista.
  `empty`/`not_empty` nie mają wartości (`Operator::needsValue()` = false).
- `Condition` na SEKCJI i na POLU (`Section->condition`, `Field->condition`),
  serializacja `toArray`/`fromArray`.
- `VisibilityResolver::resolve(schema, answers)` → `VisibilitySet{sections, fields}`;
  używany serwerowo w walidacji (`SubmissionAssembler` → ukryte pola pomijane,
  wymagane ukryte nie blokują) i w mailach (`PlaceholderFactory`). **Serwer jest
  arbitrem** — ukryte pola nie są walidowane ani zapisywane.
- `FormRenderer::renderSection` emituje `data-evreg-when-field/operator/value`
  na `<fieldset>`. `renderField` NIE emituje nic.
- `assets/public/form.js` obsługuje TYLKO warunki sekcji i TYLKO gdy trigger =
  `__type` (hardcode `!== '__type' → return`, nasłuch wyłącznie `input[name="__type"]`).
- `assets/admin/ops/schemaOps.js` przy `addSection`/`ensureTypeField` ustawia
  `condition: null`; brak jakiegokolwiek UI/ops do edycji warunku.

## Zakres

W zakresie:
- Edytor warunku per POLE i per SEKCJA w React builderze.
- Render atrybutów warunku na wrapperze pola (parytet z sekcją).
- Generyczny silnik klienta w `form.js` — dowolny trigger, sekcje i pola,
  wszystkie 6 operatorów, lustro semantyki `ConditionEngine`.

Poza zakresem (YAGNI):
- Wiele warunków na jednym elemencie / AND-OR (silnik = pojedynczy `Condition`).
- Pole `accommodation` jako trigger (złożona wartość pakiet|pokój).
- Warunki zagnieżdżone (warunek na triggerze, który sam jest warunkowy) —
  działa mechanicznie (serwer i klient liczą z bieżących odpowiedzi), ale nie
  projektujemy pod to UX.

## Model danych

Bez zmian w domenie. `condition` w schemacie (meta `_evreg_schema`) na sekcji i
na polu:

```json
{ "field": "<klucz-triggera>", "operator": "equals", "value": "prelegent" }
{ "field": "<klucz-triggera>", "operator": "in", "value": ["a", "b"] }
{ "field": "<klucz-triggera>", "operator": "not_empty" }
```

- `value`: string dla `equals`/`not_equals`, tablica stringów dla `in`/`not_in`,
  pominięte dla `empty`/`not_empty` (`Condition::toArray` już to robi).
- `null`/brak `condition` = zawsze widoczne.

## UI buildera

### Reguła operatorów wg typu triggera

- Trigger = pole **wyboru** (`radio`/`select`/`checkbox-group`): dostępne
  `equals`/`not_equals` (wartość = jedna opcja triggera) oraz `in`/`not_in`
  (wartość = wiele opcji triggera).
- Trigger = **dowolne** pole (w tym wyboru): dostępne `empty`/`not_empty`
  (bez wartości).
- Innymi słowy: dla pola nie-wyboru w UI zostają tylko `empty`/`not_empty`.

`__type` jest polem wyboru (radio) — jest poprawnym, typowym triggerem; jego
opcje pochodzą z zakładki Typy, więc lista wartości triggera musi je uwzględnić
(patrz „Źródło opcji triggera").

### Komponenty

- `FieldRow`: sekcja „Widoczność warunkowa" (rozwijana). Toggle „Pokaż
  warunkowo" → gdy on, pokazuje: `SelectControl` trigger (inne pola, po kluczu +
  etykiecie), `SelectControl` operator (lista wg typu triggera), input wartości
  adaptujący się do operatora:
  - `equals`/`not_equals`: `SelectControl` z opcjami triggera.
  - `in`/`not_in`: lista wielokrotnego wyboru opcji triggera (checkboxy albo
    multi-select; decyzja implementacyjna — MVP: grupa checkboxów opcji).
  - `empty`/`not_empty`: brak inputu wartości.
  Wyłączenie toggla → `clearCondition` (usuwa `condition`).
- `SectionEditor`: identyczny edytor w nagłówku/ciele sekcji.

### Trigger — dozwolone pola

- Lista triggerów = pozostałe pola schematu (po kluczu), z wykluczeniem:
  - samego siebie (pole/sekcja nie może warunkować się swoją wartością);
  - pól bez sensownej wartości triggera: `heading`, `paragraph`, `accommodation`.
- `__type` dozwolony.
- Uwaga cykli: nie projektujemy ochrony przed cyklem A↔B (rzadkie; skutkuje
  tylko dziwną widocznością, nie błędem). Ewentualnie prosty guard „trigger !=
  self" wystarcza dla pojedynczego elementu.

### Źródło opcji triggera (w kliencie buildera)

Opcje pola wyboru czytamy z `field.options` w schemacie. Wyjątek: `__type` ma
`options: []` w `_evreg_schema` (opcje wstrzykuje serwerowo `SchemaAssembler` z
zakładki Typy). W builderze React `config.types` jest dostępne — dla triggera
`__type` budujemy listę wartości z `config.types` (klucz→etykieta), nie z
`field.options`. To jedyny przypadek specjalny.

## Ops (czyste, jest-testowane)

W `assets/admin/ops/schemaOps.js`:

- `setFieldCondition(schema, fieldKey, condition)` — ustawia `field.condition`.
- `clearFieldCondition(schema, fieldKey)` — usuwa (`condition: null`/pominięte).
- `setSectionCondition(schema, sectionKey, condition)` — ustawia `section.condition`.
- `clearSectionCondition(schema, sectionKey)` — usuwa.

Immutable (nie mutują wejścia). `condition` w kształcie serializowanym
(`{field, operator, value?}`), zgodnym z `Condition::fromArray`.

Ewentualny helper walidacyjny UI (opcjonalny, nie blokuje zapisu — serwer
arbitrem): builder może pokazać ostrzeżenie gdy warunek niekompletny, ale zapis
i tak przechodzi (schema z niekompletnym warunkiem → `Condition::fromArray`
rzuci `SchemaException` na walidacji serwera → `ValidationReport`). MVP: bez
walidacji klienta; polegamy na `ValidationReport`.

## Render (`FormRenderer::renderField`)

Wrapper pola (`<div class="evreg-field ...">`) dostaje `data-evreg-when-*` gdy
`field->condition !== null`, dokładnie jak `renderSection` na `<fieldset>`:

```
data-evreg-when-field="<klucz>"
data-evreg-when-operator="<op>"
data-evreg-when-value="<wp_json_encode(value)>"   // pominięte dla empty/not_empty
```

Refaktor: wspólny helper `conditionAttrs(?Condition): string` używany przez
`renderSection` i `renderField` (DRY, jedno miejsce serializacji atrybutów).

Ukryte pole i tak trafia w HTML (klient je chowa); serwer i tak pomija je w
walidacji przez `VisibilityResolver` — spójne.

## Klient (`assets/public/form.js`) — generyczny silnik

Zastąpić hardcode `__type`:

- `readTrigger(form, fieldKey)` → `string[]`: czyta bieżącą wartość pola po
  `name="evreg_field[<key>]"` (radio/select: wartość zaznaczona → `[v]` lub `[]`;
  checkbox-group: wszystkie zaznaczone → lista; single checkbox: `['1']` gdy
  checked, `[]` gdy nie; text/inne: `[value]` lub `[]` gdy puste). Lustro
  `ConditionEngine::toList` na odpowiedzi.
- `isMet(operator, answerList, expectedList)` — dokładnie jak PHP:
  - `equals`: `answerList` równa `expectedList` (te same elementy, ta sama
    kolejność — jak `===` na listach po `toList`).
  - `not_equals`: negacja.
  - `in`: przecięcie niepuste.
  - `not_in`: przecięcie puste.
  - `empty`: `answerList` pusta.
  - `not_empty`: niepusta.
  - `expectedList` = `toList(JSON.parse(data-evreg-when-value))`.
- `apply(form)`: dla każdego `[data-evreg-when-field]` (sekcje I pola) policz
  `isMet(op, readTrigger(field), expected)` → `el.hidden = !met`.
- Nasłuch: delegacja `change` (i `input` dla pól tekstowych) na całym
  `.evreg-form` — każda zmiana przelicza `apply(form)`. Bez wiązania do `__type`.
- Zachować istniejące wsparcie noclegu (`applyRoommate`) — osobny handler, bez
  kolizji.

Parytet operatorów: JS `isMet` i PHP `ConditionEngine::isMet` mają być
identyczne semantycznie; ten sam zestaw przypadków testowych po obu stronach
(patrz Testy).

## Testy

- **jest** (`assets/admin/ops/schemaOps.test.js`): `setFieldCondition`/
  `clearFieldCondition`/`setSectionCondition`/`clearSectionCondition` —
  ustawienie, usunięcie, brak mutacji wejścia, kształt zgodny z serializacją.
- **PHPUnit `FormRendererTest`**: `renderField` emituje `data-evreg-when-*` dla
  pola z warunkiem (parytet z istniejącym testem sekcji); brak atrybutów bez
  warunku; `empty`/`not_empty` bez `data-evreg-when-value`.
- **PHPUnit** (jeśli brak): `ConditionEngine` — dopiąć przypadki brzegowe
  operatorów jeśli nie pokryte (equals-lista, in-przecięcie, empty-array).
- **e2e Playwright** (nowy spec): na stronie z formularzem z warunkiem
  (`equals` na `__type` + jedno pole; `not_empty` na tekstie) — zmiana triggera
  pokazuje/ukrywa pole; submit ukrytego wymaganego pola nie blokuje (parytet
  klient↔serwer w praktyce). Setup przez `wp eval-file` jak `setup-public-form.php`.

Uwaga: `form.js` jest serwowany surowo (bez webpacka, bez jest). Dlatego silnik
klienta testujemy e2e (realna przeglądarka), a nie jednostkowo jest. PHP silnik
ma testy jednostkowe. Alternatywa (odrzucona w MVP): przenieść eval do modułu
budowanego webpackiem + jest — zbędna infra na ten zakres.

## Inwarianty do utrzymania

- `src/Domain/**` bez WP/`$wpdb` — nie dotykamy (silnik gotowy).
- Logika mutacji buildera w `ops/*` testowana jest; komponenty cienkie.
- Wszystkie stringi UI przez i18n (`event-registration`).
- Serwer arbitrem walidacji; klient tylko lustrem widoczności.
- Namespacing `evreg_field[...]` — `readTrigger` czyta po tym namespace.

## Kolejność implementacji (dla planu)

1. Ops warunków + testy jest (RED→GREEN).
2. `conditionAttrs` helper + `renderField` atrybuty + testy PHPUnit.
3. Generyczny `form.js` (readTrigger/isMet/apply/delegacja) — zamiana hardcode.
4. UI: `FieldRow` + `SectionEditor` edytor warunku (trigger/operator/value,
   `__type` z `config.types`).
5. e2e Playwright.
6. Build, pełne testy, wizualna weryfikacja wp-env, paczka.
