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
