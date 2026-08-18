# Event Registration — projekt techniczny

Data: 2026-08-18
Status: zatwierdzony do planowania implementacji

## 1. Cel i kontekst

Wtyczka WordPress do budowania formularzy rejestracji na wydarzenia. Zbiera zgłoszenia,
obsługuje limity miejsc, noclegi, potwierdzenia mailowe i eksport listy uczestników.

Kontekst wdrożenia: każdy event ma własną instalację WordPress. Wtyczka jest instalowana
na wielu stronach, więc nie może zawierać niczego przypisanego do konkretnej witryny.

Użycie: dziś wewnętrzne. Produktyzacja (dystrybucja publiczna) jest prawdopodobna w przyszłości,
dlatego kod od początku spełnia standardy produktowe: prefiksowanie, i18n, `uninstall.php`,
brak hardkodowanych wartości. Pomijamy wyłącznie warstwę komercyjną (licencje, marketing).

## 2. Zakres

### v1
- CPT `evreg_event` — wiele eventów na stronie, historia poprzednich edycji
- Edytor schemy formularza w adminie (lista pól, nie drag&drop)
- Typy zgłoszenia (uczestnik, uczestnik on-line, wykładowca…) z ceną i własnym limitem
- Sekcje formularza z warunkową widocznością
- Noclegi: pakiety nocy × typy pokoi, limity, cena, opcjonalne pole współlokatora
- Limity miejsc + lista rezerwowa
- Double opt-in
- Kolejka mailowa z logiem i retry
- Edytowalne szablony maili per event
- Admin CRUD zgłoszeń: edycja, ręczne potwierdzenie, usunięcie
- Eksport CSV/XLSX
- Blok Gutenberga + shortcode
- Auto-aktualizacja z prywatnego repo GitHub

### Poza v1
- v2: płatności (ceny są już w modelu danych)
- v3: check-in na miejscu (QR)
- v4: warunki oparte o dowolne pole (odblokowanie UI), formularz wielokrokowy

### Świadomie pominięte (YAGNI)
- Drag&drop w edytorze schemy — schema JSON jest identyczna, podmiana UI nie wymaga migracji
- Captcha — honeypot + rate limit wystarczą przy tej skali
- Auto-kasowanie danych po evencie — zgłoszenia zostają do ręcznego usunięcia
- Automatyczna promocja z listy rezerwowej — decyzja należy do organizatora

## 3. Decyzje projektowe

| # | Decyzja | Wybór | Uzasadnienie |
|---|---------|-------|--------------|
| 1 | Odbiorca | Wewnętrzna, budowana jak produkt | Wiele stron, prawdopodobna produktyzacja |
| 2 | Model eventu | CPT, wiele eventów na stronę | Historia edycji, koszt zerowy przy projektowaniu od razu |
| 3 | Edytor formularza | Lista pól w adminie, schema JSON | 1/3 kosztu React DnD, identyczne dane |
| 4 | Noclegi | Dedykowany typ pola `accommodation` | Osobny podsystem w tej samej architekturze schemy |
| 5 | Wybór nocy | Predefiniowane pakiety | Odpowiada realnej ofercie hotelowej |
| 6 | Warunki | Silnik generyczny, UI ograniczone do typu zgłoszenia | Zero długu przy późniejszym rozszerzeniu |
| 7 | Maile | `wp_mail` + własna kolejka | Kompatybilność z SMTP na stronie + brak timeoutów + log |
| 8 | Storage | Własne tabele, odpowiedzi w kolumnie JSON | Atomowa rezerwacja miejsc niemożliwa na post meta |
| 9 | Frontend | Blok + shortcode, jedna strona, własny styl | Przewidywalny wygląd na N motywach |
| 10 | Dystrybucja | GitHub + `plugin-update-checker` | Aktualizacja jednym kliknięciem na wszystkich stronach |
| A | Rezerwacja przy `pending` | Blokuje miejsce, wygasa po 48 h | Inaczej niepotwierdzone zgłoszenia zamrażają pulę |
| B | Lista rezerwowa | Ręczna promocja | Organizator decyduje kto wchodzi |
| C | Duplikaty | Jeden e-mail = jedno aktywne zgłoszenie na event | Blokada wielokrotnego wysłania; zgłoszenia anulowane nie blokują |

## 4. Architektura

```
event-registration/
├─ event-registration.php        bootstrap, guard ABSPATH, autoload, aktywacja
├─ uninstall.php
├─ composer.json / package.json
├─ src/
│  ├─ Domain/                    logika czysta, zero WP API i zero $wpdb
│  │  ├─ Schema/                 FormSchema, Section, Field, FieldTypeRegistry
│  │  ├─ Conditions/             ConditionEngine, Operator
│  │  ├─ Validation/             Validator, reguły per typ pola
│  │  ├─ Capacity/               CapacityCalculator, ReservationResult
│  │  └─ Pricing/                PriceCalculator (v1 liczy, nie pobiera)
│  ├─ Persistence/               RegistrationRepository, AccommodationRepository,
│  │                             MailQueueRepository, Migrations
│  ├─ Admin/                     EventPostType, SchemaEditor, RegistrationsTable,
│  │                             MailTemplates, Settings, Export
│  ├─ Frontend/                  Block, Shortcode, FormRenderer, SubmitController
│  ├─ Mail/                      Queue, Dispatcher, TemplateRenderer, Placeholders
│  ├─ Services/                  ReservationService, RegistrationService
│  └─ Plugin.php                 kompozycja, rejestracja hooków
├─ assets/                       źródła JS/SCSS → build/ przez @wordpress/scripts
└─ tests/
   ├─ phpunit/
   └─ e2e/
```

Zasada nadrzędna: `Domain/` jest niezależne od WordPressa. Testy jednostkowe logiki
(walidacja, warunki, limity, ceny) działają bez ładowania WP. Reszta katalogów to adaptery.

## 5. Model danych

### CPT `evreg_event`

Tytuł, daty wydarzenia, oraz meta:

| Klucz | Zawartość |
|-------|-----------|
| `_evreg_schema` | JSON: sekcje → pola |
| `_evreg_types` | Typy zgłoszenia: `{key, label, price, capacity\|null, active}` |
| `_evreg_accommodation` | Pakiety, typy pokoi, limity, ceny |
| `_evreg_mail_templates` | Szablony maili (temat + treść) per typ |
| `_evreg_settings` | Limit globalny, waitlist on/off, okno zapisów, adresy organizatora |

### Tabele

```sql
{prefix}evreg_registrations
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
  event_id      BIGINT UNSIGNED NOT NULL
  type_key      VARCHAR(64)  NOT NULL
  status        VARCHAR(20)  NOT NULL   -- pending|confirmed|waitlist|cancelled
  email         VARCHAR(191) NOT NULL
  name          VARCHAR(191) NOT NULL
  token         CHAR(32)     NOT NULL
  data          LONGTEXT     NOT NULL   -- JSON: odpowiedzi na pola schemy
  price_total   DECIMAL(10,2) NOT NULL DEFAULT 0
  note          TEXT         NULL       -- notatka organizatora
  created_at    DATETIME     NOT NULL
  expires_at    DATETIME     NULL       -- wygaśnięcie rezerwacji dla status=pending
  confirmed_at  DATETIME     NULL
  updated_at    DATETIME     NOT NULL
  KEY idx_event_email (event_id, email)
  KEY idx_event_status (event_id, status)
  KEY idx_token (token)
  KEY idx_expiry (status, expires_at)

{prefix}evreg_accommodation_bookings
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
  registration_id  BIGINT UNSIGNED NOT NULL
  package_key      VARCHAR(64) NOT NULL
  room_type_key    VARCHAR(64) NOT NULL
  roommate_pref    VARCHAR(191) NULL
  price            DECIMAL(10,2) NOT NULL DEFAULT 0
  KEY idx_registration (registration_id)
  KEY idx_inventory (package_key, room_type_key)

{prefix}evreg_mail_queue
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
  registration_id  BIGINT UNSIGNED NULL
  event_id         BIGINT UNSIGNED NOT NULL
  template_key     VARCHAR(64)  NOT NULL
  recipient        VARCHAR(191) NOT NULL
  subject          TEXT         NOT NULL
  body             LONGTEXT     NOT NULL
  status           VARCHAR(20)  NOT NULL   -- queued|sent|failed
  attempts         TINYINT UNSIGNED NOT NULL DEFAULT 0
  last_error       TEXT         NULL
  scheduled_at     DATETIME     NOT NULL
  sent_at          DATETIME     NULL
  KEY idx_dispatch (status, scheduled_at)
```

Silnik InnoDB wymagany — rezerwacja miejsc opiera się na transakcjach.

**Unikalność e-maila nie jest ograniczeniem bazodanowym**, tylko regułą w `ReservationService`,
sprawdzaną w tej samej transakcji co limity (`SELECT ... FOR UPDATE` po `event_id` + `email`).
Powód: zgłoszenie `cancelled` — wygasłe lub usunięte — nie może blokować ponownej rejestracji
tej samej osoby. Ograniczenie `UNIQUE` obejmowałoby również takie wiersze, a MySQL nie ma
indeksów częściowych z warunkiem.

### Cykl życia zgłoszenia

```
submit → pending (blokuje miejsce, expires_at = +48 h)
           ├─ klik w link          → confirmed
           ├─ ręczne potwierdzenie → confirmed
           └─ upływ 48 h           → cancelled + mail "rezerwacja wygasła", miejsce wraca do puli

brak miejsc przy submisji → waitlist (nie blokuje miejsca)
waitlist → ręczna promocja przez organizatora → pending lub confirmed
```

## 6. Schema formularza

Źródło prawdy dla renderowania, walidacji, eksportu i maili. Struktura: sekcje → pola.

```json
{
  "version": 1,
  "sections": [
    {
      "key": "dane",
      "title": "Dane uczestnika",
      "description": "",
      "condition": null,
      "fields": [
        {"key": "imie",  "type": "text",  "label": "Imię i nazwisko", "required": true},
        {"key": "email", "type": "email", "label": "E-mail",          "required": true},
        {"key": "tel",   "type": "tel",   "label": "Telefon",         "required": false}
      ]
    },
    {
      "key": "noclegi",
      "title": "Noclegi",
      "condition": {"field": "__type", "operator": "in", "value": ["uczestnik", "wykladowca"]},
      "fields": [{"key": "nocleg", "type": "accommodation", "label": "Nocleg"}]
    },
    {
      "key": "towarzyszace",
      "title": "Wydarzenia towarzyszące",
      "fields": [
        {"key": "atrakcje", "type": "checkbox-group", "label": "Wybierz",
         "options": [{"value": "kolacja", "label": "Kolacja"},
                     {"value": "zwiedzanie", "label": "Zwiedzanie"}]}
      ]
    }
  ]
}
```

### Typy pól v1

`text`, `email`, `tel`, `textarea`, `number`, `date`, `select`, `radio`,
`checkbox` (pojedynczy — zgody RODO), `checkbox-group`, `hidden`,
`heading` i `paragraph` (treść, nie pole), `accommodation`.

Dodanie typu = nowy renderer + walidator w rejestrze. Struktura schemy bez zmian.

### Pole wbudowane `__type`

Typ zgłoszenia jest zawsze obecny, nieusuwalny, renderowany jako radio lub select.
Jest jedynym polem dostępnym w warunkach w UI v1.

### Konfiguracja pola `accommodation`

```json
{
  "packages": [
    {"key": "n12", "label": "Noc 1–2"},
    {"key": "n23", "label": "Noc 2–3"},
    {"key": "n13", "label": "Noce 1–3"}
  ],
  "rooms": [
    {"key": "single", "label": "Pokój 1-osobowy"},
    {"key": "double", "label": "Pokój 2-osobowy", "roommate_field": true}
  ],
  "inventory": [
    {"package": "n12", "room": "single", "capacity": 10, "price": 250},
    {"package": "n12", "room": "double", "capacity": 20, "price": 180}
  ],
  "allow_none": true
}
```

Limit i cena są przypisane do pary **pakiet × pokój** — tak wygląda realna umowa z hotelem.
`roommate_field: true` włącza opcjonalne pole tekstowe „preferowana osoba w pokoju".

## 7. Silnik warunków

Warunek: `{field, operator, value}`.
Operatory: `equals`, `not_equals`, `in`, `not_in`, `empty`, `not_empty`.

Silnik jest generyczny od pierwszej wersji. UI v1 pozwala wybrać wyłącznie pole `__type`;
przejście do warunków od dowolnego pola (v4) to zmiana w adminie, nie w rdzeniu.

**Serwer jest arbitrem widoczności.** Przy submisji backend wylicza widoczność sekcji i pól
na podstawie nadesłanych danych. Pola niewidoczne są odrzucane: nie podlegają walidacji
i nie trafiają do bazy. Frontend jedynie pokazuje i chowa. Bez tego można podesłać POST
z noclegiem do zgłoszenia on-line.

Warunki są ewaluowane w kolejności deklaracji; pole warunkujące musi występować
wcześniej niż pole zależne. Edytor schemy waliduje ten porządek przy zapisie.

## 8. Limity i rezerwacja

Najbardziej ryzykowna część systemu — tu występują wyścigi.

`ReservationService` jest jedyną drogą do zmiany stanu zajętości. Obsługuje zarówno
submisję publiczną, jak i edycję zgłoszenia w adminie (zmiana typu pokoju zwalnia
jedno miejsce i zajmuje inne). Nigdy dwie równoległe ścieżki zapisu.

Przebieg w transakcji InnoDB:

1. `START TRANSACTION`
2. `SELECT ... FOR UPDATE` na zliczeniach dla eventu, typu zgłoszenia i pary pakiet × pokój
3. Sprawdzenie limitów: globalny → typ → nocleg
4. `INSERT` zgłoszenia i rezerwacji noclegowej
5. `COMMIT`

Miejsca zajmują statusy `pending` i `confirmed`. Status `waitlist` i `cancelled` — nie.

Brak miejsca w limicie globalnym lub typie → `waitlist` (jeśli włączona) lub odmowa.
Brak miejsca wyłącznie w noclegu → zgłoszenie przyjęte, nocleg odrzucony z komunikatem;
uczestnik nie traci miejsca na evencie przez brak pokoju.

Wygasanie: zadanie cron co 15 minut przenosi `pending` z przekroczonym `expires_at`
do `cancelled` i wysyła mail informacyjny. Miejsce wraca do puli.

Test obowiązkowy: N równoległych zgłoszeń na ostatnie miejsce → dokładnie jedno przyjęte,
reszta na liście rezerwowej. Bez tego testu funkcja nie jest ukończona.

## 9. Maile

Wysyłka nigdy nie jest synchroniczna względem submisji — mail trafia do kolejki,
a użytkownik dostaje odpowiedź natychmiast.

Dispatcher uruchamiany cronem co minutę. Retry z narastającym opóźnieniem: 1 min, 5 min,
30 min, maksymalnie 3 próby, potem `failed` z zapisanym błędem.

Admin ma widok kolejki: status, odbiorca, podgląd treści, przycisk ponownej wysyłki.

### Typy maili

| Klucz | Odbiorca | Wyzwalacz |
|-------|----------|-----------|
| `optin` | uczestnik | submisja — link potwierdzający |
| `confirmed` | uczestnik | potwierdzenie (link lub ręczne) |
| `waitlist` | uczestnik | trafienie na listę rezerwową |
| `expired` | uczestnik | wygaśnięcie niepotwierdzonej rezerwacji |
| `admin_new` | organizator | każde nowe zgłoszenie |
| `bulk` | wybrani uczestnicy | ręczna wysyłka z admina |

### Szablony

Edytowalne per event: temat + treść + placeholdery
`{imie}`, `{email}`, `{event}`, `{typ}`, `{nocleg}`, `{link_potwierdzenia}`, `{podsumowanie}`.
Nieuzupełnione szablony spadają na wersje domyślne wtyczki (przetłumaczalne).

### Transport

`wp_mail` — pozostaje kompatybilny z dowolną wtyczką SMTP zainstalowaną na stronie.
Wtyczka nie konfiguruje własnego dostawcy.

WP-Cron nie jest wiarygodny na stronach o małym ruchu. Dokumentacja wdrożeniowa zawiera
instrukcję wyłączenia WP-Cron i podpięcia crona systemowego.

## 10. Frontend

Blok dynamiczny (`render_callback`, wybór eventu w inspektorze) oraz shortcode
`[evreg_form event="123"]` jako fallback dla page builderów i widgetów.

Renderer buduje HTML ze schemy. Klasy prefiksowane `evreg-*`, kolory przez zmienne CSS
ustawiane per event — wygląd przewidywalny niezależnie od motywu.

Formularz działa bez JavaScriptu (pełny POST z przeładowaniem i odtworzeniem wartości).
Z JS: warunki na żywo, walidacja inline, licznik wolnych miejsc.

Antyspam: honeypot, minimalny czas wypełnienia, nonce, limit zgłoszeń per IP.
Bez captchy w v1.

Sekcje renderują się jako nagłówki na jednej stronie. Podział na kroki (v4) będzie zmianą
wyłącznie w rendererze — schema już zawiera potrzebną strukturę.

## 11. Bezpieczeństwo

- `nonce` na formularzu publicznym i wszystkich akcjach admina
- `current_user_can` na każdym endpoincie i akcji administracyjnej
- Sanityzacja per typ pola przy zapisie, escaping przy renderowaniu
- Wyłącznie `$wpdb->prepare`; brak konkatenacji SQL
- Token potwierdzenia: 32 znaki losowe z CSPRNG, porównanie przez `hash_equals`
- Guard `defined('ABSPATH')` w każdym pliku PHP
- Serwerowa ewaluacja warunków (patrz §7) — pola ukryte nie są przyjmowane
- `uninstall.php` z opcją zachowania danych ustawianą przed odinstalowaniem
- Integracja z natywnym API prywatności WP (eksport i usunięcie danych osoby)

## 12. Eksport

CSV i XLSX. Kolumny generowane ze schemy (etykiety pól jako nagłówki) plus kolumny
systemowe: status, typ, data zgłoszenia, data potwierdzenia, kwota.
Noclegi rozbite na pakiet, typ pokoju i preferencję współlokatora.

CSV w UTF-8 z BOM — inaczej Excel łamie polskie znaki.

Filtry: status, typ zgłoszenia, zakres dat.

## 13. Administracja zgłoszeniami

Własna lista zgłoszeń (`WP_List_Table`) — filtrowanie, wyszukiwanie, akcje masowe.

Operacje na pojedynczym zgłoszeniu:
- **Edycja** wszystkich odpowiedzi (przez `ReservationService`, z przeliczeniem limitów)
- **Ręczne potwierdzenie** bez wysyłania maila — dla przypadków, gdy mail nie dotarł
  i uczestnik potwierdza telefonicznie
- **Usunięcie** z natychmiastowym zwolnieniem miejsca
- **Promocja z listy rezerwowej** na listę główną, z mailem
- **Notatka** organizatora

## 14. Środowisko i narzędzia

| Element | Wybór |
|---------|-------|
| Środowisko lokalne | `wp-env` (Docker) |
| Minimalne PHP | 8.1 |
| Minimalne WP | 6.4 |
| Autoloading | Composer PSR-4, namespace `EvReg\` |
| Build assetów | `@wordpress/scripts` |
| Testy jednostkowe | PHPUnit + WP test suite |
| Analiza statyczna | PHPStan (poziom docelowy 6+) + WPCS |
| Testy E2E | Playwright |
| Dystrybucja | Prywatne repo GitHub + `plugin-update-checker`, aktualizacja z kokpitu WP |

### Zakres testów

Jednostkowe (bez WP): walidacja schemy, silnik warunków, kalkulator limitów, kalkulator cen,
renderowanie szablonów maili.

Integracyjne (z WP): rezerwacja miejsc w warunkach współbieżności, kolejka mailowa z retry,
wygasanie rezerwacji, eksport.

E2E: pełna ścieżka — wypełnienie formularza z noclegiem → mail opt-in → potwierdzenie →
widoczność w adminie → eksport.

## 15. Ryzyka

| Ryzyko | Skutek | Przeciwdziałanie |
|--------|--------|------------------|
| Wyścig przy ostatnim miejscu | Nadkomplet uczestników | Transakcja z blokadą, test współbieżności |
| WP-Cron nie odpala się | Maile nie wychodzą | Cron systemowy w instrukcji + alert o zaległej kolejce |
| Doręczalność maili | Uczestnicy nie potwierdzają | Log kolejki, ręczne potwierdzenie w adminie, ponowna wysyłka |
| Konflikt CSS z motywem | Zepsuty formularz | Prefiksowane klasy, własny styl, zmienne CSS |
| Rozrost pola `accommodation` | Trudny w utrzymaniu wyjątek w architekturze | Trzymanie go jako typu pola z własnym rendererem, walidatorem i eksporterem |
