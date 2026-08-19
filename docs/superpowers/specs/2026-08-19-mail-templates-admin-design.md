# Edytor szablonów maili (Plan 4B-Szablony) — projekt techniczny

Data: 2026-08-19
Status: zatwierdzony do planowania implementacji
Buduje na: [Plan 4A — kolejka mailowa](2026-08-19-mail-queue-design.md) (`DefaultTemplates`, `TemplateResolver`, `MailTemplateRepository`, `Subscriber`, meta `_evreg_mail_templates`), [Plan 2A](2026-08-18-event-config-admin-design.md) (`EventConfigController`, `EventConfigRepository`, React admin, `ops/*`), [Plan 2B](2026-08-18-event-config-react-admin.md) (zakładki, `api.js`, montowanie assetów)

## 1. Cel i kontekst

Organizator może edytować treść pięciu maili transakcyjnych per event i wskazać stronę
z formularzem oraz adresy powiadamiane o nowych zgłoszeniach. Plan 4A czyta meta
`_evreg_mail_templates` (`TemplateResolver` z fallbackiem per pole na `DefaultTemplates`),
ale nic jej nie zapisuje — ten plan dokłada zapis: piątą zakładkę React, osobny endpoint REST
i rozszerzenie `MailTemplateRepository`.

Plan zamyka też dwie luki odsłonięte w 4A: (1) żywy bug — `SettingsTab` zapisuje adresy
organizatora pod `organizer_emails`, a mailowy `Subscriber` czyta `notify_emails`, więc
powiadomienia nigdy nie dostają skonfigurowanych adresów; (2) pole `form_page_id` (strona
formularza dla `{link_potwierdzenia}`) istnieje tylko programowo, bez UI.

Ekran kolejki mailowej (lista wierszy `evreg_mail_queue`, podgląd treści, ręczne wznowienie)
to **osobny plan (4B-Kolejka)** — tutaj nie występuje.

## 2. Zakres

### v1 (Plan 4B-Szablony)
- `MailTemplateController` — nowy endpoint REST GET/POST `evreg/v1/events/<id>/mail-templates`
- `MailTemplateRepository::save()` — zapis meta z `wp_slash` (dziś klasa tylko czyta)
- `MailTemplatesTab` — piąta zakładka React: 5 typów × (temat + treść) z fallbackiem na domyślne
- `mailTemplateOps` — czysta logika mutacji szablonów (testowana `jest`)
- `SettingsTab` — fix klucza `organizer_emails` → `notify_emails`; nowe pole `form_page_id` (dropdown stron)
- `App.jsx` — piąta zakładka; globalny „Zapisz" orkiestruje PUT config + POST szablonów
- `EventConfigAssets` — lista opublikowanych stron do `wp_localize_script`

### Poza Planem 4B-Szablony
- Ekran kolejki mailowej (lista, podgląd, wznowienie) → Plan 4B-Kolejka
- Podgląd szablonu na żywo → YAGNI (placeholder inputa pokazuje domyślny tekst)
- Maile HTML / WYSIWYG → sprzeczne z formatem plain text z 4A
- Migracja istniejących `organizer_emails` → niepotrzebna (patrz §9)

### Świadomie pominięte (YAGNI)
- Wersjonowanie / historia zmian szablonów — jedno źródło prawdy w meta
- Walidacja placeholderów po stronie edytora — nieznany `{placeholder}` zostaje dosłownie (zachowanie `TemplateRenderer` z 4A), organizator widzi literówkę w mailu
- Podgląd renderu w JS — wymagałby duplikacji `TemplateRenderer`, ryzyko rozjazdu z serwerem

## 3. Decyzje projektowe

| # | Decyzja | Wybór | Uzasadnienie |
|---|---------|-------|--------------|
| 1 | Podział 4B | Szablony osobno, Kolejka osobno | Każdy wykonalny w jednym cyklu |
| 2 | Wpięcie szablonów w REST | Osobny `MailTemplateController` | `EventConfigController::sanitize()` zjada `\n`; jego testy asertują 4-kluczową mapę |
| 3 | Klucz adresów organizatora | Ujednolicić na `notify_emails` | Mail layer już go czyta; „notify" trafniejszy; zero danych do migracji |
| 4 | Edytor | Plain textarea + ściąga placeholderów | Zgodny z plain-text mailami 4A; logika w `ops/`, testowalna |
| 5 | `form_page_id` w UI | Dropdown opublikowanych stron | Zero zgadywania ID; pusty = fallback na permalink eventu |
| 6 | Zapis zakładki szablonów | Globalny „Zapisz" orkiestruje oba wywołania | Spójne UX z resztą admina, jeden stan „saving" |

## 4. Architektura

```
src/Rest/
  MailTemplateController.php   GET/POST evreg/v1/events/<id>/mail-templates;
                               sanitize_textarea_field na body; can_edit jak EventConfigController

src/Persistence/
  MailTemplateRepository.php   + save( int $event_id, array $templates ): void (dziś tylko get())

assets/admin/
  tabs/MailTemplatesTab.jsx    piąta zakładka (cienka)
  tabs/SettingsTab.jsx         fix organizer_emails→notify_emails; + form_page_id (SelectControl)
  ops/mailTemplateOps.js       czysta logika: TEMPLATE_TYPES, PLACEHOLDERS_BY_TYPE, setField, normalizeForSave, mergeLoaded
  ops/mailTemplateOps.test.js  jest
  api.js                       + loadTemplates / saveTemplates
  App.jsx                      piąta zakładka; orkiestracja zapisu

src/Admin/
  EventConfigAssets.php        + lista stron do wp_localize_script (evregAdmin.pages)
```

Inwariant Planu 2 zachowany: cała logika mutacji w czystych modułach `ops/*` testowanych
`jest`; komponenty tylko wołają ops i renderują. Serwer jest arbitrem sanityzacji i whitelisty
typów; klient jest lustrem.

## 5. REST: MailTemplateController

Namespace `evreg/v1` (jak `EventConfigController::REST_NAMESPACE`). Rejestracja na
`rest_api_init`. Trasa `/events/(?P<id>\d+)/mail-templates`, metody GET i POST, obie
`permission_callback => can_edit`. `can_edit` identyczne jak w `EventConfigController`:
`current_user_can( Capabilities::CAP )` **oraz** `current_user_can( 'edit_post', $id )`, inaczej
`WP_Error` z kodem autoryzacji. Nonce `wp_rest` niesie `@wordpress/api-fetch` automatycznie.

### GET — odpowiedź

```json
{
  "templates": { "optin": {"subject":"","body":""}, "confirmed": {...}, "waitlist": {...}, "expired": {...}, "admin_new": {...} },
  "defaults":  { "optin": {"subject":"...","body":"..."}, ... }
}
```

`templates` = zapisane nadpisania z `MailTemplateRepository::get()` (puste stringi, gdy brak).
`defaults` = pętla po `DefaultTemplates::keys()` z `DefaultTemplates::get()` — teksty domyślne
serwowane, żeby UI pokazało je jako `placeholder` inputów bez duplikowania w JS. Oba obiekty
mają komplet pięciu kluczy z `DefaultTemplates::keys()`.

### POST — walidacja, sanityzacja, odpowiedź

Ciało: `{ "templates": { "<typ>": {"subject":"...","body":"..."}, ... } }`.

- Klucze typów spoza `DefaultTemplates::keys()` — **pomijane** (nie błąd). Zapobiega wstrzyknięciu obcych kluczy do meta.
- `subject` → `sanitize_text_field` (jednoliniowy). `body` → `sanitize_textarea_field` (zachowuje `\n`, usuwa tagi i znaki kontrolne).
- Pole puste po sanityzacji nie jest zapisywane; typ z obydwoma pustymi polami wypada z meta (czysty fallback na domyślny w `TemplateResolver`).
- Odpowiedź POST = ta sama forma co GET (świeże `templates` + `defaults`), by React odświeżył stan ze źródła prawdy.

`MailTemplateController` jest jedyną drogą zapisu `_evreg_mail_templates`.

## 6. Persistence: MailTemplateRepository::save

```php
public function save( int $event_id, array $templates ): void
```

- Normalizuje wejście do `{ <typ znany>: { subject, body } }`, wyłącznie niepuste pola dla znanych typów.
- Wynik pusty → `delete_post_meta( $event_id, self::META_KEY )` (nie zostawiamy `{}`).
- Wynik niepusty → `update_post_meta( $event_id, self::META_KEY, wp_slash( (string) wp_json_encode( $templates ) ) )`.

**`wp_slash` obowiązkowy** — `update_post_meta` wewnętrznie robi `wp_unslash`, co bez pre-slashu
psuje polskie znaki (`\uXXXX`) i łamania linii w treści. To dokładnie pułapka naprawiona w
`EventConfigRepository::save()` w Planie 4A; ten sam wzorzec i ten sam wymóg testu regresji.

Metoda `get()` (z 4A) pozostaje bez zmian — nadal czyta meta jako string albo tablicę,
`json_decode`, zwraca `array<string,array<string,string>>`.

## 7. Frontend

### ops/mailTemplateOps.js (czysty, testowany jest)

- `TEMPLATE_TYPES` — pięć `{ key, label }` (`optin`, `confirmed`, `waitlist`, `expired`, `admin_new`), etykiety przez `__()`.
- `PLACEHOLDERS_BY_TYPE` — ściąga dostępnych placeholderów per typ. Wszystkie pięć typów: `{imie}`, `{email}`, `{event}`, `{typ}`, `{nocleg}`, `{link_potwierdzenia}`, `{podsumowanie}` — zgodne z `PlaceholderFactory` z 4A. Statyczna mapa.
- `setField( templates, type, field, value )` → nowy obiekt, ustawia `templates[type][field]`, nie mutuje wejścia.
- `normalizeForSave( templates )` → zrzuca puste pola i puste typy, chudy obiekt do POST.
- `mergeLoaded( templates, defaults )` → stan startowy: nadpisania jako wartości inputów, `defaults` osobno do placeholderów.

### tabs/MailTemplatesTab.jsx (cienki)

Props: `templates`, `defaults`, `update('mailTemplates')`. Na każdy `TEMPLATE_TYPES`: nagłówek
(label), `TextControl` (temat, `placeholder = defaults[type].subject`), `TextareaControl`
(treść, `placeholder = defaults[type].body`, ~10 wierszy), pod spodem statyczna lista dostępnych
`{placeholderów}` z `PLACEHOLDERS_BY_TYPE`. Zmiana pola woła `setField` z ops i przekazuje do
`update`.

### App.jsx — piąta zakładka i orkiestracja

- `tabs` dostaje `{ name: 'mail', title: __( 'Szablony maili', 'event-registration' ) }` na końcu; `TabRouter` gałąź `'mail'` → `<MailTemplatesTab>`.
- Load: `useEffect` po `loadConfig` woła też `loadTemplates( eventId )`; stan zyskuje `config.mailTemplates` (nadpisania) i osobny `templateDefaults`. Błąd ładowania szablonów nie blokuje configu — osobny komunikat.
- Globalny `onSave`: `Promise.allSettled([ saveConfig( eventId, config ), saveTemplates( eventId, normalizeForSave( config.mailTemplates ) ) ])`. Częściowy błąd → komunikat który zapis padł. Sukces obu → oba stany odświeżone z odpowiedzi serwera.

### api.js

`loadTemplates( id )` = GET `/evreg/v1/events/<id>/mail-templates`; `saveTemplates( id, templates )`
= POST z ciałem `{ templates }`. Oba przez `@wordpress/api-fetch` (nonce automatyczny).

### SettingsTab.jsx — dwa fixy

- `organizer_emails` → `notify_emails` w odczycie i zapisie; etykieta „E-maile organizatora" bez zmian.
- Nowe pole `form_page_id`: `SelectControl` z listą opublikowanych stron z `evregAdmin.pages`. Pusty wybór = `0` = fallback na permalink eventu w `PlaceholderFactory`. Wartość rzutowana na `int` przy zapisie.

### EventConfigAssets.php

Do `wp_localize_script( 'evregAdmin', ... )` dochodzi `pages` — `get_posts` typu `page`,
status `publish`, zredukowane do `[ { value: id, label: title } ]`. Bez dodatkowego fetcha
po stronie klienta.

## 8. Testy

### jest (host, `assets/admin/ops/`)
- `setField` nie mutuje wejścia, ustawia właściwe pole
- `normalizeForSave` zrzuca puste pola i puste typy
- `mergeLoaded` rozdziela nadpisania od defaults
- `PLACEHOLDERS_BY_TYPE` ma wpis dla każdego `TEMPLATE_TYPES`

Komponenty cienkie — bez testów (logika w ops, inwariant Planu 2).

### Integration (wp-env)
- `MailTemplateController` GET: `defaults` z `DefaultTemplates`, puste `templates` dla nieskonfigurowanego eventu
- POST → GET round-trip zapisanego nadpisania
- `body` z `\n` i polskimi znakami przetrwa round-trip (regresja pułapki `wp_slash`)
- nieznany typ w POST pominięty; oba pola puste → typ niezapisany
- `permission_callback` odrzuca żądanie bez `edit_post`
- `MailTemplateRepository::save`: niepusty zapis + `get` round-trip; pusty wynik kasuje meta; treść wieloliniowa/unicode bez uszkodzeń
- **Spięcie z 4A:** po zapisaniu nadpisania przez kontroler, `Subscriber` kolejkuje mail z nadpisanym tematem/treścią, nie domyślnym
- **`notify_emails`:** `Subscriber` czyta adresy zapisane pod `notify_emails` (nie `admin_email`) — dowód, że fix mismatchu działa końcem w koniec
- `EventConfigControllerTest` pozostaje zielony (osobny endpoint, kontrakt nietknięty) — uruchamiać w regresji

### E2E (Playwright, opcjonalnie)
Wejście na zakładkę Szablony maili, wpisanie tematu, Zapisz, przeładowanie, wartość trwa.
Jeśli CI niestabilny — jak istniejące e2e: lokalne przejście wystarcza, utwardzenie CI to Plan 6.

## 9. Bezpieczeństwo

- `permission_callback` na obu trasach: `Capabilities::CAP` + `current_user_can( 'edit_post', $id )` + typ posta `evreg_event`
- `body` przez `sanitize_textarea_field` — usuwa tagi, zachowuje `\n`; brak wektora HTML/XSS, spójne z plain-text 4A
- Placeholdery to zwykły tekst; `TemplateRenderer` (4A) nie rozwija wartości rekurencyjnie — brak wstrzyknięcia przez wartość placeholdera
- Whitelist typów z `DefaultTemplates::keys()` — obce klucze nie trafią do meta
- Nonce `wp_rest` (api-fetch); `form_page_id` rzutowany na `int`
- `defined('ABSPATH') || exit;` w każdym nowym pliku PHP poza `src/Domain/`
- `wp_slash` przy zapisie meta — bez niego treść z polskimi znakami zapisze się uszkodzona

## 10. Ryzyka

| Ryzyko | Skutek | Odpowiedź |
|---|---|---|
| W produkcji zapisano już `organizer_emails` | Po zmianie klucza te adresy zignorowane (fallback na `admin_email`) | Skala wewnętrzna, mail layer świeży — akceptowalne; udokumentowane, nie migrowane |
| Brak `wp_slash` przy zapisie szablonu | Polskie znaki i `\n` w treści uszkodzone | Wymóg §6 + test regresji round-trip |
| Rozjazd `PLACEHOLDERS_BY_TYPE` z `PlaceholderFactory` | Edytor reklamuje placeholder, którego mail nie podstawia | Statyczna lista zgodna z 4A; nieznany placeholder i tak zostaje dosłownie (nieszkodliwe) |
| Częściowy błąd zapisu (config OK, szablony padły) | Organizator nie wie, co się zapisało | `Promise.allSettled` + komunikat który zapis padł |
| Zakładka szablonów przy `eventId=0` (nowy event) | Brak eventu do zapisania szablonów | Jak reszta admina: „zapisz szkic najpierw" (App już to obsługuje dla `eventId=0`) |
