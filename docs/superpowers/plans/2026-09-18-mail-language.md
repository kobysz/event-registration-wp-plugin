# Mail in the Registration's Language Implementation Plan (B3c-1)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Send participant emails (opt-in / waitlist / confirmed / expired) in the language the registration was submitted in; keep organizer notifications in the base language.

**Architecture:** Store the submission's Polylang language slug in a new `lang` column on the registrations table. `TemplateResolver` resolves a mail template per language — the B3b overlay `_evreg_i18n[lang].mail` first, then the base `_evreg_mail_templates`, then `DefaultTemplates` (per field). `MailQueue::enqueue` takes a `$lang`; `Subscriber` passes the registration's `lang` for participant mails and `''` for the organizer. The mail-templates tab gains a language switcher that edits the overlay for non-default languages. `$lang = ''` everywhere is a safe default — zero regression for existing installs and when Polylang is absent.

**Tech Stack:** PHP 8.1 WordPress plugin; `@wordpress/element`/`@wordpress/components` React admin; jest (admin ops); PHPUnit (wp-env container); MySQL/dbDelta migrations.

**Spec:** `docs/superpowers/specs/2026-09-18-mail-language-design.md`

## Global Constraints

- `$lang = ''` is the default at every new parameter/column; it means "base language" and must preserve today's behavior exactly (no overlay lookup).
- Only mail CONTENT varies by language; `lang` never affects status/type/token/counters/idempotency. The queue's `UNIQUE(registration_id, template_key)` is unchanged (no lang in the key).
- Base mail templates stay in `_evreg_mail_templates` (unchanged). Per-language overrides live in the B3b overlay meta `_evreg_i18n[lang].mail = { <templateKey>: { subject, body } }`. Fallback is per field: overlay → base → `DefaultTemplates`.
- Overlay language key = Polylang language slug (from `EvReg\Frontend\CurrentLanguage::get()`, which returns `pll_current_language('slug')` or `''`, filtered by `evreg_current_language`).
- Organizer notification (`Subscriber::queue_admin`) stays base language (`$lang = ''`).
- `src/Domain/**` untouched. Builder mutation logic in pure jest-tested `assets/admin/ops/*`; components thin. All UI strings via i18n `event-registration`.
- PHP in the wp-env container: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- <cmd>` (phpunit unit / `-c phpunit-integration.xml.dist` / phpcs / `phpstan analyse --memory-limit=1G`; add `--memory-limit=2G` only if phpstan OOMs). JS/jest/build on host via `npm`. Integration suite runs at pl_PL (bootstrap) — Polish base-string assertions are correct.
- phpcs clean, phpstan level 6 "No errors" for new PHP. Every PHP file outside `src/Domain/` starts with `defined( 'ABSPATH' ) || exit;`.
- Config JSON persisted via `wp_slash( wp_json_encode( ... ) )` (wp_unslash trap).
- After a test-requiring change, rebuild `event-registration.zip` via `bash scripts/build-zip.sh` (once, at the end).

---

## Task 1: Migration — `lang` column on registrations

**Files:**
- Modify: `src/Persistence/Migrations.php` (add column to registrations CREATE TABLE; bump `DB_VERSION`)
- Test: `tests/Integration/Persistence/MigrationsLangColumnTest.php`

**Interfaces:**
- Produces: registrations table has `lang varchar(12) NOT NULL DEFAULT ''`.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/Persistence/MigrationsLangColumnTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Persistence;

use EvReg\Persistence\Migrations;
use WP_UnitTestCase;

final class MigrationsLangColumnTest extends WP_UnitTestCase {

	public function test_registrations_table_has_lang_column(): void {
		Migrations::run();
		global $wpdb;
		$table   = Migrations::table( 'registrations' );
		$columns = $wpdb->get_col( "DESC {$table}", 0 ); // phpcs:ignore WordPress.DB
		$this->assertContains( 'lang', $columns );
	}
}
```

(Confirm the public method name that (re)creates tables — the codebase uses `Migrations::run()` / `Migrations::maybe_run()`. Use whichever creates the schema; `run()` per `Uninstaller`/activation. If `table()` is not public, use `$wpdb->prefix . 'evreg_registrations'`.)

- [ ] **Step 2: Run to verify it fails**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter MigrationsLangColumnTest`
Expected: FAIL — no `lang` column (test DB has the pre-existing schema; a fresh `run()` with the old definition won't add it either, so the assertion fails).

- [ ] **Step 3: Add the column + bump the version**

In `src/Persistence/Migrations.php`:
- In the registrations `CREATE TABLE`, add the column right after `name varchar(191) NOT NULL,`:
  ```
  				lang varchar(12) NOT NULL DEFAULT '',
  ```
- Bump `public const DB_VERSION = 3;` to `4`.

(`dbDelta` adds the missing column on installs whose stored `evreg_db_version` is lower than `DB_VERSION`; the bump makes `maybe_run()` re-run `dbDelta`. In the test, `Migrations::run()` runs `dbDelta` directly which adds the column.)

- [ ] **Step 4: Run to verify it passes**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter MigrationsLangColumnTest`
Expected: PASS. Also run `--filter Migrations` / any existing migration test to confirm no regression.

- [ ] **Step 5: phpcs + phpstan**

Run phpcbf/phpcs on `src/Persistence/Migrations.php` and phpstan. Expected clean.

- [ ] **Step 6: Commit**

```bash
git add src/Persistence/Migrations.php tests/Integration/Persistence/MigrationsLangColumnTest.php
git commit -m "feat: add lang column to registrations (db v4)"
```

---

## Task 2: Capture the submission language on reserve

**Files:**
- Modify: `src/Services/ReservationRequest.php` (add `lang`)
- Modify: `src/Frontend/SubmissionAssembler.php` (set `lang` from `CurrentLanguage::get()`)
- Modify: `src/Services/ReservationService.php` (write `lang` into the insert row)
- Modify: `src/Persistence/RegistrationRepository.php` (`insertRegistration` writes `lang`; add `langOf`)
- Test: `tests/Integration/Services/ReserveStoresLangTest.php`

**Interfaces:**
- Consumes: `EvReg\Frontend\CurrentLanguage::get(): string` (from B3b); the `lang` column (Task 1).
- Produces: `RegistrationRepository::langOf( int $registration_id ): string`. `ReservationRequest` carries `public readonly string $lang`.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/Services/ReserveStoresLangTest.php`. Model it on an existing reservation integration test (find one under `tests/Integration/` that calls `SubmitHandler`/reserve or builds a `ReservationRequest`; reuse its event/config setup). The test must: set `add_filter( 'evreg_current_language', fn() => 'en' )`, run a public submission (or call `SubmissionAssembler` + `ReservationService::reserve` the way the existing test does), then assert the inserted registration row's `lang` is `'en'`; and a second case with no filter asserts `''`. Read the existing test first and mirror its submission mechanics exactly; assert via `RegistrationRepository::langOf( $id )` (or `findById($id)['lang']`).

Cleanup: `remove_all_filters( 'evreg_current_language' )` in tearDown.

- [ ] **Step 2: Run to verify it fails**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter ReserveStoresLangTest`
Expected: FAIL — `langOf` undefined / `lang` not persisted (empty).

- [ ] **Step 3: Thread the language through**

- `src/Services/ReservationRequest.php`: add a constructor-promoted `public readonly string $lang = ''` (place it last so existing positional callers still work; read the current constructor first).
- `src/Frontend/SubmissionAssembler.php`: where it constructs `new ReservationRequest( ... )`, pass `lang: \EvReg\Frontend\CurrentLanguage::get()` (named arg, or positional last). Add the `use` if needed. (SubmissionAssembler is shared with admin edit, but only `reserve`/`insertRegistration` persists `lang`; `editAnswers`→`updateRegistration` never writes it, so an admin-edit request carrying a language is harmless.)
- `src/Services/ReservationService.php`: in the `insertRegistration( array( ... ) )` call inside `reserve`, add `'lang' => $request->lang,`.
- `src/Persistence/RegistrationRepository.php`:
  - In `insertRegistration`, add `'lang' => (string) ( $row['lang'] ?? '' ),` to the data array and a matching `'%s'` to the format array.
  - Add:
    ```php
    	/**
    	 * Zwraca slug języka zgłoszenia ('' gdy brak).
    	 *
    	 * @param int $registration_id ID zgłoszenia.
    	 */
    	public function langOf( int $registration_id ): string {
    		$row = $this->findById( $registration_id );
    		return is_array( $row ) ? (string) ( $row['lang'] ?? '' ) : '';
    	}
    ```

- [ ] **Step 4: Run to verify it passes**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter ReserveStoresLangTest`
Expected: PASS. Then run the reservation/submit suites to confirm no regression: `--filter "ReservationService|SubmitHandler|SubmissionAssembler"` → green.

- [ ] **Step 5: phpcs + phpstan** on the four changed PHP files. Clean.

- [ ] **Step 6: Commit**

```bash
git add src/Services/ReservationRequest.php src/Frontend/SubmissionAssembler.php src/Services/ReservationService.php src/Persistence/RegistrationRepository.php tests/Integration/Services/ReserveStoresLangTest.php
git commit -m "feat: store submission language on the registration"
```

---

## Task 3: TemplateResolver resolves per language

**Files:**
- Modify: `src/Mail/TemplateResolver.php` (add `$lang` param + overlay lookup; inject `EventConfigRepository`)
- Modify: any construction sites of `TemplateResolver` that must pass the new dependency (e.g. `src/Mail/Subscriber.php` factory)
- Test: `tests/Integration/Mail/TemplateResolverLangTest.php`

**Interfaces:**
- Consumes: `EventConfigRepository::getI18n( int ): array` (from B3b); `_evreg_i18n[lang]['mail'][key]` shape.
- Produces: `TemplateResolver::resolve( int $event_id, string $template_key, string $lang = '' ): array{subject,body}`.
- Constructor becomes `__construct( MailTemplateRepository $templates, EventConfigRepository $config )`.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/Mail/TemplateResolverLangTest.php`. Read the existing TemplateResolver test (under `tests/Integration/Mail/` or `tests/Unit/`) first to reuse its event/template setup and `DefaultTemplates` keys. The test must cover, for one template key:
- `resolve(event, key, '')` → base override if set else `DefaultTemplates` (parity with current behavior).
- With `EventConfigRepository::saveI18n(event, [ 'en' => [ 'mail' => [ key => [ 'subject' => 'EN subject', 'body' => 'EN body' ] ] ] ])`: `resolve(event, key, 'en')` → EN subject+body.
- Per-field fallback: overlay has only `subject` for `en` (empty/absent `body`) → `resolve(event, key, 'en')` returns EN subject but base/default body.
- Unknown lang (`'de'`, no overlay) → base/default.

Use the real template key constant from `DefaultTemplates::KEY_*` (read the class). Construct the resolver as `new TemplateResolver( new MailTemplateRepository(), new EventConfigRepository() )`.

- [ ] **Step 2: Run to verify it fails**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter TemplateResolverLangTest`
Expected: FAIL — `resolve` has no `$lang` param / constructor arity mismatch.

- [ ] **Step 3: Implement per-language resolution**

In `src/Mail/TemplateResolver.php`:
- Add `EventConfigRepository` to the constructor: `public function __construct( private readonly MailTemplateRepository $templates, private readonly EventConfigRepository $config ) {}` (add the `use EvReg\Persistence\EventConfigRepository;`).
- Change `resolve` to accept `string $lang = ''` and consult the overlay first, per field, before the existing base→default fallback:

```php
	public function resolve( int $event_id, string $template_key, string $lang = '' ): array {
		$key      = self::baseKey( $template_key );
		$default  = DefaultTemplates::get( $key );
		$override = $this->templates->get( $event_id )[ $key ] ?? array();

		$overlay = array();
		if ( '' !== $lang ) {
			$mail = $this->config->getI18n( $event_id )[ $lang ]['mail'][ $key ] ?? array();
			$overlay = is_array( $mail ) ? $mail : array();
		}

		$subject = self::field( $overlay, $override, $default, 'subject' );
		$body    = self::field( $overlay, $override, $default, 'body' );

		return array(
			'subject' => $subject,
			'body'    => $body,
		);
	}

	/**
	 * Wybiera pole z fallbackiem: overlay (język) → override eventu → domyślny.
	 *
	 * @param array<string,mixed> $overlay  Nadpisanie językowe.
	 * @param array<string,mixed> $override Szablon eventu (baza).
	 * @param array<string,mixed> $default  Szablon domyślny.
	 * @param string              $field    'subject' albo 'body'.
	 */
	private static function field( array $overlay, array $override, array $default, string $field ): string {
		if ( isset( $overlay[ $field ] ) && '' !== $overlay[ $field ] ) {
			return (string) $overlay[ $field ];
		}
		if ( isset( $override[ $field ] ) && '' !== $override[ $field ] ) {
			return (string) $override[ $field ];
		}
		return (string) ( $default[ $field ] ?? '' );
	}
```

Update every `new TemplateResolver( ... )` construction to pass `new EventConfigRepository()` as the second arg. Grep for `new TemplateResolver(` (e.g. `src/Mail/Subscriber.php` factory) and fix each.

- [ ] **Step 4: Run to verify it passes**

Run: `... --filter TemplateResolverLangTest` → PASS. Then `--filter "TemplateResolver|MailQueue|Subscriber"` → confirm no regression (existing `resolve` callers still work via the `$lang=''` default).

- [ ] **Step 5: phpcs + phpstan** on the changed files. Clean.

- [ ] **Step 6: Commit**

```bash
git add src/Mail/TemplateResolver.php src/Mail/Subscriber.php tests/Integration/Mail/TemplateResolverLangTest.php
git commit -m "feat: resolve mail templates per language via the i18n overlay"
```

---

## Task 4: enqueue carries the language; Subscriber uses the registration's lang

**Files:**
- Modify: `src/Mail/MailQueue.php` (add `$lang` param to `enqueue`, pass to `resolve`)
- Modify: `src/Mail/Subscriber.php` (participant queue passes `langOf(registration)`, admin passes `''`)
- Test: `tests/Integration/Mail/EnqueueLangTest.php`

**Interfaces:**
- Consumes: `TemplateResolver::resolve(..., $lang)` (Task 3); `RegistrationRepository::langOf` (Task 2).
- Produces: `MailQueue::enqueue( string $template_key, int $event_id, ?int $registration_id, string $recipient, Placeholders $values, array $headers = array(), bool $immediate = false, string $lang = '' ): bool`.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/Mail/EnqueueLangTest.php`. Read the existing MailQueue/Subscriber enqueue test to reuse setup (event, a registration, `Placeholders`, `MailQueueRepository`). It must: create an event with base templates + `saveI18n(event, ['en'=>['mail'=>[<optinKey> => ['subject'=>'EN subject','body'=>'EN body']]]])`, a registration whose `lang='en'`, fire the participant flow (call `Subscriber::on_reserved( $regId, $eventId )` or `MailQueue::enqueue(..., 'en')` directly), and assert the queued row's `subject`/`body` are the EN ones. A second assertion: the organizer/admin mail row (queue_admin) uses the base subject (not EN). Read how the existing test inspects queued rows (`MailQueueRepository`) and mirror it.

- [ ] **Step 2: Run to verify it fails**

Run: `... --filter EnqueueLangTest`
Expected: FAIL — enqueue ignores language / participant row is base, not EN.

- [ ] **Step 3: Thread the language**

- `src/Mail/MailQueue.php`: add `string $lang = ''` as the last param of `enqueue`, and change `$template = $this->templates->resolve( $event_id, $template_key );` to `$template = $this->templates->resolve( $event_id, $template_key, $lang );`. Nothing else changes.
- `src/Mail/Subscriber.php`:
  - In `queue( int $registration_id, int $event_id, string $template_key, bool $immediate )` (participant mails), resolve the language once — `$lang = self::registrations()->langOf( $registration_id );` (use the existing registration-repo accessor the class already has, or add one mirroring `mailQueue()`) — and pass it as the last arg to `enqueue(...)`.
  - In `queue_admin(...)`, call `enqueue(...)` WITHOUT a language (defaults to `''` = base). Do not pass the registration's lang here.
  - Confirm `Subscriber` can read the registration repo; if it already constructs one for other calls reuse it, else add a small private accessor like the existing `mailQueue()`.

- [ ] **Step 4: Run to verify it passes**

Run: `... --filter EnqueueLangTest` → PASS. Then `--filter "MailQueue|Subscriber|Dispatcher"` → no regression.

- [ ] **Step 5: phpcs + phpstan** on the changed files. Clean.

- [ ] **Step 6: Commit**

```bash
git add src/Mail/MailQueue.php src/Mail/Subscriber.php tests/Integration/Mail/EnqueueLangTest.php
git commit -m "feat: enqueue participant mail in the registration's language"
```

---

## Task 5: Mail-template translations in the builder (language switcher)

**Files:**
- Modify: `assets/admin/ops/i18nOps.js` (add mail-bucket get/set)
- Test: `assets/admin/ops/i18nOps.test.js`
- Modify: `assets/admin/tabs/MailTemplatesTab.jsx` (language switcher; route reads/writes)

**Interfaces:**
- Consumes: `window.evregAdmin.languages` / `.defaultLanguage` (from B3b); `config.i18n` overlay + `config.mailTemplates`; existing `saveI18n`/`saveTemplates` in `App.jsx` (unchanged).
- Produces (in `i18nOps.js`, immutable):
  - `getMailTranslation( overlay, lang, templateKey, field ) -> string`
  - `setMailTranslation( overlay, lang, templateKey, field, value ) -> overlay`
  - Overlay bucket: `overlay[lang].mail[templateKey][field]` where `field` ∈ `'subject'|'body'`.

- [ ] **Step 1: Write the failing tests (ops)**

Append to `assets/admin/ops/i18nOps.test.js`:

```js
import { getMailTranslation, setMailTranslation } from './i18nOps';

describe( 'i18nOps mail bucket', () => {
	it( 'setMailTranslation/getMailTranslation dla subject i body', () => {
		let overlay = {};
		overlay = setMailTranslation( overlay, 'en', 'optin', 'subject', 'Confirm your registration' );
		overlay = setMailTranslation( overlay, 'en', 'optin', 'body', 'Click the link.' );
		expect( getMailTranslation( overlay, 'en', 'optin', 'subject' ) ).toBe( 'Confirm your registration' );
		expect( getMailTranslation( overlay, 'en', 'optin', 'body' ) ).toBe( 'Click the link.' );
		expect( overlay.en.mail.optin.subject ).toBe( 'Confirm your registration' );
	} );

	it( 'getMailTranslation zwraca pusty string gdy brak', () => {
		expect( getMailTranslation( {}, 'en', 'optin', 'subject' ) ).toBe( '' );
	} );

	it( 'mail ops nie mutują wejścia', () => {
		const overlay = { en: { mail: { optin: { subject: 'X' } } } };
		const before = JSON.stringify( overlay );
		setMailTranslation( overlay, 'en', 'optin', 'body', 'Y' );
		expect( JSON.stringify( overlay ) ).toBe( before );
	} );
} );
```

(The existing `import { ... } from './i18nOps'` at the top of the file can be extended, or these new named imports added — match the file's current import style.)

- [ ] **Step 2: Run to verify they fail**

Run: `npm run test:js -- i18nOps`
Expected: FAIL — `getMailTranslation`/`setMailTranslation` not exported.

- [ ] **Step 3: Implement the mail-bucket ops**

Add to `assets/admin/ops/i18nOps.js` (reuse the file's `langBucket` helper if present; the bucket here is `mail`, keyed by templateKey then field):

```js
export function getMailTranslation( overlay, lang, templateKey, field ) {
	const mail = ( ( overlay[ lang ] || {} ).mail || {} )[ templateKey ] || {};
	return mail[ field ] ? String( mail[ field ] ) : '';
}

export function setMailTranslation( overlay, lang, templateKey, field, value ) {
	const langEntry = overlay[ lang ] || {};
	const mail = langEntry.mail || {};
	const tpl = mail[ templateKey ] || {};
	return {
		...overlay,
		[ lang ]: {
			...langEntry,
			mail: {
				...mail,
				[ templateKey ]: { ...tpl, [ field ]: value },
			},
		},
	};
}
```

- [ ] **Step 4: Run to verify they pass**

Run: `npm run test:js -- i18nOps`
Expected: PASS (all i18nOps tests).

- [ ] **Step 5: Add the language switcher to MailTemplatesTab**

Read `assets/admin/tabs/MailTemplatesTab.jsx` and `assets/admin/ops/mailTemplateOps.js` first (the tab uses `TEMPLATE_TYPES` and `setField`/defaults; `update('mailTemplates')` is its setter). Then:
- Read `window.evregAdmin.languages` (array of `{value,label}`) + `.defaultLanguage`. Add local state `lang` defaulting to `''` (base). Render a `SelectControl` „Język" with options `[{ value:'', label: <base language label or „Podstawowy"> }, ...languages]` — ONLY when `languages` is non-empty (otherwise no switcher; behaves exactly as today).
- When `lang === ''` (base): each template's subject/body input reads/writes `config.mailTemplates` via the existing `mailTemplateOps.setField` + `update('mailTemplates')`, exactly as now (placeholder = `DefaultTemplates` default, as today).
- When `lang !== ''` (a translation): each subject/body input reads `getMailTranslation( config.i18n||{}, lang, templateKey, field )` and on change calls `update('i18n')( setMailTranslation( config.i18n||{}, lang, templateKey, field, value ) )`. The placeholder shows the base value (the current `config.mailTemplates[templateKey][field]` or the `DefaultTemplates` default) so the translator sees what falls back.
- Keep it thin: all overlay writes go through `setMailTranslation`; no direct config mutation. All new UI strings via `__()`.
- `App.jsx` already saves both `config.mailTemplates` (saveTemplates) and `config.i18n` (saveI18n) on the global Save (from B3b) — no App change needed.

- [ ] **Step 6: Build + jest + verify live**

Run:
```bash
npm run test:js
npm run build
```
Expected: jest green, build succeeds.

Then in wp-env (Polylang absent → no languages): open an event → Szablony maili tab shows NO language switcher and behaves exactly as before (edits base templates, saves). This confirms the graceful default. (The translated-resolution path is covered by Tasks 3–4 integration tests.)

- [ ] **Step 7: Commit**

```bash
git add assets/admin/ops/i18nOps.js assets/admin/ops/i18nOps.test.js assets/admin/tabs/MailTemplatesTab.jsx
git commit -m "feat: per-language mail template editing (language switcher)"
```

---

## Task 6: Full verification + package + backlog

**Files:** Modify `docs/superpowers/backlog.md`.

- [ ] **Step 1: Full suites**

Run:
```bash
npm run test:js
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=1G
npm run test:e2e
```
Expected: all green. If RED, STOP and report BLOCKED with the failing suite.

- [ ] **Step 2: Rebuild the package**

Run: `npm run build && bash scripts/build-zip.sh`
Expected: `event-registration.zip` rebuilt.

- [ ] **Step 3: Mark B3c-1 done in backlog + commit**

In `docs/superpowers/backlog.md` B3 section, add a status line for B3c-1 done (lang column on registrations, TemplateResolver per-language via `_evreg_i18n[lang].mail`, enqueue/Subscriber pass the registration language, MailTemplatesTab language switcher; remaining B3c-2 confirmation link/page language, B3c-3 locale date/currency formats). Commit:

```bash
git add docs/superpowers/backlog.md
git commit -m "docs: mark backlog B3c-1 (mail in the registration's language) done"
```

---

## Self-Review Notes

- **Spec coverage:** migration (Task 1), language capture on reserve (Task 2), per-language resolver (Task 3), enqueue/Subscriber threading (Task 4), builder language switcher (Task 5), verification (Task 6). All spec sections covered.
- **Zero-regression:** `$lang=''` default on `resolve`/`enqueue` and `''` default on the `lang` column preserve today's behavior; existing resolver/enqueue callers and installs are unaffected — asserted by the parity cases in Tasks 3–4 and the no-regression suite runs.
- **Data safety:** `lang` only selects mail content; never touched by status/type/token/counter logic; queue idempotency key unchanged.
- **Type consistency:** overlay mail bucket `overlay[lang].mail[templateKey][field]` is identical in ContentResolver lookup (Task 3, PHP), ops (Task 5, JS), and the enqueue test (Task 4). `TemplateResolver::resolve(event, key, lang='')` and `MailQueue::enqueue(..., lang='')` signatures are used verbatim by their callers. `RegistrationRepository::langOf` (Task 2) is consumed in Task 4.
