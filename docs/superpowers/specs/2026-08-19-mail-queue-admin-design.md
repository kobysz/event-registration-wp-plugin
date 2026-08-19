# Ekran kolejki mailowej (Plan 4B-Kolejka) — projekt techniczny

Data: 2026-08-19
Status: zatwierdzony do planowania implementacji
Buduje na: [Plan 4A — kolejka mailowa](2026-08-19-mail-queue-design.md) (`MailQueueRepository`, tabela `evreg_mail_queue`, dispatcher `evreg_dispatch_mail`), [Plan 2A](2026-08-18-event-config-admin-design.md) (`Capabilities`, `EventPostType`)

## 1. Cel i kontekst

Organizator dostaje ekran admina do diagnostyki wysyłki: lista wierszy `evreg_mail_queue`
z filtrami po statusie i evencie, podgląd pełnej treści maila, ręczne wznowienie wierszy
`failed`. Plan 4A zbudował silnik kolejki (kolejkowanie, dispatcher, retry, purge), ale kolejka
jest niewidoczna — nie da się sprawdzić, dlaczego mail nie poszedł, ani ponowić nieudanej wysyłki.

Ekran to `WP_List_Table` pod submenu menu CPT `evreg_event`. Reszta admina jest React+REST
(edytor configu), ale lista logu z paginacją, filtrami i akcjami wierszy jest idiomatyczna jako
`WP_List_Table` — serwerowy render, zero webpacka, akcje przez admin-post. Ten wybór ustala też
konwencję dla Planu 5 (panel zgłoszeń).

To ostatni kawałek Planu 4B; edytor szablonów (4B-Szablony) jest już scalony.

## 2. Zakres

### v1 (Plan 4B-Kolejka)
- `MailQueueRepository` + `paginate()`, `countByFilter()`, `requeueFailed()`, `distinctEventIds()`
- `MailQueueListTable` — `WP_List_Table`: kolumny, filtry statusu/eventu, akcje wierszy
- `MailQueueScreen` — submenu, render listy, ekran szczegółów (`?action=view`), handler wznowienia
- Rejestracja w `event-registration.php` (`admin_menu` + `admin_post_*`)

### Poza Planem 4B-Kolejka
- Bulk actions (masowe wznawianie) → YAGNI, pojedyncze wystarcza
- Kasowanie wierszy z UI → purge robi cron z 4A (30 dni)
- Ponowna wysyłka `sent` (dubel do uczestnika) → świadomie odrzucone (patrz §3 #3)
- Sortowanie po dowolnej kolumnie, wyszukiwanie tekstowe → YAGNI dla logu; `id DESC` + filtry wystarczą
- Ekran React → wybrano `WP_List_Table`

### Świadomie pominięte (YAGNI)
- Keyset pagination — `OFFSET` wystarcza przy tej skali (patrz §10)
- Podgląd renderu HTML — maile są plain text (4A)
- Powiadomienia/alerty o wierszach `failed` — diagnostyka na żądanie, nie push

## 3. Decyzje projektowe

| # | Decyzja | Wybór | Uzasadnienie |
|---|---------|-------|--------------|
| 1 | Technologia UI | `WP_List_Table` | Paginacja/filtry/akcje za darmo; zero webpacka; idiomatyczne dla logu; wzorzec dla Planu 5 |
| 2 | Umiejscowienie i zakres | Submenu globalne pod menu CPT | Diagnostyka „dlaczego mail nie poszedł" bez skakania po eventach |
| 3 | Semantyka wznowienia | Tylko `failed`, `attempts=0` | `sent`/`queued`/`sending` nie mają czego wznawiać; reset prób = świeże 3 próby; ponowna wysyłka `sent` groziłaby dublem do uczestnika |
| 4 | Podgląd treści | Osobny ekran szczegółów (`?action=view`) | Prosto, read-only, bez JS; treść maila bywa długa i psułaby layout listy |
| 5 | Uprawnienia | Istniejący `edit_evreg_events` | Kto konfiguruje eventy, ten diagnozuje maile; zero nowych capów i migracji |

## 4. Architektura

```
src/Persistence/
  MailQueueRepository.php    + paginate(), countByFilter(), requeueFailed(), distinctEventIds()
                             (jedyne miejsce z SQL kolejki — inwariant z 4A)

src/Admin/
  MailQueueListTable.php     WP_List_Table: kolumny, filtry statusu/eventu, akcje wierszy
  MailQueueScreen.php        submenu, render listy, ekran szczegółów, handler wznowienia (admin-post)

event-registration.php      rejestracja MailQueueScreen::register na plugins_loaded
```

Granice: `MailQueueListTable` tylko renderuje (wiersze z repozytorium, formatowanie kolumn,
escaping). `MailQueueScreen` orkiestruje (submenu, ładowanie danych, routing `view`/`requeue`,
redirecty). `MailQueueRepository` jest jedynym miejscem z SQL kolejki — dokładamy zapytania,
nie piszemy SQL w warstwie admina. Silnik 4A (dispatcher, retry, purge) nietknięty.

## 5. Persistence: nowe zapytania

Cztery metody w `MailQueueRepository`. Filtry to `array{ status?: string, event_id?: int }` —
puste/nieznane klucze ignorowane; status walidowany względem whitelisty `STATUS_QUEUED|SENDING|SENT|FAILED`.

### `paginate( array $filters, int $per_page, int $offset ): array<int,array<string,mixed>>`
- `WHERE` składane warunkowo: `status = %s` gdy podany i w whiteliście; `event_id = %d` gdy podany > 0. Brak filtrów → wszystkie wiersze.
- `ORDER BY id DESC` (najnowsze na górze), `LIMIT %d OFFSET %d`.
- Wszystko przez `$wpdb->prepare`; klauzule z tablicy warunków + tablicy argumentów (wzorzec dynamicznego zapytania jak `expirePending` w 4A).

### `countByFilter( array $filters ): int`
- `SELECT COUNT(*)` z tym samym `WHERE` co `paginate`. Zasila paginację `WP_List_Table`.

### `requeueFailed( int $id ): bool`
```sql
UPDATE {mail_queue}
SET status = 'queued', attempts = 0, scheduled_at = <teraz UTC>, last_error = NULL, sent_at = NULL
WHERE id = %d AND status = 'failed'
```
- Warunek `status = 'failed'` w `WHERE`, nie w adminie — podrobione `id` nie wskrzesi `sent`/`queued`/`sending`.
- Zwraca `true`, gdy dokładnie jeden wiersz zmieniony (`$wpdb->query` → affected === 1), inaczej `false`.
- `scheduled_at = current_time( 'mysql', true )` (UTC). Dispatcher z 4A złapie w następnym przebiegu — admin nie woła dispatchera, tylko resetuje wiersz.

### `distinctEventIds(): array<int,int>`
- `SELECT DISTINCT event_id` z kolejki — zasila dropdown filtra eventów (tylko eventy obecne w kolejce). Tytuły dokłada warstwa admina przez `get_the_title()`.

Odczyt szczegółów używa istniejącego `find( int $id ): ?array` z 4A — bez nowej metody.

## 6. Admin: WP_List_Table

`MailQueueListTable extends \WP_List_Table`:

- **Kolumny:** `status` (etykieta tłumaczona), `template_key`, `recipient`, `event` (tytuł z `get_the_title( event_id )`, fallback `#<event_id>`), `attempts`, `scheduled_at`, `sent_at`.
- **`prepare_items()`:** czyta `status`/`event_id`/`paged` z żądania (sanityzowane, `int` gdzie liczba, whitelist statusu), woła `countByFilter` + `paginate`, `set_pagination_args( total, per_page=20 )`.
- **`column_default()`:** escapuje każdą wartość (`esc_html`). `last_error` NIE w liście (pełny na ekranie szczegółów).
- **Akcje wiersza (`handle_row_actions`):** zawsze „Podgląd" (link `?page=evreg-mail-queue&action=view&id=N`); „Wznów" tylko dla `status = 'failed'` (link z nonce na `admin-post.php?action=evreg_requeue_mail`). Brak bulk actions w v1.
- **Filtry (`extra_tablenav`):** dwa `<select>` — status (whitelist `STATUS_*` + „wszystkie") i event (`distinctEventIds()` → tytuły) — plus „Filtruj" (GET, ta sama strona). Sortowanie domyślne `id DESC`, bez sortowalnych kolumn w v1.

## 7. Admin: ekran, submenu, akcje

`MailQueueScreen`:

- **`register()`:** `add_action( 'admin_menu', ... )` → `add_submenu_page( 'edit.php?post_type=evreg_event', 'Kolejka maili', 'Kolejka maili', Capabilities::CAP, 'evreg-mail-queue', [self, 'render'] )`; `add_action( 'admin_post_evreg_requeue_mail', [self, 'handle_requeue'] )`.
- **`render()`:** guard `current_user_can( Capabilities::CAP )`; routing po `$_GET['action']` — `view` → `render_detail()`, domyślnie lista. Nagłówek, formularz filtrów, `MailQueueListTable->prepare_items()` + `->display()`. Komunikat sukcesu/porażki wznowienia z `?evreg_requeued=1|0`.
- **`render_detail( int $id )`:** `find( $id )`; brak → notice „nie znaleziono"; jest → read-only ekran: status, recipient, event, template_key, attempts, scheduled_at, sent_at, headers, `last_error`, temat, treść (`<pre>` z `esc_html` — plain text). Link „← wróć do kolejki". Gdy `failed` — przycisk „Wznów" (nonce).
- **`handle_requeue()`:** `check_admin_referer` + `current_user_can( Capabilities::CAP )`; `requeueFailed( (int) $_POST['id'] )`; `wp_safe_redirect` na listę z `?evreg_requeued=1|0`, `exit`. PRG — brak powtórnej akcji przy odświeżeniu.

Rejestracja: `event-registration.php` dokłada `add_action( 'plugins_loaded', [MailQueueScreen::class, 'register'] )`.

## 8. Testy

### Integration (wp-env, `tests/Integration/Persistence/`)
- `paginate`: filtr statusu zwraca tylko ten status; `event_id` tylko ten event; oba naraz; brak filtrów = wszystkie; `ORDER BY id DESC`; `LIMIT`/`OFFSET` (strona 2)
- `countByFilter`: zgodne z `paginate` bez limitu; z filtrami
- `requeueFailed`: `failed` → `queued`, `attempts=0`, `last_error`/`sent_at` NULL, `scheduled_at` ustawione, zwraca `true`; `sent`/`queued`/`sending` nietknięte, zwraca `false`; nieistniejące `id` → `false`
- `distinctEventIds`: unikalne `event_id` bez duplikatów
- nieznany status w filtrze ignorowany (whitelist `STATUS_*`)

### Integration (`tests/Integration/Admin/`)
- `MailQueueScreen::handle_requeue`: poprawny nonce + cap → wiersz wznowiony + redirect; brak cap → 403/`wp_die`; zły nonce → odrzucone. Przez podstawienie `$_POST`/`$_REQUEST` i przechwycenie redirectu.
- `WP_List_Table` (render) — bez testów jednostkowych (render/escaping w przeglądarce; logika danych pokryta w repozytorium)

### E2E (Playwright, opcjonalnie)
Wejście na „Kolejka maili", filtr statusu, „Podgląd" wiersza, „Wznów" na `failed` → powrót na listę ze statusem `queued`. Jeśli CI niestabilny — jak istniejące e2e; utwardzenie CI to Plan 6.

### Regresja
Cała suita integracyjna kolejki z 4A zielona (dispatcher/retry/purge nietknięte).

Weryfikacja w przeglądarce: kontroler (człowiek), subagent pomija.

## 9. Bezpieczeństwo

- `current_user_can( Capabilities::CAP )` na renderze ekranu i w `handle_requeue`
- `check_admin_referer` (nonce) na wznowieniu; PRG (`wp_safe_redirect` + `exit`) — brak powtórnej akcji przy odświeżeniu
- Treść maila i `last_error` przez `esc_html`; linki `esc_url`; `id`/`event_id`/`paged` rzutowane na `int`; status filtra walidowany względem whitelisty
- `requeueFailed` chroni się warunkiem `WHERE status = 'failed'` — podrobione `id` nie wskrzesi `sent`/`sending`
- Wszystkie zapytania przez `$wpdb->prepare`; SQL tylko w `MailQueueRepository`
- `defined( 'ABSPATH' ) || exit;` w każdym nowym pliku PHP

## 10. Ryzyka

| Ryzyko | Skutek | Odpowiedź |
|---|---|---|
| Globalna lista + `OFFSET` na wysokich stronach | Wolne zapytanie przy dużej kolejce | Skala wewnętrzna (setki wierszy/event, purge po 30 dniach) — udokumentowane, nie optymalizowane; keyset pagination gdyby zaszła potrzeba |
| Podrobione `id` w akcji wznowienia | Próba wskrzeszenia `sent`/`sending` | Warunek `WHERE status='failed'` — baza odrzuca; nonce + cap dodatkowo |
| Tytuł eventu usuniętego z bazy | `get_the_title()` pusty | Fallback `#<event_id>` w kolumnie i szczegółach |
| Wznowienie wiersza, który cron właśnie przejął | Wyścig admin vs dispatcher | `requeueFailed` działa tylko na `failed`; dispatcher przejmuje tylko `queued` warunkowym UPDATE-em (4A) — stany rozłączne, brak kolizji |
