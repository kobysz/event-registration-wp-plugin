# B39 + B40 — komunikat o braku miejsc przed formularzem, odmowa bez utraty danych

Data: 2026-11-19. Wpisy backlogu: B39, B40 (`docs/superpowers/backlog.md`).

## Intencja

Zgłaszający się dowiaduje się o braku miejsc na wydarzenie dopiero PO wypełnieniu formularza.
Przy liście rezerwowej ląduje na niej bez uprzedzenia (a to zmienia decyzję, czy w ogóle się
zapisywać); bez listy dostaje „Brak wolnych miejsc.” nad formularzem, który nadal się renderuje,
i może wysyłać w kółko. Do tego odmowa `rejected` czyści wszystko, co wpisał (B40).

Cel: uczciwość wobec zgłaszającego się PRZED wypełnieniem, a gdy odmowa i tak padnie (wyścig),
brak utraty pracy.

## Decyzje właściciela produktu

1. Gdy nic nie da się zapisać, a lista rezerwowa działa → komunikat „zapisując się trafisz na listę
   rezerwową” NAD formularzem; formularz renderuje się normalnie.
2. Gdy nic nie da się zapisać, a listy rezerwowej nie ma → komunikat i **formularza nie renderować**.
3. Treść obu komunikatów edytowalna przez organizatora, per event, tłumaczalna per język.
4. **„Wszystkie typy pełne” = wydarzenie pełne**, także przy pustym `global_cap` (2026-11-19).
5. Składanie `CapacityLimits` wyciągnięte do konstruktorów nazwanych w domenie (podejście A).

## Cele / nie-cele / anty-cele

**Cele:** komunikat zbiorczy w dwóch wariantach; jeden dom reguły składania limitów; B40.

**Nie-cele:** wyszarzanie pojedynczych pełnych typów w polu `__type` (backlog B39, „poza
zakresem pierwszego podejścia”); B35 (treść odmowy po submicie); zmiana komunikatów po submicie;
panel admina / wpis ręczny (ten ma własną ścieżkę i limity działają jak zwykle); zmiana sposobu
wyświetlania „Zajmują miejsce” w `RegistrationsOverview`.

**Anty-cele (porażka, nawet jeśli działa):**
- czwarta kopia reguł pojemności — komunikat i `reserve` mogą się rozjechać;
- komunikat przy pełnym JEDNYM typie, gdy drugi jest otwarty;
- bramka zamiast informacji — serwer zostaje arbitrem, wyścig render↔submit dalej możliwy;
- render-time czytelnik `global_cap`/`waitlist_enabled` obok `ReservationService::limits()`.

## Podejścia (składanie limitów)

- **A. Konstruktory nazwane w domenie — WYBRANE.** `CapacityLimits::forClassic(...)` /
  `::forNights(...)` nad prywatnym `fromSettings(...)`. Czysta domena, tanie testy, jeden dom.
- B. Fabryka w `src/Services/` po `event_id` — chowa rozgałęzienie trybu, ale `ReservationService`
  już ma config wczytany: podwójny odczyt albo dwa wejścia; warstwa bez korzyści.
- C. Publiczne `ReservationService::eventAvailability()` — render woła serwis transakcyjny poza
  transakcją, miesza się z kontraktem `*UnderLock`. Odrzucone.

## Projekt

### 1. Składanie limitów (`src/Domain/Capacity/CapacityLimits.php`)

- `public static function forClassic( array $settings, RegistrationTypeCollection $types, AccommodationConfig $accommodation ): self`
  — `capacities()`, `companionCountsEvent()`, `slotOccupancies()` z inwentarza (dzisiejsze `classicLimits`).
- `public static function forNights( array $settings, RegistrationTypeCollection $types, NightsConfig $nights ): self`
  — puste sloty, `companionCountsEvent()` z `NightsConfig` (dzisiejsze `nightsLimits`).
- `private static function fromSettings(...)` — JEDYNE miejsce czytające `global_cap` i
  `waitlist_enabled` (dokładnie dzisiejsza logika `limits()`: `global_cap` null/nieobecny = bez
  limitu, `waitlist_enabled` domyślnie `true`). Komentarz o B5 przenosi się tutaj.
- `ReservationService::limits/classicLimits/nightsLimits` znikają; wszystkie wołania (reserve,
  reserveNights, editAnswers ×2, promoteFromWaitlist ×2 — dziś 6 miejsc) idą wprost do konstruktorów.
  Zachowanie identyczne — istniejące testy integracyjne są siatką bezpieczeństwa.
- `RegistrationsOverview::report` przestaje budować `CapacityLimits` ręcznie i czytać `global_cap`;
  woła `forNights( $settings, $types, $nights )` gdy `$nights->isNights()`, inaczej
  `forClassic( $settings, $types, $accommodation )`. Dzisiejsze `AccommodationConfig::fromArray( $nights->isNights() ? array() : $raw )`
  zostaje bez zmian (jest potrzebne `OccupancyReport` w obu trybach; w trybie dób dostaje pustą tablicę, więc nie rzuca). Efekt uboczny: limity panelu niosą teraz też `waitlist_enabled`/`companion_counts_event`;
  sposób wyświetlania `overall()` się NIE zmienia (nie-cel).
- Domena może zależeć od `RegistrationTypeCollection`, `AccommodationConfig`, `NightsConfig` —
  wszystkie są w `src/Domain/`. `DomainPurityTest` musi zostać zielony.

### 2. Sąd o dostępności (`src/Domain/Capacity/EventAvailability.php`, nowy)

- `final class EventAvailability`, enum stanu (`EventAvailabilityState`: `Open`, `Waitlist`, `Closed`
  — albo stałe, wybór wykonawcy).
- `public static function decide( CapacityLimits $limits, OccupancySnapshot $taken, string[] $typeKeys ): self`
  - dla każdego klucza typu: `( new CapacityCalculator() )->decide( $limits, $taken, $key, null, false )`;
  - jeśli KTÓRYKOLWIEK zwraca `Outcome::Accepted` → `Open`;
  - w przeciwnym razie `$limits->waitlistEnabled ? Waitlist : Closed`;
  - **pusta lista typów → sprawdź klucz `''`** (`forType('')` = null, więc decyduje sam `global_cap`
    — dokładnie jak `reserve()` dla eventu bez typów). Brak typów + wyczerpany `global_cap` = pełny.
- **Które klucze podaje wołający:** klucze TYLKO aktywnych typów (`$types->active()` → `->key`;
  metody `keys()` w `RegistrationTypeCollection` nie ma — nie dopisywać, wystarczy `array_map`).
  Formularz publiczny oferuje wyłącznie aktywne (`SchemaAssembler::typeOptions`), więc nieaktywny
  typ z wolnym miejscem nie może trzymać eventu „otwartego”. *(Wywołanie wykonawcy.)*
- **Znana dziura, świadomie poza zakresem (B34):** `__type` nie jest domyślnie wymagany, a
  `reserve()` przyjmuje pusty klucz typu, sprawdzając go tylko z `global_cap`. Przy wszystkich typach
  pełnych i pustym/niewyczerpanym `global_cap` submisja z PUSTYM typem przejdzie. Nie dodajemy `''` do
  sprawdzanych kluczy, gdy typy istnieją — przyjęcie pustego typu to błąd (B34), nie pojemność, a
  dziura działa na korzyść zgłaszającego się (przy Closed formularza i tak nie ma). Dopisać zdanie do B34.
  *(Wywołanie wykonawcy.)*
- Reguły pojemności NIE są tu kopiowane — jedynym arbitrem jest `CapacityCalculator::decide`.
  `selection = null`, `companion = false`: „czy zmieści się choć jedna osoba bez noclegu”. Event,
  w którym zmieści się singiel, ale nie para, jest `Open` (odmowa pary dalej po submicie).
- Tryb „doby” bez znaczenia: doby to nocleg, nie pojemność wydarzenia.

### 3. Teksty komunikatów

- **Baza:** dwa klucze w `_evreg_settings`: `full_waitlist_notice`, `full_closed_notice`. Zwykły
  tekst (bez HTML) — przechodzą przez generyczny `EventConfigController::sanitize` (`sanitize_text_field`),
  NIE dopisywać ich do `RICH_TEXT_KEYS`. *(Wywołanie wykonawcy: plain text; HTML = YAGNI.)*
- **Domyślne** (puste/nieobecne = domyślny tekst wtyczki, `__()` w warstwie prezentacji):
  - waitlist: „Wszystkie miejsca są zajęte. Możesz się zapisać — trafisz na listę rezerwową i
    damy znać, gdy zwolni się miejsce.”
  - closed: „Rejestracja zamknięta — brak wolnych miejsc.”
  *(Brzmienie domyślnych: wywołanie wykonawcy, do poprawki przez właściciela przy review.)*
- **Admin:** `SettingsTab.jsx` — dwa `TextControl` (jednoliniowe: `sanitize_text_field` i tak zjada
  `\n`, więc textarea obiecywałaby coś, czego zapis nie dotrzyma) pod przełącznikiem listy rezerwowej, z
  domyślnym tekstem jako `placeholder` (wzór `MailTemplatesTab`). Zapis przez istniejący PUT configu.
  Stringi UI przez `__()`.
- **Overlay i18n:** nowy bucket `_evreg_i18n[lang].notices = { full_waitlist: '...', full_closed: '...' }`.
  - `i18nOps.langBucket` **MUSI** whitelistować `notices` (inaczej zapis innego bucketu go kasuje
    — pułapka z bucketu `mail`); test jest na to obowiązkowy.
  - `translatableItems(schema, types, accommodation, settings)` — nowy opcjonalny czwarty argument;
    wiersze `kind: 'notice'` dla obu kluczy (base = tekst z settings, albo domyślny gdy pusty),
    `get/setNoticeTranslation` w `i18nOps`; `TranslationsTab` podaje `settings`.
  - `I18nController::sanitize` bez zmian (liście `sanitize_text_field` — to jest właściwe).
- **Rozstrzyganie tekstu:** overlay `notices[key]` dla bieżącego języka → baza z settings →
  domyślny `__()`. Fallback per pole jak `TemplateResolver`. Pusty string (po `trim`) na każdym
  poziomie = brak, spada niżej.
  - Czysta część: `ContentTranslator::translateNotices( array $settings, array $overlay, string $lang ): array`
    — zwraca `$settings` z nałożonymi `full_waitlist_notice`/`full_closed_notice` z overlayu (wzór
    `translateAccommodation`). Domyślnych NIE zna (domena bez i18n).
  - Adapter: nowa publiczna `EventFormLoader::settings( int $event_id ): array` — czyta config +
    overlay + `CurrentLanguage::get()` i woła `translateNotices`. **Prywatne `translated()` i jego
    kształt zwrotny NIE zmieniają się** (`load()`/`nights()` od niego zależą). Jeden dodatkowy odczyt
    meta jest akceptowalny.
  - Domyślne teksty i wybór „pusty → domyślny” w `Shortcode` (prezentacja).

### 4. Render (`src/Frontend/Shortcode::renderForm`)

Po załadowaniu schematu i `nights` (po ścieżkach PRG sukcesu/potwierdzenia, które się nie zmieniają):

1. Zbuduj limity: `NightsResolver`/`nights` już rozstrzygnięte → `forNights` albo `forClassic`.
   Klasyczny `AccommodationConfig::fromArray` może rzucić `SchemaException` — wtedy **pomiń komunikat**
   (stan `Open`, zachowanie jak dziś); nie degradować formularza z tego powodu.
2. `EventAvailability::decide( $limits, $repo->occupancy( $event_id ), $activeTypeKeys )` — jeden
   dodatkowy odczyt zajętości (przy klasycznym z inwentarzem `availability()` robi już jeden; wolno
   go współdzielić, wybór wykonawcy).
3. `Closed` → zwróć WYŁĄCZNIE alert `evreg-full alert alert-warning` z tekstem closed. Bez formularza,
   bez `resultNotice` (po wyścigu `rejected` komunikat closed mówi to samo, lepiej). Bez enqueue `evreg-public`.
4. `Waitlist` → alert `evreg-full alert alert-info` z tekstem waitlist + dotychczasowy `$notice` + formularz.
5. `Open` → bez zmian.

Escaping przez istniejący `Shortcode::alert()` (`esc_html`). Blok idzie przez `renderForm`, więc pokryty.
`SubmitHandler` się nie zmienia (serwer arbitrem; POST na zamknięty event odrzuca `reserve`).

### 5. B40 (`src/Frontend/SubmitResult.php`, `SubmitHandler.php`)

- `SubmitResult::rejected( array $values = array() )` jak `failure()`.
- Trzy wołania w `SubmitHandler::process` (linie ~213, ~248, ~259) podają `$assembled->rawAnswers()`.
  Tylko ~259 to odmowa pojemności; ~213 i ~248 to gałęzie „schemat bez pola noclegu” przy niespójnej
  parze / pełnym noclegu — też tracą dziś dane bez powodu, więc dostają wartości tak samo. Wszystkie
  — **nie `values()`** (na gałęzi poprawnej walidacji niesie obiekt `AccommodationSelection` i nie ma
  kluczy `evreg_companion*`).
- Sprawdzić, że `FormRenderer` re-renderuje wartości dla wyniku `rejected` tak jak dla `failure`
  (ten sam akcesor wartości na `SubmitResult`); jeśli renderer filtruje po kodzie — poszerzyć.

## Testy

- Unit `CapacityLimitsTest`: `forClassic`/`forNights` — `global_cap` null/0/liczba/string,
  `waitlist_enabled` nieobecne = true, flagi companion z odpowiedniego configu.
- Unit `EventAvailabilityTest`: wszystkie typy pełne bez `global_cap` → Waitlist/Closed; jeden pełny
  + jeden otwarty → Open; typ bez limitu → Open; `global_cap` wyczerpany → pełny niezależnie od typów;
  `companion_counts_event` + towarzyszące w zajętości liczą się do globalnego; brak typów + wolny
  `global_cap` → Open; brak typów + wyczerpany `global_cap` → pełny. Unit `ContentTranslatorTest`:
  `translateNotices` — overlay wygrywa, pusty overlay spada na bazę, inny język nie przecieka.
- Integracja `RegistrationsOverview`: event w trybie dób z zalegającym niespójnym inwentarzem
  klasycznym nadal renderuje panel (regresja z B19).
- Integracja `ShortcodeTest` (lub odpowiednik): Closed → brak `<form`, jest tekst; Waitlist → tekst +
  `<form`; własny tekst z settings wygrywa nad domyślnym; overlay języka (filtr `evreg_current_language`) wygrywa nad bazą.
- Integracja `SubmitHandler`: `rejected` (pełny typ, lista wyłączona) zwraca wartości; formularz
  re-renderuje wpisane pole.
- Istniejące testy `ReservationService*` i `RegistrationsOverview` bez zmian — zielone.
- Jest: `i18nOps` — `langBucket` zachowuje `notices` przy zapisie innego bucketu; get/set notice;
  `translatableItems` z settings.
- E2E opcjonalnie (wybór wykonawcy): event pełny bez listy → brak formularza.

## Zostawione wykonawcy

Nazwa enumu/stałych stanu; współdzielenie odczytu zajętości z `availability()`; dokładna metoda
`EventFormLoader` dla przetłumaczonych notice; brzmienie domyślnych tekstów (do review); CHANGELOG
`## [Unreleased]`; aktualizacja `CLAUDE.md` (sekcja formularza publicznego + rdzeń rezerwacji:
`limits()` → `CapacityLimits::forClassic/forNights`) i backlogu (B39/B40 zamknięte); `npm run i18n:pot`;
`bash scripts/build-zip.sh`.
