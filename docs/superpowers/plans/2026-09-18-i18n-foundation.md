# Plugin i18n Foundation Implementation Plan (B3a)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the plugin's PHP UI strings (public form, emails, PHP admin screens) translate per WordPress locale, and ship an English (en_US) catalog.

**Architecture:** Source strings are Polish (msgid = the Polish literal). Add `Domain Path` + `load_plugin_textdomain` on `init` (new `src/I18n.php`), a `languages/` catalog (`.pot` + `en_US.po`/`.mo`), and WP-CLI-based npm scripts to (re)generate them. Polylang switches the WP locale per language, so this automatically renders the static PHP surface in the visitor's language. No Polylang-specific code in this plan.

**Tech Stack:** PHP 8.1 WordPress plugin; WP-CLI `i18n` (in the wp-env container) for `.pot`/`.mo`; PHPUnit (container) for the translation test; npm on host for scripts.

**Spec:** `docs/superpowers/specs/2026-09-18-i18n-foundation-design.md`

## Global Constraints

- Source language is Polish: **msgid = the Polish literal**. Do NOT change source string literals. en_US `.po` maps Polish msgid → English msgstr.
- Scope is **PHP only**. Exclude `assets/admin` (React admin JS i18n) from `.pot` scanning and from this plan.
- Ship EN only (`event-registration-en_US.po`/`.mo`). No other locales.
- `languages/` is committed to git and shipped in the zip (`build-zip.sh` uses `git archive HEAD`).
- Text domain string is exactly `event-registration`.
- Every PHP file outside `src/Domain/` starts with `defined( 'ABSPATH' ) || exit;`.
- PHP commands run in the wp-env container: `node scripts/wp-env.cjs run <cli|tests-cli> --env-cwd=wp-content/plugins/event-registration -- <cmd>`. `wp` (WP-CLI, has `i18n`) runs on the `cli` container; phpunit/phpcs/phpstan on `tests-cli`. npm runs on host.
- phpcs clean, phpstan level 6 "No errors" for new PHP.
- After a test-requiring change, rebuild `event-registration.zip` via `bash scripts/build-zip.sh` (done once at the end).

---

## Task 1: Textdomain loader

**Files:**
- Create: `src/I18n.php`
- Modify: `event-registration.php` (add `Domain Path` header; register `I18n`)

**Interfaces:**
- Consumes: `EvReg\Plugin::plugin_file()` (existing static returning the main plugin file path).
- Produces: `EvReg\I18n::register(): void` — hooks `load_plugin_textdomain` on `init`.

- [ ] **Step 1: Create the loader class**

Create `src/I18n.php`:

```php
<?php
/**
 * Ładowanie tłumaczeń PHP wtyczki (textdomain).
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg;

defined( 'ABSPATH' ) || exit;

/**
 * Rejestruje ładowanie textdomain event-registration z katalogu languages/.
 */
final class I18n {

	/**
	 * Podpina load_plugin_textdomain na init.
	 */
	public static function register(): void {
		add_action(
			'init',
			static function (): void {
				load_plugin_textdomain(
					'event-registration',
					false,
					dirname( plugin_basename( Plugin::plugin_file() ) ) . '/languages'
				);
			}
		);
	}
}
```

- [ ] **Step 2: Add Domain Path header + register the loader**

In `event-registration.php`:

Add the `Domain Path` line to the plugin header, right after `Text Domain`:

```php
 * Text Domain:       event-registration
 * Domain Path:       /languages
```

Find where other bootstrap classes call `::register()` (e.g. `EventConfigAssets::register()`, `SettingsScreen::register()`) and add alongside them:

```php
\EvReg\I18n::register();
```

- [ ] **Step 3: Lint + static checks**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs src/I18n.php event-registration.php
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=1G
```
Expected: phpcs clean, phpstan "No errors". (Run phpcbf first if phpcs flags formatting.)

- [ ] **Step 4: Commit**

```bash
git add src/I18n.php event-registration.php
git commit -m "feat: load plugin textdomain from languages/ on init"
```

---

## Task 2: Translation catalog (.pot, en_US.po, .mo) + npm scripts

**Files:**
- Modify: `package.json` (add `i18n:pot`, `i18n:mo` scripts)
- Create: `languages/event-registration.pot`
- Create: `languages/event-registration-en_US.po`
- Create: `languages/event-registration-en_US.mo`

**Interfaces:**
- Consumes: WP-CLI `i18n make-pot` / `make-mo` in the `cli` container.
- Produces: the `languages/` catalog; two npm scripts.

- [ ] **Step 1: Add npm scripts**

In `package.json` `"scripts"`, add:

```json
"i18n:pot": "node scripts/wp-env.cjs run cli --env-cwd=wp-content/plugins/event-registration -- wp i18n make-pot . languages/event-registration.pot --slug=event-registration --exclude=assets/admin,build,node_modules,tests,vendor,docs,scripts,.superpowers",
"i18n:mo": "node scripts/wp-env.cjs run cli --env-cwd=wp-content/plugins/event-registration -- wp i18n make-mo languages languages"
```

- [ ] **Step 2: Generate the .pot**

Run: `npm run i18n:pot`
Expected: `languages/event-registration.pot` created, containing `msgid` entries that are the Polish source literals (e.g. `msgid "Wyślij zgłoszenie"`). If the `languages/` dir does not exist, create it first (`mkdir languages`) and re-run.

- [ ] **Step 3: Create en_US.po with English translations**

Create `languages/event-registration-en_US.po` by copying the `.pot` and setting the PO header `Language: en_US` and `Content-Type: text/plain; charset=UTF-8`, then filling EVERY `msgstr` with a faithful English translation of the Polish `msgid`. Rules:
- Translate meaning, not word-for-word; keep it natural UI English.
- Preserve every placeholder EXACTLY (`%s`, `%d`, `%1$s`, `%2$s`, HTML like `<code>`, `„…"` quotes may become `"…"`).
- Never leave a `msgstr ""` (empty falls back to the Polish msgid).
- Keep the `event-registration` domain.

Anchor translations (use these EXACT strings so the Task 3 test is deterministic):
- `msgid "Wyślij zgłoszenie"` → `msgstr "Submit registration"`
- `msgid "Bez noclegu"` → `msgstr "No accommodation"`

Translate all remaining msgids in the same spirit (submit/notice/validation/admin-screen strings). Examples of the kind of strings present: „Dziękujemy! Sprawdź e-mail i potwierdź zgłoszenie.", „Jesteś już zapisany na to wydarzenie.", „Nieprawidłowy adres e-mail.", „Zgłoszenie potwierdzone. Do zobaczenia!", „Ustawienia", „Zgłoszenia", „Kolejka maili" — render each as natural English.

- [ ] **Step 4: Compile the .mo**

Run: `npm run i18n:mo`
Expected: `languages/event-registration-en_US.mo` created from the `.po`.

- [ ] **Step 5: Commit**

```bash
git add package.json languages/event-registration.pot languages/event-registration-en_US.po languages/event-registration-en_US.mo
git commit -m "feat: add English (en_US) translation catalog + i18n build scripts"
```

---

## Task 3: Integration test — string translates at en_US locale

**Files:**
- Create: `tests/Integration/I18nTest.php`

**Interfaces:**
- Consumes: the loader (Task 1) and the `en_US.mo` (Task 2).
- Produces: proof that `__( …, 'event-registration' )` returns English under en_US.

- [ ] **Step 1: Write the test**

Create `tests/Integration/I18nTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration;

use EvReg\Plugin;
use WP_UnitTestCase;

final class I18nTest extends WP_UnitTestCase {

	protected function tearDown(): void {
		unload_textdomain( 'event-registration' );
		restore_current_locale();
		parent::tearDown();
	}

	public function test_php_strings_translate_to_english_under_en_us(): void {
		switch_to_locale( 'en_US' );
		unload_textdomain( 'event-registration' );
		load_plugin_textdomain(
			'event-registration',
			false,
			dirname( plugin_basename( Plugin::plugin_file() ) ) . '/languages'
		);

		$this->assertSame(
			'Submit registration',
			__( 'Wyślij zgłoszenie', 'event-registration' )
		);
		$this->assertSame(
			'No accommodation',
			__( 'Bez noclegu', 'event-registration' )
		);
	}

	public function test_polish_source_returned_without_catalog(): void {
		// Locale default in tests is en_US; without our textdomain loaded the
		// msgid (Polish source) is returned verbatim — proves the assertion above
		// is really exercising the loaded .mo, not a coincidence.
		unload_textdomain( 'event-registration' );
		$this->assertSame(
			'Wyślij zgłoszenie',
			__( 'Wyślij zgłoszenie', 'event-registration' )
		);
	}
}
```

- [ ] **Step 2: Run it — verify it passes**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter I18nTest`
Expected: PASS. If `test_php_strings_translate_to_english_under_en_us` FAILS with the Polish string returned, the `.mo` is not being found — check: the `.mo` exists at `languages/event-registration-en_US.mo`, the msgstr values match the anchors EXACTLY, and the path passed to `load_plugin_textdomain` resolves (it is relative to `WP_PLUGIN_DIR`). Fix the catalog/loader (NOT the test) and re-run.

- [ ] **Step 3: Verify the RED direction is real**

Confirm the test is meaningful: `test_polish_source_returned_without_catalog` must pass (Polish returned when unloaded), and the first test must pass (English when loaded). Together they prove the catalog is actually consulted.

- [ ] **Step 4: Commit**

```bash
git add tests/Integration/I18nTest.php
git commit -m "test: PHP strings translate to English under en_US locale"
```

---

## Task 4: Packaging — ship languages/ in the zip

**Files:**
- Modify: `.distignore` (only if it excludes `languages/`)

**Interfaces:**
- Consumes: `scripts/build-zip.sh`, `.distignore`.
- Produces: a package that contains `languages/event-registration-en_US.mo`.

- [ ] **Step 1: Inspect .distignore**

Read `.distignore`. Confirm it does NOT exclude `languages/`, `*.po`, `*.mo`, or `*.pot`. If any such exclusion exists, remove it or add a negation so `languages/` ships (runtime needs at least the `.mo`). If `.distignore` is already clean for `languages/`, make no change and skip the commit in Step 3.

- [ ] **Step 2: Build the package and verify contents**

Run:
```bash
npm run build && bash scripts/build-zip.sh
python -c "import zipfile; z=zipfile.ZipFile('event-registration.zip'); n=z.namelist(); print('mo shipped:', 'event-registration/languages/event-registration-en_US.mo' in n)"
```
Expected: `mo shipped: True`. If False, fix `.distignore` (Step 1) and rebuild.

- [ ] **Step 3: Commit (only if .distignore changed)**

```bash
git add .distignore
git commit -m "build: ship languages/ in the plugin package"
```

---

## Task 5: Docs + full verification + package

**Files:**
- Modify: `CLAUDE.md` (add i18n commands), `docs/superpowers/backlog.md` (note B3a done)

- [ ] **Step 1: Document the i18n workflow**

In `CLAUDE.md`, under the commands section, add a short block:

```markdown
# i18n (host → wrapper → WP-CLI w kontenerze)
npm run i18n:pot        # regeneruj languages/event-registration.pot ze stringów PHP
npm run i18n:mo         # kompiluj languages/*.po → .mo
# Źródło stringów = polski (msgid = polski literał). Tłumaczenia: languages/event-registration-<locale>.po
```

- [ ] **Step 2: Mark B3a done in backlog**

In `docs/superpowers/backlog.md`, in the B3 section, add a line noting: "B3a (fundament i18n stringów PHP) — ZROBIONE (2026-09-18): Domain Path + load_plugin_textdomain, languages/ z en_US, scripty i18n:pot/i18n:mo. Pozostaje B3b (overlay treści per event) i B3c (mail/link/locale)."

- [ ] **Step 3: Full suites**

Run:
```bash
npm run test:js
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=1G
```
Expected: all green. (e2e unaffected by this plan; run `npm run test:e2e` if you want the full sweep.)

- [ ] **Step 4: Rebuild the package**

Run: `npm run build && bash scripts/build-zip.sh`
Expected: `event-registration.zip` rebuilt with `languages/` inside.

- [ ] **Step 5: Commit docs**

```bash
git add CLAUDE.md docs/superpowers/backlog.md
git commit -m "docs: i18n commands and B3a backlog status"
```

---

## Self-Review Notes

- **Spec coverage:** loader + Domain Path (Task 1), catalog + pipeline scripts (Task 2), translation test (Task 3), packaging (Task 4), docs + verification (Task 5). All spec sections covered.
- **TDD note:** i18n is infra+data; the meaningful test (Task 3) needs both loader and catalog. The paired tests (English-when-loaded / Polish-when-unloaded) make the assertion honest — the second fails if the first is a coincidence.
- **Placeholder scan:** anchor translations are concrete ("Submit registration", "No accommodation"); the remaining msgstr are content the implementer writes during Task 2 per the stated rules — not a plan placeholder.
- **Type consistency:** `EvReg\I18n::register()` (Task 1) is the only new symbol; referenced only from the bootstrap. Domain string `event-registration` and locale `en_US` are identical across all tasks.
