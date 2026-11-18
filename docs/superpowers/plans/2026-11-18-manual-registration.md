# Ekran „Dodaj zgłoszenie" w panelu — plan wdrożenia

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Organizator wpisuje uczestnika z panelu — zgłoszenie powstaje od razu jako potwierdzone, bez terminu ważności i domyślnie bez poczty, licząc miejsca i ceny dokładnie tak jak formularz publiczny.

**Architecture:** Zapis idzie przez istniejące `ReservationService::reserve()`, które dostaje opcjonalny opis trybu wpisu — `null` znaczy dzisiejsza ścieżka publiczna, bajt w bajt. Trwały znacznik wpisu ręcznego w tabeli zgłoszeń (db v8) pozwala promocji z listy rezerwowej zachować się tak samo. Ekran to nowa trasa przy istniejącym ekranie zgłoszeń, renderowana tym samym rendererem co edycja.

**Tech Stack:** PHP 8.1+, WordPress, dbDelta, PHPUnit (wp-env), i18n text domain `event-registration`.

**Spec:** `docs/superpowers/specs/2026-11-18-manual-registration-design.md`

## Global Constraints

- `src/Domain/**` = zero WordPressa i zero `$wpdb` (pilnuje `tests/Unit/Architecture/DomainPurityTest.php`). Domena zwraca kody, nigdy komunikaty dla użytkownika.
- **Ścieżka publiczna nie zmienia zachowania ani o bajt.** Każdy nowy parametr ma wartość domyślną równą dzisiejszemu zachowaniu.
- `lockEvent()` przed pierwszym COUNT zajętości — kolejność nienaruszalna.
- Hooki cyklu życia wyłącznie po COMMIT, poza `try/catch`.
- **Tryb wpisu ręcznego zmienia dokładnie cztery rzeczy** (pomija blokadę duplikatu, zapisuje `confirmed`, nie ustawia `expires_at`, emituje zdarzenie tylko gdy `notify` i inne niż publicznie) **i nic poza nimi.** Straż pustego typu stoi w handlerze ekranu, nie w serwisie.
- Uprawnienie: `EvReg\Admin\Capabilities::CAP` (`edit_evreg_events`).
- Każdy plik PHP poza `src/Domain/` i `tests/` zaczyna od `defined( 'ABSPATH' ) || exit;`.
- Katalogi testów wielką literą (`tests/Unit/`, `tests/Integration/`), suity małą.
- Wszystkie stringi UI przez i18n, text domain `event-registration`.
- PHPStan poziom 6 bez obniżania i bez rozproszonych `@phpstan-ignore`; `phpcs` czysto (skanuje `src` i plik główny, nie `tests/`).
- Typowane stałe klas (`public const int`) są PHP 8.3+; ta wtyczka deklaruje `Requires PHP: 8.1` — **nie używać**.

## Komendy

```bash
# PHP (kontener, przez wrapper — host nie ma PHP; NIE używać gołego npx wp-env)
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- composer analyse
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs

# Jeden plik testowy
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter NazwaTestu

# i18n i paczka (host)
npm run i18n:pot
bash scripts/build-zip.sh
```

PHPStan **tylko** przez `composer analyse` — gołe `vendor/bin/phpstan analyse` pada na domyślnym limicie 128 MB komunikatem, który wygląda na błąd kodu, a jest wyczerpaniem pamięci.

Konsola PHPUnit w tym kontenerze zniekształca komunikat każdego niezłapanego `Error` — fazę RED weryfikuj jawnym `try/catch` drukującym `get_class($e)`/`getMessage()`, nie podsumowaniem PHPUnit.

## Review Focus

Klasy wejść, których żaden task sam by nie przetestował; każda ma przypisany test w zadaniu będącym właścicielem kodu.

1. **Wpis ręczny w trybie „doby"** — cała arytmetyka idzie drugą gałęzią (`reserveNights`, `promoteFromWaitlistNights`). Pominięcie jej daje wpis, który wygasa mimo znacznika. Testy w Taskach 3 i 4, osobno dla obu trybów.
2. **Awans wpisu ręcznego z listy rezerwowej** — musi wyjść `confirmed`, bez `expires_at` i bez maila z linkiem; awans wpisu publicznego musi zostać dokładnie taki jak dziś. Test w Tasku 4.
3. **Pusty albo nieznany typ zgłoszenia** — dałby wiersz potwierdzony, darmowy i bez typu, liczony tylko do limitu globalnego. Test w Tasku 6.
4. **Włączony przełącznik maila przy pustym adresie** — kolejka odrzuca pustego adresata bez słowa, więc bez straży organizator dostaje „dodano" i ciszę. Test w Tasku 6.
5. **Migracja na istniejącej bazie** — zastane wiersze muszą po `maybe_upgrade()` czytać się jako wpisy publiczne, nie ręczne. Test w Tasku 2.

---

## Struktura plików

- `src/Domain/Registration/ManualEntry.php` — **nowy**, czysty obiekt wartości trybu wpisu.
- `src/Domain/Validation/Validator.php` — trzeci parametr `validate()`: klucze pól zwolnione z `required`.
- `src/Frontend/SubmissionAssembler.php` — flaga `$emailOptional`, wyznaczenie klucza pola e-mail.
- `src/Persistence/Migrations.php` — kolumna znacznika, `DB_VERSION` 7 → 8.
- `src/Persistence/RegistrationRepository.php` — zapis i odczyt znacznika.
- `src/Services/ReservationService.php` — `ManualEntry` w `reserve()`/`reserveNights()`, znacznik honorowany w `promoteFromWaitlist()`/`promoteFromWaitlistNights()`.
- `src/Admin/RegistrationEditForm.php` — rozdzielenie ramy na `renderEdit()`/`renderAdd()`, mapowanie `night_full:*`.
- `src/Admin/RegistrationView.php` — `render_add()`, `render_add_errors()`, wiersz znacznika na detalu.
- `src/Admin/RegistrationsScreen.php` — trasa `add`, handler, przycisk na liście.
- `src/Admin/RegistrationNotice.php` — kody komunikatów wyniku dodawania.

---

### Task 1: `ManualEntry` i zwolnienie pola z `required`

**Files:**
- Create: `src/Domain/Registration/ManualEntry.php`
- Modify: `src/Domain/Validation/Validator.php` (sygnatura `validate()` i sprawdzenie `required`)
- Modify: `src/Frontend/SubmissionAssembler.php` (`assemble()`)
- Test: `tests/Unit/Domain/Registration/ManualEntryTest.php`
- Test: `tests/Unit/Domain/Validation/ValidatorOptionalRequiredTest.php`
- Test: `tests/Integration/Frontend/SubmissionAssemblerTest.php` (dopisać)

**Interfaces:**
- Produces:
  - `EvReg\Domain\Registration\ManualEntry` — `public function __construct( public readonly bool $notify = false )`.
  - `Validator::validate( FormSchema $schema, array $answers, array $optionalKeys = array() ): ValidationResult` — klucze z `$optionalKeys` nie podlegają sprawdzeniu `required`; pozostała walidacja typu działa na nich bez zmian.
  - `SubmissionAssembler::assemble( FormSchema $schema, array $post, ?NightsConfig $nights = null, bool $emailOptional = false ): AssembledSubmission` — przy `true` podaje walidatorowi klucz pola e-mail.
  - `SubmissionAssembler::emailFieldKey( FormSchema $schema ): string` — **pierwsze pole typu `email` w kolejności schematu, niezależnie od wartości**; `''` gdy schemat nie ma takiego pola. To NIE jest ta sama reguła co prywatne `extractEmail()`, które bierze pierwsze NIEPUSTE — udokumentuj różnicę w docblocku.

- [ ] **Step 1: Napisz failujące testy jednostkowe**

`ManualEntryTest`: `test_defaults_to_not_notifying` (`( new ManualEntry() )->notify === false`), `test_carries_notify_flag`.

`ValidatorOptionalRequiredTest` (czysta domena, schemat budowany w teście): pole wymagane bez wartości daje błąd `required`; to samo pole wymienione w `$optionalKeys` błędu nie daje; **pole wymienione w `$optionalKeys`, ale z wartością NIEPOPRAWNĄ dla swojego typu, nadal daje błąd typu** (zwolnienie dotyczy wyłącznie `required`); pole NIEwymienione nadal daje `required`.

- [ ] **Step 2: Uruchom i potwierdź, że padają**

Run: `… vendor/bin/phpunit --testsuite unit --filter "ManualEntry|ValidatorOptionalRequired"`
Expected: FAIL — klasa i trzeci parametr nie istnieją.

- [ ] **Step 3: Zaimplementuj `ManualEntry` i trzeci parametr `Validator::validate()`**

`ManualEntry` to `final readonly class` bez metod poza konstruktorem. W `Validator` sprawdzenie `required` pomija pola, których klucz jest w `$optionalKeys`; reszta pętli walidacji bez zmian.

- [ ] **Step 4: Uruchom testy jednostkowe**

Expected: PASS. Dodatkowo `DomainPurityTest` musi zostać zielony — `ManualEntry` nie może wołać niczego z WordPressa.

- [ ] **Step 5: Napisz failujący test asemblera**

W `SubmissionAssemblerTest`: schemat z wymaganym polem `email` i pustą wartością — `assemble(...)` bez czwartego argumentu daje `isValid() === false` z błędem przy tym polu; `assemble(..., true)` daje `isValid() === true`, a `request()->email === ''`. Osobny test: `emailFieldKey()` na schemacie, w którym pierwsze pole `email` jest puste, a drugie wypełnione, zwraca klucz **pierwszego**.

- [ ] **Step 6: Uruchom i potwierdź, że pada**

Run: `… -c phpunit-integration.xml.dist --filter SubmissionAssemblerTest`

- [ ] **Step 7: Zaimplementuj `$emailOptional` i `emailFieldKey()` w `SubmissionAssembler`**

- [ ] **Step 8: Uruchom testy asemblera**

Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add src/Domain/Registration/ManualEntry.php src/Domain/Validation/Validator.php src/Frontend/SubmissionAssembler.php tests/
git commit -m "feat(domain): manual entry value object and optional-required validation seam"
```

---

### Task 2: Znacznik wpisu ręcznego w bazie (db v8)

**Files:**
- Modify: `src/Persistence/Migrations.php` (`DB_VERSION`, instrukcja CREATE dla `registrations`)
- Modify: `src/Persistence/RegistrationRepository.php` (`insertRegistration()`, mapowanie wiersza w `findById()`)
- Test: `tests/Integration/Persistence/ManualFlagTest.php`

**Interfaces:**
- Produces:
  - Kolumna `manual` typu `tinyint(1) NOT NULL DEFAULT 0` na `evreg_registrations`.
  - `Migrations::DB_VERSION === 8`.
  - `insertRegistration()` przyjmuje opcjonalny klucz `manual` w tablicy wiersza (brak = `0`), format `%d`.
  - Wiersz zwracany przez `findById()` niesie klucz `manual`.

- [ ] **Step 1: Napisz failujące testy**

W `ManualFlagTest`: wstawienie bez klucza `manual` daje w odczycie `0`; wstawienie z `manual => 1` daje `1`; `Migrations::DB_VERSION` to `8`. Czwarty test pod Review Focus 5: **wiersz, który o kolumnie nie wie, czyta się jako `0`** — wstaw go surowym `INSERT`-em wymieniającym wyłącznie kolumny sprzed migracji i sprawdź, że `findById()` zwraca `manual` równe `0`. To pokrywa zastane zgłoszenia na bazie aktualizowanej z wersji 7: `dbDelta` dokłada kolumnę z wartością domyślną, więc żaden istniejący wiersz nie może udawać wpisu ręcznego.

- [ ] **Step 2: Uruchom i potwierdź, że padają**

Run: `… -c phpunit-integration.xml.dist --filter ManualFlagTest`

- [ ] **Step 3: Dodaj kolumnę i podbij wersję**

Kolumna dopisana do instrukcji CREATE tabeli zgłoszeń — `dbDelta` dołoży ją na istniejącej bazie, nie trzeba osobnego ALTER-a. `DB_VERSION` 7 → 8.

- [ ] **Step 4: Przeprowadź zapis i odczyt przez repozytorium**

- [ ] **Step 5: Uruchom testy**

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Persistence/Migrations.php src/Persistence/RegistrationRepository.php tests/Integration/Persistence/ManualFlagTest.php
git commit -m "feat(db): manual-entry column on registrations, schema v8"
```

---

### Task 3: Tryb wpisu ręcznego w `reserve()` i `reserveNights()`

**Files:**
- Modify: `src/Services/ReservationService.php` (`reserve()`, `reserveNights()`)
- Test: `tests/Integration/Services/ManualReservationTest.php`

**Interfaces:**
- Consumes: `ManualEntry` (Task 1), kolumna `manual` i klucz w `insertRegistration()` (Task 2).
- Produces: `ReservationService::reserve( int $event_id, ReservationRequest $request, ?ManualEntry $manual = null ): ReservationResult`. `reserveNights()` dostaje ten sam parametr jako ostatni.

Tryb zmienia **dokładnie** to:
1. pomija `activeRegistrationExists()` (bezwarunkowo, także przy niepustym adresie),
2. status przy wyniku przyjętym to `RegistrationStatus::Confirmed`, nie `Pending`,
3. `expires_at` zostaje `null`,
4. `manual => 1` w zapisie wiersza,
5. po COMMIT, **tylko gdy `notify`**: dla wyniku przyjętego `do_action( 'evreg_registration_confirmed', $id, $event_id )` — **nie** `evreg_registration_reserved`, bo ten szablon niesie link potwierdzający, a wiersz jest już potwierdzony; dla listy rezerwowej `evreg_registration_waitlisted` jak dziś. Przy `notify === false` nie leci nic.

Dodatkowo: `confirmed_at` ustawiane przez `markConfirmed( $id )` **wewnątrz tej samej transakcji**, zaraz po wstawieniu wiersza — to jedyne miejsce znające konwencję „potwierdzone = status + `confirmed_at` + `updated_at`"; nie rozszerzaj `insertRegistration()` o tę kolumnę.

Wynik na listę rezerwową zachowuje się jak dziś: status `waitlist`, `expires_at` null jak dziś, znacznik `manual` zapisany.

- [ ] **Step 1: Napisz failujące testy — gałąź klasyczna**

W `ManualReservationTest`, każdy test osobno: wpis ręczny przyjęty ma status `confirmed`, `confirmed_at` niepuste, `expires_at` null, `manual` = 1; **nie powstaje żaden wiersz w kolejce maili** przy `notify === false`; przy `notify === true` powstaje wiersz kolejki dla szablonu potwierdzenia, a **nie** dla opt-in; drugi wpis na ten sam adres w tym samym wydarzeniu przechodzi (blokada duplikatu pominięta); pełny typ przy włączonej liście rezerwowej daje `waitlist` z `manual` = 1; `reserve()` wołane **bez** trzeciego argumentu daje dokładnie dzisiejszy wynik (status `pending`, `expires_at` niepuste, `manual` = 0, wiersz kolejki opt-in).

- [ ] **Step 2: Napisz te same testy dla trybu „doby"**

Ten sam zestaw na wydarzeniu w trybie `nights`, z wierszami dób. Minimum: przyjęty wpis ręczny jest `confirmed`, bez `expires_at`, `manual` = 1, z poprawnymi wierszami dób; ścieżka bez trzeciego argumentu niezmieniona.

- [ ] **Step 3: Uruchom i potwierdź, że padają**

Run: `… -c phpunit-integration.xml.dist --filter ManualReservationTest`

- [ ] **Step 4: Przeprowadź `?ManualEntry` przez obie gałęzie**

Obie gałęzie muszą dostać ten sam tryb — pominięcie dobowej daje wpis, który wygasa mimo znacznika.

- [ ] **Step 5: Uruchom testy i pełną suitę integracyjną**

Expected: PASS, i **żaden istniejący test ścieżki publicznej nie zmienia wyniku**.

- [ ] **Step 6: Commit**

```bash
git add src/Services/ReservationService.php tests/Integration/Services/ManualReservationTest.php
git commit -m "feat(reservations): manual entry mode in reserve and reserveNights"
```

---

### Task 4: Promocja z listy rezerwowej honoruje znacznik

**Files:**
- Modify: `src/Services/ReservationService.php` (`promoteFromWaitlist()`, `promoteFromWaitlistNights()`)
- Test: `tests/Integration/Services/ManualPromotionTest.php`

**Interfaces:**
- Consumes: kolumna `manual` czytana z wiersza zgłoszenia (Task 2).
- Produces: brak nowych sygnatur — zmienia się zachowanie obu metod promocji dla wierszy ze znacznikiem.

Dla wiersza ze znacznikiem promocja kończy się `markConfirmed( $id )` zamiast `markPending( $id, $expires_at )` i **nie emituje `evreg_registration_reserved`**. Dla wiersza bez znacznika nie zmienia się nic — ani status, ani termin, ani hook.

- [ ] **Step 1: Napisz failujące testy**

Promocja wpisu ręcznego z listy rezerwowej: status `confirmed`, `confirmed_at` niepuste, `expires_at` null, **zero nowych wierszy w kolejce maili**. Promocja wpisu publicznego: status `pending`, `expires_at` niepuste, wiersz kolejki opt-in — dokładnie jak dziś. Oba przypadki osobno dla trybu klasycznego i dobowego.

- [ ] **Step 2: Uruchom i potwierdź, że padają**

Run: `… -c phpunit-integration.xml.dist --filter ManualPromotionTest`

- [ ] **Step 3: Rozgałęź obie metody promocji po znaczniku**

Bramka pojemności, zajętość i cała arytmetyka miejsc zostają bez zmian — rozgałęzienie dotyczy wyłącznie finalnego zapisu statusu i emisji hooka.

- [ ] **Step 4: Uruchom testy i pełną suitę integracyjną**

- [ ] **Step 5: Commit**

```bash
git add src/Services/ReservationService.php tests/Integration/Services/ManualPromotionTest.php
git commit -m "feat(reservations): promote manual entries straight to confirmed"
```

---

### Task 5: Rozdzielenie ramy formularza i render ekranu dodawania

**Files:**
- Modify: `src/Admin/RegistrationEditForm.php` (`render()` → `renderEdit()` + `renderAdd()` nad wspólną prywatną ramą; mapowanie `night_full:*` w `errorMessage()`)
- Modify: `src/Admin/RegistrationView.php` (`render_add()`, `render_add_errors()`, wiersz znacznika w `render_detail()`)
- Test: `tests/Integration/Admin/ManualAddFormRenderTest.php`

**Interfaces:**
- Consumes: nic z wcześniejszych tasków poza kolumną `manual` (Task 2) do wiersza na detalu.
- Produces:
  - `RegistrationEditForm::renderEdit( FormSchema $schema, array $answers, int $reg_id, ?array $errors = null, ?array $submitted = null, ?NightsConfig $nights = null ): string` — dzisiejsze `render()` pod nową nazwą, zachowanie bez zmian (nonce `evreg_edit_<id>`, ukryte `registration`, cel `ACTION_EDIT`).
  - `RegistrationEditForm::renderAdd( FormSchema $schema, int $event_id, ?array $errors = null, ?array $submitted = null, ?NightsConfig $nights = null ): string` — ta sama treść wierszy, inna rama: nonce dodawania, ukryte pole z ID wydarzenia, cel akcji dodawania, **przełącznik „wyślij potwierdzenie" domyślnie wyłączony**, własna etykieta przycisku.
  - `RegistrationView::render_add( int $event_id )` — bez wydarzenia (`0`) renderuje wybór wydarzenia zamiast formularza.
  - `RegistrationView::render_add_errors( FormSchema $schema, int $event_id, array $errors, array $submitted, ?NightsConfig $nights )`.

- [ ] **Step 1: Napisz failujące testy renderu**

Formularz dodawania zawiera: pole nonce dodawania, ukryte pole z ID wydarzenia, **nie** zawiera pola `registration`, zawiera niezaznaczony przełącznik wysyłki, a wszystkie pola schematu są puste. Render bez wydarzenia pokazuje wybór wydarzenia i **nie** zawiera `<form>` zgłoszenia. `renderAdd(...)` z błędem `night_full:<klucz doby>` przy polu noclegu wypisuje komunikat o wyczerpanej dobie, a nie „Nieprawidłowa wartość." (`errorMessage()` jest prywatna — sprawdzaj przez render, nie rozszerzaj jej widoczności dla testu). Detal zgłoszenia ze znacznikiem pokazuje wiersz o wpisie ręcznym, a zgłoszenie bez znacznika go nie pokazuje.

- [ ] **Step 2: Uruchom i potwierdź, że padają**

- [ ] **Step 3: Rozdziel ramę i dopisz render dodawania**

Wiersze pól renderują się wspólnym prywatnym kodem — **nie kopiuj** `renderRow`/`renderControl`/`renderAccommodation`/`renderNights`/`renderCompanionRow`. Zaktualizuj dwa istniejące wywołania `render()` w `RegistrationView`.

- [ ] **Step 4: Uruchom testy renderu i pełną suitę**

- [ ] **Step 5: Commit**

```bash
git add src/Admin/RegistrationEditForm.php src/Admin/RegistrationView.php tests/Integration/Admin/ManualAddFormRenderTest.php
git commit -m "feat(admin): add-registration form rendering"
```

---

### Task 6: Trasa, handler i komunikaty ekranu dodawania

**Files:**
- Modify: `src/Admin/RegistrationsScreen.php` (stała akcji, rejestracja `admin_post`, gałąź `add` w routerze, handler, przycisk na liście)
- Modify: `src/Admin/RegistrationNotice.php` (kody wyniku)
- Test: `tests/Integration/Admin/ManualAddHandlerTest.php`

**Interfaces:**
- Consumes: `ManualEntry` i `assemble( …, $emailOptional )` (Task 1), `reserve( …, ?ManualEntry )` (Task 3), `renderAdd` / `render_add_errors` (Task 5).
- Produces: trasa `?page=evreg-registrations&action=add&event=<id>`, akcja `admin-post` dodawania, kody komunikatów.

Przebieg handlera, w tej kolejności: nonce → uprawnienie → ID wydarzenia (brak albo post innego typu → komunikat o braku) → `EventFormLoader::load()` (null → `config_error`) → `nights()` w `try/catch` na `SchemaException` (→ `config_error`) → `assemble( …, emailOptional: true )` → **straż pustego/nieznanego typu** → **straż „przełącznik włączony przy pustym adresie"** → `companionRoomMismatch` **bezwarunkowo** (inaczej niż `handle_edit`, który stosuje ją warunkowo — przy dodawaniu nie ma czego zastać) → `reserve( $event_id, $request, new ManualEntry( $notify ) )` → mapowanie wyniku.

Mapowanie wyniku: przyjęty → PRG na detal nowego zgłoszenia z komunikatem o dodaniu; lista rezerwowa → PRG z komunikatem o dodaniu na listę rezerwową; odmowa pojemności → PRG z komunikatem o braku miejsc; wyczerpany nocleg → **bez PRG**, błąd przy polu noclegu i ponowny render z `rawAnswers()` (nie `values()` — na gałęzi poprawnej walidacji `values()` niesie nocleg jako obiekt i nie ma kluczy `evreg_companion*`).

Przy NIEPUSTYM adresie, który ma już aktywne zgłoszenie na to wydarzenie, komunikat o powodzeniu mówi o tym osobno — informacja, nie blokada.

- [ ] **Step 1: Napisz failujące testy handlera**

Zły nonce → `WPDieException`; brak uprawnienia → `WPDieException`; poprawny POST tworzy zgłoszenie `confirmed` i przekierowuje na jego detal (przekierowanie łap filtrem `wp_redirect` rzucającym wyjątek); **pusty typ → brak zapisu i błąd przy polu typu**; **włączony przełącznik przy pustym adresie → brak zapisu i błąd walidacji**; pusty adres przy wyłączonym przełączniku → zapis przechodzi; brakujące wymagane pole inne niż e-mail → brak zapisu i ponowny render; dodanie na adres mający już aktywne zgłoszenie → zapis przechodzi, a komunikat niesie informację o duplikacie.

- [ ] **Step 2: Uruchom i potwierdź, że padają**

Run: `… -c phpunit-integration.xml.dist --filter ManualAddHandlerTest`

- [ ] **Step 3: Dopisz trasę, handler, przycisk i kody komunikatów**

Przycisk „Dodaj zgłoszenie" na liście zgłoszeń obok „Eksportuj CSV", niosący bieżący filtr wydarzenia.

- [ ] **Step 4: Uruchom testy i pełną bramkę**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- composer analyse
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
```

- [ ] **Step 5: Commit**

```bash
git add src/Admin/RegistrationsScreen.php src/Admin/RegistrationNotice.php tests/Integration/Admin/ManualAddHandlerTest.php
git commit -m "feat(admin): add-registration route, handler and notices"
```

---

### Task 7: Katalog i18n, dokumentacja, backlog, paczka

**Files:**
- Modify: `languages/event-registration.pot` (regenerowany, nie edytowany ręcznie)
- Modify: `CHANGELOG.md` (sekcja `## [Unreleased]`, po angielsku, dla użytkownika końcowego)
- Modify: `CLAUDE.md` (panel zgłoszeń i rdzeń rezerwacji)
- Modify: `docs/superpowers/backlog.md` (nowy wpis)

**Interfaces:**
- Consumes: nazwy ze wszystkich wcześniejszych tasków.
- Produces: nic.

- [ ] **Step 1: Zregeneruj katalog i18n**

Run: `npm run i18n:pot`
Expected: nowe msgid komunikatów ekranu dodawania; **żaden istniejący msgid nie znika** — sprawdź `git diff languages/`. `languages/event-registration-en_US.po` to zastany dryf i **nie** jest przedmiotem tego zadania.

- [ ] **Step 2: Dopisz wpis do `CHANGELOG.md`**

Pod `## [Unreleased]`, w `### Added`. Językiem użytkownika: organizator może dodać zgłoszenie z panelu; powstaje od razu jako potwierdzone i nie wygasa; domyślnie nie wychodzi żaden mail, a przełącznik pozwala wysłać potwierdzenie; limity miejsc działają normalnie. Bez nazw klas i filtrów, bez nagłówka wersji i daty.

- [ ] **Step 3: Dopisz fakty do `CLAUDE.md`**

(a) tryb wpisu ręcznego jako opcjonalny parametr `reserve()`/`reserveNights()`, `null` = ścieżka publiczna, i **lista dokładnie tych rzeczy, które zmienia** — żeby nikt nie dołożył piątej; (b) kolumna znacznika i db v8, oraz że promocja z listy rezerwowej rozgałęzia się po nim; (c) mail przy wpisie ręcznym idzie szablonem potwierdzenia, nie opt-in, i dlaczego; (d) straż pustego typu stoi w handlerze ekranu dodawania, a `reserve()` jej NIE ma — ścieżka publiczna zachowuje tę dziurę świadomie; (e) jedyny wyjątek walidacyjny: w panelu wolno zostawić puste wyłącznie pole e-mail.

- [ ] **Step 4: Dopisz wpis do `docs/superpowers/backlog.md`**

Kolejny wolny numer po ostatnim istniejącym: brak straży nieznanego typu w `reserve()`/`reserveNights()` na ścieżce publicznej — `__type` nie jest domyślnie wymagane, więc puste pole typu daje wiersz bez typu, darmowy, liczony tylko do limitu globalnego. Pozostałe cztery ścieżki transakcyjne tę straż mają.

- [ ] **Step 5: Przebuduj paczkę instalacyjną**

Run: `bash scripts/build-zip.sh`
Expected: `event-registration.zip` powstaje bez błędu (gitignorowany, nie commituj).

- [ ] **Step 6: Commit**

```bash
git add languages/event-registration.pot CHANGELOG.md CLAUDE.md docs/superpowers/backlog.md
git commit -m "docs: manual registration facts, changelog and backlog entry"
```
