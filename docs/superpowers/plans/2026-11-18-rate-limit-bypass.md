# Zwolnienie organizatora z limitu submisji — plan wdrożenia

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Użytkownik z uprawnieniem `edit_evreg_events` nie wchodzi w limit submisji formularza publicznego, a kto w limit wejdzie, dostaje komunikat mówiący, że to blokada czasowa.

**Architecture:** Cała zmiana zachowania siedzi w `SubmitHandler::handle()` — brama zwolnienia owija parę `isRateLimited()`/`recordAttempt()`, próg przechodzi przez filtr z podłogą, a odrzucenie zwraca nowy kod `rate_limited` zamiast wspólnego `spam`. Prymitywy licznika zostają nietknięte, więc istniejący `SubmitRateLimitTest` dalej mierzy to samo. Powierzchnia użytkownika to jeden wpis w mapie komunikatów `Shortcode::resultNotice()`.

**Tech Stack:** PHP 8.1+, WordPress, PHPUnit (wp-env), i18n text domain `event-registration`.

**Spec:** `docs/superpowers/specs/2026-11-18-rate-limit-bypass-design.md`

## Global Constraints

- Uprawnienie to `EvReg\Admin\Capabilities::CAP` (`edit_evreg_events`) — bez nowego capa, bez hardkodowania literału. Precedens importu `Admin\Capabilities` poza `src/Admin/`: `src/Rest/EventConfigController.php:103`.
- Zwolniony użytkownik przeskakuje `isRateLimited()` **oraz** `recordAttempt()` — nie inkrementuje licznika wspólnego IP.
- `isRateLimited()` i `recordAttempt()` zostają publicznymi prymitywami licznika; brama zwolnienia wchodzi w `handle()`, nie w ich środek.
- Nonce obowiązuje każdego, w tym zwolnionych. Zwolnienie dotyczy wyłącznie limitu.
- Nonce, honeypot i okno 3 s zostają na kodzie `spam` z dotychczasowym mglistym komunikatem. Tylko blokada limitu dostaje własny kod i własny tekst.
- Próg domyślny: `RATE_LIMIT = 30`. Okno: `HOUR_IN_SECONDS` (bez zmian).
- Wszystkie stringi UI przez i18n, text domain `event-registration`.
- Każdy plik PHP poza `src/Domain/` i `tests/` zaczyna od `defined( 'ABSPATH' ) || exit;` (pliki w tym planie już go mają).
- PHPStan poziom 6 bez obniżania i bez rozproszonych `@phpstan-ignore`; `phpcs` czysto pod `phpcs.xml.dist`.

## Komendy

```bash
# PHP (kontener, przez wrapper — host nie ma PHP; NIE używać gołego npx wp-env)
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- composer analyse
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs

# Jeden plik testowy
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter SubmitRateLimitTest

# i18n i paczka (host)
npm run i18n:pot
bash scripts/build-zip.sh
```

PHPStan **tylko** przez `composer analyse` — gołe `vendor/bin/phpstan analyse` pada na domyślnym limicie 128 MB komunikatem, który wygląda na błąd kodu.

## Review Focus

Klasy wejść, które spec implikuje, a których żaden task by sam nie przetestował. Każda ma przypisany test w zadaniu, które jest właścicielem kodu.

1. **Filtr `evreg_rate_limit` zwracający `0`, wartość ujemną albo nieliczbową** — nie wolno, by literówka w `functions.php` zablokowała wszystkim wysyłkę przy pierwszej próbie. Podłoga `max( 1, ... )`. Test w Tasku 1.
2. **Filtr `evreg_bypass_rate_limit` zwracający `false` przy użytkowniku z capem** — semantyka WP: filtr wygrywa w obie strony, więc zwolnienie da się zdjąć. Zachowanie świadome; test w Tasku 1 pilnuje, że filtr jest naprawdę ostatnim słowem.
3. **Zwolniony użytkownik nie inkrementuje licznika** — anty-cel ze specyfikacji: organizator za wspólnym NAT-em nie może zużywać limitu uczestnikom. Test w Tasku 1 porównuje stan licznika przed i po.
4. **Zły nonce u użytkownika z capem** — zwolnienie z limitu nie może przepuścić zgłoszenia bez nonce'a; wynik musi zostać `spam`. Test w Tasku 1.
5. **Nonce/honeypot/okno 3 s po zmianie** — żadna z tych trzech ścieżek nie może zacząć zwracać `rate_limited`; to ujawniałoby botom mechanizm. Test w Tasku 1 na honeypocie i oknie 3 s przez `process()`.

---

## Struktura plików

- `src/Frontend/SubmitHandler.php` — brama zwolnienia, filtrowany próg, zwrot nowego kodu. Jedyne miejsce zmiany zachowania.
- `src/Frontend/SubmitResult.php` — nowa fabryka `rateLimited()`.
- `src/Frontend/Shortcode.php` — wpis w mapie komunikatów.
- `tests/Integration/Frontend/SubmitRateLimitTest.php` — rozszerzony o zwolnienie, filtry i podłogę.
- `tests/Integration/Frontend/SubmitHandlerTest.php` — rozszerzony o ścieżkę `handle()` pod limitem i nad limitem.
- `languages/event-registration.pot`, `CHANGELOG.md`, `CLAUDE.md`, `docs/superpowers/backlog.md` — Task 2.

---

### Task 1: Brama zwolnienia, filtrowany próg i własny kod blokady

**Files:**
- Modify: `src/Frontend/SubmitHandler.php` (stałe ~25-32, `handle()` ~70-98, `isRateLimited()` ~121-123)
- Modify: `src/Frontend/SubmitResult.php` (fabryki statyczne, obok `spam()`)
- Modify: `src/Frontend/Shortcode.php` (mapa w `resultNotice()`, ~186-193)
- Test: `tests/Integration/Frontend/SubmitRateLimitTest.php`
- Test: `tests/Integration/Frontend/SubmitHandlerTest.php`

**Interfaces:**
- Consumes: `EvReg\Admin\Capabilities::CAP`; istniejące `SubmitHandler::isRateLimited(): bool`, `SubmitHandler::recordAttempt(): void`, `SubmitHandler::resultFor( int ): ?SubmitResult`.
- Produces:
  - `SubmitHandler::RATE_LIMIT` — `public const int` = `30` (wartość domyślna progu).
  - `SubmitHandler::rateLimit(): int` — `public static`, próg po filtrze `evreg_rate_limit`, nie mniej niż 1.
  - `SubmitHandler::bypassesRateLimit(): bool` — `public static`, `current_user_can( Capabilities::CAP )` po filtrze `evreg_bypass_rate_limit`.
  - `SubmitResult::rateLimited(): self` — kod `'rate_limited'`, `success = false`, bez błędów pól i bez wartości.

- [ ] **Step 1: Napisz failujące testy w `SubmitRateLimitTest.php`**

Pięć testów. `$_SERVER['REMOTE_ADDR']` ustawiany w każdym (różne adresy, żeby kubełki się nie mieszały). Filtry zdejmowane przez `remove_all_filters()` w `tearDown()`.

```php
public function test_threshold_defaults_to_thirty(): void {
	$this->assertSame( 30, SubmitHandler::RATE_LIMIT );
	$this->assertSame( 30, SubmitHandler::rateLimit() );
}

public function test_filter_overrides_threshold(): void {
	add_filter( 'evreg_rate_limit', static fn() => 5 );
	$this->assertSame( 5, SubmitHandler::rateLimit() );
}

public function test_filter_cannot_lock_everyone_out(): void {
	add_filter( 'evreg_rate_limit', static fn() => 0 );
	$this->assertSame( 1, SubmitHandler::rateLimit() );

	remove_all_filters( 'evreg_rate_limit' );
	add_filter( 'evreg_rate_limit', static fn() => 'nonsens' );
	$this->assertSame( 1, SubmitHandler::rateLimit() );
}

public function test_anonymous_visitor_is_not_exempt(): void {
	wp_set_current_user( 0 );
	$this->assertFalse( SubmitHandler::bypassesRateLimit() );
}

public function test_event_manager_is_exempt_and_filter_has_last_word(): void {
	wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	$this->assertTrue( SubmitHandler::bypassesRateLimit() );

	add_filter( 'evreg_bypass_rate_limit', static fn() => false );
	$this->assertFalse( SubmitHandler::bypassesRateLimit() );
}
```

Uwaga do ostatniego testu: capy `edit_evreg_events` nadaje administratorowi `Capabilities::maybe_grant()` na `admin_init`, który w teście nie odpala. Zawołaj jawnie `Capabilities::grant()` (publiczne static, `src/Admin/Capabilities.php:52`) przed `wp_set_current_user` i pamiętaj, że capy roli są globalne — po teście nie zostawiaj po sobie stanu, który zakłóciłby `CapabilitiesTest`.

- [ ] **Step 2: Uruchom i potwierdź, że padają**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter SubmitRateLimitTest`
Expected: FAIL — `rateLimit()` i `bypassesRateLimit()` nie istnieją, `RATE_LIMIT` to 10.

Konsola PHPUnit w tym kontenerze zniekształca komunikat niezłapanego `Error`. Jeśli fazy RED nie da się odczytać z podsumowania, zweryfikuj ją jawnym `try/catch` drukującym `get_class($e)`/`getMessage()`.

- [ ] **Step 3: Zaimplementuj próg i bramę w `src/Frontend/SubmitHandler.php`**

- `RATE_LIMIT` z `10` na `30`, docblock mówi, że to wartość domyślna, filtrowalna.
- `public static function rateLimit(): int` — `apply_filters( 'evreg_rate_limit', self::RATE_LIMIT )`, rzutowanie na int, wynik `max( 1, ... )`. Docblock nazywa powód podłogi: filtr zwracający 0 zablokowałby wszystkich przy pierwszej próbie.
- `isRateLimited()` porównuje z `self::rateLimit()`, nie ze stałą.
- `public static function bypassesRateLimit(): bool` — `(bool) apply_filters( 'evreg_bypass_rate_limit', current_user_can( Capabilities::CAP ) )`. Docblock: zwolnienie jest nieosiągalne bez logowania (żadnego nośnika z POST, ciasteczka ani nagłówka), a filtr jest ostatnim słowem w obie strony.
- Import `use EvReg\Admin\Capabilities;`.

- [ ] **Step 4: Uruchom testy z Kroku 1**

Run: jak w Kroku 2.
Expected: PASS (5 testów) — istniejący `test_rate_limit_blocks_after_threshold` dalej zielony, bo iteruje po `RATE_LIMIT`.

- [ ] **Step 5: Napisz failujące testy ścieżki `handle()` w `SubmitHandlerTest.php`**

Cztery testy. `handle()` czyta `$_POST` i na sukcesie robi `wp_safe_redirect` + `exit`, więc **każdy** z tych testów musi kończyć się na ścieżce BEZ przekierowania — nad limitem (bail) albo na błędzie walidacji (brak wymaganych pól). POST składa się z `evreg_submit=1`, `evreg_event=<id>`, `evreg-nonce` z `wp_create_nonce( 'evreg_submit_' . $id )` i `evreg_ts` starszego niż 3 s (`time() - 10`).

```php
public function test_handle_over_limit_reports_rate_limited(): void
// anonim, licznik dobity do rateLimit() przez recordAttempt(), pełny poprawny POST
// → SubmitHandler::resultFor( $this->event_id )->code() === 'rate_limited'

public function test_handle_over_limit_lets_event_manager_through(): void
// użytkownik z capem, licznik dobity, POST BEZ wymaganych pól
// → kod wyniku 'invalid' (nie 'rate_limited') = brama przepuściła do walidacji

public function test_exempt_submission_does_not_increment_counter(): void
// użytkownik z capem, licznik czysty, POST bez wymaganych pól
// → get_transient( <klucz rate dla REMOTE_ADDR> ) nadal pusty/0 po handle()

public function test_bad_nonce_still_blocks_event_manager(): void
// użytkownik z capem, nonce 'bzdura'
// → kod wyniku 'spam', NIE 'rate_limited' i nie przejście dalej
```

Klucz transienta w trzecim teście zbuduj tak samo jak `rateKey()`, czyli `'evreg_rate_' . md5( $_SERVER['REMOTE_ADDR'] )` — `rateKey()` jest prywatny i nie należy tego zmieniać.

- [ ] **Step 6: Uruchom i potwierdź, że padają**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter SubmitHandlerTest`
Expected: FAIL — pierwszy daje `spam` zamiast `rate_limited`, drugi i trzeci wchodzą w limit.

- [ ] **Step 7: Zaimplementuj `SubmitResult::rateLimited()` i wpleć bramę w `handle()`**

- `SubmitResult::rateLimited(): self` obok `spam()`, kod `'rate_limited'`.
- W `handle()`, **po** weryfikacji nonce'a i przed `process()`: gdy `! self::bypassesRateLimit()`, wykonaj sprawdzenie limitu (bail z `SubmitResult::rateLimited()`) i `recordAttempt()`; gdy zwolniony — pomiń oba. Kolejność nonce → brama → limit → `process()` jest wiążąca: nonce chroni każdego.
- Komentarz w kodzie przy bramie: dlaczego zwolniony nie inkrementuje licznika (wspólne IP za NAT-em).

- [ ] **Step 8: Uruchom testy z Kroku 5**

Run: jak w Kroku 6.
Expected: PASS.

- [ ] **Step 9: Dodaj komunikat blokady i test, że pozostałe ścieżki go nie dostają**

- W `Shortcode::resultNotice()` wpis `'rate_limited'` z treścią przez `__()`: zbyt wiele prób z tego urządzenia, odczekaj godzinę i spróbuj ponownie lub skontaktuj się z organizatorem. Wariant alertu zostaje `warning`, jak reszta mapy. Treść `spam` **bez zmian**.
- Test w `SubmitHandlerTest.php`, że honeypot (`evreg_hp` niepusty) i okno 3 s (`evreg_ts` = `time()`) nadal dają przez `process()` kod `'spam'`, nie `'rate_limited'`.

- [ ] **Step 10: Pełna bramka**

Run, po kolei:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- composer analyse
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
```
Expected: wszystko zielone, PHPStan i phpcs bez zgłoszeń.

- [ ] **Step 11: Commit**

```bash
git add src/Frontend/SubmitHandler.php src/Frontend/SubmitResult.php src/Frontend/Shortcode.php tests/Integration/Frontend/SubmitRateLimitTest.php tests/Integration/Frontend/SubmitHandlerTest.php
git commit -m "feat(antispam): exempt event managers from the submission rate limit"
```

---

### Task 2: Katalog i18n, dokumentacja, backlog, paczka

**Files:**
- Modify: `languages/event-registration.pot` (regenerowany, nie edytowany ręcznie)
- Modify: `CHANGELOG.md` (sekcja `## [Unreleased]`)
- Modify: `CLAUDE.md` (sekcja o formularzu publicznym)
- Modify: `docs/superpowers/backlog.md` (dwa nowe wpisy)

**Interfaces:**
- Consumes: nazwy z Tasku 1 — `SubmitHandler::rateLimit()`, `SubmitHandler::bypassesRateLimit()`, filtry `evreg_rate_limit` i `evreg_bypass_rate_limit`, kod wyniku `rate_limited`.
- Produces: nic, czym karmiłby się kolejny task.

- [ ] **Step 1: Zregeneruj katalog i18n**

Run: `npm run i18n:pot`
Expected: w `languages/event-registration.pot` pojawia się msgid nowego komunikatu blokady; **żaden istniejący msgid nie znika**. Sprawdź `git diff --stat languages/` i przejrzyj usunięcia. `languages/event-registration-en_US.po` jest zastanym dryfem i **nie** jest przedmiotem tego zadania.

- [ ] **Step 2: Dopisz wpis do `CHANGELOG.md`**

Pod `## [Unreleased]`, w `### Changed` (utwórz sekcję, jeśli brak), po angielsku, językiem użytkownika końcowego: organizatorzy z uprawnieniem do zarządzania eventami nie wchodzą już w limit antyspamowy formularza; próg podniesiony z 10 do 30 zgłoszeń na godzinę i filtrowalny; kto w limit wejdzie, dostaje komunikat mówiący, że blokada mija po godzinie.

- [ ] **Step 3: Dopisz fakty do `CLAUDE.md`**

W sekcji o formularzu publicznym (3B), przy opisie `SubmitHandler`, trzy rzeczy: (a) próg to `rateLimit()` = `RATE_LIMIT` (30) po filtrze `evreg_rate_limit` z podłogą 1, a nie sama stała; (b) `bypassesRateLimit()` zwalnia posiadacza `Capabilities::CAP` i pomija ZARAZEM sprawdzenie i inkrementację licznika, filtr `evreg_bypass_rate_limit` jest ostatnim słowem; (c) blokada limitu ma własny kod `rate_limited` z własnym komunikatem, a nonce/honeypot/okno 3 s **zostają** na mglistym `spam`, żeby nie ujawniać antyspamu — nie ujednolicać.

- [ ] **Step 4: Dopisz dwa wpisy do `docs/superpowers/backlog.md`**

Ostatni zajęty numer to B31, więc nowe wpisy to **B32** i **B33**, w formacie pozostałych wpisów w tym pliku:
- **B32:** Ekran „Dodaj zgłoszenie" w panelu administracyjnym, żeby organizator nie wpisywał uczestników publicznym formularzem (odrzucone w tej poprawce jako nieproporcjonalne; patrz podejście C w specyfikacji).
- **B33:** `SubmitHandler::rateKey()` czyta `REMOTE_ADDR` bez obsługi zaufanego proxy: za Cloudflare lub nginx proxy bez `mod_remoteip` cały ruch dzieli jeden kubełek, więc limit staje się globalny dla strony. Diagnoza: liczba wierszy `%evreg_rate_%` w `options` na produkcji.

- [ ] **Step 5: Przebuduj paczkę instalacyjną**

Run: `bash scripts/build-zip.sh`
Expected: `event-registration.zip` powstaje bez błędu (plik jest gitignorowany, nie commituj go).

- [ ] **Step 6: Commit**

```bash
git add languages/event-registration.pot CHANGELOG.md CLAUDE.md docs/superpowers/backlog.md
git commit -m "docs: rate-limit exemption facts, changelog and backlog entries"
```
