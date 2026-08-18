# Event Config Admin (Plan 2) — projekt techniczny

Data: 2026-08-18
Status: zatwierdzony do planowania implementacji
Buduje na: [Plan 1 — fundament i domena](2026-08-18-event-registration-design.md), warstwa `src/Domain/` gotowa

## 1. Cel i kontekst

Druga warstwa wtyczki Event Registration: interfejs administratora do konfiguracji eventu.
Organizator definiuje formularz (sekcje i pola), typy zgłoszenia (cena, limit), noclegi
(pakiety × pokoje × inwentarz) oraz ustawienia eventu (daty, limity, okno zapisów, maile
organizatora). Konfiguracja jest zapisywana jako meta CPT i konsumowana przez Plan 3
(formularz publiczny + rezerwacja).

Warstwa domeny z Planu 1 (`FormSchema`, `SchemaIntegrityChecker`, `RegistrationTypeCollection`,
`AccommodationConfig`) jest gotowa i pozostaje źródłem prawdy dla walidacji. Plan 2 dokłada
jeden czysty obiekt domenowy (`SchemaAssembler`) oraz warstwę adapterów WordPressa (CPT, REST,
persystencja, aplikacja React).

## 2. Zakres

### v1 (Plan 2)
- CPT `evreg_event` (wiele eventów na stronie, historia, natywna lista WP)
- Wyłączenie edytora blokowego dla tego CPT; aplikacja React montowana pod tytułem
- Aplikacja React z czterema zakładkami: Formularz, Typy zgłoszenia, Noclegi, Ustawienia
- `SchemaAssembler` — składanie kompletnej `FormSchema` z rozdzielonych meta
- Endpoint REST `GET`/`PUT` całej konfiguracji z walidacją serwerową i raportem
- Capability `edit_evreg_events`
- Zapis nieblokujący z raportem walidacji (polityka B)

### Poza Planem 2
- Renderowanie i wysyłka formularza publicznego, rezerwacja miejsc → Plan 3
- Szablony maili → Plan 4 (tam jest kolejka i wysyłka)
- Gating publikacji na podstawie poprawności → Plan 3 (Plan 2 tylko zwraca raport)
- Podgląd formularza w adminie → Plan 3

### Świadomie pominięte (YAGNI)
- Edycja opcji `__type` inline w edytorze schemy (opcje pochodzą z zakładki Typy)
- Drag & drop w edytorze pól (kolejność strzałkami; schema JSON identyczna, podmiana UI później bez migracji)
- Granularne testy komponentów React (narzędzie wewnętrzne; pokrycie przez PHPUnit + jedną ścieżkę E2E)
- Warunki widoczności oparte o dowolne pole (UI ograniczone do `__type`; silnik generyczny już w Planie 1)

## 3. Decyzje projektowe

| # | Decyzja | Wybór | Uzasadnienie |
|---|---------|-------|--------------|
| 1 | Zakres | Pełna konfiguracja eventu | Plan 3 potrzebuje kompletnego eventu (schema, typy, limity, noclegi) do działania |
| 2 | Technologia UI | Jeden spójny React admin | Dwie powierzchnie mocno zagnieżdżone; vanilla JS = dług od pierwszego dnia; dwa paradygmaty = niespójność |
| 3 | Kontener | CPT + klasyczny ekran edycji, zakładki w Reakcie | Darmowa lista/historia/akcje WP; jeden przycisk zapisu, atomowo |
| 4 | Źródło prawdy | Model 2 — osobne meta + `SchemaAssembler` przy odczycie | Zero duplikacji i błędów synchronizacji; assembler i tak wymagany przez Plan 3 |
| 5 | Polityka zapisu | B — zapis zawsze, waliduj i ostrzegaj | Edytor konfiguracji nie może gubić edycji przez przejściową niepoprawność; walidacja to informacja, nie brama |

## 4. Architektura

```
src/
├─ Domain/Schema/SchemaAssembler.php       CZYSTA domena: składanie kompletnej FormSchema
├─ Admin/
│  ├─ EventPostType.php                     rejestracja CPT, wyłączenie Gutenberga, mount aplikacji
│  ├─ EventConfigAssets.php                 enqueue build/ tylko na ekranie edycji evreg_event
│  └─ Capabilities.php                      cap edit_evreg_events, mapowanie, nadanie roli administrator
├─ Persistence/EventConfigRepository.php    odczyt/zapis czterech meta jako JSON (cały blob)
└─ Rest/EventConfigController.php           GET/PUT /evreg/v1/events/{id}/config, auth, walidacja, raport

assets/admin/                               źródła @wordpress/scripts → build/
├─ index.js                                 mount, ładowanie configu, routing zakładek, przycisk Zapisz
├─ api.js                                   @wordpress/api-fetch, GET/PUT config
├─ tabs/FormTab.jsx                         edytor schemy: sekcje → pola
├─ tabs/TypesTab.jsx                        repeater typów zgłoszenia
├─ tabs/AccommodationTab.jsx               pakiety, pokoje, siatka inwentarza
├─ tabs/SettingsTab.jsx                     daty, limity, waitlist, okno, maile organizatora
└─ validation/report.js                     render raportu walidacji (kody → komunikaty PL)
```

Zasada nadrzędna z Planu 1 obowiązuje: `SchemaAssembler` w `src/Domain/` nie dotyka WordPressa ani
`$wpdb` — bierze surowe tablice i zwraca `FormSchema`. Odczyt meta i wywołanie assemblera to zadanie
warstwy adapterów (`EventConfigRepository`, `EventConfigController`). Test czystości domeny z Planu 1
(`DomainPurityTest`) obejmuje nowy plik automatycznie.

## 5. Model danych

Konfiguracja eventu żyje w czterech meta CPT `evreg_event`, każde jako JSON. Wszystkie
`show_in_rest = false` — wystawiane wyłącznie przez dedykowany endpoint, nie surowo.

| Meta | Zawartość | Obiekt Planu 1 |
|------|-----------|----------------|
| `_evreg_schema` | sekcje → pola; `__type` bez opcji, `accommodation` bez configu | `FormSchema` (po złożeniu) |
| `_evreg_types` | `[{key,label,price,capacity,active}]` | `RegistrationTypeCollection` |
| `_evreg_accommodation` | `{packages,rooms,inventory,allow_none}` | `AccommodationConfig` |
| `_evreg_settings` | ustawienia eventu (poniżej) | — |

### `_evreg_settings`

```json
{
  "start_date": "2026-09-10",
  "end_date": "2026-09-12",
  "global_cap": 200,
  "waitlist_enabled": true,
  "registration_opens": "2026-07-01",
  "registration_closes": "2026-09-01",
  "organizer_emails": ["biuro@example.com"]
}
```

`global_cap` `null` = brak limitu. Daty w formacie `Y-m-d`. `organizer_emails` — lista adresów
powiadamianych o nowym zgłoszeniu (konsumuje Plan 4).

### Zależność Model 2

`_evreg_schema` nie jest samodzielnie poprawną `FormSchema`: pole `__type` jest zapisane bez opcji,
a pola `accommodation` bez configu. Kompletną, poprawną schemę produkuje `SchemaAssembler` (§6) przy
walidacji (Plan 2) i przy renderowaniu (Plan 3). To jest zamierzone — jedno źródło prawdy dla opcji
typów i inwentarza noclegów, bez duplikacji.

## 6. SchemaAssembler

Czysta jednostka domenowa w `src/Domain/Schema/`. Bierze trzy surowe tablice (nie WordPress, nie
meta — surowe dane), zwraca kompletną `FormSchema`.

```
SchemaAssembler::assemble(
    array $schema,          // surowy _evreg_schema
    array $types,           // surowy _evreg_types
    array $accommodation    // surowy _evreg_accommodation
): FormSchema
```

Kroki:
1. Zbuduj `RegistrationTypeCollection` z `$types`; opcje pola `__type` = aktywne typy (`key` → `label`).
2. Zbuduj `AccommodationConfig` z `$accommodation`; wstrzyknij ją jako `config` każdego pola typu
   `accommodation` w schemie.
3. Zwróć `FormSchema::fromArray()` na tak wzbogaconej tablicy (rzuca `SchemaException` przy
   strukturalnym błędzie).

Walidacja konfiguracji (używana przez REST przy `PUT`):

```
assemble( ... ) → SchemaIntegrityChecker::check( $formSchema )
```

Wynik walidacji to lista kodów błędów (nie komunikaty — tłumaczenie w warstwie prezentacji, zgodnie
z regułą domeny z Planu 1). Assembler i checker nie zapisują niczego — czysta funkcja.

Assembler jest konsumowany identycznie przez Plan 3 przy renderowaniu formularza — budujemy go raz, tu.

## 7. REST API

Namespace `evreg/v1`. Jeden zasób — cała konfiguracja eventu.

### `GET /evreg/v1/events/{id}/config`

Zwraca:
```json
{
  "schema": { ... },
  "types": [ ... ],
  "accommodation": { ... },
  "settings": { ... },
  "validation": { "valid": true, "errors": [] }
}
```

### `PUT /evreg/v1/events/{id}/config`

Body: `{schema, types, accommodation, settings}`. Zapisuje surowe meta **zawsze** (polityka B), po
sanityzacji per pole. Zwraca zaktualizowaną konfigurację wraz z `validation`:

```json
{ "valid": false, "errors": [ { "code": "missing_type_field", "detail": "..." } ] }
```

Raport walidacji powstaje z `SchemaAssembler` + `SchemaIntegrityChecker` na **złożonej** schemie.
Niepoprawność nie blokuje zapisu; jest informacją zwrotną.

### Autoryzacja

- `permission_callback`: `current_user_can( 'edit_post', $id )` oraz capability `edit_evreg_events`
- Nonce `wp_rest` przekazywany przez `@wordpress/api-fetch` (middleware nonce)
- Sanityzacja per pole przy zapisie; wartości pól formularza traktowane jako dane, nie kod

## 8. Aplikacja React

Montowana przez `edit_form_after_title` na ekranie edycji `evreg_event` — pełna szerokość pod
tytułem. Zbudowana `@wordpress/scripts`, komponenty `@wordpress/components`, stan lokalny, zapis przez
`@wordpress/api-fetch`.

### Zakładka Formularz
Edytor schemy: sekcje z tytułem/opisem/warunkiem, w każdej lista pól. Paleta typów: `text`, `email`,
`tel`, `textarea`, `number`, `date`, `select`, `radio`, `checkbox`, `checkbox-group`, `heading`,
`paragraph`, `accommodation`. Kolejność strzałkami.

Pola specjalne:
- `__type` — auto-wstawione, nieusuwalne; pozycjonujesz (sekcja, etykieta, warunek), **nie** edytujesz
  opcji (pochodzą z zakładki Typy).
- `accommodation` — dodawane jak pole; inwentarz **nie** edytowany tu, tylko w zakładce Noclegi.

Warunki widoczności v1: tylko z polem `__type` jako warunkującym (UI wąskie, silnik generyczny z
Planu 1 gotów na rozszerzenie).

### Zakładka Typy zgłoszenia
Repeater: `key`, `label`, `price`, `capacity` (puste = brak limitu), `active`. Źródło opcji `__type`.

### Zakładka Noclegi
Pakiety (lista), pokoje (lista, flaga „pole współlokatora"), siatka inwentarza par pakiet × pokój z
limitem i ceną, przełącznik `allow_none`. Źródło configu pola `accommodation`.

### Zakładka Ustawienia
Daty eventu, `global_cap`, `waitlist_enabled`, okno zapisów (`registration_opens`/`closes`), adresy
maili organizatora.

### Zapis
Jeden przycisk „Zapisz" wysyła całość przez `PUT`. Raport walidacji renderowany pod paskiem akcji
(kody z serwera mapowane na komunikaty PL w `validation/report.js`). Zapis nieblokujący.

## 9. Bezpieczeństwo

- Nonce `wp_rest` na `PUT`; `current_user_can` + capability `edit_evreg_events` na każdym wywołaniu REST
- Sanityzacja per typ pola przy zapisie meta; escaping przy renderowaniu raportu i wartości w JS
- Meta `show_in_rest = false` — brak wycieku surowej konfiguracji przez core REST
- Serwer jest arbitrem walidacji (`SchemaAssembler` + `SchemaIntegrityChecker`); klient tylko lustrzanie
  dla informacji zwrotnej na żywo
- Guard `ABSPATH` we wszystkich plikach PHP poza `src/Domain/`

## 10. Testy

- `SchemaAssembler` → PHPUnit **unit** (bez WordPressa): wstrzykiwanie opcji `__type` z aktywnych typów,
  wstrzykiwanie configu noclegów, pominięcie typów nieaktywnych, błędy strukturalne, walidacja złożonej
  schemy przez `SchemaIntegrityChecker`
- `EventConfigRepository` (round-trip meta), `EventConfigController` (GET/PUT, autoryzacja, sanityzacja,
  raport walidacji), `Capabilities` (nadanie/sprawdzenie) → PHPUnit **integration** (wp-env)
- **Jedna** ścieżka Playwright **E2E**: utwórz event → dodaj pole, typ i nocleg → zapisz → przeładuj →
  konfiguracja utrwalona i odtworzona
- Granularne testy komponentów React — pominięte (YAGNI, narzędzie wewnętrzne)

## 11. Podział na taski (wysokopoziomowo)

Szczegóły w planie implementacji. Kolejność wynika z zależności:

1. CPT `evreg_event` + wyłączenie Gutenberga + capability `edit_evreg_events`
2. `SchemaAssembler` (czysta domena, PHPUnit unit)
3. `EventConfigRepository` (odczyt/zapis czterech meta)
4. `EventConfigController` (REST GET/PUT + walidacja + raport)
5. Build tooling `@wordpress/scripts` + enqueue + mount aplikacji (pusty szkielet React)
6. Zakładka Formularz (edytor schemy)
7. Zakładka Typy zgłoszenia
8. Zakładka Noclegi
9. Zakładka Ustawienia
10. Walidacja + raport (integracja klient ↔ serwer)
11. Ścieżka E2E Playwright

## 12. Ryzyka

| Ryzyko | Skutek | Przeciwdziałanie |
|--------|--------|------------------|
| Rozjazd opcji `__type` / configu noclegów | Formularz renderuje nieaktualne dane | Model 2 — jedno źródło, składanie przez assembler przy każdym odczycie |
| Niepoprawna schema zapisana i renderowana | Zepsuty formularz publiczny | Raport walidacji w Planie 2; gating renderowania w Planie 3 |
| Rozrost aplikacji React | Trudna w utrzymaniu | Jedna zakładka = jeden komponent, wspólny stan configu, zapis całości |
| Build tooling JS na Windows/Docker | Blokada developmentu | Plan 1 nie budował JS — `@wordpress/scripts` jest **nowe** w Planie 2 (task 5 instaluje i konfiguruje); `package.json` ma dziś tylko `@wordpress/env`. Build w kontenerze, weryfikacja pustym mountem przed zakładkami |
| Toolchain E2E (Playwright + wp-env) | Krucha ścieżka krytyczna | Jedna ścieżka happy-path; selektory stabilne; uruchamiana w CI osobno |
