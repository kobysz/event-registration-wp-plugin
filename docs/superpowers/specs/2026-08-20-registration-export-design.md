# Eksport zgłoszeń do CSV (Plan 5C) — projekt techniczny

Data: 2026-08-20
Status: zatwierdzony do planowania implementacji
Buduje na: [Plan 3B — formularz publiczny](2026-08-18-public-form-design.md) (`EventFormLoader`, `FormSchema`, `allFields`), [Plan 5A — panel zgłoszeń](2026-08-19-registrations-admin-design.md) (`RegistrationsScreen`, `RegistrationsListTable`, filtry status/typ/event, `status_label`, `type_label`, wzorzec handlerów admin-post), [Plan 5B — edycja odpowiedzi](2026-08-20-registration-edit-design.md) (mapowanie `data` JSON + schema na odpowiedzi)

## 1. Cel i kontekst

Organizator potrzebuje pobrać zgłoszenia jako plik do dalszej pracy (listy obecności, rozliczenia,
mailing). Panel 5A daje listę z filtrami i akcje, 5B edycję — brakuje eksportu. Plan 5C dokłada
pobieranie zgłoszeń do **CSV** z kolumnami wyprowadzonymi ze schematu eventu, respektujące bieżące
filtry listy.

Eksport jest **per event**: kolumny odpowiedzi wynikają ze schematu konkretnego eventu, więc plik ma
sens tylko dla jednego eventu (różne eventy = różne pola = różne kolumny). Handler wymaga wybranego
`event_id`. Format to **CSV** (natywny `fputcsv`, zero nowych zależności — XLSX wymagałby biblioteki
i commitowania `vendor/`, świadomie pominięte). Plik otwiera się w Excelu/Sheets; UTF-8 BOM zapewnia
poprawne polskie znaki.

## 2. Zakres

### v1 (Plan 5C)
- `RegistrationExportMapper` (`src/Domain/Export/`) — czyste mapowanie schema→kolumny i `data`→komórki
- `RegistrationRepository` + `exportRegistrations()` (wszystkie pasujące, bez paginacji) + `accommodationBookingsFor()` (bulk)
- `RegistrationsExporter` (`src/Admin/`) — `buildCsv()` zwraca pełny tekst CSV (BOM, tożsamość+odpowiedzi+nocleg)
- `RegistrationsScreen` + `ACTION_EXPORT`, `handle_export` (nonce+cap, wymóg eventu, streaming), przycisk „Eksportuj CSV"

### Poza Planem 5C
- **XLSX** → wymaga biblioteki (openspout/PhpSpreadsheet) i commitowania `vendor/` albo build-stepu; CSV wystarcza
- Eksport wielu eventów naraz (unia pól) → wybrano wymóg jednego eventu
- Wybór/kolejność kolumn przez UI, zapamiętywane presety → YAGNI
- Eksport asynchroniczny/w tle dla ogromnych zbiorów → skala per-event mieści się w jednym żądaniu
- Kolumna surowego JSON → odrzucone (kolumny per pole są czytelne)

### Świadomie pominięte (YAGNI)
- Streaming wierszowy do `php://output` bez bufora — `buildCsv` buduje string (testowalne); skala per-event bezpieczna dla pamięci
- Osobny nonce per event — jeden nonce akcji `evreg_export` (bez `id`, bo eksport nie dotyczy pojedynczego wiersza)

## 3. Decyzje projektowe

| # | Decyzja | Wybór | Uzasadnienie |
|---|---------|-------|--------------|
| 1 | Format | Tylko CSV | Natywny `fputcsv`, zero zależności, bez zmian w dystrybucji (`vendor/` gitignored) |
| 2 | Zakres eventu | Wymagaj jednego `event_id` | Kolumny = schema eventu; wiele eventów = różne kolumny |
| 3 | Kolumny | Tożsamość + pola input + nocleg z labelami | Czytelny plik; labele autora dla pól, i18n dla tożsamości |
| 4 | Podział warstw | Czysty `RegistrationExportMapper` (domena) + `buildCsv` string (admin) | Mapowanie testowalne bez WP; i18n/status w prezentacji |
| 5 | Kodowanie | UTF-8 + BOM | Excel czyta polskie znaki |
| 6 | Bezpieczeństwo danych | Neutralizacja CSV injection na komórkach danych | Excel wykonałby formułę `=…` z danych uczestnika |
| 7 | Pobieranie | admin-post, nonce'd GET link, streaming + exit | Pobranie (nie mutacja); brak PRG bo strumień |
| 8 | Bookingi | `accommodationBookingsFor` bulk | Unika N+1 przy wielu wierszach |

## 4. Architektura

```
src/Domain/Export/RegistrationExportMapper.php   nowy: czyste mapowanie (bez WP, bez i18n)
                                                  answerColumns(), answerCells(), accommodationCells()
src/Persistence/RegistrationRepository.php        + exportRegistrations(), accommodationBookingsFor()
src/Admin/RegistrationsExporter.php               nowy: buildCsv() → pełny tekst CSV (BOM + wiersze)
src/Admin/RegistrationsScreen.php                 + ACTION_EXPORT, handle_export(), przycisk eksportu,
                                                    rejestracja admin_post_evreg_export
```

Granice: mapowanie schema→kolumny/komórki wyłącznie w `RegistrationExportMapper` (domena, testowalna
bez kontenera). Składanie tekstu CSV (BOM, nagłówki i18n, status/typ label, neutralizacja injection)
w `RegistrationsExporter::buildCsv` (prezentacja, zwraca string). SQL zgłoszeń wyłącznie w
`RegistrationRepository`. `RegistrationsScreen` orkiestruje (przycisk, handler, streaming). Silnik
3A/4A/5A/5B nietknięty poza dołożeniem dwóch metod repo i elementów ekranu.

## 5. Domena: RegistrationExportMapper

Czysty PHP w `src/Domain/Export/`. Zero WordPressa, zero `$wpdb`, zero `__()`. Labele pochodzą ze
schematu i configu (treść autora eventu, nie stringi UI). `DomainPurityTest` musi zostać zielony.

```
final class RegistrationExportMapper {
    /** Uporządkowane pola input (pomija Heading/Paragraph); nagłówek = label pola. @return array<int,array{key:string,label:string}> */
    public function answerColumns( FormSchema $schema ): array;

    /** Wartości komórek odpowiedzi w kolejności answerColumns; multi-value → implode(', '); brak/null → ''. @param array<string,mixed> $data @return array<int,string> */
    public function answerCells( array $data, FormSchema $schema ): array;

    /** Labele noclegu z bookingu. @param array<string,mixed>|null $booking @return array{package:string,room:string,roommate:string} */
    public function accommodationCells( ?array $booking, AccommodationConfig $config ): array;
}
```

- `answerColumns`: iteruje `$schema->allFields()`, bierze pola z `$field->type->isInput() === true`, zwraca `{key: $field->key, label: $field->label}` w kolejności schematu.
- `answerCells`: dla każdej kolumny z `answerColumns` czyta `$data[$key]`; tablica → `implode(', ', array_map('strval', $val))`; brak klucza lub `null` → `''`; inaczej `(string) $val`.
- `accommodationCells`: `null` booking → `['package'=>'','room'=>'','roommate'=>'']`. Inaczej: `package` = label z `$config` po `package_key` (fallback surowy klucz), `room` = label po `room_type_key` (fallback klucz), `roommate` = `(string) ($booking['roommate_pref'] ?? '')`. Labele przez `$config->packages()`/`$config->rooms()` (dopasowanie po `->key`, odczyt `->label`).

## 6. Prezentacja: RegistrationsExporter::buildCsv

`src/Admin/RegistrationsExporter.php`. Buduje **pełny tekst CSV jako string** (osobno od wysyłki HTTP,
żeby był testowalny).

```
public function buildCsv(
    FormSchema $schema,
    AccommodationConfig $accommodation,
    RegistrationTypeCollection $types,
    array $rows,                          // wiersze evreg_registrations (ARRAY_A)
    array $bookingsById                   // registration_id => wiersz bookingu
): string;
```

Kolejność kolumn (nagłówek → wartość):

Tożsamość (nagłówki i18n):
| Nagłówek | Wartość |
|---|---|
| „ID" | `id` |
| „Status" | `RegistrationsListTable::status_label( $status )` (i18n, istnieje) |
| „Typ" | `$types->get( $type_key )?->label` (fallback surowy `type_key`) |
| „E-mail" | `email` |
| „Imię i nazwisko" | `name` |
| „Cena" | `number_format( (float) $price_total, 2, '.', '' )` |
| „Utworzono" | `created_at` |
| „Potwierdzono" | `confirmed_at` (puste gdy NULL) |
| „Notatka" | `note` (puste gdy NULL) |

Odpowiedzi: po jednej kolumnie na pole z `mapper->answerColumns($schema)` (nagłówek = label autora),
wartości z `mapper->answerCells( json_decode($row['data'], true), $schema )`.

Nocleg (nagłówki i18n, na końcu): „Nocleg – pakiet", „Nocleg – pokój", „Nocleg – współlokator" —
z `mapper->accommodationCells( $bookingsById[$id] ?? null, $accommodation )`.

Szczegóły:
- **BOM** `\xEF\xBB\xBF` na początku stringa.
- Zapis przez `fputcsv` do bufora (`fopen('php://temp','r+')`), separator `,`, enclosure `"`, na końcu `stream_get_contents`. `fputcsv` cytuje wartości z separatorem/cudzysłowem/nową linią.
- **Neutralizacja CSV injection:** każda **komórka danych** (nie nagłówek), której pierwszy znak to `= + - @` (lub TAB `\t`, CR `\r`), poprzedzona apostrofem `'`. Nagłówki (i18n + labele autora) nie są neutralizowane.
- Nagłówek budowany raz z tej samej `$schema` co wiersze → stała liczba kolumn dla całego pliku.
- Puste/brakujące → `''`. Multi-value nietablicowe → `(string)`.

## 7. Repozytorium — nowe metody (jedyne SQL zgłoszeń)

### `exportRegistrations( array $filters ): array`
Jak `paginateRegistrations` ale bez `LIMIT/OFFSET`: `SELECT * FROM {registrations} <where> ORDER BY id ASC`.
Reuse prywatnego `registrationWhere( $filters )` (whitelist statusu, `type_key`/`event_id` gdy niepuste).
Zwraca wszystkie pasujące wiersze (`ARRAY_A`). Kolejność `ASC` (chronologiczna, czytelna w pliku).

### `accommodationBookingsFor( array $ids ): array`
Bulk: `SELECT * FROM {bookings} WHERE registration_id IN (<placeholdery %d>)`. Zwraca mapę
`registration_id => wiersz` (`ARRAY_A`). Puste `$ids` → pusta mapa (bez zapytania). Unika N+1 przy
wielu wierszach eksportu. Gdy zgłoszenie ma >1 booking (nie powinno), wygrywa pierwszy napotkany.

Filtry: kształt `array{ status?: string, type_key?: string, event_id?: int }` (jak 5A). `registrationWhere`
już istnieje; `distinctTypeKeys`/`distinctEventIds`/`findAccommodationBooking` bez zmian.

## 8. Ekran: przycisk i handler

### Przycisk „Eksportuj CSV"
Renderowany na ekranie listy (`render_list`, obok filtrów / w `extra_tablenav`). Nonce'd GET link
niosący **bieżące filtry**: `wp_nonce_url( admin_url( 'admin-post.php?action=evreg_export' + '&status=' + '&type_key=' + '&event_id=' ), 'evreg_export' )`. Tylko filtry niepuste dołączane. `esc_url`.
Gdy brak wybranego eventu — link i tak renderowany; handler wymusza event i przekieruje z notice
(albo przycisk disabled gdy `event_id` pusty — decyzja implementacyjna, oba akceptowalne; preferowane
disabled/hint gdy brak eventu, żeby nie generować pustego przekierowania).

### `ACTION_EXPORT = 'evreg_export'`, rejestracja `add_action( 'admin_post_evreg_export', [ self::class, 'handle_export' ] )`.

### `handle_export(): void`
```
check_admin_referer( 'evreg_export' )                 -- nonce (CSRF)
if ! current_user_can( Capabilities::CAP ): wp_die     -- cap (dane osobowe)
filters = sanitize z $_GET: status (whitelist), type_key (text), event_id (int)
event_id = (int) filters['event_id'] ?? 0
if event_id <= 0: redirect( 'export_no_event' )        -- PRG na listę + notice, buildCsv NIE wołane
schema = EventFormLoader->load( event_id )             -- gdy null: redirect( 'export_no_event' )
config = EventConfigRepository->get( event_id )
types = RegistrationTypeCollection::fromArray( config['types'] )
accommodation = AccommodationConfig::fromArray( config['accommodation'] )
rows = repository->exportRegistrations( filters )
ids = array_column( rows, 'id' )
bookings = repository->accommodationBookingsFor( ids )
csv = ( new RegistrationsExporter() )->buildCsv( schema, accommodation, types, rows, bookings )
filename = sanitize_file_name( 'zgloszenia-event-' . event_id . '-' . current_time('Y-m-d') . '.csv' )
header 'Content-Type: text/csv; charset=utf-8'
header 'Content-Disposition: attachment; filename="' . filename . '"'
echo csv; exit
```
Handler nie robi PRG na sukcesie (strumień + `exit`). Filtry re-czytane i sanityzowane po stronie
serwera (nie ufamy linkowi). Nowy kod notice `export_no_event` tam gdzie 5A renderuje `evreg_msg`.

## 9. Bezpieczeństwo

- Handler: `check_admin_referer('evreg_export')` **potem** `current_user_can(Capabilities::CAP)` — nonce przed cap; oba przed dostępem do danych. Eksport = odczyt danych osobowych, cap obowiązkowy.
- Filtry walidowane po stronie serwera (status whitelist w `registrationWhere`, `event_id` rzutowany na int, `type_key` sanityzowany) — link tylko podpowiada.
- **CSV injection** neutralizowany na komórkach danych (§6) — ochrona odbiorcy pliku przed wykonaniem formuł z danych uczestników.
- `filename` przez `sanitize_file_name` + w cudzysłowie w nagłówku.
- Streaming ustawia własne nagłówki i `exit` — brak dalszego renderu admina.

## 10. Testy

### Unit (`tests/Unit/`, host — czysta domena)
`RegistrationExportMapper`:
- `answerColumns` pomija Heading/Paragraph, zachowuje kolejność, nagłówek = label.
- `answerCells` mapuje po kluczach; multi-value `implode(', ')`; brak/null → `''`; nietablicowe → `(string)`.
- `accommodationCells` booking→labele (fallback klucz), roommate przepisany, `null`→trzy puste.

### Integracyjne (`tests/Integration/`, kontener)
`RegistrationsExporter::buildCsv`:
- Pełny wiersz (schema email+text+checkbox-group, typ, nocleg): nagłówek tożsamość+pola+nocleg, wartości poprawne, status i18n, typ-label, nocleg-labele.
- **BOM** na starcie stringa.
- **CSV injection:** komórka danych `=CMD()` → `'=CMD()`; nagłówek NIE neutralizowany.
- Multi-value w komórce złączone `, ` i cytowane przez `fputcsv`.
- Pusty booking / brak pola → puste komórki, stała liczba kolumn.

`RegistrationRepository`:
- `exportRegistrations` — filtry zawężają, brak LIMIT (wszystkie pasujące), `ORDER BY id ASC`, nieznany status ignorowany.
- `accommodationBookingsFor` — mapa `id→wiersz`, puste `ids`→pusta mapa, tylko podane id.

`RegistrationsScreen::handle_export`:
- Zły/brak nonce → `WPDieException`; brak capa → `WPDieException`.
- Brak `event_id` (≤0) → PRG na listę + notice `export_no_event` (redirect łapany filtrem `wp_redirect`), `buildCsv` nie osiągnięte.
- Przycisk „Eksportuj CSV" na liście: nonce'd URL z bieżącymi filtrami (asercja linku + nonce).

Nagłówki HTTP i `exit` przy sukcesie są trudne do asercji w PHPUnit — logika pliku pokryta przez
`buildCsv` (string), handler pokryty na guardach + wymogu eventu.

### Architektura
`DomainPurityTest` zielony (`RegistrationExportMapper` w `src/Domain/Export`, bez WP/i18n). phpcs + phpstan poziom 6 czysto.

## 11. Kolejność implementacji

1. `RegistrationExportMapper` (domena) — czyste mapowanie, unit.
2. `RegistrationRepository::exportRegistrations` + `accommodationBookingsFor` — SQL.
3. `RegistrationsExporter::buildCsv` — składanie CSV (BOM, injection, labele).
4. `RegistrationsScreen` — `ACTION_EXPORT`, `handle_export`, przycisk, notice.
