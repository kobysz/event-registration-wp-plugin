# Spec: Mail w języku zgłoszenia (B3c-1 / B3 Plan 3, część 1)

Data: 2026-09-18. Backlog: B3 (`docs/superpowers/backlog.md`). Poprzednicy: B3a (fundament i18n), B3b (overlay treści per event). B3c zdekomponowane: **B3c-1 mail w języku zgłoszenia** (ten spec), B3c-2 język linku/strony potwierdzenia, B3c-3 formaty daty/waluty locale.

## Cel

Mail potwierdzający (i pozostałe maile uczestnika: opt-in/waitlist/confirmed/expired) ma dochodzić w **języku, w którym zgłoszenie zostało złożone** (język strony formularza wg Polylang). Organizator dostaje powiadomienie w języku bazowym/site.

## Zakres

W zakresie:
- Zapis języka zgłoszenia (kolumna `lang` w tabeli zgłoszeń, migracja).
- Rozstrzyganie szablonu maila per język: overlay `_evreg_i18n[lang].mail` → baza `_evreg_mail_templates` → `DefaultTemplates` (fallback per pole subject/body).
- Przekazanie języka od enqueue do resolvera; maile uczestnika w języku zgłoszenia, mail organizatora w bazowym.
- Edycja tłumaczeń szablonów w `MailTemplatesTab` (przełącznik języka).

Poza zakresem (kolejne slice / YAGNI):
- Język **linku/strony potwierdzenia** (URL do właściwej strony Polylang) — B3c-2.
- **Formaty daty/waluty** wg locale w treści maila (placeholdery `{cena}` itd. renderowane jak dziś) — B3c-3.
- Tłumaczenie **etykiet treści** formularza — zrobione w B3b.
- Powiadomienie organizatora pozostaje jednojęzyczne (bazowe).

## Model danych

### Kolumna `lang`

Tabela `{prefix}evreg_registrations` dostaje kolumnę:
```
lang varchar( 12 ) NOT NULL DEFAULT ''
```
- Wartość = slug języka Polylang w chwili rezerwacji (`CurrentLanguage::get()`), lub `''` gdy brak (Polylang nieaktywny / język domyślny).
- Dodanie przez `Migrations`: dopisać kolumnę do `CREATE TABLE` zgłoszeń ORAZ **bump `Migrations` schema version** (stała wersji), by `dbDelta` wykonał `ALTER TABLE ... ADD lang` na istniejących instalacjach. (Migrations używa `dbDelta`; sprawdzić `Migrations::VERSION`/`VERSION_OPTION` i mechanizm — dopisanie kolumny do definicji + bump wersji wystarcza, `dbDelta` dodaje brakującą kolumnę.)
- `''` domyślnie → istniejące zgłoszenia i ścieżka bez Polylang działają jak dziś (resolver z `lang=''` = baza).

### Tłumaczenia szablonów w overlay B3b

Rozszerzenie meta `_evreg_i18n` (z B3b) o bucket `mail`:
```json
{ "<lang>": { "mail": { "<templateKey>": { "subject": "...", "body": "..." } } } }
```
- `templateKey` = klucz typu szablonu (`DefaultTemplates::KEY_*`, np. opt-in/confirmed/waitlist/expired/admin_new — dokładny zestaw jak w `MailTemplatesTab`/`TEMPLATE_TYPES`).
- Baza (język domyślny) zostaje w `_evreg_mail_templates` (bez zmian). Tylko języki nie-domyślne trafiają do overlay.
- Puste subject/body → fallback (baza → default) per pole.

## Rozstrzyganie szablonu (TemplateResolver)

`src/Mail/TemplateResolver::resolve( int $event_id, string $template_key, string $lang = '' ): array{subject,body}`:
1. `key = baseKey( $template_key )` (jak dziś — obcina wariant po `:`).
2. Jeśli `'' !== $lang`: `overlay = EventConfigRepository::getI18n( $event_id )[ $lang ]['mail'][ $key ] ?? []`. Weź `subject`/`body` z overlay gdy niepuste.
3. Fallback per pole: brak/puste w overlay → baza `_evreg_mail_templates[key]` → `DefaultTemplates::get(key)` (obecna logika `resolve`).
- Iniekcja: `TemplateResolver` dostaje `EventConfigRepository` (do `getI18n`) obok istniejącego `MailTemplateRepository`. `lang=''` → pomija krok overlay = obecne zachowanie (zero regresji; istniejące wywołania bez langu działają).

## Przepływ języka: enqueue → resolve

- `MailQueue::enqueue( $template_key, $event_id, $registration_id, $recipient, $values, $headers = [], $immediate = false, string $lang = '' )` — nowy ostatni param `$lang`, przekazany do `$this->templates->resolve( $event_id, $template_key, $lang )`.
- `Subscriber::queue( $registration_id, $event_id, $template_key, $immediate )` — czyta język zgłoszenia (`RegistrationRepository::find($registration_id)->lang` lub dedykowany getter `langOf(int): string`) i przekazuje do `enqueue` (maile UCZESTNIKA).
- `Subscriber::queue_admin(...)` — `enqueue(..., $lang = '')` (organizator = język bazowy).
- `Subscriber` konstruuje `TemplateResolver` z `EventConfigRepository` (dostroić `mailQueue()`/fabrykę).

## Zapis języka przy rezerwacji

- `CurrentLanguage::get()` (z B3b) daje slug w chwili submitu.
- `SubmitHandler` (public form submit) przekazuje język do `ReservationService::reserve` (rozszerzyć request/DTO rezerwacji o `lang`), a `RegistrationRepository::insert` zapisuje kolumnę `lang`.
- Ścieżka admina (`editAnswers`) NIE ustawia langu (edycja nie zmienia języka zgłoszenia). `promoteFromWaitlist`/`confirmManually` też nie — działają na istniejącym wierszu (lang już zapisany przy pierwotnej rezerwacji).
- Miejsce odczytu langu = tam gdzie powstaje request submisji (SubmitHandler/SubmissionAssembler). Dokładny punkt: sprawdzić jak `reserve` przyjmuje dane i dodać `lang` do wstawianego wiersza (RegistrationRepository::insert — dołożyć kolumnę do mapy insertu).

## Admin — przełącznik języka w MailTemplatesTab

- `MailTemplatesTab` dostaje selektor języka (opcje z `window.evregAdmin.languages` + `defaultLanguage`, z B3b).
- Język domyślny (lub brak języków) → edycja jak dziś: `config.mailTemplates` przez istniejące `mailTemplateOps.setField` / zapis `saveTemplates`.
- Język nie-domyślny → edycja `config.i18n[lang].mail`: nowe czyste ops w `i18nOps.js` — `getMailTranslation( overlay, lang, templateKey, field )` / `setMailTranslation( overlay, lang, templateKey, field, value )` (bucket `mail`), zapis przez istniejące `saveI18n` (B3b). Placeholder = wartość bazowa (temat/treść z `mailTemplates`/`DefaultTemplates`), widoczna gdy tłumaczenie puste.
- Komponent cienki; logika w ops (jest-testowana).

## Testy

- **Unit/Integration** `TemplateResolver`: `lang=''` → baza/default (parytet z obecnym testem); `lang='en'` z overlay.mail → tłumaczenie; fallback per pole (overlay ma subject, brak body → body z bazy/default); nieznany lang → baza.
- **Integration migracja**: po `Migrations` tabela zgłoszeń ma kolumnę `lang` (na świeżej instalacji testowej).
- **Integration reserve**: submit z `add_filter('evreg_current_language', fn()=>'en')` → zapisany wiersz ma `lang='en'`; bez filtra → `''`.
- **Integration enqueue**: dla zgłoszenia z `lang='en'` i overlay.mail[en] → wiersz kolejki ma przetłumaczony subject/body; mail organizatora (queue_admin) w bazowym.
- **jest** `i18nOps`: `getMailTranslation`/`setMailTranslation` (bucket mail, immutable). `MailTemplatesTab` routing weryfikowany wizualnie/istniejącymi testami ops.
- **e2e**: pominięte (Polylang nieobecny w wp-env; ścieżka języka przez filtr integracyjnie).

## Inwarianty / uwagi

- `src/Domain/**` bez zmian (resolver `TemplateResolver` jest w `src/Mail/`, warstwa WP — może używać repo).
- Snapshot treści maila renderowany przy enqueue (jak dziś) — język ustalany raz, w chwili kolejkowania; spójne z modelem 4A.
- Idempotencja kolejki (UNIQUE registration_id+template_key) bez zmian — język nie tworzy nowych wariantów klucza.
- `lang=''` wszędzie jako bezpieczny default = zero regresji dla istniejących instalacji i braku Polylang.
- Powiadomienia organizatora świadomie w języku bazowym.
- Data-safety: język to tylko wybór wariantu treści; nie zmienia statusu/typu/liczników.

## Kolejność implementacji (dla planu)

1. Migracja: kolumna `lang` + bump wersji schematu + test że kolumna istnieje.
2. `RegistrationRepository`: zapis `lang` w insert + getter `langOf`/pole na modelu; `SubmitHandler`/`ReservationService::reserve` threadują `CurrentLanguage::get()`. Test reserve zapisuje lang.
3. `TemplateResolver::resolve( ..., $lang )` + iniekcja `EventConfigRepository` + testy (overlay/baza/default/fallback).
4. `MailQueue::enqueue( ..., $lang )` + `Subscriber` (uczestnik = lang zgłoszenia, admin = '') + test enqueue.
5. `i18nOps` mail-bucket ops (jest) + `MailTemplatesTab` przełącznik języka (routing default↔overlay) + build.
6. Pełne testy, paczka, backlog.
