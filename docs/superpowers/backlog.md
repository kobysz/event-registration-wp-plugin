# Backlog — funkcje odłożone

Roadmapa planów 1–6 zamknięta (patrz `CLAUDE.md`). Poniżej funkcje zgłoszone
w iteracji funkcjonalnej, do zaplanowania osobno (każda: brainstorming → spec →
plan → wykonanie). Rejestr utworzony 2026-08-21.

---

## B1 — Zależności pól (warunkowa widoczność) — pełny stack

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

## B2 — Render formularza z klasami Bootstrap 5

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
