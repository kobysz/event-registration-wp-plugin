# Public Form (Plan 3B) — projekt techniczny

Data: 2026-08-18
Status: zatwierdzony do planowania implementacji
Buduje na: [Plan 1 — domena](2026-08-18-event-registration-design.md) (`Validator`, `VisibilityResolver`, `PriceCalculator`), [Plan 2A](2026-08-18-event-config-admin-design.md) (`SchemaAssembler`, `EventConfigRepository`), [Plan 3A](2026-08-18-reservation-backend-design.md) (`ReservationService::reserve/confirm`)

## 1. Cel i kontekst

Publiczny frontend rejestracji: renderowanie formularza eventu ze złożonej schemy, obsługa submisji
z walidacją serwerową, wywołanie transakcyjnej rezerwacji (`ReservationService` z Planu 3A), oraz
endpoint potwierdzenia double opt-in. Formularz działa bez JavaScriptu (pełny POST); z JS dochodzą
warunki na żywo (chowanie/pokazywanie sekcji wg typu zgłoszenia).

To domyka cykl: uczestnik realnie widzi formularz, wysyła go, ląduje jako `pending` z tokenem, i może
potwierdzić linkiem. Wysyłka maila niosącego ten link to Plan 4; tutaj link istnieje i działa.

## 2. Zakres

### v1 (Plan 3B)
- `EventFormLoader` — config eventu → kompletny `FormSchema` + kolekcje typów/noclegów (wspólne dla renderu i submisji)
- `FormRenderer` — `FormSchema` → HTML z re-renderem błędów i zachowaniem wartości
- `SubmitHandler` — POST-to-self na `template_redirect`: antyspam → walidacja → rezerwacja → PRG/re-render
- `Block` + `Shortcode` — osadzenie formularza eventu
- `ConfirmationController` — endpoint `?evreg_confirm=token`
- Lekki publiczny JS (warunki na żywo) + izolowany CSS
- Ścieżka E2E Playwright: wypełnij → submit → pending → potwierdź → confirmed

### Poza Planem 3B
- Wysyłka maili (opt-in, powiadomienia organizatora) → Plan 4
- Walidacja inline i licznik miejsc na żywo (polish) → później
- Automatyczna promocja z listy rezerwowej → Plan 5
- Płatności → v2

### Świadomie pominięte (YAGNI)
- Build webpackiem publicznego JS (skrypt jest maleńki — plik vanilla enqueue'owany wprost)
- Konfigurowalne oznaczanie pola e-mail/name flagą (heurystyka deterministyczna, patrz §7)

## 3. Decyzje projektowe

| # | Decyzja | Wybór | Uzasadnienie |
|---|---------|-------|--------------|
| 1 | Architektura submisji | POST-to-self, handler na `template_redirect`; PRG na sukcesie | Re-render z błędami i zachowaniem wartości naturalny (handler w kontekście strony), zero transientów do wygaszania |
| 2 | Renderowanie | Serwerowe PHP, działa bez JS | Serwer arbitrem; JS tylko chowa/pokazuje |
| 3 | Publiczny JS | Vanilla, enqueue wprost (bez webpacka) | Skrypt maleńki (toggle sekcji wg data-*); build zbędny |
| 4 | Źródło email/name | Heurystyka deterministyczna ze schemy (§7) | Bez dodatkowego configu; pole e-mail i tak wymagane |

## 4. Architektura

```
src/
├─ Frontend/
│  ├─ EventFormLoader.php        config → SchemaAssembler → FormSchema + RegistrationTypeCollection + AccommodationConfig
│  ├─ FormRenderer.php           FormSchema (+ wynik submisji) → HTML, prefiks evreg-*
│  ├─ SubmitHandler.php          template_redirect: antyspam + Validator + rezerwacja; process() testowalny + hook z PRG
│  ├─ SubmitResult.php           wynik submisji (wartość): ok/errors/values/message
│  ├─ Block.php                  dynamiczny blok Gutenberga (render_callback, wybór eventu)
│  ├─ Shortcode.php              [evreg_form event="ID"]
│  └─ ConfirmationController.php  ?evreg_confirm=token → ReservationService::confirm → PRG na komunikat
assets/public/
├─ form.js                       warunki na żywo (chowa/pokazuje sekcje wg __type)
└─ form.css                      izolowany styl, zmienne CSS na kolory per event
```

Zasada: `Validator`, `VisibilityResolver`, `SchemaAssembler`, `ReservationService` z Planów 1–3A pozostają
nietknięte. `FormRenderer`, `SubmitHandler` itd. to adaptery WordPressa (esc_*, `$_POST`, hooki). Brak nowej
czystej jednostki domenowej (`SubmitResult` to DTO w `src/Frontend/`, nie w Domain).

## 5. Przepływ submisji (POST-to-self)

Formularz POSTuje na URL bieżącej strony (pole ukryte `evreg_event` = ID, nonce, honeypot, timestamp).

1. `SubmitHandler` na `template_redirect` wykrywa POST (marker `evreg_submit` + weryfikacja nonce). Brak → nic.
2. **Antyspam:** honeypot musi być pusty; `now − timestamp ≥ próg` (np. 3 s); rate-limit per IP (transient, np. 10/godz.). Naruszenie → cichy `SubmitResult::spam()` (renderer pokazuje ogólny błąd).
3. `EventFormLoader::load(eventId)` → `FormSchema` + kolekcje.
4. Zbuduj tablicę odpowiedzi z `$_POST` (tylko klucze pól schemy).
5. `Validator::validate($schema, $answers)` → `ValidationResult` (`errors()`, `values()`).
6. **Błędy** → `SubmitResult::invalid(errors, submittedValues)` — przekazany do renderera, formularz re-renderuje się z komunikatami i odtworzonymi wartościami. **Bez redirectu** (POST-to-self, kontekst strony).
7. Sukces: wyciągnij `typeKey` (odpowiedź `__type`), `AccommodationSelection` (z pola `accommodation`), `email`/`name` (§7); zbuduj `ReservationRequest`; `ReservationService::reserve(eventId, request)`.
8. Mapuj `ReservationResult`:
   - `reserved` → PRG redirect `?evreg=reserved` (komunikat „sprawdź e-mail / potwierdź")
   - `waitlisted` → PRG `?evreg=waitlisted`
   - `rejected` → `SubmitResult` z komunikatem „brak miejsc" (re-render, bez redirectu)
   - `duplicate` → `SubmitResult` „jesteś już zapisany" (re-render)
9. Po PRG strona ładuje się z `?evreg=...`; blok/shortcode renderuje komunikat sukcesu zamiast formularza.

`SubmitHandler::process(int $eventId, array $post): SubmitResult` jest jądrem testowalnym (bez redirectu);
cienki hook `handle()` woła `process()` i wykonuje PRG albo zostawia wynik dla renderera.

## 6. Renderowanie

`FormRenderer::render(FormSchema $schema, ?SubmitResult $result = null): string`:
- sekcje jako `<fieldset>` z legendą; pola wg typu (13 typów + `accommodation` + `heading`/`paragraph`)
- `__type` jako radio z opcjami (wstrzyknięte przez `SchemaAssembler`); `accommodation` jako wybór pakiet×pokój z inwentarza + opcjonalne pole współlokatora
- klasy `evreg-*`, wartości i kolory przez zmienne CSS per event
- pola ukryte: `evreg_event`, `evreg_submit`, nonce (`wp_nonce_field`), honeypot (`evreg_hp`, ukryty CSS-em), timestamp (`evreg_ts`)
- bez JS: wszystkie sekcje widoczne; sekcje warunkowe dostają atrybuty `data-evreg-when-field`/`-operator`/`-value` dla JS
- **re-render błędów:** gdy `$result` niesie błędy, przy każdym polu z błędem komunikat (kod → PL), a `value`/`checked` odtworzone z `submittedValues`
- escaping: `esc_html`/`esc_attr`/`esc_textarea` na każdym wyjściu; wartości użytkownika nigdy surowe

## 7. Ekstrakcja email/name

`SubmitHandler` wyprowadza pola rezerwacji ze zwalidowanych odpowiedzi deterministycznie:
- `email` = wartość pierwszego **widocznego** pola typu `email`. Brak pola `email` w schemie → `SubmitHandler` zwraca `SubmitResult` z błędem konfiguracyjnym „formularz musi zawierać pole e-mail" (to check w `SubmitHandler` przy ekstrakcji, nie w `Validator` — `Validator` sprawdza odpowiedzi, nie obecność pól). Bez adresu nie da się wysłać opt-in.
- `name` = wartość pola o kluczu `name` lub `imie`, jeśli jest; inaczej pierwszego pola typu `text`; inaczej wartość email.
- `typeKey` = odpowiedź pola `__type`.
- `AccommodationSelection` = z odpowiedzi pola typu `accommodation` (już zwalidowanej przez `AccommodationValidator`).

Widoczność liczona `VisibilityResolver` (serwer arbitrem) — pola niewidoczne pomijane w ekstrakcji.

## 8. Potwierdzenie

`ConfirmationController` na `template_redirect`:
- wykryj `?evreg_confirm=<token>`
- `ReservationService::confirm(token)` → `ConfirmationResult` (`confirmed`/`already_confirmed`/`expired`/`waitlist`/`not_found`)
- PRG redirect na stronę z `?evreg_confirmed=<kod>` (usuwa token z URL), komunikat renderowany wg kodu

## 9. Osadzenie

- **Blok dynamiczny** (`render_callback`): inspektor wybiera event po ID; renderuje formularz albo — po PRG — komunikat wyniku.
- **Shortcode** `[evreg_form event="123"]` — fallback dla page builderów/widgetów.
- Oba czytają ewentualny `SubmitResult` z bieżącego żądania (POST-to-self) oraz `?evreg=`/`?evreg_confirmed=` z URL.

## 10. Bezpieczeństwo i antyspam

- Nonce (`wp_nonce_field` + `wp_verify_nonce`) na submisji; honeypot; minimalny czas wypełnienia; rate-limit per IP (transient).
- Serwer jest arbitrem walidacji i widoczności (`Validator` + `VisibilityResolver`) — pola niewidoczne odrzucane; klient tylko chowa/pokazuje.
- Sanityzacja per typ pola (`Validator`), escaping przy renderze (`esc_*`), token porównywany przez lookup (Plan 3A).
- `ReservationService` (Plan 3A) jest jedyną drogą zmiany zajętości — transakcja + blokada per event.
- Guard `ABSPATH` we wszystkich plikach PHP; token w URL to token-uprawnienie (nie PII).
- Enqueue `form.js`/`form.css` tylko gdy na stronie jest blok/shortcode (warunkowo).

## 11. Testy

- `EventFormLoader` → integracyjny (config → poprawny `FormSchema` + kolekcje).
- `FormRenderer` → integracyjny (typy pól → HTML; honeypot/nonce/hidden obecne; escaping; re-render błędów odtwarza wartości i pokazuje komunikaty). Asercje na łańcuchu HTML.
- `SubmitHandler::process` → integracyjny (woła realny `ReservationService`): poprawny POST → `reserved`; błąd walidacji → `invalid` z wartościami; honeypot wypełniony → `spam`; duplikat → `duplicate`; brak miejsc → `rejected`/`waitlisted`. Ekstrakcja email/name z heurystyki.
- `ConfirmationController` → integracyjny (token → PRG/komunikat wg `ConfirmationResult`).
- `Block`/`Shortcode` → integracyjny (renderują formularz dla eventu; komunikat po `?evreg=`).
- **E2E Playwright:** utwórz i skonfiguruj event (przez admin z 2B lub fixture), otwórz stronę z shortcode, wypełnij, wyślij → `pending`; pobierz token z bazy, otwórz link potwierdzenia → `confirmed`.

## 12. Podział na taski (wysokopoziomowo)

1. `EventFormLoader` (config → FormSchema + kolekcje)
2. `FormRenderer` (schema → HTML, honeypot/nonce/hidden, escaping)
3. `SubmitResult` + `SubmitHandler::process` (antyspam + walidacja + ekstrakcja + rezerwacja) + test
4. `SubmitHandler::handle` hook (template_redirect + PRG) + re-render błędów w rendererze
5. `Block` + `Shortcode` (osadzenie, podniesienie wyniku)
6. `ConfirmationController` (endpoint potwierdzenia)
7. `form.js` + `form.css` + warunkowy enqueue
8. Ścieżka E2E Playwright

## 13. Ryzyka

| Ryzyko | Skutek | Przeciwdziałanie |
|--------|--------|------------------|
| Re-render gubi wpisane wartości | Frustracja, porzucenie | POST-to-self trzyma wartości w żądaniu; renderer odtwarza z `submittedValues` |
| Brak pola e-mail w schemie | Nie da się wysłać opt-in | Walidacja odrzuca; komunikat konfiguracyjny |
| Podwójny submit (bez PRG) | Duplikat/podwójna rezerwacja | PRG na sukcesie; guard duplikatu w `ReservationService` (Plan 3A) |
| Warunki tylko w JS | Uczestnik online wypełnia pola noclegu | Serwer arbitrem: pola niewidoczne odrzucane niezależnie od JS |
| Konflikt CSS z motywem | Zepsuty formularz | Prefiksowane klasy `evreg-*`, izolowany styl, zmienne CSS |
| Antyspam blokuje realnych | Utrata zgłoszeń | Progi łagodne (czas 3 s, rate-limit hojny); honeypot niewidoczny, nie CAPTCHA |
