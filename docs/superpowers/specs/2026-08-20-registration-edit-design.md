# Edycja odpowiedzi zgłoszenia (Plan 5B) — projekt techniczny

Data: 2026-08-20
Status: zatwierdzony do planowania implementacji
Buduje na: [Plan 3A — backend rezerwacji](2026-08-18-reservation-backend-design.md) (`ReservationService`, `RegistrationRepository`, `CapacityCalculator`, `PriceCalculator`, inwariant lock→count), [Plan 3B — formularz publiczny](2026-08-18-public-form-design.md) (`SubmitHandler` validate→extract→reserve, `FormRenderer`, `EventFormLoader`, `form.js`), [Plan 4A — kolejka mailowa](2026-08-19-mail-queue-design.md) (`Subscriber`, hooki cyklu życia), [Plan 5A — panel zgłoszeń](2026-08-19-registrations-admin-design.md) (`RegistrationsScreen`, `AdminActionResult`, wzorzec handlerów admin-post, ekran szczegółów)

## 1. Cel i kontekst

Organizator potrzebuje poprawić dane istniejącego zgłoszenia: literówkę w polu, zmianę typu
zgłoszenia, zmianę lub dodanie noclegu. Panel 5A daje read-only ekran szczegółów i akcje cyklu
życia, ale nie da się zmienić **odpowiedzi**. Plan 5B dokłada edycję.

Edycja jest transakcyjnie najtrudniejszą częścią panelu, bo zmiana `type_key` lub noclegu **zmienia
zajętość miejsc**. Musi przejść ten sam inwariant `lockEvent→occupancy→decide` co `reserve` (3A),
z jedną poprawką: zajętość liczona **z wykluczeniem własnego wiersza** (inaczej zgłoszenie
double-liczy własny seat i blokuje własną edycję). Walidacja i ekstrakcja odpowiedzi to dokładnie
ten sam pipeline co formularz publiczny (3B) — wydzielony do wspólnego `SubmissionAssembler`, żeby
admin i front używały jednego źródła prawdy.

## 2. Zakres

### v1 (Plan 5B)
- `SubmissionAssembler` — wydzielony validate+extract z `SubmitHandler`; wspólny dla frontu i admina
- `RegistrationRepository` + `occupancyExcluding()`, `updateRegistration()`
- `ReservationService` + `editAnswers()` (transakcja, self-exclusion, twardy blok)
- `AdminActionResult` + kody `edited`, `capacity_full`, `accommodation_full`
- `RegistrationEditForm` — serwerowy renderer formularza edycji w adminie
- `RegistrationsScreen` + routing `?action=edit`, handler `handle_edit`, przycisk „Edytuj"

### Poza Planem 5B
- Eksport CSV/XLSX → Plan 5C
- Override adminem ponad limit (wymuszenie zapisu na pełny typ/nocleg) → wybrano twardy blok
- Mail przy edycji (re-wysyłka potwierdzenia po zmianie typu) → wybrano bez maila
- Edycja zgłoszeń `cancelled` → nieedytowalne (myliłoby cykl życia)
- Promocja waitlisty przez edycję (zmiana typu waitlisty nie promuje) → służy do tego akcja „Promuj" z 5A
- Historia/audyt zmian odpowiedzi → YAGNI
- Bulk edit → YAGNI

### Świadomie pominięte (YAGNI)
- Osobny DTO edycji — reużywamy `ReservationRequest` (email/name/typeKey/data/selection pasuje 1:1)
- Nowa metoda „replace booking" — wymiana przez istniejące `deleteAccommodationBooking`+`insertAccommodationBooking`

## 3. Decyzje projektowe

| # | Decyzja | Wybór | Uzasadnienie |
|---|---------|-------|--------------|
| 1 | Nowy typ/nocleg jest pełny | **Twardy blok** — odrzucenie jak `reserve`, status niezmieniony | Limit z 3A pozostaje nienaruszalny; admin najpierw zwalnia miejsce |
| 2 | Edytowalne statusy | pending, confirmed, waitlist (NIE cancelled) | cancelled martwe; waitlist edytowalna ale nie promowana |
| 3 | Zajętość przy recheck | `occupancyExcluding(self)` dla statusów zajmujących miejsce | Zgłoszenie nie może blokować własnej edycji własnym seatem |
| 4 | Waitlist | Edycja bez bramki pojemności, status zostaje `waitlist` | Waitlist nie zajmuje miejsca (pending+confirmed) → nie double-liczy, nie gejtuje |
| 5 | Formularz edycji | Bespoke `RegistrationEditForm` w adminie | Pełna kontrola; `FormRenderer` sklejony z powłoką publiczną (honeypot/nonce/sekcje) |
| 6 | Conditional visibility | Reuse publicznego `form.js` (handle `evreg-public`) + atrybuty `data-evreg-when-*` | Chowanie sekcji wg `__type` identyczne jak 3B, bez duplikacji JS |
| 7 | Walidacja/ekstrakcja | Wydzielić `SubmissionAssembler`, front i admin używają jednego | Jedno źródło prawdy; duplikacja groziłaby rozjazdem walidacji |
| 8 | Mail przy edycji | Brak — `editAnswers` nie emituje hooka | Organizator poprawia dane, uczestnik nie dostaje spamu (wzór `confirmManually`) |
| 9 | DTO edycji | Reuse `ReservationRequest` | email/name/typeKey/data/selection pasuje 1:1 |

## 4. Architektura

```
src/Frontend/SubmissionAssembler.php     nowy: assemble(FormSchema, array $post): AssembledSubmission
                                          (validate → extract email/name/typ/selekcja → ReservationRequest|errors)
src/Frontend/SubmitHandler.php           deleguje process() do SubmissionAssembler (regresja 3B pilnuje)
src/Persistence/RegistrationRepository.php  + occupancyExcluding(), updateRegistration()
src/Services/ReservationService.php      + editAnswers()
src/Services/AdminActionResult.php       + edited(), capacityFull(), accommodationFull()
src/Admin/RegistrationEditForm.php       nowy renderer serwerowy formularza edycji
src/Admin/RegistrationsScreen.php        + routing ?action=edit, handle_edit(), przycisk „Edytuj",
                                          enqueue evreg-public na ekranie edycji
```

Granice: walidacja/ekstrakcja wyłącznie w `SubmissionAssembler`. Transakcja edycji i inwariant
lock→count wyłącznie w `ReservationService::editAnswers`. SQL zgłoszeń wyłącznie w
`RegistrationRepository`. `RegistrationEditForm` renderuje, `RegistrationsScreen` orkiestruje.
Silnik 3A/4A nietknięty poza dołożeniem metod. `SubmitHandler` refaktoryzowany do delegacji, ale
jego zewnętrzne zachowanie niezmienione (testy 3B zielone bez zmian).

## 5. SubmissionAssembler — wspólny pipeline walidacji

Dziś `SubmitHandler::process` (3B) robi: `EventFormLoader::load` → `extractAnswers` → `Validator::validate`
→ na sukcesie `extractEmail`/`extractName`/`extractSelection` → `ReservationRequest`. Prywatne metody
ekstrakcji żyją w `SubmitHandler`. Wydzielamy je bez zmiany zachowania.

```
final class SubmissionAssembler {
    public function __construct( private FieldValidatorRegistry $validators, ... ) {}

    // Waliduje surowe posty względem złożonej schemy i buduje ReservationRequest.
    public function assemble( FormSchema $schema, array $post ): AssembledSubmission;
}

final class AssembledSubmission {
    public function isValid(): bool;
    public function errors(): array;          // ValidationError[] (puste gdy valid)
    public function values(): array;          // znormalizowane odpowiedzi (do re-renderu)
    public function request(): ?ReservationRequest;   // null gdy invalid
}
```

`assemble`: `extractAnswers(schema, post)` → `Validator::validate(schema, answers)` → jeśli invalid
zwraca `AssembledSubmission` z `errors` + `values=answers` (surowe, do re-renderu z zachowaniem
wpisanych wartości); jeśli valid → `extractEmail`/`extractName`/`extractSelection` z
`result->values()` → `ReservationRequest(email, name, values['__type'], values, selection)`.

`SubmitHandler::process` po refaktorze: spam-guardy (honeypot/timestamp — zostają w handlerze, to
antyspam publiczny) → `loader->load` → `assembler->assemble` → invalid: `SubmitResult::invalid(errors, values)`
→ valid: `reservations->reserve(event_id, assembled->request())`. **Zewnętrzny kontrakt `process`
niezmieniony** — istniejące testy 3B przechodzą bez modyfikacji (regresja pilnuje).

Ekstrakcja jest schema-driven i czysta (bez `$wpdb`, bez globali WP poza tym co już w `SubmitHandler`).
`SubmissionAssembler` mieszka w `src/Frontend/` (nie Domain — zależy od `FormSchema`/walidatorów, ale
te są już domenowe; sam assembler jest adapterem kompozycji). `DomainPurityTest` niezmieniony.

## 6. Repozytorium — nowe metody (jedyne SQL zgłoszeń)

### `occupancyExcluding( int $event_id, int $exclude_id ): OccupancySnapshot`
Kopia `occupancy( $event_id )` z dodatkowym `AND r.id != %d` w **obu** zliczeniach: liczniku
per-`type_key` ORAZ w JOIN-ie bookingów liczącym sloty. Zwraca `OccupancySnapshot` policzony tak,
jakby wiersz `$exclude_id` nie istniał. Liczy tylko `RegistrationStatus::occupyingValues()`
(pending+confirmed), identycznie jak `occupancy`.

### `updateRegistration( int $id, string $type_key, string $email, string $name, string $data_json, float $price ): void`
`$wpdb->update` tabeli `evreg_registrations`: `type_key`, `email`, `name`, `data` (już
`wp_json_encode` po stronie serwisu — string), `price_total` (`%f`), `updated_at = current_time('mysql')`.
Wzór: istniejące `updateNote`. Nie dotyka `status`, `token`, `created_at`, `confirmed_at`, `expires_at`.

Booking noclegu wymieniany istniejącymi `deleteAccommodationBooking($id)` + `insertAccommodationBooking($id, selection, price)` — bez nowej metody.

## 7. Serwis: `editAnswers( int $id, ReservationRequest $request ): AdminActionResult`

Reużywa istniejącego `ReservationRequest`. Konstruktor `ReservationService` bez zmian (ma już
`RegistrationRepository`, `EventConfigRepository`, `CapacityCalculator`, `PriceCalculator`).

```
row = repository->findById( id )
if row == null: return notFound()
status = RegistrationStatus::from( row['status'] )
if status == Cancelled: return invalidStatus()          -- twardy blok edycji anulowanych

event_id = (int) row['event_id']
load config: types, accommodation, settings ( EventConfigRepository::get )
type = types->get( request->typeKey )                    -- do ceny; nieznany typ → invalidStatus()

START TRANSACTION

if status in { Pending, Confirmed }:                      -- zajmuje miejsce → bramka pojemności
    lockEvent( event_id )                                -- SELECT … FOR UPDATE; MUSI przed COUNT
    limits   = CapacityLimits( global_cap, types->capacities(), accommodation->capacities(), waitlist_enabled )
    snapshot = repository->occupancyExcluding( event_id, id )   -- świeży COUNT bez własnego seata
    decision = calculator->decide( limits, snapshot, request->typeKey, request->selection )
    if decision->outcome == Rejected:
        ROLLBACK; return capacityFull()                  -- typ/global pełny
    if request->selection != null and not decision->accommodationGranted:
        ROLLBACK; return accommodationFull()             -- wybrany slot noclegu pełny (twardy blok)
    item = ( request->selection != null )
             ? accommodation->item( selection->packageKey, selection->roomKey ) : null
else:                                                     -- Waitlist: bez bramki, nie zajmuje miejsca
    item = ( request->selection != null )
             ? accommodation->item( selection->packageKey, selection->roomKey ) : null

price = PriceCalculator::total( type, accommodation, request->selection )
repository->updateRegistration( id, request->typeKey, request->email, request->name,
                                wp_json_encode( request->data ), price )
repository->deleteAccommodationBooking( id )
if request->selection != null:
    repository->insertAccommodationBooking( id, request->selection, item != null ? item->price : 0.0 )

COMMIT
return edited()
-- ŻADEN hook cyklu życia nie jest emitowany → Subscriber (4A) nie kolejkuje maila
```

**Inwarianty:**
- `lockEvent` przed `occupancyExcluding` — nośny inwariant lock→count z 3A (§8 spec 3A). NIE zmieniać kolejności.
- Self-exclusion: `occupancyExcluding(event_id, id)` — bez tego zgłoszenie w pełnym typie blokuje własną edycję pola (10/10 wliczając siebie → Rejected). Z wykluczeniem: 9/10 → przechodzi. Gdy nowy typ = stary typ, net efekt: „czy jest miejsce, jakby mnie tu nie było" = zawsze tak dla no-op typu.
- Twardy blok noclegu: `reserve` przy `accommodationGranted=false` zapisuje bez noclegu; edycja z podaną selekcją **odrzuca całość** (`accommodationFull`) — inaczej cicho zgubiłaby wybór organizatora.
- Waitlist: brak `lockEvent`/`occupancy` (nie liczymy — nie zajmuje miejsca), status zostaje `waitlist` (`updateRegistration` nie dotyka `status`). Transakcja nadal owija update+wymianę bookingu dla atomowości.
- Cała mutacja (update wiersza + wymiana bookingu) atomowa; ROLLBACK zostawia wiersz i booking nietknięte.

### AdminActionResult — nowe kody
`code`: dochodzą `edited` (sukces), `capacity_full`, `accommodation_full`. Reużywa
`invalid_status`/`not_found`. Nowe fabryki: `edited()`, `capacityFull()`, `accommodationFull()`.
Błędy walidacji NIE idą przez `AdminActionResult` — wracają jako `AssembledSubmission::errors()` do
inline re-renderu formularza; `editAnswers` dostaje tylko już-zwalidowane odpowiedzi.

## 8. Admin: formularz, walidacja, handler

### `RegistrationEditForm`
`render( FormSchema $schema, array $answers, int $reg_id ): string` — iteruje `$schema->allFields()`,
pomija display-only (`Heading`, `Paragraph`), renderuje kontrolkę per `FieldType` prefillowaną z `$answers`:

| FieldType | Kontrolka |
|---|---|
| Text/Email/Tel/Number/Date | `<input type=…>` z `value` |
| Textarea | `<textarea>` |
| Select | `<select>` z zaznaczoną opcją |
| Radio/Checkbox/CheckboxGroup | inputy z `checked` |
| Hidden | `<input type=hidden>` (w tym `__type`) |
| Accommodation | `<select>` slotów `package|room` (zaznaczony bieżący) + roommate |

Wszystkie wartości escapowane (`esc_attr`/`esc_textarea`/`esc_html`), wszystkie labelki przez i18n
(`__( …, 'event-registration' )`). Emituje `data-evreg-when-*` na sekcjach zależnych od `__type`
(te same atrybuty co `FormRenderer`), żeby `form.js` chował/pokazywał identycznie.

Powłoka: `<form method="post" action="admin-post.php">` + hidden `action=evreg_edit_registration`,
hidden `registration=<id>`, `wp_nonce_field( 'evreg_edit_' . $id )`. **Bez** honeypot/timestamp
(antyspam publiczny, w adminie zbędny — dostęp chroni cap + nonce).

Na błąd walidacji renderer przyjmuje opcjonalne `?array $errors` i `?array $submitted` → prefill z
`$submitted` zamiast `$answers`, komunikat `errors[].detail` **escapowany** pod polem (nigdy
`dangerouslySetInnerHTML`/surowy echo).

### `RegistrationsScreen` — routing i handler
- Przycisk „Edytuj" w `render_actions` dla statusów {pending, confirmed, waitlist}.
- `?action=edit&id=N` → `render_edit(int $id)`: `findById`; brak → notice+powrót na listę; status
  `cancelled` → notice „nie można edytować anulowanego" + powrót na detal; inaczej: `json_decode data`,
  schema przez `EventFormLoader::load(event_id)`, echo `RegistrationEditForm::render`. Enqueue handle
  `evreg-public` (form.js) tylko na tym ekranie.
- `handle_edit()` admin-post: `guard( ACTION_EDIT )` (nonce `check_admin_referer('evreg_edit_'.$id)`
  → `current_user_can('edit_evreg_events')`; zły → `wp_die`) → `EventFormLoader::load` →
  `SubmissionAssembler::assemble( schema, $_POST )`:
  - **invalid** → `render_edit` **inline** (bez redirectu) z `errors` + `submitted` (zachowane wartości).
  - **valid** → `ReservationService::editAnswers( id, assembled->request() )` → mapowanie wyniku:

| `AdminActionResult` | Reakcja |
|---|---|
| `edited` | PRG na detal (`?action=view&id=N`) + notice sukcesu |
| `capacity_full` | PRG na edycję (`?action=edit&id=N`) + notice „brak miejsc w wybranym typie" |
| `accommodation_full` | PRG na edycję + notice „brak miejsc w wybranym noclegu" |
| `invalid_status` | PRG na listę + notice błędu |
| `not_found` | PRG na listę + notice błędu |

Stałe: `ACTION_EDIT = 'evreg_edit_registration'`; `add_action( 'admin_post_evreg_edit_registration', [ …, 'handle_edit' ] )`.
PRG przez `wp_safe_redirect` + `exit`, notice kodem powodu w query (jak 5A), tłumaczenie w warstwie admina.

## 9. Bezpieczeństwo

- Handler: nonce **potem** cap (wzór 5A `guard`). Nonce per-wiersz (`evreg_edit_<id>`).
- Cap `edit_evreg_events` na ekranie i w handlerze.
- Serwer arbitrem walidacji — `SubmissionAssembler` waliduje po stronie serwera niezależnie od tego
  co przyszło; klient tylko formularzem. `errors[].detail` renderowany **escapowany**.
- Escaping wszystkich wartości w `RegistrationEditForm` (prefill z bazy i z re-renderu).
- Selekcja noclegu walidowana przez `AccommodationValidator` (część pipeline'u) — nieistniejący slot
  → błąd walidacji, nie dochodzi do `editAnswers`.
- Transakcja + `lockEvent` chronią przed wyścigiem edycji vs równoległa rezerwacja (ten sam lock co `reserve`).

## 10. Testy

### Integracyjne (`tests/Integration/`, kontener wp-env)

**Serwis `editAnswers` — rdzeń transakcyjny:**
- **Self-exclusion:** confirmed w typie A wypełnionym do limitu (łącznie z tym wierszem); edycja pola
  tekstowego, typ zostaje A → `edited` (dowód wykluczenia własnego seata; anty-double-count).
- **Twardy blok typ:** przeniesienie do typu B pełnego → `capacity_full`; wiersz i booking niezmienione (ROLLBACK).
- **Twardy blok nocleg:** selekcja slotu pełnego → `accommodation_full`; stary booking nietknięty (ROLLBACK).
- **Happy path typ+cena:** zmiana typu na wolny → `type_key`+`data`+`price_total` zaktualizowane, cena z `PriceCalculator`.
- **Wymiana noclegu:** zmiana slotu na wolny → stary booking skasowany, nowy wstawiony z ceną `InventoryItem`.
- **Waitlist bez bramki:** edycja waitlist na typ pełny → `edited`, status zostaje `waitlist` (nie promuje, nie gejtuje).
- **cancelled nieedytowalne:** → `invalid_status`, zero zmian.
- **not_found:** złe id → `not_found`.
- **Brak maila:** po edycji (także zmianie typu) **zero wierszy w `evreg_mail_queue`** (wzór testu `confirmManually`).

**Handler/ekran (`RegistrationsScreen`):**
- `handle_edit` zły nonce/cap → `WPDieException`.
- Valid posty → `editAnswers` wołane, PRG na detal (redirect łapany filtrem `wp_redirect` rzucającym wyjątek).
- Invalid posty → **brak** redirectu, HTML formularza z escapowanym błędem (inline re-render).
- `render_edit` na cancelled → notice, formularz nierenderowany.

**Assembler (`SubmissionAssembler`):**
- valid → `ReservationRequest` z poprawnym email/name/typ/selekcja.
- invalid → `errors[]`, `request()` null, `values()` = surowe odpowiedzi.
- **Regresja 3B:** istniejące testy `SubmitHandler::process` przechodzą zielono bez zmian.

### Unit (`tests/Unit/`, host)
- `AdminActionResult` fabryki `edited`/`capacityFull`/`accommodationFull` — kod+reason.

### Architektura
`DomainPurityTest` zielony (assembler w `src/Frontend`, nie Domain). phpcs + phpstan (poziom 6) czysto.

## 11. Kolejność implementacji

1. `SubmissionAssembler` + refaktor `SubmitHandler` do delegacji (regresja 3B zielona) — fundament walidacji.
2. `AdminActionResult` nowe fabryki — najtańsze, odblokowuje serwis.
3. `RegistrationRepository::occupancyExcluding` + `updateRegistration` — SQL.
4. `ReservationService::editAnswers` — rdzeń transakcyjny (self-exclusion, twardy blok, brak maila).
5. `RegistrationEditForm` — renderer.
6. `RegistrationsScreen` routing + `handle_edit` + przycisk + enqueue — spięcie.
