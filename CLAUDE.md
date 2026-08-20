# CLAUDE.md

Operacyjny przewodnik po tym repo dla sesji AI. Fakty tu zebrane były wielokrotnie odkrywane od nowa — czytaj przed pracą.

## Czym jest projekt

Wtyczka WordPress do budowania formularzy rejestracji na wydarzenia (pola formularza, typy zgłoszenia z cenami/limitami, noclegi, potwierdzenia mailowe, eksport). Instalowana na wielu stronach (jeden event = jedna instalacja WP nie obowiązuje — CPT wspiera wiele eventów). Budowana jak produkt (i18n, uninstall, brak hardkodowania pod stronę), używana wewnętrznie.

Roadmapa 6 planów, każdy w cyklu spec → plan → wykonanie subagentami (superpowers). Scalone do master: **Plan 1 (domena), Plan 2 (CPT + REST + React admin), Plan 3A (transakcyjny backend rezerwacji), Plan 3B (formularz publiczny), Plan 4A (silnik kolejki mailowej), Plan 4B-Szablony (edytor szablonów maili), Plan 4B-Kolejka (ekran kolejki mailowej), Plan 5A (panel zgłoszeń: lista + akcje), Plan 5B (edycja odpowiedzi), Plan 5C (eksport CSV)**. Plan 4 (kolejka mailowa) i Plan 5 (panel zgłoszeń) zamknięte w całości. Kolejny: Plan 6 (auto-update z GitHub + hardening CI + uninstall.php + WP privacy API). Specyfikacje i plany w `docs/superpowers/`.

Formularz publiczny (3B): `src/Frontend/` — `EventFormLoader` (składa schemę), `FormRenderer` (serwerowy HTML, escaping, re-render błędów z zachowaniem wartości, honeypot/nonce/timestamp), `SubmitHandler` (`process` = antyspam→walidacja→ekstrakcja email/name/typ/nocleg→`ReservationService::reserve`; `handle` na `template_redirect` = nonce, rate-limit per IP transient 10/godz, PRG na sukcesie, statyczny magazyn wyniku per request), `Shortcode` `[evreg_form event="ID"]` (ścieżka podstawowa) + minimalny dynamiczny `Block` (oba przez `Shortcode::renderForm`), `ConfirmationController` (`?evreg_confirm=<token>` → PRG `?evreg_confirmed=<kod>`). Publiczne assety statyczne w `assets/public/` (`form.js` chowa sekcje wg `__type` przez `data-evreg-when-*`, `form.css`) — bez webpacka, handle `evreg-public` rejestrowany na `init`. Submisja POST-to-self (nie admin-post): heurystyka email = pierwsze niepuste pole typu `email`; name = `name`/`imie` → pierwsze `text` → email.

Kolejka mailowa (4A): `src/Mail/` — `Subscriber` (hooki `evreg_registration_reserved|waitlisted|confirmed|expired` → kolejka), `MailQueue::enqueue` (render snapshotu przy zapisie, INSERT IGNORE pod `UNIQUE(registration_id, template_key)` = idempotencja), `Dispatcher` (odzysk `sending` >5 min → claim warunkowym UPDATE-em → `wp_mail` → `sent`/retry 1 min, 5 min/`failed`, powód z `wp_mail_failed`), `TemplateResolver` (meta `_evreg_mail_templates` z fallbackiem per pole na `DefaultTemplates`), `PlaceholderFactory`. Czysta domena w `src/Domain/Mail/`. Crony: `evreg_dispatch_mail` co minutę + jednorazowy `evreg_dispatch_mail_now` po zakolejkowaniu maila uczestnika, `evreg_purge_mail_queue` dziennie (kasuje `sent` starsze niż 30 dni). Maile plain text.

Edytor szablonów (4B): piąta zakładka React `MailTemplatesTab` (5 typów × temat+treść, fallback per pole na `DefaultTemplates` widoczny jako placeholder). Zapis osobnym endpointem `MailTemplateController` (`evreg/v1/events/<id>/mail-templates`, GET/POST) — NIE przez `EventConfigController` (jego `sanitize()` zjada `\n`, testy asertują 4-kluczową mapę). `MailTemplateRepository::save()` owija JSON w `wp_slash` (pułapka `wp_unslash` z 4A). Logika mutacji w czystym `ops/mailTemplateOps.js`. Globalny „Zapisz" w `App.jsx` orkiestruje PUT config + POST szablonów przez `Promise.allSettled`. `form_page_id` i `notify_emails` dostały UI w `SettingsTab` (dropdown stron + poprawiony klucz — wcześniej UI zapisywało `organizer_emails`, którego mail layer nie czytał).

Ekran kolejki (4B-Kolejka): `MailQueueScreen` — submenu „Kolejka maili" pod menu CPT `evreg_event`, cap `edit_evreg_events`. `MailQueueListTable` (WP_List_Table) listuje `evreg_mail_queue` z filtrami statusu/eventu, paginacja 20/stronę, `ORDER BY id DESC`. Akcja „Podgląd" → ekran szczegółów (`?action=view&id=N`, read-only, treść w `<pre>` escapowana). Akcja „Wznów" tylko na `failed` → `admin-post` `evreg_requeue_mail` (nonce + cap + PRG) → `MailQueueRepository::requeueFailed` (`status→queued`, `attempts=0`, chronione `WHERE status='failed'`). Admin nie woła dispatchera — resetuje wiersz, cron `evreg_dispatch_mail` z 4A złapie w następnym przebiegu. Zapytania listujące (`paginate`/`countByFilter`/`distinctEventIds`) i wznowienie w `MailQueueRepository` (jedyne SQL kolejki).

Panel zgłoszeń (5A): `RegistrationsScreen` — submenu „Zgłoszenia" pod menu CPT `evreg_event`, cap `edit_evreg_events`. `RegistrationsListTable` (WP_List_Table) listuje `evreg_registrations` z filtrami status/typ/event. Ekran szczegółów (`?action=view`) pokazuje odpowiedzi (złożona schema przez `EventFormLoader`), nocleg, notatkę (edytowalną). Akcje cyklu życia w `ReservationService` (jedyne miejsce z transakcją lock→count): `confirmManually` (pending→confirmed, BEZ maila — nie emituje hooka), `cancel` (miękkie, status=cancelled + kasuje booking, zwalnia miejsce), `promoteFromWaitlist` (waitlist→pending pod lock→count jak `reserve`, emituje `evreg_registration_reserved` → mail opt-in z 4A), `deleteRegistration` (twarde, tylko na cancelled, sprząta osierocone wiersze kolejki). Handlery admin-post nonce→cap→akcja→PRG. `AdminActionResult` niesie kod wyniku. Eksport CSV/XLSX to Plan 5C.

Edycja odpowiedzi (5B): `SubmissionAssembler` (`src/Frontend/`) — wydzielony z `SubmitHandler` wspólny pipeline validate→ekstrakcja (email/name/typ/selekcja) → `AssembledSubmission{isValid,errors,values,request}`; `SubmitHandler::process` deleguje (kontrakt 3B niezmieniony, guard pustego emaila zostaje w handlerze). `ReservationService::editAnswers` — transakcyjny rdzeń: `lockEvent`→`occupancyExcluding(self)`→`decide`, twardy blok `Outcome::Accepted !== outcome` (łapie Rejected I Waitlisted — inaczej przy waitlist_enabled edycja przepełniłaby pełny typ) + `accommodationFull` gdy selekcja niegrantowana, guard nieznanego typu→`invalidStatus`, waitlist BEZ bramki (nie zajmuje miejsca, status zostaje), **bez hooków = bez maila** (jak `confirmManually`); nowe kody `AdminActionResult::edited/capacityFull/accommodationFull`. Repo: `occupancyExcluding(event,exclude)` (= `occupancy` + `AND id!=%d` w 3 zapytaniach) + `updateRegistration` (6 kolumn, nie tyka status/token/dat). `RegistrationEditForm` (`src/Admin/`) — serwerowy formularz edycji, kontrolka per `FieldType` prefill z `submitted??answers`, pełny escaping, błędy przez `errorMessage()` (mapa kod→PL jak `FormRenderer`) z labelem pola, reuse `form.js` (`evreg-public`) do conditional wg `__type`. `RegistrationsScreen`: `?action=edit`→`render_edit`, `handle_edit` (nonce `evreg_edit_<id>` przed cap, PRG na detal / re-render inline na błąd walidacji), link „Edytuj" dla pending/confirmed/waitlist. UWAGA: `editAnswers` NIE woła `activeRegistrationExists` (duplicate-email guard z `reserve`), `idx_event_email` nie-UNIQUE — admin może ręcznie zdublować email; świadomie odłożone (przyszły plan).

Eksport CSV (5C): `RegistrationExportMapper` (`src/Domain/Export/`, czysty — bez WP/i18n) mapuje schema→kolumny: `answerColumns` (pola input, POMIJA `__type` i `accommodation` — reprezentowane osobno przez kolumny tożsamości/noclegu), `answerCells` (data JSON→komórki, multi-value `implode(', ')`), `accommodationCells` (booking→labele pakiet/pokój, fallback surowy klucz). `RegistrationsExporter::buildCsv` (`src/Admin/`, prezentacja) — zwraca PEŁNY tekst CSV jako string (testowalne osobno od streamingu): UTF-8 BOM, nagłówki tożsamości i18n (ID/Status/Typ/E-mail/Imię/Cena/Utworzono/Potwierdzono/Notatka) + labele pól autora + Nocleg×3, status przez `RegistrationsListTable::status_label`, typ przez `RegistrationTypeCollection::get`, **neutralizacja CSV injection** (komórki DANYCH zaczynające się od `= + - @ \t \r` poprzedzone `'`; nagłówek NIE). Repo: `exportRegistrations(filters)` (jak `paginateRegistrations` bez LIMIT, `ORDER BY id ASC`, reuse `registrationWhere`) + `accommodationBookingsFor(ids)` (bulk `IN`, mapa `registration_id→wiersz`, unika N+1, empty-ids short-circuit). `RegistrationsScreen`: przycisk „Eksportuj CSV" (nonce'd GET link niosący bieżące filtry) → `handle_export` (`ACTION_EXPORT='evreg_export'`, nonce→cap→**wymóg `event_id>0`** inaczej PRG `export_no_event`→`EventFormLoader::load` null-guard→`buildCsv`→nagłówki `text/csv`+`Content-Disposition`+`echo`+`exit`, bez PRG na sukcesie). Eksport per event (kolumny = schema jednego eventu). XLSX świadomie pominięty (wymagałby biblioteki + commitu `vendor/`).

Rdzeń rezerwacji (3A): nośny inwariant — w `ReservationService::reserve` `lockEvent()` (SELECT … FOR UPDATE wiersza `evreg_locks` per event) MUSI poprzedzać pierwszy COUNT zajętości (snapshot-timing InnoDB REPEATABLE READ). Nie zmieniać kolejności. Zajętość liczona świeżo z tabeli zgłoszeń (pending+confirmed zajmują miejsce).

## Środowisko i komendy — KRYTYCZNE

**Host nie ma PHP.** Testy/narzędzia PHP działają w kontenerze wp-env. JS build i jest działają na hoście przez zwykły `npm` (Node 24).

**Nie używaj gołego `npx wp-env`** — rozwiązuje się do przestarzałej pustej paczki, a Node ma na tej maszynie kwirk DNS. Zawsze przez wrapper `node scripts/wp-env.cjs`. Porty: dev 8891, tests 8892.

```bash
# JS (host, bez kontenera)
npm run build            # webpack → build/admin/
npm run start            # watch
npm run test:js          # jest (moduły assets/admin/ops/*)

# PHP (kontener, przez wrapper)
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs

# E2E (host, wymaga działającej wp-env)
node scripts/wp-env.cjs start
npm run test:e2e         # Playwright, celuje w localhost:8891

# Composer (host, obraz dockera — bez lokalnego PHP)
docker run --rm -v "$PWD:/app" -w //app composer:2 install
```

Logowanie do wp-env: `admin` / `password`.

## Inwarianty architektury — nie łam

- **`src/Domain/**` = zero WordPressa i zero `$wpdb`.** Czysty PHP, testowalny bez WP. Pilnuje `tests/Unit/Architecture/DomainPurityTest.php` (denylist ciągów). Adaptery WP siedzą w `src/Admin/`, `src/Persistence/`, `src/Rest/`.
- **Domena rzuca wyjątki / zwraca kody błędów, NIGDY komunikaty dla użytkownika.** Tłumaczenie w warstwie prezentacji.
- **Model 2 konfiguracji eventu:** cztery osobne meta (`_evreg_schema`, `_evreg_types`, `_evreg_accommodation`, `_evreg_settings`). `_evreg_schema` trzyma pole `__type` BEZ opcji i pola `accommodation` BEZ configu; `SchemaAssembler` (czysta domena) scala je w kompletną `FormSchema` przy walidacji i renderowaniu. Nie duplikuj — jedno źródło prawdy.
- **React: cała logika mutacji w czystych modułach `assets/admin/ops/*` testowanych `jest`.** Komponenty cienkie, tylko wołają ops. Ops nie mutują wejścia (zwracają nowe obiekty). Nie wkładaj transformacji do komponentu — nie da się jej przetestować.
- **Wszystkie stringi UI (PHP i JS) przez i18n**, text domain `event-registration`. W JS: `__()` z `@wordpress/i18n`. To wielokrotnie umykało — sprawdzaj każdy literał.
- **Serwer jest arbitrem walidacji**, klient tylko lustrem. `validation.errors[].detail` to surowy tekst wyjątku — renderuj ESCAPOWANY (React domyślnie; nigdy `dangerouslySetInnerHTML`).
- **Hooki cyklu życia zgłoszenia emitowane wyłącznie po COMMIT.** Nasłuchujący nigdy nie działa w transakcji rezerwacji. Idempotencję maili gwarantuje indeks unikalny w kolejce, nie dyscyplina wołających.

## Konwencje

- Namespace `EvReg\`, PSR-4, `src/`. Prefiks `evreg_` / meta `_evreg_`. REST `evreg/v1`.
- Każdy plik PHP poza `src/Domain/` i `tests/` zaczyna od `defined( 'ABSPATH' ) || exit;`.
- **Katalogi testów wielką literą** (`tests/Unit/`, `tests/Integration/`), nazwy suit PHPUnit małą (`--testsuite unit`).
- Metody obiektów wartości: camelCase (`fromArray`, `slotKey`). Globalne funkcje/hooki: snake_case z prefiksem.
- `phpcs.xml.dist` wyklucza trzy sniffy nie do pogodzenia z architekturą (nazwy plików PSR-4, właściwości i metody camelCase). Nowy kod musi przejść `phpcs` czysto pod tą konfiguracją. Nie dodawaj czwartego wykluczenia bez powodu.
- PHPStan poziom 6, bez obniżania i bez rozproszonych `@phpstan-ignore`.
- `.gitattributes` wymusza LF (autocrlf na tej maszynie psuł phpcs w kontenerze).
- `build/` ignorowane w gicie — commituj źródła `assets/`, nie artefakty.

## Gotchas

- **Konsola PHPUnit w tym kontenerze zniekształca komunikat każdego niezłapanego `Error`.** Fazę RED weryfikuj jawnym try/catch drukującym `get_class($e)`/`getMessage()`/`getFile()`/`getLine()`, nie podsumowaniem PHPUnit.
- Przerwany `wp-env start` potrafi zostawić uszkodzony wolumen MariaDB (`ibdata1: wrong space ID`) — `docker compose down` w `~/.wp-env/<hash>/` i usuń wolumeny `mysql`/`mysql-test`.
- React admin montuje się na ekranie edycji CPT `evreg_event`. Na `post-new.php` (niezapisany event) `eventId=0` — app pokazuje „zapisz szkic najpierw", nie próbuje ładować.
- Capabilities self-heal (`Capabilities::maybe_grant()` na `admin_init`) propagują się przy pierwszym żądaniu — pierwsza edycja świeżo aktywowanej wtyczki może dać przejściowe „not allowed", drugie żądanie OK.
- **Klucz adresów organizatora to `notify_emails`** (nie `organizer_emails`). `SettingsTab` do Planu 4B zapisywał zły klucz — powiadomienia spadały na `admin_email`. Naprawione; przy zmianach ustawień pilnuj tej nazwy.
- **`WP_List_Table` nie jest autoloadowane** — plik podklasy (`MailQueueListTable`) musi `require_once ABSPATH.'wp-admin/includes/class-wp-list-table.php'` przed deklaracją klasy. Testy: `set_current_screen(...)` przed instancjonowaniem; redirect handlera łapany filtrem `wp_redirect` rzucającym wyjątek, zły nonce/cap → `WPDieException`.
- **Ręczne potwierdzenie (`confirmManually`) NIE emituje `evreg_registration_confirmed`** — inaczej Subscriber z 4A wysłałby mail. Świadome; test pilnuje braku wiersza kolejki. Promocja z waitlisty PODLEGA temu samemu inwariantowi lock→count co `reserve` — nie zmieniać kolejności lockEvent→occupancy.

## Workflow

Praca idzie przez skille superpowers: brainstorming → writing-plans → subagent-driven-development → finishing-a-development-branch. Każdy plan: własna gałąź od master, ledger w `.superpowers/sdd/`, review po każdym tasku + whole-branch review na końcu, merge do master po zielonych testach. Nie commituj bezpośrednio na master z wyjątkiem spec/plan/docs.
