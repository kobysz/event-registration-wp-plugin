# Panel zgłoszeń — lista i akcje (Plan 5A) — projekt techniczny

Data: 2026-08-19
Status: zatwierdzony do planowania implementacji
Buduje na: [Plan 3A — backend rezerwacji](2026-08-18-reservation-backend-design.md) (`ReservationService`, `RegistrationRepository`, `CapacityCalculator`, inwariant lock→count), [Plan 4A — kolejka mailowa](2026-08-19-mail-queue-design.md) (`Subscriber`, hooki cyklu życia), [Plan 4B-Kolejka](2026-08-19-mail-queue-admin-design.md) (wzorzec `WP_List_Table` + ekran + handlery admin-post)

## 1. Cel i kontekst

Organizator dostaje panel administracyjny do zarządzania zgłoszeniami: lista z filtrami,
ekran szczegółów z odpowiedziami uczestnika i notatką, oraz akcje cyklu życia — ręczne
potwierdzenie, anulowanie, promocja z listy rezerwowej, trwałe usunięcie, edycja notatki.
Dziś zgłoszenia powstają przez formularz publiczny (3B) i żyją w bazie, ale organizator nie ma
ich jak zobaczyć ani nimi zarządzać.

Panel to `WP_List_Table` pod submenu menu CPT `evreg_event` — ta sama konwencja co ekran kolejki
mailowej (4B-Kolejka). Akcje zmieniające zajętość miejsc idą przez `ReservationService`
(jedyne miejsce z transakcją i inwariantem lock→count z 3A), nie przez warstwę admina.

**Edycja odpowiedzi** (zmiana typu/noclegu z przeliczeniem limitów) to **Plan 5B** — transakcyjnie
najtrudniejsza część, własny cykl. **Eksport CSV/XLSX** to **Plan 5C**. Tutaj: lista + akcje + notatka.

## 2. Zakres

### v1 (Plan 5A)
- `RegistrationRepository` + zapytania listujące i mutacje statusu/notatki/kasowania
- `ReservationService` + `confirmManually`, `cancel`, `promoteFromWaitlist`, `deleteRegistration`
- `AdminActionResult` — obiekt wartości wyniku akcji
- `RegistrationsListTable` — `WP_List_Table` z filtrami status/typ/event
- `RegistrationsScreen` — submenu, ekran szczegółów (read-only odpowiedzi + notatka), pięć handlerów admin-post
- Rejestracja w `event-registration.php`

### Poza Planem 5A
- Edycja odpowiedzi uczestnika (zmiana typu/noclegu, re-walidacja, przeliczenie limitów) → Plan 5B
- Eksport CSV/XLSX → Plan 5C
- Bulk actions (masowe potwierdzanie/anulowanie) → YAGNI
- Mail przy ręcznym anulowaniu → spec nie wymaga
- Promocja od razu na `confirmed` (pomijając opt-in) → wybrano ścieżkę `pending`

### Świadomie pominięte (YAGNI)
- Sortowanie po dowolnej kolumnie, wyszukiwanie pełnotekstowe — `id DESC` + filtry wystarczą
- Keyset pagination — `OFFSET` wystarcza przy tej skali
- Powiadomienia/alerty o nowych zgłoszeniach w panelu — mail `admin_new` (4A) to załatwia

## 3. Decyzje projektowe

| # | Decyzja | Wybór | Uzasadnienie |
|---|---------|-------|--------------|
| 1 | Dekompozycja Planu 5 | 5A lista+akcje, 5B edycja, 5C eksport | Każdy wykonalny w jednym cyklu; edycja transakcyjna oddzielona od reszty |
| 2 | Warstwa akcji zmieniających zajętość | Nowe metody w `ReservationService` | Tam żyje transakcja i inwariant lock→count z 3A |
| 3 | Usuwanie | Miękkie („Anuluj" → cancelled) + twarde („Usuń trwale" tylko na cancelled) | Anulowane nie blokuje ponownej rejestracji (decyzja C z 3A); twarde kasowanie sprząta osierocone wiersze kolejki |
| 4 | Promocja z waitlisty | → `pending` pod lock→count, mail opt-in | Trzyma inwariant limitów i double opt-in; uczestnik potwierdza normalnie |
| 5 | Ręczne potwierdzenie | Tylko `pending`→`confirmed`, BEZ maila | „Uczestnik potwierdził telefonicznie"; nie emituje hooka, więc Subscriber nie wyśle maila |
| 6 | UI | `WP_List_Table` + submenu, jak 4B-Kolejka | Konwencja ustalona; paginacja/filtry/akcje za darmo |

## 4. Architektura

```
src/Persistence/RegistrationRepository.php   + paginateRegistrations(), countRegistrations(),
                                               distinctTypeKeys(), markCancelled(), markPending(),
                                               deleteAccommodationBooking(), deleteMailQueueByRegistration(),
                                               hardDelete(), updateNote()
src/Services/ReservationService.php          + confirmManually(), cancel(), promoteFromWaitlist(),
                                               deleteRegistration()
src/Services/AdminActionResult.php           nowy obiekt wartości
src/Admin/RegistrationsListTable.php         WP_List_Table: kolumny, filtry, akcje wierszy
src/Admin/RegistrationsScreen.php            submenu, ekran szczegółów, pięć handlerów admin-post
event-registration.php                       rejestracja RegistrationsScreen::register na plugins_loaded
```

Granice: akcje zmieniające zajętość żyją w `ReservationService` (transakcja + lock→count).
`RegistrationsListTable` renderuje. `RegistrationsScreen` orkiestruje (submenu, routing, handlery).
SQL zgłoszeń wyłącznie w `RegistrationRepository`. Notatka (bez wpływu na zajętość) idzie z
handlera wprost do `repository->updateNote`. Silnik 3A/4A nietknięty poza dołożeniem metod.

## 5. Serwis: transakcje i inwarianty

Cztery nowe metody w `ReservationService`, każda zwraca `AdminActionResult`.

### `confirmManually( int $id ): AdminActionResult`
Bez transakcji lock→count — `pending`→`confirmed` nie zmienia zajętości (oba stany zajmują miejsce).
`findById` → guard status `pending` (inaczej `invalid_status`; brak wiersza → `not_found`) →
`repository->markConfirmed($id)` (istnieje, ustawia `confirmed_at`). **Nie emituje
`evreg_registration_confirmed`** — bez maila. Zwraca `confirmed`.

### `cancel( int $id ): AdminActionResult`
Anulowanie tylko zwalnia miejsce (zajętość = pending+confirmed liczona świeżo), nigdy nie
przekracza limitu → **nie wymaga lock→count**. Transakcja dla atomowości: `START` → guard status
w {pending, confirmed, waitlist} (inaczej `invalid_status`) → `repository->markCancelled($id)`
(status=cancelled, updated_at) → `repository->deleteAccommodationBooking($id)` → `COMMIT`.
Zwraca `cancelled`. Bez maila.

### `promoteFromWaitlist( int $id ): AdminActionResult`
Jedyna transakcyjnie krytyczna — `waitlist`→`pending` **zwiększa** zajętość, pełny wzorzec `reserve`:

```
START TRANSACTION
  lockEvent( event_id )      -- SELECT … FOR UPDATE; MUSI przed pierwszym COUNT
  guard status == waitlist   -- inaczej ROLLBACK, invalid_status
  occupancy( event_id )      -- świeży COUNT
  decision = CapacityCalculator.decide( limits, occupancy, type_key, selection )
             -- type_key z findById; selection z findAccommodationBooking
  if Rejected: ROLLBACK; return rejected( reason )
  repository->markPending( id, expires_at = +48h )
COMMIT
do_action( 'evreg_registration_reserved', id, event_id, token )   -- mail opt-in z 4A
return promoted
```

Token już istnieje w wierszu. Kolejność `lockEvent` przed `occupancy` — nośny inwariant z 3A,
NIE zmieniać (§8 spec 3A). Hook emitowany po COMMIT.

### `deleteRegistration( int $id ): AdminActionResult`
Twarde, tylko na `cancelled`. Transakcja dla atomowości trzech kasowań: `START` → guard status
`cancelled` (inaczej `invalid_status` — nie kasujemy aktywnych) → `repository->deleteAccommodationBooking($id)`
→ `repository->deleteMailQueueByRegistration($id)` (osierocone wiersze kolejki) →
`repository->hardDelete($id)` → `COMMIT`. Zwraca `deleted`.

### AdminActionResult
Obiekt wartości: `code` (`confirmed|cancelled|promoted|deleted|rejected|invalid_status|not_found`)
i opcjonalny `reason` (kod powodu odrzucenia promocji). Konstruktor prywatny + statyczne fabryki.
Domena/serwis zwraca kod, warstwa admina tłumaczy na komunikat.

### Nowe metody repozytorium (jedyne SQL zgłoszeń)
`paginateRegistrations( array $filters, int $per_page, int $offset ): array`,
`countRegistrations( array $filters ): int`,
`distinctTypeKeys( int $event_id ): array<int,string>`,
`markCancelled( int $id ): void`,
`markPending( int $id, string $expires_at ): void`,
`deleteAccommodationBooking( int $id ): void`,
`deleteMailQueueByRegistration( int $id ): void`,
`hardDelete( int $id ): void`,
`updateNote( int $id, string $note ): void`.
Filtry: `array{ status?: string, type_key?: string, event_id?: int }`; nieznany status/pusty typ/`event_id<=0` ignorowane.
`findById`/`markConfirmed`/`occupancy`/`findAccommodationBooking`/`lockEvent` już istnieją (3A/4A).

## 6. Admin: WP_List_Table

`RegistrationsListTable extends \WP_List_Table` (require `wp-admin/includes/class-wp-list-table.php`
przed deklaracją — jak 4B-Kolejka):

- **Kolumny:** `name`, `email`, `type` (etykieta typu z configu eventu, fallback klucz), `status`
  (etykieta tłumaczona przez helper `status_label` — wzorzec z 4B-Kolejki), `event` (tytuł, fallback `#id`),
  `price_total`, `created_at`.
- **`prepare_items()`:** filtry `status`/`type_key`/`event_id` z żądania (sanityzacja; status whitelist
  z `RegistrationStatus`), `countRegistrations` + `paginateRegistrations`, `ORDER BY id DESC`, 20/stronę.
- **`column_default()`:** escapuje każdą wartość (`esc_html`); `event` z fallbackiem `#<event_id>`;
  `type`/`status` przez etykiety.
- **Akcje wiersza (wg statusu):**
  - `pending` → Podgląd, Potwierdź, Anuluj
  - `confirmed` → Podgląd, Anuluj
  - `waitlist` → Podgląd, Promuj, Anuluj
  - `cancelled` → Podgląd, Usuń trwale
  Każda akcja mutująca to link z nonce na `admin-post.php`.
- **Filtry (`extra_tablenav`):** select statusu (whitelist + „wszystkie"), typu (`distinctTypeKeys` → etykiety),
  eventu (jak 4B-Kolejka). „Filtruj" (GET).
- Bez bulk actions, bez sortowalnych kolumn (YAGNI).

## 7. Admin: ekran szczegółów i handlery

`RegistrationsScreen`:

- `SLUG = 'evreg-registrations'`; stałe akcji `ACTION_CONFIRM/CANCEL/PROMOTE/DELETE/NOTE` (`evreg_reg_confirm` itd.).
- **`register()`:** `admin_menu` → submenu „Zgłoszenia" pod `edit.php?post_type=evreg_event`, cap `Capabilities::CAP`;
  pięć `add_action( 'admin_post_<akcja>', ... )`.
- **`render()`:** cap guard; routing `?action=view` → `render_detail`, else lista. Notice z `?evreg_msg=<kod>`
  mapowanego na tekst i18n (sukces/porażka per kod `AdminActionResult`).
- **`render_detail( int $id )`:** `findById` → read-only: dane systemowe (status jako etykieta, typ, event,
  `price_total`, `created_at`/`confirmed_at`/`expires_at`), **odpowiedzi** (złożona schema przez
  `SchemaAssembler` + `data` wiersza; pola `Etykieta: wartość`, escapowane; nocleg rozbity z bookingu),
  **notatka** (textarea + zapis przez `ACTION_NOTE`). Przyciski akcji wg statusu (nonce). Link „← wróć".
- **Handlery** (`handle_confirm/cancel/promote/delete/note`): każdy `check_admin_referer( ACTION_x . '_' . $id )`
  → `current_user_can( CAP )` → woła `ReservationService::<metoda>` (albo `repository->updateNote` dla notatki)
  → `wp_safe_redirect` na listę (albo szczegóły dla notatki) z `?evreg_msg=<kod>` → `exit`. Kolejność
  nonce→cap→akcja→PRG (jak 4B-Kolejka).
- Handler składa `ReservationService` z `RegistrationRepository` + `EventConfigRepository` (jak dziś, bez DI kontenera).

Rejestracja: `event-registration.php` dokłada `add_action( 'plugins_loaded', [RegistrationsScreen::class, 'register'] )`.

## 8. Testy

### Integration — ReservationService (`tests/Integration/Services/`)
- `confirmManually`: `pending`→`confirmed` + `confirmed_at`; **nie kolejkuje maila** (brak wiersza `confirmed` w `evreg_mail_queue` przy zarejestrowanym Subscriberze); `confirmed`/`waitlist`/`cancelled` → `invalid_status`, wiersz nietknięty
- `cancel`: z `pending`/`confirmed`/`waitlist` → `cancelled`, booking skasowany, miejsce wraca (kolejna `reserve` przechodzi po zapełnieniu-anulowaniu); `cancelled` → `invalid_status`
- `promoteFromWaitlist`: wolne miejsce → `waitlist`→`pending` + `expires_at` + mail opt-in (hook `reserved`); brak miejsc → `rejected`, wiersz `waitlist`; `pending` → `invalid_status`
- **Test współbieżności promocji** (jak `ReservationConcurrencyTest` z 3A): dwie równoległe promocje na ostatnie miejsce → dokładnie jedna `pending`, druga `rejected`
- `deleteRegistration`: `cancelled` → wiersz + booking + wiersze kolejki maili skasowane; aktywny status → `invalid_status`

### Integration — RegistrationRepository
`paginateRegistrations` (filtry status/typ/event, `id DESC`, limit/offset), `countRegistrations`,
`distinctTypeKeys`, `markCancelled`, `markPending`, `deleteAccommodationBooking`,
`deleteMailQueueByRegistration`, `hardDelete`, `updateNote` — round-trip i filtry na realnej bazie.

### Integration — Admin (`tests/Integration/Admin/`)
`RegistrationsListTable::prepare_items` (filtry, wiersze); akcje wiersza wg statusu (Promuj tylko waitlist,
Usuń trwale tylko cancelled); handlery: happy-path (RedirectException) + zły nonce/brak cap (`WPDieException`) —
wzorzec z 4B-Kolejki.

### E2E (Playwright, opcjonalnie)
Wejście na „Zgłoszenia", filtr, podgląd, potwierdzenie/anulowanie. Jeśli CI niestabilny — jak istniejące e2e.

### Regresja
Cała suita 3A/4A zielona (`reserve`, `confirm`, dispatcher, współbieżność) — istniejące ścieżki nietknięte.

Weryfikacja w przeglądarce: kontroler (człowiek), subagent pomija.

## 9. Bezpieczeństwo

- `current_user_can( Capabilities::CAP )` (`edit_evreg_events`) na renderze i każdym z pięciu handlerów
- Nonce (`check_admin_referer`) per akcja per id; PRG (`wp_safe_redirect` + `exit`) — brak powtórki przy odświeżeniu
- `promoteFromWaitlist` chroni limity inwariantem lock→count — wyścigowy/podrobiony request nie przekroczy puli
- `deleteRegistration` guard status `cancelled` w serwisie — nie kasuje aktywnych nawet przy podrobionym id
- `confirmManually`/`cancel` guardy statusu w serwisie
- Odpowiedzi uczestnika i notatka renderowane przez `esc_html`; notatka `sanitize_textarea_field` przy zapisie
- SQL zgłoszeń tylko w `RegistrationRepository` przez `$wpdb->prepare`; `id`/`event_id` int; status filtra whitelist
- `defined( 'ABSPATH' ) || exit;` w każdym nowym pliku PHP

## 10. Ryzyka

| Ryzyko | Skutek | Odpowiedź |
|---|---|---|
| Promocja emituje `reserved` → mail opt-in, uczestnik musi potwierdzić ponownie | Organizator oczekujący „promocja = potwierdzony" zaskoczony | Świadoma decyzja (trzyma double opt-in); udokumentowane; zmiana na `confirmed` to przyszłe rozszerzenie bez zmiany modelu |
| Wyścig promocji o ostatnie miejsce | Przekroczenie limitu | `lockEvent` przed `occupancy` (inwariant 3A); test współbieżności dowodzi serializacji |
| Twarde usunięcie zostawia osierocone wiersze kolejki maili | Wiersze `evreg_mail_queue` bez zgłoszenia | `deleteMailQueueByRegistration` w tej samej transakcji |
| Ręczne potwierdzenie emitujące hook wysłałoby mail „confirmed" | Podwójny/niechciany mail | `confirmManually` NIE emituje hooka — świadomie, test pilnuje braku wiersza kolejki |
| Tytuł/typ eventu usuniętego z bazy | Puste etykiety | Fallback `#<event_id>` / sam klucz typu |
