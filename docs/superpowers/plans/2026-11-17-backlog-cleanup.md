# Domknięcie backlogu (B14, B21, B12, B17, B19, B16, B20) — plan

> **Dla pracujących agentowo:** WYMAGANY SUB-SKILL: superpowers:subagent-driven-development.

**Cel:** zamknąć wszystkie otwarte błędy i dług strukturalny z backlogu — pięć poprawek
i dwa refaktory bez zmiany zachowania.

**Spec:** `docs/superpowers/backlog.md` — wpisy B12, B14, B16, B17, B19, B20, B21. Każdy
ma Stan, Skutek i Szkic; to one są wiążącą specyfikacją, a nie ten plan. Plan ustala
tylko kolejność, granice tasków i wymagane testy.

**Kolejność jest wymuszona:** najpierw poprawki, refaktory na końcu. Refaktor zachowujący
zachowanie jest najbezpieczniejszy, gdy zachowanie jest już poprawne i przypięte testami;
odwrotna kolejność miesza dwa rodzaje ryzyka w jednym diffie. B16 idzie po B12 (oba
w `ReservationService`), B20 po wszystkim, co dotyka `RegistrationsScreen`.

## Global Constraints

- **Inwariant 3A:** `lockEvent()` poprzedza pierwszy odczyt zajętości w każdej ścieżce
  transakcyjnej. Dotyczy to także B17, który lock dopiero wprowadza na nową ścieżkę.
- **Hooki cyklu życia wyłącznie po COMMIT.** Ręczne potwierdzenie i edycja nie emitują
  hooków (brak maila) — to świadome i testy tego pilnują.
- `src/Domain/**` bez WordPressa, bez `$wpdb`, bez stringów dla użytkownika
  (`DomainPurityTest`). Domena zwraca kody, prezentacja robi z nich zdania.
- Całe SQL w `RegistrationRepository`.
- Każdy plik PHP poza `src/Domain/` i `tests/` zaczyna się od `defined( 'ABSPATH' ) || exit;`.
  Wszystkie stringi UI przez i18n, text domain `event-registration`; w JS `__()`.
- PHPStan 6 bez obniżania i bez `@phpstan-ignore`; `phpcs` czysto, bez nowych wykluczeń.
- Logika mutacji konfiguracji w adminie w czystych `assets/admin/ops/*`, bez mutowania wejścia.
- **Dwa tryby noclegu zostają rozdzielone.** Żadna gałąź `nights` nie konstruuje
  `AccommodationConfig`; `null` wstrzyknięte w dół znaczy „klasyczny", nigdy „niepoprawny".
- **`nights === null` = „nie podano"** (edycja zachowuje wiersze), PUSTY `NightSelection`
  = „porzuć nocleg". Ta asymetria jest nośna — B21 na niej stoi.
- TDD: najpierw test padający z właściwego powodu, potem implementacja.

### Komendy

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- composer analyse
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
npm run test:js && npm run build
node scripts/wp-env.cjs start && npm run test:e2e
```

Nigdy gołe `npx wp-env`. PHPStan WYŁĄCZNIE przez `composer analyse` (niesie
`--memory-limit=2G`; goła binarka pada na 128M komunikatem wyglądającym jak błąd kodu).

---

## Task 1: B14 — ukryte warunkowo pole `required` blokuje wysyłkę

**Files:** `assets/public/form.js`; e2e w `tests/e2e/conditional-visibility.spec.js`.

Czysto kliencka. Serwer jest już świadomy warunków (`Validator` + `VisibilityResolver`),
więc pole schowane warunkowo i tak nie jest po jego stronie wymagane.

- [ ] W `apply()`, po ustawieniu `el.hidden`, przenieść `required` z kontrolek w środku na
      atrybut zapasowy i z powrotem przy ponownym pokazaniu — stan musi dać się odtworzyć.
      Wzór do powtórzenia: `applyCompanion`, gdzie `required` chodzi w parze z widocznością.
- [ ] e2e: pole wymagane w sekcji schowanej warunkiem → formularz **wysyła się**; po
      pokazaniu sekcji to samo pole blokuje wysyłkę z natywnym komunikatem. Bez tego
      drugiego kroku test przeszedłby po prostym usunięciu `required` zewsząd.
- [ ] `npm run test:js`, `npm run test:e2e`, commit.

## Task 2: B21 — nocleg ukryty warunkiem gubi się przy edycji

**Files:** `src/Frontend/SubmissionAssembler.php`, ewentualnie `src/Domain/Validation/Validator.php`;
testy integracyjne dla OBU trybów.

Kod wspólny dla obu trybów i dla formularza publicznego — stąd osobny task i własna siatka.

- [ ] Dla pola noclegu **niewidocznego** ekstrakcja zwraca `null`, nie pustą selekcję.
      `reserve` czyta `null` jako „bez noclegu" (nowe zgłoszenie nic nie traci),
      `editAnswers` jako „nie dotykaj noclegu".
- [ ] Testy: edycja zmieniająca typ zgłoszenia tak, że warunek pola noclegu przestaje
      pasować — osobno `nights` i `classic`. W obu: wiersze/booking, miejsca i cena
      **nietknięte**. Plus test, że świadoma rezygnacja (pole WIDOCZNE, nic nie zaznaczone)
      dalej zrzuca nocleg — inaczej poprawka zabiłaby rezygnację.
- [ ] Pełne bramki, commit.

## Task 3: B12 — awans z waitlisty nie ponawia wybranego noclegu

**Files:** `src/Services/ReservationService.php`; testy w `tests/Integration/Services/`.

**Decyzja produktowa, rozstrzygnięta:** promocja honoruje życzenie zapisane w `data`
i wycenia je po **bieżącym** cenniku — dokładnie tak, jak już robi to gałąź `nights`
(`nightWish` + `promoteFromWaitlistNights`). Alternatywa (brak łóżka mimo wolnych miejsc)
jest gorsza dla zgłaszającego się, a różnicę ceny widzi admin w wyniku akcji.

- [ ] Gdy brak bookingu, odczytać selekcję z `data` zgłoszenia i podać ją do ponownej
      decyzji. `promoted_no_accommodation` ma odpalać także wtedy, gdy życzenie było,
      a miejsca nie ma — dziś ten kod jest praktycznie martwy.
- [ ] Kolejność `lockEvent` → odczyt zajętości bez zmian. Hooki po COMMIT.
- [ ] Testy: waitlista z życzeniem + wolne miejsce → booking powstaje, cena z bieżącego
      cennika; waitlista z życzeniem + brak miejsca → `promoted_no_accommodation` i brak
      bookingu; waitlista bez życzenia → zwykłe `promoted`.
- [ ] Pełne bramki, commit.

## Task 4: B17 — wyścig sprawdzenie-zapis przy zmianie trybu

**Files:** `src/Rest/EventConfigController.php`, `src/Persistence/RegistrationRepository.php`
(jeśli trzeba udostępnić lock), testy w `tests/Integration/Rest/`.

- [ ] Sprawdzenie `eventHasAccommodation()` i zapis konfiguracji wykonać pod `lockEvent`
      — tym samym wierszem `evreg_locks`, który biorą ścieżki rezerwacji.
- [ ] Uważać na zakleszczenie: żadna ścieżka rezerwacji nie może czekać na ten lock
      trzymany przez długi zapis REST. Jeśli trzymanie locka przez cały zapis okaże się
      nieproporcjonalne, zgłoś to zamiast improwizować.
- [ ] Testy: odmowa 409 dalej działa; zapis niezmieniający trybu nie jest blokowany;
      lock zostaje zwolniony także gdy zapis rzuci.
- [ ] Pełne bramki, commit.

## Task 5: B19 — jedno źródło prawdy o trybie noclegu

**Files:** `src/Frontend/NightsResolver.php` i jego wołający.

**Reguła nośna, nie do naruszenia:** tryb jest właściwością **EVENTU**, nie schematu.
Event dobowy bez pola noclegu w formularzu nie ma w schemacie żadnego śladu trybu — dziś
właśnie dlatego `forSchema` zwraca tam `null`, a powierzchnie czytające surowe meta
odpowiadają inaczej.

- [ ] Znieść rozjazd: tryb rozstrzygany z surowego meta także tam, gdzie potrzebny jest
      obiekt `NightsConfig`. Powierzchnie czytające zapisane zgłoszenia (detal i edycja
      w adminie) mają wtedy pokazywać doby również dla eventu, któremu usunięto pole
      noclegu z formularza.
- [ ] `isNightsMode()` nie ma dziś żadnego wołającego produkcyjnego — usunąć razem z jego
      testem albo dać mu rolę w nowym kształcie; nie zostawiać martwego.
- [ ] Testy: event dobowy BEZ pola noclegu w schemacie — detal i edycja pokazują zapisane
      doby; event klasyczny bez zmian.
- [ ] Pełne bramki, commit.

## Task 6: B16 — koniec duplikacji w `ReservationService`

**Files:** `src/Services/ReservationService.php` (+ ewentualnie nowy obiekt pomocniczy
w `src/Services/`).

**Bez zmiany zachowania.** Pełna siatka testów integracyjnych jest tu siatką
bezpieczeństwa: ma przejść bez modyfikacji. Test, który trzeba poprawić, żeby refaktor
przeszedł, jest sygnałem zmiany zachowania — wtedy STOP i zgłoś.

- [ ] Wydzielić: (a) budowę `CapacityLimits` z configu i flagi, (b) budowę wierszy dób ze
      snapshotem etykiety i ceny, (c) sprawdzenie zliczeń pod lockiem.
- [ ] **Zachować kolejność `lockEvent` → odczyt zajętości** w każdej ścieżce; wydzielona
      metoda nie może wykonać odczytu przed lockiem ani przenieść hooka do transakcji.
- [ ] Po refaktorze: żaden z trzech fragmentów nie istnieje w więcej niż jednej kopii.
- [ ] Pełne bramki **bez modyfikacji testów**, commit.

## Task 7: B20 — rozbić `RegistrationsScreen`

**Files:** `src/Admin/RegistrationsScreen.php` + nowa klasa prezentacji w `src/Admin/`.

**Bez zmiany zachowania**, ta sama zasada co w Tasku 6.

- [ ] Wydzielić renderowanie detalu i edycji do osobnej klasy; handlery `admin-post`
      (nonce → cap → akcja → PRG) **zostają** w `RegistrationsScreen`.
- [ ] Nie zmieniać kolejności ani treści sprawdzeń bezpieczeństwa.
- [ ] Pełne bramki bez modyfikacji testów, commit.

---

## Samoprzegląd

Pokrycie: B14 → T1, B21 → T2, B12 → T3, B17 → T4, B19 → T5, B16 → T6, B20 → T7.
Zależności: T6 po T3 (ten sam plik), T7 na końcu (dotyka ekranu, którego T5 może tknąć).
Ryzyka: T2 rusza kod wspólny dla obu trybów i formularza publicznego; T4 wprowadza lock na
ścieżkę, która go nie miała (ryzyko zakleszczenia); T6 i T7 to refaktory, w których jedyną
miarą poprawności jest niezmieniona siatka testów.
