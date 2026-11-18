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

**Status B3c-1:** ZROBIONE (2026-09-18) — kolumna `lang` w `evreg_registrations` (db v4), `TemplateResolver::resolve(event, key, lang='')` rozstrzyga per język przez `_evreg_i18n[lang].mail` (fallback per pole → baza → default), `MailQueue::enqueue` i `Subscriber` przekazują język zgłoszenia (uczestnik) / `''` (organizator), przełącznik języka w `MailTemplatesTab`.

**Status B3c-2:** ZROBIONE (2026-09-19, master ebec7fa, v0.1.3) — `PlaceholderFactory::confirmationUrl` lokalizuje stronę potwierdzenia (`form_page_id`, inaczej event) na język zgłoszenia przez `pll_get_post` przed `get_permalink`; strona bazowa gdy `lang=''`/brak Polylang. Filtr `evreg_confirmation_page_id($post_id,$lang)` = nadpisanie mapowania + seam testowy (Polylang nieobecny w wp-env). Komunikat landing idzie za locale strony przez `__()` (bez zmian w `ConfirmationController`). E2E ścieżki językowej do ręcznej weryfikacji na stronie z Polylang.

**Status B3c-3:** ZAMKNIĘTE 2026-09-19 jako NIE DOTYCZY (nie budowane). Dwie części, obie odpadają:
1. **Formaty daty/waluty locale — YAGNI, brak powierzchni.** Waluta pojawia się tylko w eksporcie CSV (`RegistrationsExporter.php:85` `number_format(price, 2, '.', '')` = format maszynowy, świadomie NIE lokalizowany — CSV musi być stabilnie parsowalny). Daty tylko surowe UTC w CSV. W mailu brak placeholdera ceny/daty; formularz nie pokazuje cen. Ożywić dopiero gdy dodamy widoczną cenę/datę w mailu lub formularzu (wtedy osobny pomysł, nie B3c-3).
2. **Admin schema/eksport w języku bazowym (notatka z B3b) — bezprzedmiotowe pod założeniem.** Przyjęte założenie (2026-09-19): **panel admina obsługiwany tylko po polsku, a polski = język domyślny Polylang.** Wtedy `CurrentLanguage::get()`='pl', overlay `_evreg_i18n['pl']` jest pusty z definicji (baza nie trafia do overlay) → `ContentTranslator` zwraca bazę → bug nie występuje. **WARUNEK OŻYWIENIA:** jeśli wtyczka trafi na stronę, gdzie domyślny język Polylang ≠ polski, a admin pracuje po polsku, to schema/CSV pokazałyby polski overlay zamiast bazy — wtedy wymusić język bazowy dla ścieżek admin-facing (np. `EventFormLoader::loadBase()` albo zerowanie `evreg_current_language` w kontekście admina).

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

---

## B5 — Osoba towarzysząca (companion) + podwójne zajęcie noclegu — ZROBIONE (2026-09-19)

**Status:** scalone. Checkbox „Osoba towarzysząca" + pole imienia (public form + edycja admina), konfigurowalne w noclegu (`companion_enabled`, `companion_counts_event`). Companion + nocleg = 2 miejsca ze slotu (`seats` na bookingu, occupancy `SUM(seats)`), cena ×2 pozycji noclegu; opcjonalnie +1 do `global_cap` (nigdy do limitu typu). Kolumny `companion`/`companion_name` (db v5). Companion-aware we WSZYSTKICH ścieżkach: `reserve`, `editAnswers`, `promoteFromWaitlist` (ta ostatnia dodana w trakcie — plan pominął). Surfacing: detal, edycja, CSV, mail (`{osoba_towarzyszaca}` + podsumowanie), WP Privacy exporter + eraser (czyści imię, zostawia flagę). Spec/plan: `docs/superpowers/{specs,plans}/2026-09-19-companion-person*`. Szkic zakresu poniżej (zrealizowany).

**Cel:** checkbox „osoba towarzysząca"; po zaznaczeniu pokazuje się pole
tekstowe „imię i nazwisko osoby towarzyszącej". Jeśli osoba towarzysząca
występuje **i** wybrany jest nocleg → z puli noclegu (inventory pakiet+pokój)
zdejmowane są **2 miejsca zamiast 1**.

**Stan obecny (do potwierdzenia w spec):**
- Warunkowa widoczność pól JEST (B1) — checkbox→pole tekstowe da się złożyć
  istniejącym `Condition` (trigger = checkbox, operator `not_empty`/`equals`).
  Więc UI „pokaż pole gdy zaznaczone" nie wymaga nowego silnika.
- **Zajętość noclegu liczona 1:1.** Rezerwacja noclegu = jeden wiersz
  `evreg_accommodation_bookings`; zajętość pakietu/pokoju liczona względem
  `capacity` z `InventoryItem`. Domena: `src/Domain/Accommodation/`
  (`AccommodationConfig`, `InventoryItem`, `AccommodationSelection`),
  transakcyjne liczenie w `ReservationService::reserve` (lock→count→decide) i
  `editAnswers` (`occupancyExcluding`). Nigdzie nie ma pojęcia „liczba osób na
  jednym bookingu" — to jest sedno zmiany.

**Trudna część (nie UI, lecz liczenie miejsc):**
- Booking musi nieść **liczbę zajmowanych miejsc** (1 albo 2). Opcje:
  (a) kolumna `seats`/`occupancy` na `evreg_accommodation_bookings` (migracja,
  bump `Migrations` DB_VERSION), zajętość = `SUM(seats)` zamiast `COUNT(*)`;
  (b) dwa wiersze bookingu na jedno zgłoszenie (prostsze liczenie, gorsze
  modelowo — współlokator/anonimizacja/eksport muszą to ogarnąć). Rekomendacja
  wstępna: (a) kolumna seats + `SUM`.
- **Inwariant lock→count** (3A) bez zmian: nadal `lockEvent` przed liczeniem,
  ale count = suma miejsc. Dotknąć zarówno `reserve` jak `editAnswers`
  (`occupancy`/`occupancyExcluding` w `RegistrationRepository`).
- Decyzja pojemności (`decide`) musi uwzględnić, że wniosek prosi o 2 miejsca —
  odrzucić/na-waitlistę gdy zostało tylko 1 (dziś bramka zna tylko 1).
- **Pojemność TYPU zgłoszenia** (`global_cap`/limit typu) — czy osoba
  towarzysząca liczy się też do limitu miejsc na wydarzeniu/typie, czy tylko do
  noclegu? Rozstrzygnąć w spec (wpływa na `reserve` occupancy zgłoszeń, nie
  tylko noclegu).
- Cena: czy osoba towarzysząca dopłaca (drugie miejsce noclegowe = druga cena
  pokoju?) — `PriceCalculator`. Rozstrzygnąć w spec.

**Powierzchnie do dotknięcia (szkic):** schema/builder (nowe pole checkbox +
tekstowe, prawdopodobnie oznaczone semantycznie jako companion, nie zwykłe
pola — inaczej silnik noclegu ich nie rozpozna), `SubmissionAssembler`
(ekstrakcja flagi + imienia towarzysza), domena noclegu (seats), migracja,
`ReservationService` (reserve+editAnswers), `PriceCalculator`,
`RegistrationExportMapper`/`PlaceholderFactory` (pokazać towarzysza),
Privacy eraser (anonimizacja imienia towarzysza).

**Uwaga:** to NIE jest bounded — dotyka transakcyjnego rdzenia rezerwacji i
modelu danych noclegu. Własny spec → plan → SDD.

---

## B6 — Tłumaczenie labeli noclegów (i18n, uzupełnienie B3b) — ZROBIONE (2026-09-19)

**Status:** scalone. Overlay `_evreg_i18n[lang]` dostał bucket `accommodation: { packages:{key:label}, rooms:{key:label} }`. `ContentTranslator::translateAccommodation` nakłada labele pakietów/pokojów przed `SchemaAssembler` (wołane w `EventFormLoader`); `translatableItems` + `get/setAccommodationTranslation` w `i18nOps` (`langBucket` whitelistuje bucket). Macierz w `TranslationsTab` listuje wiersze noclegu. Tylko labele — klucze/ceny/pojemności nietknięte (data-safe). „Bez noclegu"/label współlokatora zostają stałymi stringami pluginu (B3a/.po). Szkic zakresu poniżej (zrealizowany bez bucketu `misc` — te stringi nie są per-event).

**Cel:** labele pakietów, pokojów, opcji „Bez noclegu" i pola współlokatora
tłumaczone per język (Polylang), jak reszta treści formularza.

**Stan obecny:** overlay `_evreg_i18n` (B3b) NIE ma bucketu `accommodation`.
`ContentTranslator` i `i18nOps.translatableItems` obejmują tylko
sekcje/pola/opcje/typy (+ `mail` z B3c-1). Labele noclegu pochodzą z
`AccommodationConfig` (osobne meta), scalane przez `SchemaAssembler` — zawsze
język bazowy. Świadomie wykluczone w spec B3b.

**Szkic zakresu:** dodać bucket `accommodation` do overlay
(`{ packages:{key:label}, rooms:{key:label}, misc:{no_accommodation, roommate_label} }`),
rozszerzyć `ContentTranslator` o nakładkę na `AccommodationConfig` przed
`SchemaAssembler`, dodać pozycje noclegu do `translatableItems` (macierz w
`TranslationsTab`), ops w `i18nOps.js` z immutable set/get. Tylko labele —
klucze/ceny/pojemności nietknięte (data-safe, jak B3b). Zależność: może kolidować
z B5 (jeśli B5 zmienia model noclegu) — zrobić po B5 albo skoordynować.

---

## B7 — Wycena noclegu bramkowana przyznaniem (pre-existing bug, ujawniony przez B5) — ZROBIONE (2026-09-19)

**Status:** scalone. `reserve()` liczy cenę noclegu z `$decision->accommodationGranted ? $selection : null` — przy `accommodation_full` (brak bookingu) cena = tylko typ (companion bez ×2). `promoteFromWaitlist()` odrzuca (`invalid_status`) zgłoszenie z typem usuniętym z configu (guard jak `editAnswers`), zamiast cichego zerowania `price_total`. `editAnswers` był już bezpieczny (odrzuca edycję `accommodationFull` przed wyceną). Jeden plik `ReservationService`.

**Cel:** cena zgłoszenia nie powinna zawierać opłaty za nocleg, którego NIE
przyznano. Dziś `ReservationService::reserve` liczy `price_total` przez
`PriceCalculator::total($type, $accommodation, $selection, $companion)` gdy
`$selection` istnieje — **niezależnie od `accommodationGranted`**. Gdy slot
pełny (`accommodation_full`, granted=false) zgłoszenie wchodzi bez bookingu,
ale nadal jest obciążone ceną noclegu (a przy companionie **2×**, bo
`accommodation_full` jest częsty — companion potrzebuje 2 miejsc). To
pre-existing (dotyczy też nie-companion), ujawnione i zaznaczone w whole-branch
review B5. `promoteFromWaitlist` i (nowo) tylko tam zostało już zbramkowane w
B5; `reserve` (i sprawdzić `editAnswers`) — NIE.

**Powiązany edge (config-drift):** `promoteFromWaitlist` z nieznanym/usuniętym
`type_key` (typ skasowany z configu po zawaitlistowaniu) → `decide` traktuje
brak typu jak brak limitu → Accepted → nowy kod zeruje `price_total`. Brak
guardu nieznanego typu (jaki ma `editAnswers`). Nie-blokujące, wartość ceny.

**Szkic:** w `reserve` (i `editAnswers` jeśli dotyczy) liczyć cenę noclegu z
`$decision->accommodationGranted ? $selection : null` (jak zrobiono w
`promoteFromWaitlist` w B5). Dodać guard nieznanego typu w `promoteFromWaitlist`.
Testy: accommodation_full → `price_total` = tylko typ (bez noclegu, bez ×2).
Mała zmiana, ale dotyka wyceny wszystkich zgłoszeń → własny mini-plan + testy
regresji istniejących cen.

---

## B8 — Eksport zgłoszeń do XLSX obok CSV — MAŁO ISTOTNE (2026-09-20)

**Priorytet:** niski (na teraz). CSV wystarcza; XLSX to wygoda.

**Cel:** drugi format eksportu (XLSX) obok istniejącego CSV, per event.

**Stan obecny:** `RegistrationsExporter::buildCsv` składa nagłówki
(tożsamość + labele pól + Nocleg×3) i wiersze przez `fputcsv` do `php://temp`,
zwraca BOM+string. `RegistrationExportMapper` (domena) już daje kolumny/komórki.

**Szkic zakresu:**
- Wydzielić budowanie wierszy (`headers + rows[]`) z `buildCsv` → wspólne dla
  CSV i XLSX (dziś zaszyte w `fputcsv`).
- Dodać `XlsxExporter` na lekkiej bibliotece **openspout/openspout** (MIT,
  streaming, mało zależności; PhpSpreadsheet za ciężki).
- Wpiąć `format=xlsx` w `RegistrationsScreen::handle_export` + drugi
  przycisk/dropdown formatu.
- Testy: wspólny test wierszy + smoke XLSX.

**Koszt/uwagi:** główny koszt = nowa zależność composer i wzrost commitowanego
`vendor/` (--no-dev) w zipie. openspout pisze komórki jako string (nie formuła)
→ mniejsze ryzyko injection; neutralizację można zachować dla spójności.
Świadomie pominięte w 5C z powodu biblioteki+vendor; technicznie proste.

---

## B9 — Tłumaczenie opisu sekcji (i18n, drobna luka)

**Priorytet:** niski. Analogiczna luka do B6/short-label/description pola.

**Stan:** `Section->description` renderuje się na formularzu
(`FormRenderer:72`), ale `ContentTranslator::apply` tłumaczy tylko **tytuł**
sekcji, nie opis. Overlay nie ma dla opisu sekcji miejsca.

**Szkic:** dodać bucket/klucz `sectionDescriptions` do overlay (jak
`descriptions` dla pól), nakładać na `section['description']` w `ContentTranslator`,
dopisać wiersze do `translatableItems` + get/set w `i18nOps` + routing w
`TranslationsTab`. Tylko labele/teksty (data-safe). Mały, mechaniczny mirror.

## B10 — aria-describedby dla help textu pola (a11y)

**Priorytet:** niski. Drobiazg dostępnościowy.

**Stan:** help text pola renderuje się jako `<p class="evreg-field-desc form-text">`
pod kontrolką (`FormRenderer::renderField`), ale nie jest powiązany z kontrolką
przez `aria-describedby`, więc czytniki ekranu nie łączą opisu z polem.

**Szkic:** nadać `<p>` `id="evreg-<key>-desc"` i dodać `aria-describedby` do
kontrolki w `renderControl` (gdy pole ma opis). Uwaga: przy błędzie walidacji
warto też wskazać komunikat błędu (`aria-describedby` może mieć wiele id).
Analogicznie rozważyć w `RegistrationEditForm`.

---

## B11 — Tłumaczenie opisów opcji i opisów pomocniczych pól

**Priorytet:** niski. Uzupełnienie overlay i18n (B3b/B6) o treści dodane w opisach.

**Stan:** overlay `_evreg_i18n[lang]` tłumaczy m.in. `options` (etykiety opcji) oraz
`descriptions` (opis pomocniczy pola). **Opis opcji** (`options[].description`,
pokazywany po wybraniu opcji) nie ma swojego bucketu — w innym języku wróci wersja
bazowa.

**Szkic:** dodać bucket `optionDescriptions: { fieldKey: { optionValue: tekst } }`
(kształt jak istniejący `options`), nałożyć w `ContentTranslator::apply` obok etykiet
opcji, dopisać wiersze do `translatableItems` + get/set w `i18nOps`, routing w
`TranslationsTab`. Uwaga: treść bywa HTML-em (link), więc overlay musi przechodzić
przez tę samą ścieżkę sanitizacji co `description` (wp_kses_post), a nie
sanitize_text_field.

---

## B12 — Promocja z waitlisty nie ponawia wybranego noclegu — **ZAMKNIĘTE**

**Naprawione** na gałęzi `feature/backlog-cleanup`: `promoteFromWaitlist` bez bookingu czyta
życzenie z JSON-a odpowiedzi (`accommodationWish()` — bliźniak `nightWish()`, klucz pola
z `accommodationFieldKey()`, oba zapisane kształty przez
`AccommodationSelection::normalizeArray()`) i próbuje je przyznać **po cenniku bieżącym**;
to jest odpowiedź na decyzję produktową, której szkic poniżej nie podjął. Rozstrzygnięcie
rozchodzi się wg POCHODZENIA wyboru, bo życzenie i przyznane łóżko to różne fakty:
życzenie, którego pokój dziś nie zgadza się z flagą towarzyszącej, jest **zdejmowane**
z kodem `promoted_no_accommodation` (odmowa zamieniłaby JEDNĄ zmianę konfiguracji w N
ręcznych edycji i trzymała ludzi na liście mimo wolnych miejsc), a selekcja odtworzona
z WIERSZA bookingu nadal odmawia `occupancyMismatch()` — to łóżko system realnie przyznał.
Nocleg poza tym jednym wypadkiem nigdy nie blokuje samego awansu. Testy:
`tests/Integration/Services/PromoteFromWaitlistTest.php`. Opis luki zostaje poniżej dla zapisu.

**Priorytet:** średni. Luka funkcjonalna, bez ryzyka dla danych (nic nie jest zepsute ani przesprzedane).

**Stan:** `ReservationService::reserve` wstawia booking noclegu tylko gdy
`$decision->accommodationGranted` jest true, a decyzja waitlistowa zawsze niesie
`accommodationGranted === false`. Zwykły przepływ — ktoś wybiera nocleg, wydarzenie jest
pełne, trafia na waitlistę — zapisuje wybór w kolumnie `data` zgłoszenia i **nie tworzy
wiersza bookingu**. `ReservationService::promoteFromWaitlist` wyprowadza selekcję do
ponownej decyzji wyłącznie z `findAccommodationBooking()`; bez bookingu `$selection` to
`null`, więc (a) wybrany nocleg nie jest ponawiany, nawet gdy miejsca się zwolniły, oraz
(b) nowy wynik `promoted_no_accommodation` nie może wystąpić — admin widzi zwykły komunikat
„Awansowano" bez śladu, że ta osoba chciała łóżka.

**Skutek:** kod `promoted_no_accommodation` odpala się tylko dla zgłoszenia waitlistowego,
które zyskało booking przez `editAnswers` (wąski przypadek opisany w `ReserveCompanionTest`
jako pozostały booking po edycji na etapie waitlisty). Nic nie jest przesprzedane, a
`PlaceholderFactory::accommodationLabel` czyta booking, więc żaden mail nie obiecuje łóżka,
którego nie ma.

**Szkic:** gdy brak bookingu, sięgnąć po selekcję zapisaną w `data` zgłoszenia. Wymaga to
schemy wewnątrz transakcyjnego serwisu (dziś nic o schemach nie wie) oraz decyzji
produktowej, której nikt nie podjął: czy promocja ma zajmować łóżko wybrane tygodnie
wcześniej, być może po zmienionej od tego czasu cenie?

---

## B13 — Stringi panelu React nie są tłumaczalne w ogóle

**Priorytet:** niski, dopóki obowiązuje założenie „panel admina tylko po polsku" (B3c-3).

**Stan:** komponenty w `assets/admin/` konsekwentnie owijają literały w `__()` z
`@wordpress/i18n` i inwariant z CLAUDE.md („wszystkie stringi UI, PHP i JS, przez i18n")
jest formalnie spełniony. Ale **brakuje całej maszynerii po stronie WordPressa**:
`wp_set_script_translations()` nie jest wołane nigdzie w `src/` ani w bootstrapie wtyczki,
a `npm run i18n:pot` wyklucza `assets/admin` (i `build`), więc żaden string JS nie trafia
do katalogu. Bez wczytanego katalogu JS `__()` zwraca literał bez zmian — niezależnie od
tego, czy w `.pot` cokolwiek by było.

**Skutek:** dziś żaden. Założenie jest, że admin jest polski, a polski to język źródłowy,
więc literał = tłumaczenie. Pułapka polega na tym, że owinięcie w `__()` wygląda jak
gotowość do tłumaczenia, której nie ma — ktoś może uznać, że wystarczy dorzucić `.po`.

**Szkic:** `wp_set_script_translations( 'evreg-admin', 'event-registration', plugin_dir_path(...) . 'languages' )`
przy enqueue bundla, zdjęcie `assets/admin` z `--exclude` w `i18n:pot` (albo skanowanie
`build/` zamiast źródeł), plus `wp i18n make-json` w ścieżce budowania `.mo`. Dopiero wtedy
`.po` dla panelu ma sens.

---

## B14 — Ukryte warunkowo pole `required` blokuje wysyłkę bez komunikatu — **ZAMKNIĘTE**

**Naprawione** na gałęzi `feature/backlog-cleanup`: `assets/public/form.js` dostał
`syncRequired( form )`, wołane z `apply()` zaraz po przełączeniu `hidden`. Przenosi
`required` na/z atrybutu zapasowego `data-evreg-required` (stan odtwarza się przy ponownym
pokazaniu), licząc go dla KAŻDEJ kontrolki osobno przez
`control.closest( '[data-evreg-when-field][hidden]' )` — pole może mieć kilku warunkowych
przodków, więc oglądanie jednego kontenera nie wystarczy. Drugą połową naprawy jest
**jeden właściciel atrybutu**: `required` imienia osoby towarzyszącej należy WYŁĄCZNIE do
`applyCompanion` (ustawia je na `on && ! input.closest( '[data-evreg-when-field][hidden]' )`),
a `syncRequired` pomija `[data-evreg-companion-input]` — dwóch właścicieli jednego atrybutu
było tu prawdziwym zagrożeniem, bo sekcja schowana warunkiem wymagałaby pola, które
`applyCompanion` właśnie odwymagało. Serwer był arbitrem już wcześniej, więc zmiana jest
czysto kliencka. E2E: `tests/e2e/conditional-visibility.spec.js`. Opis pułapki zostaje
poniżej dla zapisu.

**Priorytet:** średni (pułapka cicha — formularz po prostu nie wysyła się).

**Stan:** `FormRenderer::renderControl` dokłada atrybut `required` każdemu polu z
`$field->required`, niezależnie od tego, czy pole leży w sekcji/polu schowanym przez
warunkową widoczność (B1). `assets/public/form.js::apply()` przełącza tylko
`el.hidden` na kontenerze `[data-evreg-when-field]` — **nie zdejmuje `required`** z
kontrolek w środku. Kontrolka ukryta przez `display:none` nadal podlega walidacji
HTML, więc przeglądarka nie może jej sfokusować: Chrome loguje „An invalid form
control with name='…' is not focusable" i **po cichu przerywa submit**. Człowiek
klika „Wyślij" i nic się nie dzieje.

**Nie dotyczy** pola imienia osoby towarzyszącej — tam `required` chodzi w parze z
widocznością (serwer renderuje warunkowo, `applyCompanion` przełącza), i to jest
wzór do powtórzenia.

**Szkic:** w `apply()` po ustawieniu `el.hidden` przelecieć
`el.querySelectorAll('[required], [data-evreg-required]')` i przenosić `required`
na/z atrybutu zapasowego (`data-evreg-required`), żeby stan dał się odtworzyć przy
ponownym pokazaniu. Serwer i tak jest arbitrem i już jest świadomy warunków
(`SubmissionAssembler` buduje `Validator` z `VisibilityResolver`, więc pole schowane
warunkowo nie jest wymagane po stronie serwera), czyli zmiana jest czysto kliencka.

---

## B15 — Edycja zgłoszenia w adminie renderuje nocleg jako niezaznaczony — **ZAMKNIĘTE**

**Naprawione** na gałęzi `fix/answers-json-accommodation-shape`: `AccommodationSelection` implementuje `JsonSerializable` (nowe zapisy mają klucze kanoniczne), a `normalizeArray()` czyta oba kształty, więc wiersze zapisane wcześniej też prefillują się poprawnie. Opis błędu zostaje poniżej dla zapisu.

**Priorytet:** średni, ale to **żywy błąd w wydanej wersji**, nie dług projektowy.

**Stan:** `ReservationService` zapisuje odpowiedzi jako
`wp_json_encode( $request->data )`, a `$data` to `$values` z walidacji, gdzie wpis pola
noclegu jest **obiektem** `AccommodationSelection` (tak go zwraca
`AccommodationValidator::validate` → `FieldOutcome::valid( $selection )`). Obiekt nie
implementuje `JsonSerializable`, więc `wp_json_encode` zapisuje nazwy właściwości:
`{"packageKey":"p1","roomKey":"single","roommatePref":""}`.

Czytające miejsca oczekują kluczy z `AccommodationSelection::toArray()`, czyli
`package`/`room`/`roommate`:
- `RegistrationEditForm::renderAccommodation` (`$current` z `$value['package']`),
- `RegistrationsScreen::render_answers` (fallback `package_key`/`room_type_key`).

`RegistrationsScreen::render_edit` prefilluje formularz **wyłącznie** z
`json_decode( $row['data'] )` (dokłada tylko `evreg_companion*` z kolumn), więc przy
edycji zgłoszenia z noclegiem **żadna pozycja noclegu nie jest zaznaczona**. Detal to
maskuje, bo woli wiersz bookingu.

**Skutek:** admin otwiera edycję, widzi nocleg jako niewybrany i albo dostaje błąd
walidacji (gdy pole jest wymagane), albo zapisuje zgłoszenie **bez noclegu** — co
w `editAnswers` kasuje booking i przelicza cenę w dół. Cicho.

**Szkic:** zapisywać `->toArray()` zamiast obiektu (np. normalizacja w
`SubmissionAssembler` przed złożeniem `AssembledSubmission`), a czytające miejsca na czas
przejściowy przyjmować **oba** kształty (stare wiersze w bazie mają już złe klucze).
Test: round-trip zapis → `render_edit` → zaznaczona właściwa pozycja. Osobna zmiana od
pul dobowych; tryb `nights` świadomie zapisuje listę kluczy, nie obiekt, żeby tego nie
odziedziczyć.

---

## B16 — `ReservationService` zduplikowany po gałęziach `nights` — ZROBIONE CZĘŚCIOWO (2026-11-17)

**Status:** duplikacja zniesiona, długość nie. Na gałęzi `feature/backlog-cleanup` z każdej
z trzech rzeczy wymienionych w „Szkicu" została jedna kopia: budowa `CapacityLimits`
6 miejsc → 1 (`limits()` za nazwanymi `classicLimits()`/`nightsLimits()`), budowa wierszy dób
3 → 1 (`nightRows()` + `writeNightRows()`), odczyt zajętości 9 miejsc → 2
(`decideUnderLock()`, `blockingNightUnderLock()`). Wszystkie transakcje przechodzą przez
`RegistrationRepository::beginTransaction()/commit()/rollback()` — **poza repozytorium nie
ma już ani jednego inline `START TRANSACTION`/`COMMIT`/`ROLLBACK`**. Zachowanie
niezmienione (refaktor pod pełną siatką testów integracyjnych), inwariant
`lockEvent` → zliczenie nietknięty: helpery świadomie NIE biorą locka same, a nazwy
`*UnderLock` są kontraktem wołania.

**POZOSTAJE:** plik ma nadal 1616 linii, czyli objaw z nagłówka („ponad 1300") nie zniknął.
Task świadomie zabronił rozdzielania ścieżek (`reserve`/`reserveNights`,
`editAnswers`/`editAnswersNights`, `promoteFromWaitlist`/`promoteFromWaitlistNights`), bo
cięcie kodu transakcyjnego to osobna decyzja z własnym ryzykiem, a nie dokończenie tego
wpisu. Gdy wrócimy: dzielić po TRYBIE (klasyczny vs doby), nie po czasowniku — dzisiejsze
pary metod już tak stoją.

**Priorytet:** średni (dług, który już raz kosztował błąd).

**Stan:** plik ma ponad 1300 linii i cztery gałęzie `nights`; budowa `CapacityLimits`, budowa wierszy dób i sprawdzenie zliczeń istnieją w trzech–czterech kopiach (`reserve`, `editAnswers`, `promoteFromWaitlist`, oraz ścieżki pomocnicze).

**Skutek:** ryzyko rozjazdu jest realne — projekt już to przerobił przy arytmetyce towarzyszącej (B5), gdzie jedna ścieżka została poprawiona, a pozostałe nie. Poprawka w jednej kopii wygląda na kompletną.

**Odłożone**, bo wyciąganie kodu transakcyjnego w połowie planu jest bardziej ryzykowne niż zostawienie go, a żaden z pozostałych tasków nie dodał kolejnej gałęzi.

**Szkic:** wydzielić prywatne metody/obiekt pomocniczy dla (a) budowy `CapacityLimits` z configu + flagi, (b) budowy wierszy dób ze snapshotem etykiety i ceny, (c) sprawdzenia zliczeń pod lockiem. Zachować kolejność `lockEvent` → zliczenie (nośny inwariant 3A); refaktor pod pełną siatką testów integracyjnych, bez zmiany zachowania.

---

## B17 — Wyścig sprawdzenie-zapis przy zmianie trybu noclegu — **ZAMKNIĘTE**

**Naprawione** na gałęzi `feature/backlog-cleanup`: `EventConfigController::update_config`
owija sprawdzenie (`mode_change_locked`) i zapis (`EventConfigRepository::save`) w JEDNĄ
transakcję pod tym samym wierszem `evreg_locks`, który biorą ścieżki rezerwacji
(`beginTransaction` → `lockEvent` → sprawdzenie → `save` → `commit`; odmowa 409 i rzut idą
przez `rollback`). Kolejność `lockEvent` przed pierwszym odczytem zajętości — jak w
`reserve`, nie zmieniać. `build_payload()` (kolejne odczyty zajętości) przeniesione ZA
`commit`, żeby żadna rezerwacja nie czekała dłużej, niż trzeba. Przy okazji
`RegistrationRepository` dostał `beginTransaction()`/`commit()`/`rollback()`, których używa
teraz cały rdzeń (B16). Test: `tests/Integration/Rest/AccommodationModeTest.php`. Opis
wyścigu zostaje poniżej dla zapisu.

**Priorytet:** niski.

**Stan:** `eventHasAccommodation()` i `save()` nie siedzą pod `lockEvent`, więc pierwsze zgłoszenie z noclegiem może zostać zatwierdzone między jednym a drugim i zostawić booking pod starym trybem.

**Skutek:** wąski (wymaga commitu między dwoma instrukcjami jednego żądania admina), widoczny w panelu zajętości, odwracalny przełączeniem trybu z powrotem.

**Szkic:** wykonać sprawdzenie i zapis konfiguracji pod `lockEvent` (ta sama blokada wiersza `evreg_locks`, którą biorą ścieżki rezerwacji).

---

## B18 — Kolejka maili wciąż może stanąć przez event klasyczny — **ZAMKNIĘTE**

**Naprawione** na gałęzi `fix/classic-mail-queue-stall`: `accommodationLabel` łapie `SchemaException` i degraduje do pustej konfiguracji, czyli do snapshotów etykiet z bookingu (a w ostateczności surowych kluczy), tak jak eksport. Test integracyjny stawia niespójny inwentarz pod istniejącym bookingiem i asertuje, że mail buduje się z czytelnym noclegiem. Opis problemu zostaje poniżej dla zapisu.

**Priorytet:** średni (zatrzymuje WSZYSTKIE eventy), choć wymaga niespójnych danych.

**Stan:** `PlaceholderFactory::accommodationLabel` woła `AccommodationConfig::fromArray` dla eventów klasycznych, więc event klasyczny z zalegającym lub niespójnym inwentarzem kończy `fromArray` wyjątkiem w dispatcherze odpalanym z crona. Wiersz zostaje w `sending`, sweep odzysku podejmuje go po pięciu minutach i znów wywala — cała kolejka stoi dla każdego eventu.

**Skutek:** istniało przed gałęzią `night-seat-pools` (nie zostało przez nią wprowadzone); ten sam promień rażenia, który ścieżka `nights` właśnie zamknęła.

**Szkic:** w ścieżce klasycznej łapać `SchemaException` i degradować etykietę noclegu (surowe klucze jak w eksporcie), tak jak robi to rozstrzygnięcie trybu z surowej konfiguracji; osobny test z niespójnym inwentarzem i wierszem w kolejce.

---

## B19 — Dwie drogi rozstrzygania trybu noclegu — ZROBIONE (2026-11-17), z dopiskiem

**Status:** scalone na gałęzi `feature/backlog-cleanup`. Jedno źródło prawdy o trybie:
`NightsResolver::isNightsRaw` → **`isNights( array $accommodation )`**,
`forSchema( FormSchema )` → **`forAccommodation( array $accommodation )`** (deleguje do
`isNights`, więc nie mogą się rozejść), a `isNightsMode` **usunięte** — nie miało
wołającego produkcyjnego. Droga obiektowa to teraz **`EventFormLoader::nights( int $event_id )`**
i rozstrzyga z konfiguracji PRZETŁUMACZONEJ, więc overlay etykiet dób (B6) nie jest
obchodzony — to był dokładnie skutek opisany niżej. Reguła „tryb jest właściwością EVENTU,
nie schematu" nie tylko zachowana, ale dopiero teraz egzekwowana: detal i edycja w adminie
pokazują zapisane doby także dla eventu, któremu pole noclegu usunięto z formularza.
Nazwy historyczne poniżej (`isNightsRaw`, `forSchema`, `isNightsMode`) NIE ISTNIEJĄ już
w kodzie — nie wskrzeszaj ich z tego opisu.

**Dopisek (nie zamyka się do końca):** `RegistrationsOverview`
(`src/Admin/RegistrationsOverview.php:94`) nadal buduje `NightsConfig::fromArray( $accommodation_raw )`
sam, z surowego meta, więc panel zajętości jest ślepy na overlay językowy. To NIE jest
rozjazd *trybu* — czyta konfigurację tego samego eventu, czyli dokładnie to, o co B19
prosiło — ale jest dosłownie tym skutkiem, który B19 zapowiadało, więc zostaje zapisane,
a nie zamknięte milczeniem. Szkic: brać obiekt z `EventFormLoader::nights()` i degradować
na `SchemaException`, jak reszta wejść.

**Priorytet:** niski.

**Stan:** powierzchnie czytające zapisane zgłoszenia (panel zajętości, `ReservationService`, mail, eksport CSV, WP Privacy) odczytują tryb z surowego meta przez `NightsResolver::isNightsRaw`; ścieżka publiczna formularza przechodzi przez `NightsResolver::forSchema`, bo potrzebuje nie samego trybu, a obiektu `NightsConfig`. Dziś są równoważne.

**Skutek:** powierzchnie czytające surowe meta nie zobaczą niczego, co przyszły overlay albo filtr nałoży przy składaniu schemy.

**Szkic:** jedno źródło prawdy o trybie, znoszące rozjazd między surowym meta a złożoną schemą — z zachowaniem dzisiejszej reguły, że tryb jest właściwością EVENTU, a nie schematu (patrz docblock `NightsResolver::isNightsMode`: event dobowy bez pola noclegu w formularzu nie ma w schemacie śladu trybu).

---

## B20 — `RegistrationsScreen` ma ponad 950 linii — ZROBIONE (2026-11-17)

**Status:** scalone na gałęzi `feature/backlog-cleanup`. 1053 → 508 linii. Oczywiste cięcie
ze „Szkicu" wykonane: renderowanie detalu, edycji i inline'owego re-renderu błędów edycji
wyszło do nowego **`RegistrationView`** (`src/Admin/`, instancyjne, wstrzykiwane
`RegistrationRepository` + `EventConfigRepository`), a mapa kod→komunikat do nowego
**`RegistrationNotice`**. Na ekranie zostały, jak zapowiadał szkic: router `render()`,
siedem handlerów `admin-post`, lista i eksport. Objaw z nagłówka zniesiony; że 508 linii
to nadal dwie rzeczy — patrz B29.

**Priorytet:** niski (dług strukturalny).

**Stan:** jedna klasa niesie listę, detal, edycję, komunikaty, siedem handlerów i eksport.

**Szkic:** oczywistym cięciem jest renderowanie detalu i edycji do osobnej klasy prezentacji; handlery admin-post zostają.

**Uwaga:** zdarzenie „ukryte pole `required` blokuje wysyłkę" jest już opisane jako B14 i nie wymaga uzupełnienia.

---

## B21 — Nocleg ukryty warunkową widocznością gubi się przy edycji w adminie — **ZAMKNIĘTE**

**Naprawione** na gałęzi `feature/backlog-cleanup`, głębiej niż szkic recenzenta poniżej:
„nie podano" jest teraz FAKTEM NIESIONYM PRZEZ ŻĄDANIE, a nie wyczytywanym z `null`.
`ReservationRequest` dostał dziesiąty parametr `$accommodationSupplied` (default `true`,
czyli zero regresji dla wołających) i akcesor `accommodationAnswered( bool $nights_mode )`
— jedyny nośnik tej odpowiedzi (dobowy czyta `nights !== null`, klasyczny tę flagę).
`SubmissionAssembler::accommodationSupplied()` to JEDYNY dom reguły: odpowiedź udzieloną
od nieudzielonej odróżnia OBECNOŚĆ klucza pola w wartościach z `Validatora` (pole widoczne
trafia tam zawsze, choćby z `null` = pusty wybór; schemat bez pola noclegu daje
„nie podano", żeby samo usunięcie pola z formularza nie kasowało wierszy zastanych
zgłoszeń). Edycja, która odpowiedzi nie podała, zostaje przy wyborze ZAPISANYM
(`storedSelection()` z bookingu na ścieżce klasycznej, `nights === null` na dobowej), więc
booking/wiersze dób, miejsca i cena zostają; świadoma rezygnacja przy polu WIDOCZNYM nadal
zdejmuje nocleg. `answersKeepingAccommodation()` przepisuje przy tym zapisany klucz noclegu
do JSON-a odpowiedzi — bez tego zgłoszenie z LISTY REZERWOWEJ, którego nocleg żyje
wyłącznie w JSON-ie, traciłoby życzenie i późniejszy awans nie miałby czego przyznać (B12).
Testy: `tests/Integration/Services/EditAnswersTest.php`,
`tests/Integration/Services/NightEditTest.php`. Opis utraty zostaje poniżej dla zapisu.

**Priorytet:** średni (cicha utrata danych i pieniędzy, ale wymaga zbiegu okoliczności).

**Stan:** `RegistrationEditForm` renderuje WSZYSTKIE pola schematu (`FormSchema::allFields()`), natomiast `Validator` zwraca wartości tylko pól WIDOCZNYCH (`VisibilityResolver::resolve(...)->visibleFields()`). Gdy pole noclegu ma warunek (B1) i edycja zmienia odpowiedź, od której ten warunek zależy — typowo typ zgłoszenia — pole przestaje być widoczne w trakcie tej właśnie edycji, więc jego wartość wypada z wyniku walidacji. Ekstrakcja widzi wtedy brak wyboru, a nie „nie podano": na ścieżce dobowej powstaje PODANY, pusty `NightSelection`, czyli świadoma rezygnacja.

**Skutek:** zaznaczone doby (klasycznie: wybrany pakiet i pokój) giną razem z wierszami, zajęte miejsca wracają do puli, a cena spada — pod komunikatem „Odpowiedzi zgłoszenia zaktualizowane". Dokładnie ta klasa cichej utraty, którą zamknęły B-slots, B7 i odmowa po nazwie w `editAnswersNights`, tylko wejściem przez warunkową widoczność.

**Dlaczego nie teraz:** zachowanie jest **identyczne na ścieżce klasycznej**, więc problem jest wspólny i zastany, a nie wniesiony przez pule dobowe. Naprawa dotyka `Validator`/`SubmissionAssembler`, czyli kodu wspólnego dla obu trybów i dla formularza publicznego — to osobna gałąź z własną siatką testów, nie fala poprawek po przeglądzie.

**Szkic (propozycja recenzenta):** dla pola noclegu niewidocznego zwracać `null` zamiast pustej selekcji. Jest to bezpieczne na obu ścieżkach i w obu trybach: `reserve` czyta `null` jako „bez noclegu" (nic nie traci, bo nowe zgłoszenie nic nie ma), a `editAnswers` czyta `null` jako „nie dotykaj noclegu" — ta asymetria jest już zbudowana i opisana przy `ReservationRequest::$nights`. Testy: edycja zmieniająca typ tak, że warunek pola noclegu przestaje pasować, osobno dla trybu `nights` i `classic`; w obu wiersze, miejsca i cena muszą zostać nietknięte.

---

## B22 — `RegistrationRepository::beginTransaction()` ignoruje wynik `$wpdb->query()`

**Priorytet:** średni. **To nie jest nowa ekspozycja** — inline'owe `START TRANSACTION`,
które ta metoda zastąpiła (B16), ignorowały wynik dokładnie tak samo. Konsolidacja daje
przyszłej poprawce jedno gardło zamiast dziewięciu miejsc, i dlatego wpis stoi tu, a nie
przy B16.

**Stan:** `beginTransaction()`, `commit()` i `rollback()` wołają `$wpdb->query( ... )`
i wyrzucają wynik. `beginTransaction()` ma dziś dziewięciu wołających: ośmiu
w `ReservationService` (`reserve`, `reserveNights`, `cancel`, `promoteFromWaitlist`,
`promoteFromWaitlistNights`, `editAnswers`, `editAnswersNights`, `deleteRegistration`)
i jednego w `src/Rest/EventConfigController.php` (B17).

**Skutek:** cicho nieudane `START TRANSACTION` zostawia następne `SELECT … FOR UPDATE`
w autocommit — zamek wiersza puszcza na końcu instrukcji, zliczenie zajętości dostaje
własny snapshot, więc dwie równoległe rezerwacje mogą sprzedać to samo ostatnie miejsce.
To jest dokładnie ten inwariant, którego pilnuje 3A. Każdy późniejszy `rollback()` jest
wtedy no-opem: awaria w połowie zapisu zostawia wiersz zgłoszenia bez bookingu albo bez
wierszy dób.

**Szkic:** sprawdzać wynik `$wpdb->query()` w tych trzech metodach i rzucać
`\RuntimeException` przy `false` — wołający mają już `catch ( \Throwable )` z `rollback()`
i ponownym rzuceniem, a ścieżka REST opakowuje to w 500. Wymaga **własnego testu**:
podstawić `$wpdb`, którego `query()` zwraca `false`, i asertować, że ścieżka rzuca, a nie
idzie dalej.

---

## B23 — Reguła companion/pokój sądzona poza lockiem

**Priorytet:** niski (wąskie okno, wymaga dwóch adminów edytujących JEDNO zgłoszenie), ale
to **wycinek defektu właśnie zamkniętego, nie inny defekt** — stąd jedna pozycja na oba
poniższe fakty.

**Stan:** `RegistrationsScreen::handle_edit` sądzi regułę pary na bookingu odczytanym POZA
transakcją (odczyt dla `$previous_slot`; zastany, nie wniesiony przez falę poprawek),
a `editAnswers` czyta swój booking POD lockiem. W okienku między tymi dwoma odczytami
dwóch adminów nadal zbuduje cichą sprzeczność: admin A przenosi slot do pokoju
jednoosobowego bez towarzyszącej; admin B, w locie, przestawia flagę na edycji
„niepodanej", przeczytawszy stary pokój dwuosobowy — sędzia przyklepuje na nieaktualnym
pokoju, a `editAnswers` przesadza zgłoszenie do nowego, zapisanego pokoju jednoosobowego
z `companion = 1`.

**Ta sama pozycja obejmuje nieobramowany `AccommodationConfig::fromArray`:**
`RegistrationsScreen::companionRoomMismatch` buduje konfigurację POZA oba
`try`/`catch ( SchemaException )` bloki `handle_edit` (jeden wokół `$loader->nights()`,
drugi wokół `editAnswers`), więc event klasyczny z zalegającymi, niespójnymi danymi
noclegu daje przy zapisie fatal zamiast komunikatu `config_error`, który dałby
`editAnswers`. Zastane dla ścieżki „podanej"; fala poprawek wpuściła w ten sam rzut także
ścieżkę „niepodaną".

**Szkic:** solidnym domem reguły jest **efektywna selekcja wewnątrz `editAnswers`** — tam
booking jest już czytany pod lockiem — plus kod wyniku umiejący nieść POLE.
`AdminActionResult` niesie dziś kod i `reason`, więc błąd da się dowieźć do ekranu edycji
tą samą drogą, którą jedzie `accommodation_gone`. Przy przeniesieniu reguły
`companionRoomMismatch` znika razem z rzutem; dopóki stoi, objąć ją
`catch ( SchemaException )`.

---

## B24 — Klasyczna edycja „niepodana" przelicza snapshot ceny bookingu

**Priorytet:** niski. Nie regresja (przed B21 booking ginął w całości, a przeliczanie przy
edycji „nieprzesuwającej" jest starsze niż gałąź), ale ścieżka z B21 czyni to osiągalnym
bez pokazania operatorowi pola, o którym mowa.

**Stan:** `editAnswers` (klasyczny) na edycji nieruszającej ani slotu, ani flagi zachowuje
`seats` (`$stored_seats`), ale booking **kasuje i wstawia od nowa**, licząc `$acc_price`
z `$accommodation->item( ... )`, czyli z CENNIKA BIEŻĄCEGO. Bliźniak dobowy
(`editAnswersNights`) snapshotów nie tyka wcale, a pozycję, która zniknęła z konfiguracji,
odrzuca po nazwie (`accommodationGone`).

**Skutek:** gdy pokój albo pakiet usunięto z inwentarza, `item()` zwraca `null`, więc cena
noclegu po cichu staje się `0.00`, a wiersz zostaje. Gdy tylko zmieniono cenę — historia
przepisuje się na dzisiejszą. Operator widzi „Odpowiedzi zgłoszenia zaktualizowane".

**Szkic:** nie przepisywać wiersza, gdy slot i flaga się nie zmieniły (zachować snapshot
`price` tak, jak zachowujemy `seats`) — mniejsze i spójne z `night_label`/`price` jako
snapshotami. Alternatywa: odrzucać po nazwie, jak ścieżka dobowa.

---

## B25 — Niewidoczne pola innych typów niż nocleg nadal wypadają z JSON-a przy edycji

**Priorytet:** niski (stawka = jedna odpowiedź, nie miejsca i pieniądze).

**Stan:** mechanizm jest ten sam co w B21: `RegistrationEditForm` renderuje WSZYSTKIE pola,
`Validator` zwraca wartości tylko WIDOCZNYCH, a `updateRegistration` zapisuje
`$request->data` jako całość. B21 ratuje wyłącznie klucz pola noclegu
(`answersKeepingAccommodation()`), bo na nim wiszą miejsca i pieniądze.

**Skutek:** edycja zmieniająca odpowiedź, od której zależy warunek innego pola, gubi
dotychczasową odpowiedź tego pola. Cicho, pod „zaktualizowano".

**Szkic:** uogólnić `answersKeepingAccommodation()` na „scal zapisany JSON z nowymi
wartościami dla kluczy, których ta edycja nie podała" — z rozstrzygnięciem produktowym,
którego B21 nie potrzebowało: czy odpowiedź na pole, które przestało obowiązywać, ma
zostać zachowana, czy skasowana? (Nocleg zachowujemy, bo niesie przydział miejsc.)

---

## B26 — `NightsResolver` siedzi w `src/Frontend/`

**Priorytet:** niski (czysto strukturalne, zero zachowania).

**Stan:** `NightsResolver` jest rozstrzygaczem na poziomie EVENTU, a zależą od niego
`src/Admin/`, `src/Mail/`, `src/Privacy/` i `src/Rest/` — nazwa katalogu jest dziś
najmniej trafną rzeczą w tej klasie. Kandydat: `src/Accommodation/`.

**Uwaga przy przeprowadzce:** `RegistrationView` i `RegistrationsScreen` tworzą po B20
cykl na poziomie KLAS (View czyta `RegistrationsScreen::SLUG` i `ACTION_*`, Screen
instancjuje View). Tylko stałe, więc nic się nie psuje, ale **żadnej z tych dwóch nie da
się przenieść niezależnie** — parami albo wcale.

---

## B27 — Dwie luki testowe

**Priorytet:** niski. Oba sprawdzone jako nieszkodliwe DZIŚ — zapisane właśnie dlatego, że
„nieszkodliwe dziś" ugryzło ten projekt już trzy razy.

**Stan:**
1. Zmigrowane w B19 wołanie rozstrzygania trybu w `RegistrationsScreen::handle_edit`
   (`$loader->nights( $event_id )`) nie jest przypięte żadnym testem: cofnięcie go do
   starego spaceru po schemacie nie potrafi dziś zgubić wierszy, bo schemat bez pola
   noclegu daje `nights === null`, czyli „nie podano", po obu drogach jednakowo.
2. Brzeg łączony: schemat BEZ pola noclegu **plus** niepoprawna konfiguracja dób. Każda
   połowa ma swój test, ścieżki są ortogonalne, więc samego złożenia nikt nie pokrywa.

**Szkic:** test integracyjny na (1), który stawia event dobowy z polem noclegu usuniętym
ze schematu i asertuje, że edycja odpowiedzi zachowuje wiersze dób; test na (2) wokół
degradacji opisanej w B28 — jedna konfiguracja obsłuży oba.

---

## B28 — Zawężona degradacja wniesiona przez B19

**Priorytet:** niski. Decyzja o trybie stoi tam, gdzie ma stać — **szersza niż trzeba jest
tylko degradacja**, nie reguła.

**Stan:** dla eventu dobowego, któremu pole noclegu usunięto z formularza, **gdy
jednocześnie** lista dób jest nieczytelna, publiczna strona pokazuje dziś „Rejestracja
jest niedostępna" (`Shortcode` łapie `SchemaException`), a każda edycja odpowiedzi
w adminie odbija się kodem `config_error` — choć obie powierzchnie wcześniej działały, dla
formularza, który o nocleg nawet nie pyta. `forSchema()` rzucało tylko wtedy, gdy schemat
niósł pole; `EventFormLoader::nights()` rozstrzyga z EVENTU, więc rzuca niezależnie od
schematu.

**Skutek:** osiągalność jest większa, niż wygląda — `Night::fromArray` rzuca na pustym
kluczu, więc wystarczy jedna pusta karta doby zapisana z buildera.

**Szkic:** degradować węziej, nie cofać decyzji o trybie. Gdy schemat pola noclegu nie
niesie, niepoprawna lista dób nie ma na co wpłynąć na ścieżce formularza — wystarczy
wtedy sam `mode`, tak jak robi `RegistrationsScreen::build_export_csv` (czyta
`NightsResolver::isNights` i obiektu nie buduje). Alternatywa albo uzupełnienie:
walidować karty dób przy zapisie w REST, żeby pusty klucz nie trafiał do meta.

---

## B29 — `RegistrationsScreen` to nadal dwie rzeczy

**Priorytet:** niski (dług strukturalny, następnik B20).

**Stan:** po B20 ekran ma 508 linii i niesie prezentację listy **plus** siedem handlerów
`admin-post` **plus** eksport. Objaw z nagłówka B20 („ponad 950 linii") jest zniesiony.

**Szkic:** dalsze cięcie lista/handlery to osobna decyzja, nie dokończenie B20 —
podejmować, gdy ekran znów zacznie rosnąć, nie prewencyjnie. Patrz też uwaga o cyklu
`RegistrationView` ↔ `RegistrationsScreen` w B26.

---

## B30 — Przetłumaczony help text pola traci rich text

**Priorytet:** niski.

**Stan:** bazowy `description` jest w `RICH_TEXT_KEYS` i renderuje się przez `wp_kses_post`,
ale jego tłumaczenie (bucket `descriptions` overlayu) przechodzi przez `sanitize_text_field`,
więc przetłumaczony help text traci link, który polski zachowuje. Gałąź `companion-note`
naprawiła dokładnie tę asymetrię dla przypisu towarzyszącej i zostawiła sąsiednią.

**Szkic:** symetryczne rozszerzenie w `I18nController::sanitize`
(`3 === count( $path ) && 'descriptions' === $path[1]`). Bezpieczne, bo segment wybierany
przez autora jest tu liściem (klucz pola), a nie elementem ścieżki rozstrzygającym wyjątek.

---

## B31 — Opis pola w `<p>` łamie się na akapitach

**Priorytet:** niski.

**Stan:** opisy pól są opakowane w `<p>` w `FormRenderer.php` (~linia 185), a ich allowlista
(`wp_kses_post`) dopuszcza HTML blokowy, więc help text z dwoma akapitami wychodzi z własnego
opakowania i traci klasę. Ten sam kształt i ta sama klasa poprawki co przypis towarzyszącej
(`<div class="… form-text">`), zastosowana tam tylko do przypisu.

**Szkic:** zmienić wrapper opisu na `<div>`; `.form-text` Bootstrapa nie ustawia `display`,
więc wygląd się nie zmienia. Sprawdzić testy asertujące `<p class="evreg-field-desc`.

---

## B32 — Ekran „Dodaj zgłoszenie" w panelu administracyjnym — ZROBIONE (2026-11-18)

**Status:** dostarczone na gałęzi `feature/manual-registration`. Odrzucenie ze „Stanu" poniżej
jest już NIEAKTUALNE — ekran powstał dokładnie w kształcie ze „Szkicu": submenu „Zgłoszenia" →
`?action=add` (`RegistrationsScreen::ROUTE_ADD`), formularz z `RegistrationEditForm::renderAdd`,
handler `admin-post` `ACTION_ADD` i wywołanie `ReservationService::reserve` ze znacznikiem
`ManualEntry`, z pominięciem antyspamu i z tą samą bramką pojemności. Wpis ręczny wchodzi
`confirmed`, bez `expires_at` (cron wygaszający go nie zabierze), z trwałą kolumną `manual`,
którą honoruje awans z listy rezerwowej, i NIE wysyła maila, dopóki organizator nie zaznaczy
przełącznika wysyłki. Zostało poza zakresem: straż nieznanego typu na ścieżce publicznej (B34),
treść `capacity_full` przy odmowie z limitu globalnego (B35).

**Priorytet:** niski.

**Stan (nieaktualny, zapis sprzed dostarczenia):** organizator wpisujący uczestników ręcznie
robi to publicznym formularzem. Zwolnienie z limitu antyspamowego (gałąź `rate-limit-bypass`)
usuwa główne tarcie, ale nie zmienia samego obejścia. Dedykowany ekran w panelu został w tej
poprawce odrzucony jako nieproporcjonalny (podejście C w specyfikacji).

**Szkic:** ekran obok edycji zgłoszenia, wołający `ReservationService::reserve` z pominięciem
antyspamu i walidacji publicznej, z tą samą bramką pojemności.

---

## B33 — `rateKey()` nie obsługuje zaufanego proxy

**Priorytet:** niski.

**Stan:** `SubmitHandler::rateKey()` czyta `REMOTE_ADDR` bez obsługi zaufanego proxy. Za
Cloudflare albo nginx proxy bez `mod_remoteip` cały ruch dzieli jeden kubełek, więc limit staje
się globalny dla strony, a nie per urządzenie.

**Diagnoza:** liczba wierszy `%evreg_rate_%` w `options` na produkcji — jeden wiersz mimo wielu
odwiedzających = wspólny kubełek.

---

## B34 — Brak straży nieznanego typu w `reserve()` / `reserveNights()` na ścieżce publicznej

**Priorytet:** niski.

**Stan:** `__type` nie jest domyślnie wymagane, więc puste pole typu na formularzu publicznym
daje zgłoszenie bez typu: darmowe, liczone tylko do limitu globalnego. Pozostałe cztery ścieżki
transakcyjne (edycja i awans, także w wariantach dobowych) tę straż mają. Ekran
dodawania zgłoszenia ręcznie stawia ją we własnym handlerze, a nie w `reserve()`, więc dziura na
ścieżce publicznej została w tej gałęzi świadomie nietknięta.

**Szkic:** przenieść straż do `reserve()`/`reserveNights()` (kod `invalidStatus`/nowy), po
sprawdzeniu, że żaden zastany event nie opiera się na pustym typie; wtedy handler dodawania może
ją oddać.

---

## B35 — Wynik `rejected` używa komunikatu `capacity_full`, który zawsze wskazuje limit typu

**Priorytet:** niski.

**Stan:** komunikat współdzielony z handlerem edycji nazywa limit typu zgłoszenia, także gdy
odmowa wynikła z limitu globalnego eventu. Zastane, nie wniesione przez ekran dodawania.

**Szkic:** rozróżnić przyczynę odmowy w wyniku (typ vs global) i dać dwa komunikaty.

---

## B36 — `RegistrationNotice` rzuca ostrzeżenie PHP na tablicowy parametr zapytania

**Priorytet:** niski.

**Stan:** `RegistrationNotice` czyta wartości `$_GET` gołym rzutowaniem `(string)`; parametr
tablicowy, np. `?evreg_dup[]=1`, daje ostrzeżenie PHP „Array to string conversion". Wzorzec
zastany w całym pliku, nie wniesiony przez ekran dodawania.

**Szkic:** jeden helper czytający skalar (`is_scalar` → `sanitize_text_field( wp_unslash() )`,
inaczej pusty) używany przez wszystkie miejsca w pliku.
