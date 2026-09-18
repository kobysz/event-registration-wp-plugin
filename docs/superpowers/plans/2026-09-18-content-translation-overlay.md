# Per-Event Content Translation Overlay Implementation Plan (B3b)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the builder translate a single event's content (field labels, section titles, type names, choice-option labels) into Polylang languages, and render the public form in the visitor's language.

**Architecture:** A per-event JSON meta `_evreg_i18n` holds `{ lang: { sections, fields, types, options } }` overrides. A pure domain resolver `ContentTranslator` applies the current language's overrides onto the raw config before `SchemaAssembler`. `EventFormLoader` reads the overlay + current Polylang language (via a filterable `CurrentLanguage` adapter) and applies the resolver. A dedicated REST controller saves/loads the overlay; a new React `TranslationsTab` edits it via pure jest-tested ops. Fallback to base whenever an override is missing/empty. Keys/values (identifiers) are never translated — only labels — so submissions and counters stay language-agnostic.

**Tech Stack:** PHP 8.1 WordPress plugin; `@wordpress/element`/`@wordpress/components` React admin; jest (admin ops); PHPUnit (wp-env container); WP REST API.

**Spec:** `docs/superpowers/specs/2026-09-18-content-translation-overlay-design.md`

## Global Constraints

- `src/Domain/**` = zero WordPress, zero `$wpdb`. `ContentTranslator` is pure PHP.
- Builder mutation logic in pure `assets/admin/ops/*`, jest-tested, immutable (never mutate input).
- Only labels are translated; identifiers (`section.key`, `field.key`, `type.key`, `option.value`) are NEVER changed. Missing/empty override → fall back to the base string.
- Overlay top-level key = Polylang language **slug** (e.g. `en`), not locale. The default language has no entry (base = `_evreg_schema`/`_evreg_types`).
- All new UI strings via i18n, text domain `event-registration` (`__()` in JS).
- Config-shape facts (verbatim): `schema = { version, sections: [ { key, title, description, condition, fields: [ { key, type, label, required, options?: [ { value, label } ], condition? } ] } ] }`; `types = [ { key, label, price, capacity, active } ]`. `EventConfigRepository::get(int)` returns `[ 'schema'=>array, 'types'=>array, 'accommodation'=>array, 'settings'=>array ]` (JSON-decoded).
- PHP commands run in the wp-env container: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- <cmd>` (phpunit/phpcs/phpstan). JS/jest/build on host via `npm`. Integration PHPUnit config: `-c phpunit-integration.xml.dist`; unit: `--testsuite unit`. The integration suite runs at locale pl_PL (bootstrap) — assertions on Polish base strings are correct.
- phpcs clean, phpstan level 6 "No errors" for new PHP. Every PHP file outside `src/Domain/` starts with `defined( 'ABSPATH' ) || exit;`.
- Repos that persist config JSON wrap it in `wp_slash( wp_json_encode( ... ) )` (the `wp_unslash` trap). Mirror `MailTemplateRepository`.
- After a test-requiring change, rebuild `event-registration.zip` via `bash scripts/build-zip.sh` (once, at the end).

---

## Task 1: Domain resolver — ContentTranslator

**Files:**
- Create: `src/Domain/Schema/ContentTranslator.php`
- Test: `tests/Unit/Domain/Schema/ContentTranslatorTest.php`

**Interfaces:**
- Produces: `EvReg\Domain\Schema\ContentTranslator::apply( array $schema, array $types, array $overlay, string $lang ): array` → returns `[ $schema, $types ]` with label overrides applied; input not mutated.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Domain/Schema/ContentTranslatorTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Schema;

use EvReg\Domain\Schema\ContentTranslator;
use PHPUnit\Framework\TestCase;

final class ContentTranslatorTest extends TestCase {

	private function schema(): array {
		return array(
			'version'  => 1,
			'sections' => array(
				array(
					'key'    => 'dane',
					'title'  => 'Dane',
					'fields' => array(
						array( 'key' => 'imie', 'type' => 'text', 'label' => 'Imię' ),
						array(
							'key'     => 'rozmiar',
							'type'    => 'select',
							'label'   => 'Rozmiar',
							'options' => array(
								array( 'value' => 's', 'label' => 'Mały' ),
								array( 'value' => 'l', 'label' => 'Duży' ),
							),
						),
					),
				),
			),
		);
	}

	private function types(): array {
		return array(
			array( 'key' => 'pacjent', 'label' => 'Pacjent' ),
			array( 'key' => 'prelegent', 'label' => 'Prelegent' ),
		);
	}

	public function test_applies_overrides_for_language(): void {
		$overlay = array(
			'en' => array(
				'sections' => array( 'dane' => 'Details' ),
				'fields'   => array( 'imie' => 'First name' ),
				'types'    => array( 'pacjent' => 'Patient' ),
				'options'  => array( 'rozmiar' => array( 's' => 'Small' ) ),
			),
		);

		[ $schema, $types ] = ( new ContentTranslator() )->apply( $this->schema(), $this->types(), $overlay, 'en' );

		$this->assertSame( 'Details', $schema['sections'][0]['title'] );
		$this->assertSame( 'First name', $schema['sections'][0]['fields'][0]['label'] );
		$this->assertSame( 'Small', $schema['sections'][0]['fields'][1]['options'][0]['label'] );
		$this->assertSame( 'Duży', $schema['sections'][0]['fields'][1]['options'][1]['label'] ); // brak override → baza
		$this->assertSame( 'Patient', $types[0]['label'] );
		$this->assertSame( 'Prelegent', $types[1]['label'] ); // brak override → baza
	}

	public function test_missing_or_empty_override_falls_back_to_base(): void {
		$overlay = array( 'en' => array( 'fields' => array( 'imie' => '' ) ) );

		[ $schema ] = ( new ContentTranslator() )->apply( $this->schema(), $this->types(), $overlay, 'en' );

		$this->assertSame( 'Imię', $schema['sections'][0]['fields'][0]['label'] );
	}

	public function test_empty_lang_or_unknown_returns_base(): void {
		$overlay = array( 'en' => array( 'fields' => array( 'imie' => 'First name' ) ) );

		[ $schema1 ] = ( new ContentTranslator() )->apply( $this->schema(), $this->types(), $overlay, '' );
		[ $schema2 ] = ( new ContentTranslator() )->apply( $this->schema(), $this->types(), $overlay, 'de' );

		$this->assertSame( 'Imię', $schema1['sections'][0]['fields'][0]['label'] );
		$this->assertSame( 'Imię', $schema2['sections'][0]['fields'][0]['label'] );
	}

	public function test_does_not_mutate_input(): void {
		$schema  = $this->schema();
		$types   = $this->types();
		$overlay = array( 'en' => array( 'fields' => array( 'imie' => 'First name' ) ) );
		$before_schema = wp_json_encode( $schema );
		$before_types  = wp_json_encode( $types );

		( new ContentTranslator() )->apply( $schema, $types, $overlay, 'en' );

		$this->assertSame( $before_schema, wp_json_encode( $schema ) );
		$this->assertSame( $before_types, wp_json_encode( $types ) );
	}
}
```

Note: `wp_json_encode` is available in the unit suite bootstrap (used across existing unit tests). If not, use `json_encode`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit --filter ContentTranslatorTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write minimal implementation**

Create `src/Domain/Schema/ContentTranslator.php`:

```php
<?php
/**
 * Nakłada tłumaczenia treści (labele) na surowy config przed asemblacją.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Schema;

/**
 * Podmienia etykiety sekcji/pól/opcji/typów wg overlay dla danego języka.
 * Czysta domena: brak WordPressa. Nie mutuje wejścia.
 */
final class ContentTranslator {

	/**
	 * Zwraca [schema, types] z nałożonymi tłumaczeniami dla języka $lang.
	 * Brak języka / brak lub pusty override → wartość bazowa.
	 *
	 * @param array<string,mixed>       $schema  Surowa schema.
	 * @param array<int,array<string,mixed>> $types Surowe typy.
	 * @param array<string,mixed>       $overlay Mapa lang → nadpisania.
	 * @param string                    $lang    Slug języka (pusty = baza).
	 *
	 * @return array{0:array<string,mixed>,1:array<int,array<string,mixed>>}
	 */
	public function apply( array $schema, array $types, array $overlay, string $lang ): array {
		if ( '' === $lang || ! isset( $overlay[ $lang ] ) || ! is_array( $overlay[ $lang ] ) ) {
			return array( $schema, $types );
		}

		$over     = $overlay[ $lang ];
		$sections = isset( $over['sections'] ) && is_array( $over['sections'] ) ? $over['sections'] : array();
		$fields   = isset( $over['fields'] ) && is_array( $over['fields'] ) ? $over['fields'] : array();
		$options  = isset( $over['options'] ) && is_array( $over['options'] ) ? $over['options'] : array();
		$type_map = isset( $over['types'] ) && is_array( $over['types'] ) ? $over['types'] : array();

		foreach ( $schema['sections'] ?? array() as $si => $section ) {
			$section_key = (string) ( $section['key'] ?? '' );
			$schema['sections'][ $si ]['title'] = self::pick( $sections, $section_key, (string) ( $section['title'] ?? '' ) );

			foreach ( $section['fields'] ?? array() as $fi => $field ) {
				$field_key = (string) ( $field['key'] ?? '' );
				$schema['sections'][ $si ]['fields'][ $fi ]['label'] = self::pick( $fields, $field_key, (string) ( $field['label'] ?? '' ) );

				$field_opts = isset( $options[ $field_key ] ) && is_array( $options[ $field_key ] ) ? $options[ $field_key ] : array();
				foreach ( $field['options'] ?? array() as $oi => $option ) {
					$value = (string) ( $option['value'] ?? '' );
					$schema['sections'][ $si ]['fields'][ $fi ]['options'][ $oi ]['label'] = self::pick( $field_opts, $value, (string) ( $option['label'] ?? '' ) );
				}
			}
		}

		foreach ( $types as $ti => $type ) {
			$type_key         = (string) ( $type['key'] ?? '' );
			$types[ $ti ]['label'] = self::pick( $type_map, $type_key, (string) ( $type['label'] ?? '' ) );
		}

		return array( $schema, $types );
	}

	/**
	 * Zwraca niepuste tłumaczenie z mapy pod kluczem, inaczej wartość bazową.
	 *
	 * @param array<string,mixed> $map  Mapa tłumaczeń.
	 * @param string              $key  Klucz.
	 * @param string              $base Wartość bazowa (fallback).
	 */
	private static function pick( array $map, string $key, string $base ): string {
		$value = isset( $map[ $key ] ) ? (string) $map[ $key ] : '';
		return '' !== $value ? $value : $base;
	}
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit --filter ContentTranslatorTest`
Expected: PASS.

- [ ] **Step 5: phpcs + phpstan**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcbf src/Domain/Schema/ContentTranslator.php
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs src/Domain/Schema/ContentTranslator.php
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=1G
```
Expected: phpcs clean, phpstan "No errors".

- [ ] **Step 6: Commit**

```bash
git add src/Domain/Schema/ContentTranslator.php tests/Unit/Domain/Schema/ContentTranslatorTest.php
git commit -m "feat: ContentTranslator applies per-language label overrides"
```

---

## Task 2: Overlay persistence + current-language adapter + EventFormLoader integration

**Files:**
- Modify: `src/Persistence/EventConfigRepository.php` (add `getI18n`/`saveI18n`)
- Create: `src/Frontend/CurrentLanguage.php`
- Modify: `src/Frontend/EventFormLoader.php` (apply overlay before assemble)
- Test: `tests/Integration/Frontend/EventFormLoaderI18nTest.php`

**Interfaces:**
- Consumes: `ContentTranslator::apply` (Task 1); `EventConfigRepository::get`.
- Produces:
  - `EventConfigRepository::getI18n( int $event_id ): array` (decoded `_evreg_i18n` or `[]`).
  - `EventConfigRepository::saveI18n( int $event_id, array $overlay ): void` (stores `wp_slash( wp_json_encode( $overlay ) )`).
  - `EvReg\Frontend\CurrentLanguage::get(): string` — `pll_current_language('slug')` if available else `''`, run through `apply_filters( 'evreg_current_language', $lang )`.

- [ ] **Step 1: Write the failing integration test**

Create `tests/Integration/Frontend/EventFormLoaderI18nTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Frontend;

use EvReg\Frontend\EventFormLoader;
use EvReg\Persistence\EventConfigRepository;
use WP_UnitTestCase;

final class EventFormLoaderI18nTest extends WP_UnitTestCase {

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		$this->event_id = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
		$repo           = new EventConfigRepository();
		$repo->save(
			$this->event_id,
			array(
				'schema' => array(
					'version'  => 1,
					'sections' => array(
						array(
							'key'    => 'dane',
							'title'  => 'Dane',
							'fields' => array(
								array( 'key' => '__type', 'type' => 'radio', 'label' => 'Typ' ),
								array( 'key' => 'imie', 'type' => 'text', 'label' => 'Imię', 'required' => true ),
							),
						),
					),
				),
				'types'         => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 0.0 ) ),
				'accommodation' => array( 'packages' => array(), 'rooms' => array(), 'inventory' => array() ),
			)
		);
		$repo->saveI18n( $this->event_id, array( 'en' => array( 'fields' => array( 'imie' => 'First name' ) ) ) );
	}

	protected function tearDown(): void {
		remove_all_filters( 'evreg_current_language' );
		parent::tearDown();
	}

	public function test_base_language_renders_polish(): void {
		$schema = ( new EventFormLoader( new EventConfigRepository() ) )->load( $this->event_id );
		$label  = $this->labelOf( $schema, 'imie' );
		$this->assertSame( 'Imię', $label );
	}

	public function test_current_language_en_applies_overlay(): void {
		add_filter( 'evreg_current_language', static fn (): string => 'en' );
		$schema = ( new EventFormLoader( new EventConfigRepository() ) )->load( $this->event_id );
		$label  = $this->labelOf( $schema, 'imie' );
		$this->assertSame( 'First name', $label );
	}

	private function labelOf( $schema, string $key ): string {
		foreach ( $schema->sections() as $section ) {
			foreach ( $section->fields as $field ) {
				if ( $field->key === $key ) {
					return $field->label;
				}
			}
		}
		return '';
	}
}
```

(Confirm the `FormSchema`/`Section`/`Field` accessors used — `->sections()`, `->fields`, `->key`, `->label` — against `src/Domain/Schema/`. They are used identically in `FormRendererTest`/`RegistrationEditFormTest`; reuse whatever those tests use if a name differs.)

- [ ] **Step 2: Run to verify it fails**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter EventFormLoaderI18nTest`
Expected: FAIL — `saveI18n` undefined (and, once that exists, the `en` test fails because the overlay isn't applied yet).

- [ ] **Step 3: Implement persistence + adapter + integration**

In `src/Persistence/EventConfigRepository.php`, add (mirror the existing `get`/`save` JSON handling and `MailTemplateRepository` slashing — read those first):

```php
	/**
	 * Zwraca overlay tłumaczeń treści (_evreg_i18n) lub pustą tablicę.
	 *
	 * @param int $event_id ID eventu.
	 *
	 * @return array<string,mixed>
	 */
	public function getI18n( int $event_id ): array {
		$raw = get_post_meta( $event_id, '_evreg_i18n', true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Zapisuje overlay tłumaczeń treści.
	 *
	 * @param int                 $event_id ID eventu.
	 * @param array<string,mixed> $overlay  Mapa lang → nadpisania.
	 */
	public function saveI18n( int $event_id, array $overlay ): void {
		update_post_meta( $event_id, '_evreg_i18n', wp_slash( (string) wp_json_encode( $overlay ) ) );
	}
```

Create `src/Frontend/CurrentLanguage.php`:

```php
<?php
/**
 * Bieżący język treści (Polylang lub filtr).
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Zwraca slug bieżącego języka: Polylang, z możliwością nadpisania filtrem.
 */
final class CurrentLanguage {

	/**
	 * Slug bieżącego języka; '' gdy brak (→ treść bazowa).
	 */
	public static function get(): string {
		$lang = function_exists( 'pll_current_language' ) ? (string) pll_current_language( 'slug' ) : '';
		return (string) apply_filters( 'evreg_current_language', $lang );
	}
}
```

In `src/Frontend/EventFormLoader.php::load()`, apply the overlay before assembling. Add the `use` for `ContentTranslator`, and change the assemble block:

```php
		$config = $this->config->get( $event_id );
		$schema = is_array( $config['schema'] ) ? $config['schema'] : array();

		if ( array() === $schema || empty( $schema['sections'] ) ) {
			return null;
		}

		$types = is_array( $config['types'] ) ? $config['types'] : array();
		[ $schema, $types ] = ( new ContentTranslator() )->apply(
			$schema,
			$types,
			$this->config->getI18n( $event_id ),
			CurrentLanguage::get()
		);

		try {
			return $this->assembler->assemble(
				$schema,
				$types,
				is_array( $config['accommodation'] ) ? $config['accommodation'] : array()
			);
		} catch ( SchemaException $e ) {
			return null;
		}
```

Add `use EvReg\Domain\Schema\ContentTranslator;`.

- [ ] **Step 4: Run to verify it passes**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter EventFormLoaderI18nTest`
Expected: PASS (both tests). Then run the full `EventFormLoader`-adjacent suites to ensure no regression: `--filter "EventFormLoader|FormRendererTest|ShortcodeTest"` → all green.

- [ ] **Step 5: phpcs + phpstan**

Run phpcbf/phpcs on the three PHP files and phpstan (as in Task 1 Step 5). Expected clean.

- [ ] **Step 6: Commit**

```bash
git add src/Persistence/EventConfigRepository.php src/Frontend/CurrentLanguage.php src/Frontend/EventFormLoader.php tests/Integration/Frontend/EventFormLoaderI18nTest.php
git commit -m "feat: apply content-translation overlay for the current language on load"
```

---

## Task 3: REST controller for the overlay

**Files:**
- Create: `src/Rest/I18nController.php`
- Modify: `event-registration.php` (register the controller)
- Test: `tests/Integration/Rest/I18nControllerTest.php`

**Interfaces:**
- Consumes: `EventConfigRepository::getI18n`/`saveI18n` (Task 2).
- Produces: routes `GET`/`POST /evreg/v1/events/(?P<id>\d+)/i18n`; cap `edit_evreg_events`; sanitizes label strings; returns the stored overlay.

- [ ] **Step 1: Read the reference controller**

Read `src/Rest/MailTemplateController.php` fully — mirror its structure (namespace, `register()` on `rest_api_init`, route registration, permission callback, GET/POST handlers, JSON body handling). The `I18nController` is the same shape with `_evreg_i18n` and a nested-string sanitizer.

- [ ] **Step 2: Write the failing round-trip test**

Create `tests/Integration/Rest/I18nControllerTest.php` modeled on the existing `MailTemplateController` test (find it under `tests/Integration/Rest/` and mirror its request dispatch via `rest_do_request`/`WP_REST_Request`, admin user with cap, event post). It must:
- POST `{ "en": { "fields": { "imie": "First name" } } }` to `/evreg/v1/events/<id>/i18n` as an admin → 200.
- GET the same route → body contains the saved overlay (`en.fields.imie === "First name"`).
- Assert a non-privileged user gets 403 (mirror the mail-template test's permission assertion).

Use the exact request/dispatch helpers the mail-template test uses.

- [ ] **Step 3: Run to verify it fails**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter I18nControllerTest`
Expected: FAIL (route not registered / 404).

- [ ] **Step 4: Implement the controller + register it**

Create `src/Rest/I18nController.php` mirroring `MailTemplateController`, with:
- Route base `events/(?P<id>\d+)/i18n`, namespace `evreg/v1`.
- `permission_callback` requiring `current_user_can( 'edit_evreg_events' )` (match the exact cap/check the mail-template controller uses).
- GET → `return rest_ensure_response( ( new EventConfigRepository() )->getI18n( $id ) );`
- POST → read JSON params (the overlay), sanitize recursively (every leaf via `sanitize_text_field`; keys kept as-is but cast to string), `saveI18n`, return the saved overlay.
- A private recursive sanitizer:

```php
	/**
	 * Sanityzuje zagnieżdżoną mapę tłumaczeń: liście przez sanitize_text_field.
	 *
	 * @param mixed $value Surowa wartość z żądania.
	 *
	 * @return array<string,mixed>|string
	 */
	private function sanitize( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$out[ (string) $k ] = $this->sanitize( $v );
			}
			return $out;
		}
		return sanitize_text_field( (string) $value );
	}
```

Register in `event-registration.php` next to the other REST controllers:

```php
add_action( 'plugins_loaded', array( \EvReg\Rest\I18nController::class, 'register' ) );
```

(Match how `MailTemplateController` is registered — same hook/pattern.)

- [ ] **Step 5: Run to verify it passes**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter I18nControllerTest`
Expected: PASS. phpcbf/phpcs on `src/Rest/I18nController.php` + `event-registration.php`, phpstan — clean.

- [ ] **Step 6: Commit**

```bash
git add src/Rest/I18nController.php event-registration.php tests/Integration/Rest/I18nControllerTest.php
git commit -m "feat: REST controller to load/save the content-translation overlay"
```

---

## Task 4: Builder ops — i18nOps

**Files:**
- Create: `assets/admin/ops/i18nOps.js`
- Test: `assets/admin/ops/i18nOps.test.js`

**Interfaces:**
- Produces (all immutable, never mutate input):
  - `translatableItems( schema, types ) -> Array<{ kind:'section'|'field'|'type'|'option', key, base, fieldKey?, optionValue? }>` — enumerate every translatable string in reading order (each section title; then that section's field labels; each choice field's option labels; then each type label). Includes the `__type` field as a `field` (its label is translatable); does NOT enumerate `__type` options (they come from types).
  - `getTranslation( overlay, lang, kind, key ) -> string` and `getOptionTranslation( overlay, lang, fieldKey, optionValue ) -> string` — read current value (or `''`).
  - `setTranslation( overlay, lang, kind, key, value ) -> overlay` (kind ∈ section|field|type) and `setOptionTranslation( overlay, lang, fieldKey, optionValue, value ) -> overlay`.
  - Overlay shape: `{ [lang]: { sections:{}, fields:{}, types:{}, options:{ [fieldKey]:{} } } }`.

- [ ] **Step 1: Write the failing tests**

Create `assets/admin/ops/i18nOps.test.js`:

```js
import {
	translatableItems,
	getTranslation,
	getOptionTranslation,
	setTranslation,
	setOptionTranslation,
} from './i18nOps';

const schema = {
	version: 1,
	sections: [
		{
			key: 'dane',
			title: 'Dane',
			fields: [
				{ key: '__type', type: 'radio', label: 'Typ zgłoszenia' },
				{ key: 'imie', type: 'text', label: 'Imię' },
				{ key: 'rozmiar', type: 'select', label: 'Rozmiar', options: [ { value: 's', label: 'Mały' } ] },
			],
		},
	],
};
const types = [ { key: 'pacjent', label: 'Pacjent' } ];

describe( 'i18nOps', () => {
	it( 'translatableItems enumeruje sekcje, pola, opcje i typy', () => {
		const items = translatableItems( schema, types );
		expect( items ).toEqual( [
			{ kind: 'section', key: 'dane', base: 'Dane' },
			{ kind: 'field', key: '__type', base: 'Typ zgłoszenia' },
			{ kind: 'field', key: 'imie', base: 'Imię' },
			{ kind: 'field', key: 'rozmiar', base: 'Rozmiar' },
			{ kind: 'option', key: 'rozmiar:s', base: 'Mały', fieldKey: 'rozmiar', optionValue: 's' },
			{ kind: 'type', key: 'pacjent', base: 'Pacjent' },
		] );
	} );

	it( 'setTranslation/getTranslation dla pola', () => {
		let overlay = {};
		overlay = setTranslation( overlay, 'en', 'field', 'imie', 'First name' );
		expect( getTranslation( overlay, 'en', 'field', 'imie' ) ).toBe( 'First name' );
		expect( overlay.en.fields.imie ).toBe( 'First name' );
	} );

	it( 'setOptionTranslation/getOptionTranslation', () => {
		let overlay = {};
		overlay = setOptionTranslation( overlay, 'en', 'rozmiar', 's', 'Small' );
		expect( getOptionTranslation( overlay, 'en', 'rozmiar', 's' ) ).toBe( 'Small' );
		expect( overlay.en.options.rozmiar.s ).toBe( 'Small' );
	} );

	it( 'get* zwraca pusty string gdy brak', () => {
		expect( getTranslation( {}, 'en', 'field', 'x' ) ).toBe( '' );
		expect( getOptionTranslation( {}, 'en', 'f', 'o' ) ).toBe( '' );
	} );

	it( 'ops nie mutują wejścia', () => {
		const overlay = { en: { fields: { imie: 'X' } } };
		const before = JSON.stringify( overlay );
		setTranslation( overlay, 'en', 'field', 'imie', 'Y' );
		setOptionTranslation( overlay, 'en', 'rozmiar', 's', 'Z' );
		expect( JSON.stringify( overlay ) ).toBe( before );
	} );
} );
```

- [ ] **Step 2: Run to verify they fail**

Run: `npm run test:js -- i18nOps`
Expected: FAIL — module not found.

- [ ] **Step 3: Write minimal implementation**

Create `assets/admin/ops/i18nOps.js`:

```js
function langBucket( overlay, lang ) {
	const base = overlay[ lang ] || {};
	return {
		sections: base.sections || {},
		fields: base.fields || {},
		types: base.types || {},
		options: base.options || {},
	};
}

const KIND_BUCKET = { section: 'sections', field: 'fields', type: 'types' };

export function translatableItems( schema, types ) {
	const items = [];
	( schema.sections || [] ).forEach( ( section ) => {
		items.push( { kind: 'section', key: section.key, base: section.title || section.key } );
		( section.fields || [] ).forEach( ( field ) => {
			items.push( { kind: 'field', key: field.key, base: field.label || field.key } );
		} );
		( section.fields || [] ).forEach( ( field ) => {
			if ( field.key === '__type' ) {
				return;
			}
			( field.options || [] ).forEach( ( opt ) => {
				items.push( {
					kind: 'option',
					key: `${ field.key }:${ opt.value }`,
					base: opt.label || opt.value,
					fieldKey: field.key,
					optionValue: opt.value,
				} );
			} );
		} );
	} );
	( types || [] ).forEach( ( type ) => {
		items.push( { kind: 'type', key: type.key, base: type.label || type.key } );
	} );
	return items;
}

export function getTranslation( overlay, lang, kind, key ) {
	const bucket = langBucket( overlay, lang )[ KIND_BUCKET[ kind ] ];
	return bucket && bucket[ key ] ? String( bucket[ key ] ) : '';
}

export function getOptionTranslation( overlay, lang, fieldKey, optionValue ) {
	const opts = langBucket( overlay, lang ).options[ fieldKey ] || {};
	return opts[ optionValue ] ? String( opts[ optionValue ] ) : '';
}

export function setTranslation( overlay, lang, kind, key, value ) {
	const bucketName = KIND_BUCKET[ kind ];
	const current = langBucket( overlay, lang );
	return {
		...overlay,
		[ lang ]: {
			...current,
			[ bucketName ]: { ...current[ bucketName ], [ key ]: value },
		},
	};
}

export function setOptionTranslation( overlay, lang, fieldKey, optionValue, value ) {
	const current = langBucket( overlay, lang );
	return {
		...overlay,
		[ lang ]: {
			...current,
			options: {
				...current.options,
				[ fieldKey ]: { ...( current.options[ fieldKey ] || {} ), [ optionValue ]: value },
			},
		},
	};
}
```

- [ ] **Step 4: Run to verify they pass**

Run: `npm run test:js -- i18nOps`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add assets/admin/ops/i18nOps.js assets/admin/ops/i18nOps.test.js
git commit -m "feat: builder ops for content-translation overlay"
```

---

## Task 5: Admin wiring — languages, API, App, TranslationsTab

**Files:**
- Modify: `src/Admin/EventConfigAssets.php` (localize `languages`/`defaultLanguage`)
- Modify: `assets/admin/api.js` (add `loadI18n`/`saveI18n`)
- Modify: `assets/admin/App.jsx` (load/save overlay, add tab)
- Create: `assets/admin/tabs/TranslationsTab.jsx`
- Modify: `assets/admin/style.css` (matrix styling)

**Interfaces:**
- Consumes: `i18nOps` (Task 4); the REST route (Task 3); `window.evregAdmin.languages` / `.defaultLanguage`.
- Produces: a "Tłumaczenia" tab editing `config.i18n`, saved with the other config on the global Save.

- [ ] **Step 1: Localize the Polylang language list**

In `src/Admin/EventConfigAssets.php`, extend the `wp_localize_script( self::HANDLE, 'evregAdmin', array( ... ) )` payload with:

```php
'languages'       => self::translationLanguages(),
'defaultLanguage' => function_exists( 'pll_default_language' ) ? (string) pll_default_language( 'slug' ) : '',
```

Add a private helper that returns non-default Polylang languages as `[ { value: slug, label: name } ]` (empty when Polylang inactive):

```php
	/**
	 * Języki Polylang bez domyślnego, do UI tłumaczeń. Puste bez Polylang.
	 *
	 * @return array<int,array{value:string,label:string}>
	 */
	private static function translationLanguages(): array {
		if ( ! function_exists( 'pll_languages_list' ) || ! function_exists( 'pll_default_language' ) ) {
			return array();
		}
		$default = (string) pll_default_language( 'slug' );
		$slugs   = (array) pll_languages_list( array( 'fields' => 'slug' ) );
		$names   = (array) pll_languages_list( array( 'fields' => 'name' ) );
		$out     = array();
		foreach ( $slugs as $i => $slug ) {
			if ( (string) $slug === $default ) {
				continue;
			}
			$out[] = array( 'value' => (string) $slug, 'label' => (string) ( $names[ $i ] ?? $slug ) );
		}
		return $out;
	}
```

- [ ] **Step 2: API helpers**

In `assets/admin/api.js`, mirror `loadTemplates`/`saveTemplates` for the i18n route `evreg/v1/events/${eventId}/i18n`:
- `export function loadI18n( eventId )` — GET, returns the overlay object (`{}` if empty).
- `export function saveI18n( eventId, overlay )` — POST with the overlay as the JSON body, returns the saved overlay.

Use the exact `apiFetch` pattern (path, method, data) that `loadTemplates`/`saveTemplates` use.

- [ ] **Step 3: App load/save + tab**

In `assets/admin/App.jsx`:
- Import `loadI18n`, `saveI18n` from `./api`; import `TranslationsTab`.
- On mount (the effect that calls `loadConfig`/`loadTemplates`), also call `loadI18n( eventId )` and set `config.i18n` (default `{}`); track an `i18nLoaded` flag mirroring `templatesLoaded`.
- Add a tab `{ name: 'i18n', title: __( 'Tłumaczenia', 'event-registration' ) }` to the `tabs` array and a branch in `TabRouter` returning `<TranslationsTab config={ config } update={ update } />`.
- In `onSave`, add `saveI18n( eventId, config.i18n || {} )` to the `Promise.allSettled` task list, guarded by `i18nLoaded` (never POST i18n before its initial load completes — same reasoning as templates).

- [ ] **Step 4: TranslationsTab component**

Create `assets/admin/tabs/TranslationsTab.jsx`. Thin component:
- Reads `window.evregAdmin.languages` (array) and `.defaultLanguage`.
- If `languages` is empty → render a `Notice`/paragraph: `__( 'Włącz Polylang i dodaj języki, aby tłumaczyć treść.', 'event-registration' )`. Nothing else.
- Otherwise: compute `items = translatableItems( config.schema || {}, config.types || [] )`. Render a table: one row per item showing the base string (read-only) and, for each language, a `TextControl` whose value is `getTranslation`/`getOptionTranslation` and whose `onChange` calls `update('i18n')( setTranslation(...) / setOptionTranslation(...) )`. For `option` items use the option ops with `fieldKey`/`optionValue`; otherwise the `kind`/`key` ops.
- `update` is the same `update( key )` factory used by other tabs (`update('i18n')` returns a setter for `config.i18n`).
- All labels via `__()`.

Reference an existing tab (`MailTemplatesTab.jsx` / `TypesTab.jsx`) for the `config`/`update` prop contract and control usage.

- [ ] **Step 5: CSS**

Append minimal matrix styles to `assets/admin/style.css` (e.g. `.evreg-i18n-tab` table layout, base column muted, inputs full-width). Keep it consistent with existing tab styling.

- [ ] **Step 6: Build + jest + verify live**

Run:
```bash
npm run test:js
npm run build
```
Expected: all jest green (incl. Task 4), build succeeds.

Then in wp-env (Polylang is NOT installed): open an event's config → the new "Tłumaczenia" tab shows the "Włącz Polylang…" notice (languages empty). Save still works (empty overlay). This confirms the graceful path. (The translated-render path is covered by Task 2's integration test via the `evreg_current_language` filter.)

- [ ] **Step 7: Commit**

```bash
git add src/Admin/EventConfigAssets.php assets/admin/api.js assets/admin/App.jsx assets/admin/tabs/TranslationsTab.jsx assets/admin/style.css
git commit -m "feat: Translations tab wiring (languages, API, App, matrix)"
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

- [ ] **Step 3: Mark B3b done in backlog + commit**

In `docs/superpowers/backlog.md`, note B3b done (per-event content overlay: `_evreg_i18n`, `ContentTranslator`, `EventFormLoader` integration, `I18nController`, Translations tab; remaining B3c). Commit:

```bash
git add docs/superpowers/backlog.md
git commit -m "docs: mark backlog B3b (content translation overlay) done"
```

---

## Self-Review Notes

- **Spec coverage:** resolver (Task 1), persistence + adapter + loader integration (Task 2), REST (Task 3), ops (Task 4), admin tab/wiring (Task 5), verification (Task 6). All spec sections covered.
- **Data safety:** only labels are translated; keys/values untouched — Task 1 tests assert base fallback and no mutation; submission/validation path (by key) is not touched anywhere in this plan.
- **Testing without Polylang:** the front path is exercised via `add_filter('evreg_current_language', …)` in Task 2; the admin graceful path (no languages) is verified live in Task 5; e2e intentionally omitted.
- **Type consistency:** overlay shape `{ lang: { sections, fields, types, options:{fieldKey:{}} } }` is identical across ContentTranslator (Task 1), repo (Task 2), controller (Task 3), ops (Task 4), and the tab (Task 5). `ContentTranslator::apply(schema, types, overlay, lang)` signature is used verbatim by EventFormLoader (Task 2).
