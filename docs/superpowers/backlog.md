# Backlog — funkcje odłożone

Roadmapa planów 1–6 zamknięta (patrz `CLAUDE.md`). Poniżej funkcje zgłoszone
w iteracji funkcjonalnej, do zaplanowania osobno (każda: brainstorming → spec →
plan → wykonanie). Rejestr utworzony 2026-08-21.

---

## B1 — Zależności pól (warunkowa widoczność) — pełny stack — ZROBIONE (2026-09-18)

**Status:** scalone — pełny stack (ops, render `data-evreg-when-*` na polach i sekcjach, generyczny form.js, edytor w builderze, e2e).

**Cel:** pokazywanie/ukrywanie dowolnego pola LUB sekcji w zależności od
wartości innego pola (nie tylko `__type`).

**Stan obecny (2026-08-21):** silnik domeny istnieje i jest generyczny, ale
brak UI i pełnego renderu klienta — funkcja nieużywalna end-to-end.

Co JEST:
- `src/Domain/Conditions/` — `Condition {field, operator, value}`, `Operator`
  (`equals/not_equals/in/not_in/empty/not_empty`), `ConditionEngine::isMet`
  (ocena wg dowolnego pola z odpowiedzi).
- `Condition` na SEKCJI i na POLU (`Section->condition`, `Field->condition`).
- `src/Domain/Schema/VisibilityResolver` → `VisibilitySet` (widoczne sekcje+pola);
  używany serwerowo w walidacji (`SubmissionAssembler` — ukryte pola pomijane)
  i w mailach (`PlaceholderFactory`). Serwer egzekwuje warunki.

Czego BRAK (3 luki):
1. **UI buildera** — `assets/admin/ops/schemaOps.js` zawsze ustawia
   `condition: null`. Zero UI do zdefiniowania warunku. Trzeba: edytor per pole
   i per sekcja (wybór pola-triggera + operator + wartość) zapisujący `condition`
   w schemacie; czyste ops + testy jest.
2. **Render pola (klient)** — `FormRenderer::renderField` NIE emituje
   `data-evreg-when-*` (robi to tylko `renderSection`). Warunki pól nie trafiają
   do HTML. Trzeba: emisja atrybutów `data-evreg-when-*` również na kontenerze pola.
3. **`assets/public/form.js`** — obsługuje tylko warunki SEKCJI i tylko gdy
   trigger = `__type` (hardcode `!== '__type' → return`, nasłuch wyłącznie
   `input[name="__type"]`). Trzeba: generyczny silnik — nasłuch zmian DOWOLNEGO
   trigger-pola (radio/checkbox/select/text), toggle sekcji I pól, obsługa
   wszystkich operatorów (parytet z `ConditionEngine`).

**Uwaga spójności:** logika oceny warunku istnieje dwa razy (PHP `ConditionEngine`
+ JS `form.js`). Utrzymać parytet operatorów/semantyki; rozważyć wspólny zestaw
testów danych (te same przypadki po obu stronach).

**Szkic akceptacji:** w builderze ustawiam „pokaż pole X gdy pole Y = wartość Z";
publiczny formularz pokazuje/ukrywa X na żywo przy zmianie Y; ukryte pole nie jest
walidowane/zapisywane; walidacja serwerowa spójna z klientem.

---

## B2 — Render formularza z klasami Bootstrap 5 — ZROBIONE (2026-08-24)

**Status:** scalone. `FormRenderer` emituje klasy BS5 (`form-control`/`form-select`/
`form-check*`/`form-label`/`is-invalid`+`invalid-feedback`/`btn btn-primary`/
`mb-3`/`mb-4`) obok zachowanych hooków `evreg-*`. Ładowanie BS5 opt-in: globalna
opcja `evreg_load_bootstrap` (`SettingsScreen`) warunkowo enqueue'uje bundlowany
`assets/public/bootstrap.min.css` (v5.3.3); default OFF; kasowana przez uninstaller.
Sekcje: fieldset+legend (`fs-5`). Oryginalny szkic zakresu poniżej (zrealizowany).



**Cel:** publiczny formularz renderowany z klasami **Bootstrap 5** (czyste BS5),
by wpasować się w motywy budowane na BS5. Założenie: motyw ładuje Bootstrap 5
(wtyczka nie bundluje BS — do potwierdzenia w spec: opcjonalny enqueue CDN/lokalny
jako fallback?).

**Stan obecny:** `FormRenderer` emituje własne klasy `evreg-*` + statyczny
`assets/public/form.css` (minimalny). Pojedynczy checkbox ma już inline label
(`.evreg-checkbox-label`).

**Zakres do zaplanowania:**
- Mapowanie kontrolek na BS5: `form-control` (input/textarea/select),
  `form-select` (select), `form-check` + `form-check-input`/`form-check-label`
  (radio/checkbox/checkbox-group), `form-label`, `invalid-feedback` + `is-invalid`
  (błędy), `row`/`col` lub `mb-3` dla odstępów, `btn btn-primary` (submit).
- Zachować inwarianty 3B: namespacing `evreg_field[...]`, honeypot/nonce/ts,
  `action` = kanoniczny permalink, re-render błędów z wartościami, escaping,
  conditional `data-evreg-when-*` (spójne z B1).
- Sekcje: `fieldset`/`legend` lub `card` — decyzja w spec.
- Kompatybilność wsteczna: czy zostawić tryb „gołych" klas `evreg-*`? (rozważyć
  — patrz odrzucony „konfigurowalny preset"; MVP = czyste BS5).
- `assets/public/form.css` prawdopodobnie zbędny/minimalny przy BS5.

**Powiązanie:** B1 i B2 dotykają tego samego renderu (`FormRenderer`) — rozważyć
kolejność/wspólny plan, by nie przerabiać renderu dwa razy. Sugerowana kolejność:
B2 (nowa baza klas) → B1 (dołożyć atrybuty warunków na już-BS5 render).

**Kontekst użytkownika:** buduje większość skórek na Bootstrap 5 — stąd wybór BS5.

---

## B3 — Wersje językowe (WordPress + Polylang)

**Status B3a:** ZROBIONE (2026-09-18) — Domain Path + `load_plugin_textdomain` (`src/I18n.php`), `languages/` z en_US (151 stringów), scripty `i18n:pot`/`i18n:mo`, testy integracyjne wymuszają pl_PL.

**Status B3b:** ZROBIONE (2026-09-18) — meta `_evreg_i18n` + `ContentTranslator` (domena) + wpięcie w `EventFormLoader` (język przez `CurrentLanguage`/filtr `evreg_current_language`) + `I18nController` REST + zakładka Tłumaczenia (macierz string×język, języki z Polylang). Pozostaje B3c (mail/link/formaty locale).

**Cel:** wielojęzyczność wtyczki na stronach z **Polylang** — formularz,
komunikaty, maile i etykiety w języku strony/odwiedzającego.

**Stan obecny:** wszystkie stringi UI przez i18n (text domain `event-registration`),
ale brak tłumaczeń (.po/.mo) i brak integracji z Polylang. Konfiguracja eventu
(schema/typy/nocleg/szablony maili) jest jednojęzyczna (meta per post).

**Do zaplanowania (spec):**
- Tłumaczenia stringów wtyczki: `languages/` + `load_plugin_textdomain`,
  pot/po/mo; ewentualnie translation-ready dla wtyczek tłumaczących.
- Treści konfigurowane per event (etykiety pól, typy, szablony maili) — jak
  tłumaczyć? Opcje: (a) osobny event per język (Polylang tłumaczy CPT
  `evreg_event`, każdy język = własna konfiguracja), (b) pola wielojęzyczne w
  schemie, (c) integracja z rejestrem stringów Polylang. Rozstrzygnąć w spec.
- Język maila potwierdzającego = język zgłoszenia/strony (obecnie jeden zestaw
  `DefaultTemplates`/meta).
- Link potwierdzenia i strona docelowa w odpowiednim języku (`form_page_id`
  per język?).
- Data/waluta/format wg locale.

**Zależności:** dotyka `FormRenderer`, `PlaceholderFactory`/szablonów maili,
`SettingsScreen`, `EventFormLoader`. Duży temat — prawdopodobnie własna
roadmapa, nie pojedynczy plan.

---

## B4 — Opcja pola „unikalne" (walidacja unikalności per pole)

**Cel:** flaga „unikalne" na polu schematu — wartość nie może się powtórzyć
w obrębie eventu (np. numer PWZ — prawo wykonywania zawodu — jeden numer =
jedno zgłoszenie).

**Stan obecny:** jest TYLKO unikalność e-maila na rezerwacji publicznej —
`ReservationService::reserve` woła `RegistrationRepository::activeRegistrationExists(event, email)`
→ `ReservationResult::duplicate()`. Indeks `idx_event_email` jest NIE-unikalny
(miękki guard w kodzie). `editAnswers` (admin) świadomie NIE sprawdza duplikatu.
Brak mechanizmu unikalności dla dowolnego innego pola.

**Do przemyślenia — ZASADNOŚĆ (uwaga użytkownika):** skoro e-mail jest już
unikalny per event, lekarz chcący zdublować numer PWZ musiałby użyć innego
e-maila — więc osobna flaga „unikalne" na PWZ łapałaby dokładnie ten przypadek
(inny mail, ten sam numer). Pytanie czy realny/warty obsługi. Rozstrzygnąć
przed budową; być może niepotrzebne.

**Jeśli robimy (szkic zakresu):**
- Flaga `unique` na polu w builderze (checkbox obok „wymagane"; ops + testy).
- Walidacja: sprawdzenie kolizji wartości w obrębie eventu przy submicie
  (i przy edycji admina?). Gdzie liczyć — odpowiedzi trzymane jako JSON w
  `evreg_registrations.data`; zapytanie po wartości pola = skan/`JSON_EXTRACT`
  (MySQL 5.7+) albo osobna kolumna/indeks dla pól unikalnych (wydajność).
- Komunikat błędu per pole (jak `roommate_not_allowed`/`invalid_email`).
- Spójność z edycją admina (`editAnswers` dziś pomija duplikat-email — czy
  „unikalne" ma tam działać?).
- Normalizacja porównania (trim, wielkość liter dla numerów/tekstu).
