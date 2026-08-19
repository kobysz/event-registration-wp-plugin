# Kolejka mailowa (Plan 4A) — projekt techniczny

Data: 2026-08-19
Status: zatwierdzony do planowania implementacji
Buduje na: [Plan 3A — backend rezerwacji](2026-08-18-reservation-backend-design.md) (`ReservationService`, `ExpirePending`, `RegistrationRepository`), [Plan 2A](2026-08-18-event-config-admin-design.md) (`EventConfigRepository`, `SchemaAssembler`), [Plan 3B](2026-08-18-public-form-design.md) (`ConfirmationController`, token potwierdzenia)

## 1. Cel i kontekst

Silnik kolejki mailowej i wpięcie pięciu maili transakcyjnych w istniejący cykl życia
zgłoszenia. Po tym planie double opt-in działa końcem w koniec: uczestnik dostaje maila
z linkiem, którego endpoint (Plan 3B) już obsługuje, a organizator dostaje powiadomienie
o każdym nowym zgłoszeniu.

Wysyłka nigdy nie jest synchroniczna względem submisji — mail trafia do kolejki, użytkownik
dostaje odpowiedź natychmiast. Dispatcher pracuje z crona z ponawianiem i logiem błędów.

Edytor szablonów per event (zakładka React, meta `_evreg_mail_templates`) oraz ekran kolejki
w adminie (podgląd treści, ręczne wznowienie) to **Plan 4B**. Tutaj szablony są czytane
z meta, ale zapisywać je może wyłącznie kod — UI nie istnieje, działa fallback na domyślne
teksty wtyczki. Mail `bulk` i promocja z listy rezerwowej to **Plan 5**.

## 2. Zakres

### v1 (Plan 4A)
- `Domain/Mail/` — `TemplateRenderer`, `Placeholders`, `SummaryBuilder`, `RetryPolicy` (czyste, bez WP)
- `Mail/` — `DefaultTemplates`, `TemplateResolver`, `PlaceholderFactory`, `MailQueue`, `Dispatcher`, `Subscriber`
- `MailQueueRepository` — jedyne `$wpdb` dla kolejki
- Migracja `DB_VERSION` 2 → 3: kolumna `headers` + `UNIQUE KEY uniq_registration_template (registration_id,template_key)`
- Hooki cyklu życia w `ReservationService` i `ExpirePending`; `expirePending()` zwraca wygasłe wiersze
- Cron `evreg_dispatch_mail` (co minutę + natychmiastowy strzał) i `evreg_purge_mail_queue` (dziennie)
- Pięć maili: `optin`, `confirmed`, `waitlist`, `expired`, `admin_new`

### Poza Planem 4A
- Edytor szablonów per event (5. zakładka React, zapis `_evreg_mail_templates`) → Plan 4B
- Ekran kolejki w adminie: lista, podgląd treści, ręczne wznowienie → Plan 4B
- Pola `form_page_id` i `notify_emails` w UI ustawień → Plan 4B (w 4A czytane, z fallbackiem)
- Mail `bulk` (ręczna wysyłka do wybranych) → Plan 5
- Promocja z listy rezerwowej i jej mail → Plan 5
- Maile HTML — v1 wysyła plain text

### Świadomie pominięte (YAGNI)
- Śledzenie bounce'ów i otwarć — wymaga API dostawcy, poza modelem `wp_mail`
- Własny transport SMTP — kompatybilność z wtyczką SMTP zainstalowaną na stronie jest celem
- Priorytety w kolejce — przy tej skali kolejność `scheduled_at` wystarcza

## 3. Decyzje projektowe

| # | Decyzja | Wybór | Uzasadnienie |
|---|---------|-------|--------------|
| 1 | Podział planu | 4A silnik, 4B UI | Każdy wykonalny w jednym cyklu, jak 3A/3B |
| 2 | Wyzwalanie maili | Hooki `do_action` po COMMIT | `ReservationService` nie wie o mailach; mail nigdy nie wywróci transakcji |
| 3 | Moment wysyłki | Cron co minutę + `wp_schedule_single_event(time())` po zakolejkowaniu | Opt-in wychodzi w sekundy, cron stały łapie retry i nieudany spawn |
| 4 | Format | Plain text | Zero escapowania, zero testów w klientach pocztowych, textarea w 4B |
| 5 | Retencja | Kasowanie `sent` po 30 dniach | Log wysyłki ma wartość diagnostyczną tylko świeży |
| 6 | Idempotencja | `UNIQUE (registration_id, template_key)` | Gwarancja w bazie, nie w dyscyplinie wołających |
| 7 | Treść maila | Snapshot renderowany przy kolejkowaniu | Edycja szablonu nie zmienia tego, co czeka; dispatcher potrzebuje tylko wiersza |
| 8 | Claim wiersza | Warunkowy `UPDATE ... WHERE status='queued'` | Dwa crony nie wyślą dubla, bez trzymania blokady przez czas SMTP |

## 4. Architektura

```
src/Domain/Mail/           czyste PHP, zero WP
  TemplateRenderer.php     podstawianie placeholderów w temacie i treści
  Placeholders.php         zbiór {klucz => wartość}
  SummaryBuilder.php       FormSchema + odpowiedzi → tekst {podsumowanie}
  RetryPolicy.php          attempts → następny termin | failed

src/Mail/                  adapter WordPressa
  DefaultTemplates.php     domyślne teksty przez __()
  TemplateResolver.php     _evreg_mail_templates → szablon, fallback na default
  PlaceholderFactory.php   zgłoszenie + event + config → Placeholders
  MailQueue.php            enqueue(): render snapshot → INSERT idempotentny
  Dispatcher.php           odzysk → claim → wp_mail → sent | retry | failed
  Subscriber.php           hooki cyklu życia → MailQueue

src/Persistence/
  MailQueueRepository.php  jedyne $wpdb dla kolejki

src/Cron/
  DispatchMail.php         co minutę + single event po zakolejkowaniu
  PurgeMailQueue.php       dziennie
```

Inwariant zachowany: podstawianie placeholderów, budowa podsumowania i polityka ponawiania
to czyste PHP testowane bez WordPressa. `DefaultTemplates` **musi** siedzieć w `src/Mail/`,
nie w `Domain/` — `__()` to funkcja WordPressa i `DomainPurityTest` ją odrzuci.

### Hooki cyklu życia

Emitowane **po COMMIT**, nigdy wewnątrz transakcji.

| Hook | Argumenty | Emiter |
|---|---|---|
| `evreg_registration_reserved` | `$registration_id, $event_id, $token` | `ReservationService::reserve` (pending) |
| `evreg_registration_waitlisted` | `$registration_id, $event_id` | `ReservationService::reserve` |
| `evreg_registration_confirmed` | `$registration_id, $event_id` | `ReservationService::confirm` |
| `evreg_registration_expired` | `$registration_id, $event_id` | `ExpirePending`, jeden na wygasłe zgłoszenie |

Istniejący `evreg_pending_expired` (licznik) zostaje bez zmian — 4A dokłada hook per wiersz obok niego.

`Subscriber` mapuje zdarzenia na maile:

| Zdarzenie | Maile |
|---|---|
| `reserved` | `optin` (uczestnik), `admin_new` (organizator) |
| `waitlisted` | `waitlist` (uczestnik), `admin_new` (organizator) |
| `confirmed` | `confirmed` (uczestnik) |
| `expired` | `expired` (uczestnik) |

### Zmiana w Planie 3A

`RegistrationRepository::expirePending()` zwraca dziś sam licznik. Musi zwrócić wygasłe wiersze
(`id`, `event_id`), żeby cron mógł wyemitować hook per zgłoszenie: SELECT kandydatów →
`UPDATE ... WHERE id IN (...) AND status='pending'` → hook dla każdego kandydata.

Bez dodatkowego lockowania. Gdyby dwa przebiegi crona nałożyły się na tym samym wierszu
i oba wyemitowały hook, `UNIQUE (registration_id, template_key)` zablokuje drugi wiersz maila.
Ta gwarancja jest **powodem**, dla którego kandydaci nie wymagają `SELECT ... FOR UPDATE` —
a to z kolei trzyma cron wygasania z dala od blokad, które `ReservationService` bierze
w ustalonej kolejności (patrz Plan 3A, §8).

## 5. Model danych

Tabela `evreg_mail_queue` istnieje od Planu 1 (utworzona, nigdy nieużywana). Migracja
`DB_VERSION` 2 → 3 dokłada indeks i kolumnę:

```sql
headers text NULL
UNIQUE KEY uniq_registration_template (registration_id,template_key)
```

`headers` trzyma nagłówki maila jako JSON tablicy linii (`["Reply-To: jan@example.com"]`).
Bez tej kolumny `Reply-To` na powiadomieniu organizatora nie przetrwałby do momentu wysyłki —
dispatcher czyta wyłącznie wiersz kolejki, nigdy zgłoszenia. Puste dla maili bez nagłówków.

`dbDelta` doda jedno i drugie bez skryptu czyszczącego — kolejka nigdy nie działała, więc tabela
jest pusta na każdej istniejącej instalacji.

`registration_id` pozostaje `NULL`-owalne. MySQL traktuje `NULL` w indeksie unikalnym jako
zawsze różny, więc przyszły `bulk` (Plan 5) sam z siebie wypada spod gwarancji unikalności,
a maile transakcyjne są nią objęte.

`admin_new` idzie do wielu adresów, a każdy adres to osobny wiersz z tym samym
`registration_id`. Żeby indeks ich nie zablokował, klucz zapisuje się jako
`admin_new:<md5(adres)>`. `template_key` ma 64 znaki — `admin_new:` plus 32 znaki md5
mieści się z zapasem. `TemplateResolver` przy rozstrzyganiu obcina wszystko od dwukropka,
więc wszystkie warianty trafiają na ten sam szablon.

### Stany wiersza kolejki

```
enqueue → queued (scheduled_at = teraz)
queued  → sending   claim: UPDATE ... SET status='sending', attempts=attempts+1,
                    scheduled_at=teraz WHERE id=? AND status='queued';
                    affected=1 znaczy „mój wiersz”
sending → sent      wp_mail zwróciło true, sent_at = teraz
sending → queued    porażka, attempts < 3, scheduled_at = teraz + 1 min | 5 min, last_error
sending → failed    porażka przy attempts = 3, last_error zapisany
```

Claim nadpisuje `scheduled_at` chwilą przejęcia — dzięki temu wiek wiersza `sending` liczy się
od momentu wysyłki, nie od zakolejkowania. Wiersz osierocony w `sending` (fatal error w trakcie
`wp_mail`) wraca do `queued`, gdy jego `scheduled_at` jest starszy niż 5 minut; odzysk wykonuje
ten sam cron na starcie przebiegu.

Wysyłka nigdy nie modyfikuje `subject` ani `body`; treść jest snapshotem z chwili kolejkowania.

## 6. Domain/Mail (czysta domena)

**`TemplateRenderer::render(string $template, Placeholders $values): string`**
Podmienia wyłącznie znane klucze. Nierozpoznane `{cokolwiek}` zostaje w treści dosłownie —
organizator widzi literówkę w szablonie zamiast pustki w mailu. Podmiana jest jednoprzebiegowa:
wartość zawierająca `{...}` nie jest rozwijana ponownie.

**`Placeholders`** — niemutowalny zbiór `klucz => string`. Klucze bez klamr (`imie`, nie `{imie}`).

**`SummaryBuilder::build(FormSchema $schema, array $data): string`**
Linia `Etykieta: wartość` na pole. Pomija pola ukryte warunkowo (ta sama ewaluacja co przy
walidacji — `ConditionEngine`), pola puste oraz pole wbudowane `__type` (jest osobnym
placeholderem `{typ}`). Wartości tablicowe (checkbox wielokrotny) łączy przecinkiem.

**`RetryPolicy::next(int $attempts): ?int`**
Zwraca opóźnienie w sekundach do następnej próby albo `null`, gdy próby się wyczerpały:
1 → 60, 2 → 300, 3 → `null` (`failed`). Maksymalnie trzy próby.

Specyfikacja główna wymieniała opóźnienia „1 min, 5 min, 30 min” przy maksymalnie trzech
próbach — to cztery kroki na trzy próby. Przycięte do dwóch opóźnień; liczba prób jest wiążąca.

## 7. Mail (adapter WordPressa)

### TemplateResolver

`resolve(int $event_id, string $template_key): array{subject: string, body: string}`

Czyta `_evreg_mail_templates[$key]`; brakujące albo puste pole spada na
`DefaultTemplates::get($key)`. Rozstrzyganie jest **per pole** — event może nadpisać sam
temat i zostać przy domyślnej treści. Klucz obcinany do części przed dwukropkiem
(`admin_new:<hash>` → `admin_new`).

### DefaultTemplates

Pięć par temat/treść (`optin`, `confirmed`, `waitlist`, `expired`, `admin_new`) jako
literały przez `__()` z text domain `event-registration`. Teksty krótkie, plain text,
link jako goły URL w osobnej linii.

### PlaceholderFactory

| Placeholder | Źródło |
|---|---|
| `{imie}` | `registrations.name` |
| `{email}` | `registrations.email` |
| `{event}` | `get_the_title($event_id)` |
| `{typ}` | etykieta z `_evreg_types` po `type_key`; fallback: sam klucz |
| `{nocleg}` | pakiet + typ pokoju z `evreg_accommodation_bookings`; pusty string, gdy brak rezerwacji |
| `{link_potwierdzenia}` | adres strony formularza + `?evreg_confirm=<token>` |
| `{podsumowanie}` | `SummaryBuilder` na złożonej schemie (`SchemaAssembler`) i `registrations.data` |

**Adres strony formularza.** Shortcode może stać na dowolnej stronie, więc nie ma
jednoznacznego adresu formularza. Kolejność: `_evreg_settings['form_page_id']` → permalink
tej strony; brak klucza → permalink samego eventu CPT. Oba warianty potwierdzają poprawnie,
bo `ConfirmationController` działa na `template_redirect` niezależnie od tego, która to strona.
Pole w UI dochodzi w 4B.

### MailQueue

`enqueue(string $template_key, int $event_id, ?int $registration_id, string $recipient, Placeholders $values): void`

Rozstrzyga szablon, renderuje temat i treść, wstawia wiersz `queued` ze `scheduled_at = teraz`.
Insert idempotentny: kolizja z indeksem unikalnym jest normalnym wynikiem, nie błędem —
wiersz już czeka albo już poszedł. Po udanym wstawieniu maila do uczestnika planuje
`wp_schedule_single_event(time(), 'evreg_dispatch_mail')`.

### Subscriber

Rejestruje cztery `add_action` na hooki z §4. Każdy handler wczytuje zgłoszenie po id,
buduje `Placeholders` przez `PlaceholderFactory` i woła `MailQueue::enqueue`.
`admin_new` iteruje po adresach organizatora: `_evreg_settings['notify_emails']` (tablica),
pusta → `get_option('admin_email')`. Mail do organizatora dostaje nagłówek `Reply-To`
na adres uczestnika.

### Dispatcher

Przebieg:

1. Odzysk: `sending` ze `scheduled_at` starszym niż 5 minut → `queued`.
2. Pobranie do 20 wierszy `queued` ze `scheduled_at <= teraz`, `ORDER BY scheduled_at`.
   Rozmiar batcha przez filtr `evreg_mail_batch_size`.
3. Na wiersz: claim — `UPDATE ... SET status='sending', attempts=attempts+1, scheduled_at=teraz
   WHERE id=? AND status='queued'`. `affected <> 1` znaczy, że wiersz wziął ktoś inny;
   pomiń bez błędu.
4. `wp_mail($recipient, $subject, $body, $headers)` — nagłówki dekodowane z kolumny `headers`.
5. `true` → `sent` + `sent_at`. `false` albo wyjątek → `RetryPolicy::next(attempts)`:
   liczba → `queued` z nowym `scheduled_at`; `null` → `failed`. W obu razach `last_error`.

**Powód porażki.** `wp_mail` zwraca `false` bez wyjaśnienia. Na czas wysyłki dispatcher
podpina `wp_mail_failed` i bierze komunikat z `WP_Error`; przy `false` bez zdarzenia zapisuje
komunikat generyczny. Wyjątek rzucony przez wtyczkę SMTP jest łapany i traktowany jak porażka —
jeden zły wiersz nie może przerwać przebiegu crona.

## 8. Cron

Interwał `evreg_1min` dokładany do filtra `cron_schedules` obok istniejącego `evreg_15min`.
Filtr **musi** być zarejestrowany przed planowaniem zadań przy aktywacji — ta sama pułapka,
która wywróciła aktywację w Planie 3A.

| Hook | Rytm | Robota |
|---|---|---|
| `evreg_dispatch_mail` | co minutę + `wp_schedule_single_event(time())` po zakolejkowaniu maila uczestnika | odzysk osieroconych, wysyłka batcha |
| `evreg_expire_pending` | istniejący, co 15 min | bez zmian poza zwracaniem wygasłych wierszy |
| `evreg_purge_mail_queue` | dziennie | `DELETE` wierszy `sent` z `sent_at` starszym niż 30 dni |

Purge nie tyka `failed` ani `queued`. Deaktywacja wtyczki odplanowuje wszystkie trzy zadania.

WP-Cron na stronie bez ruchu nie odpali — dokumentacja wdrożeniowa (Plan 6) opisuje
wyłączenie WP-Cron i podpięcie crona systemowego. To znane ograniczenie, nie usterka.

## 9. Bezpieczeństwo

- Adresy odbiorców przez `sanitize_email` przed wstawieniem do kolejki; pusty po sanityzacji = brak wiersza
- Treść plain text — brak escapowania HTML, brak wektora XSS w mailu
- `{link_potwierdzenia}` budowany przez `add_query_arg` na permalinku z bazy, nigdy z żądania
- Token nigdy nie trafia do logu błędów ani do `last_error`
- Wszystkie zapytania przez `$wpdb->prepare`; `MailQueueRepository` jest jedynym miejscem z SQL
- Guard `defined('ABSPATH')` w każdym pliku poza `Domain/`
- Cron nie ma wejścia użytkownika — brak nonce'ów i sprawdzeń uprawnień do dodania w 4A

## 10. Testy

### Unit (bez WordPressa)
- `TemplateRenderer`: podmiana znanych kluczy; nieznany `{klucz}` zostaje dosłownie; wartość z klamrami nie jest rozwijana ponownie
- `RetryPolicy`: 1 → 60, 2 → 300, 3 → `null`
- `SummaryBuilder`: pomija pola ukryte warunkowo, puste i `__type`; łączy wartości tablicowe
- `Placeholders`: niemutowalność, brak klucza

### Integration (wp-env)
- Migracja do wersji 3 tworzy indeks unikalny
- Podwójny `enqueue` tego samego `(registration_id, template_key)` daje jeden wiersz
- Dwa `admin_new` na różne adresy dają dwa wiersze
- Claim: drugi warunkowy UPDATE na tym samym wierszu nie łapie nic
- Dispatcher: sukces → `sent`; porażka → `queued` ze `scheduled_at` w przyszłości; trzecia porażka → `failed` z `last_error`
- Odzysk: `sending` starsze niż 5 minut wraca do `queued`
- Purge kasuje wyłącznie `sent` starsze niż 30 dni
- `ReservationService::reserve` → wiersze `optin` i `admin_new`; wariant waitlist → `waitlist` i `admin_new`
- `ReservationService::confirm` → wiersz `confirmed`
- `ExpirePending` → wiersz `expired` na każde wygasłe zgłoszenie
- `{link_potwierdzenia}` w treści zawiera `?evreg_confirm=` i token zgłoszenia

Wysyłka podmieniana filtrem `pre_wp_mail` — żaden test nie dotyka SMTP.

### E2E
Brak nowych. Kolejka jest niewidoczna w przeglądarce aż do Planu 4B.

`DomainPurityTest` pilnuje, że `Domain/Mail/` nie wciągnie WordPressa.

## 11. Podział na taski (wysokopoziomowo)

1. `Domain/Mail/`: `Placeholders`, `TemplateRenderer`, `RetryPolicy` + unit
2. `Domain/Mail/SummaryBuilder` + unit
3. Migracja `DB_VERSION` 2 → 3 i `MailQueueRepository` + integration
4. `DefaultTemplates`, `TemplateResolver`, `PlaceholderFactory` + integration
5. `MailQueue` (enqueue idempotentny, snapshot, natychmiastowy strzał) + integration
6. `Dispatcher` (odzysk, claim, retry, `wp_mail_failed`) + integration
7. Hooki w `ReservationService`; `expirePending()` zwraca wiersze; hook w `ExpirePending`
8. `Subscriber` + integration przez `ReservationService` i cron wygasania
9. `DispatchMail` i `PurgeMailQueue`: rejestracja interwału, planowanie przy aktywacji, odplanowanie przy deaktywacji

## 12. Ryzyka

| Ryzyko | Skutek | Odpowiedź |
|---|---|---|
| `wp_mail` zwraca `true`, dostawca odbija maila | Kolejka pokazuje `sent`, uczestnik nic nie dostał | Bounce'ów nie da się śledzić bez API dostawcy — udokumentowane; ścieżką ratunkową jest ręczne potwierdzenie zgłoszenia (Plan 5) |
| WP-Cron martwy na stronie bez ruchu | Maile stoją w kolejce | Natychmiastowy strzał po submisji ratuje typowy przypadek; cron systemowy w dokumentacji wdrożeniowej |
| Wolny SMTP zjada limit czasu przebiegu | Batch nie kończy się w całości | Batch 20, każdy wiersz claimowany osobno — niedokończone wiersze zostają `queued` i wchodzą do następnego przebiegu; osierocone `sending` odzyskiwane po 5 minutach |
| `sanitize_text_field` w `EventConfigController::sanitize()` zjada znaki nowej linii | Treści szablonów zapisane przez REST straciłyby formatowanie | W 4A meta jest tylko czytana. **Plan 4B musi dodać wyjątek** na `_evreg_mail_templates` (`sanitize_textarea_field`) |
| Podwójna emisja hooka cyklu życia | Dubel maila do uczestnika | `UNIQUE (registration_id, template_key)` — dubel odbija się na poziomie bazy |
