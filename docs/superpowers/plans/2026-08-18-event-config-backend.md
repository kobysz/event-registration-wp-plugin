# Event Config Backend (Plan 2A) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Zbudować backend konfiguracji eventu — CPT `evreg_event` z własną capability, czysty `SchemaAssembler` składający kompletną `FormSchema` z rozdzielonych meta, repozytorium czterech meta oraz endpoint REST z nieblokującą walidacją — bez UI.

**Architecture:** Warstwa domeny z Planu 1 pozostaje nietknięta i jest źródłem prawdy dla walidacji. Plan 2A dokłada jeden czysty obiekt domenowy (`SchemaAssembler` w `src/Domain/Schema/`, zero WordPressa) oraz adaptery WordPressa w `src/Admin/`, `src/Persistence/`, `src/Rest/`. Konfiguracja żyje w czterech meta CPT jako JSON; REST wystawia ją jednym zasobem.

**Tech Stack:** PHP 8.1, WordPress 6.4+, Composer PSR-4 (`EvReg\`), PHPUnit 9.6, `wp-env` (Docker), PHPStan 6, WPCS. Bez JavaScriptu — React admin to Plan 2B.

**Spec:** `docs/superpowers/specs/2026-08-18-event-config-admin-design.md`

## Global Constraints

- Minimalne PHP: **8.1**. Minimalne WordPress: **6.4**.
- Namespace root `EvReg\`, PSR-4, katalog `src/`.
- Prefiks funkcji/opcji/hooków: `evreg_`. Prefiks meta: `_evreg_`. Namespace REST: `evreg/v1`.
- Text domain: `event-registration`.
- CPT slug: **`evreg_event`**. Capability bramkująca: **`edit_evreg_events`**.
- Cztery meta (JSON, `show_in_rest = false`): `_evreg_schema`, `_evreg_types`, `_evreg_accommodation`, `_evreg_settings`.
- `src/Domain/**` nie może zawierać ani jednego wywołania funkcji WordPressa ani `$wpdb`. Dotyczy nowego `SchemaAssembler`. Pilnuje tego `tests/Unit/Architecture/DomainPurityTest.php`.
- Domena zwraca **kody błędów / rzuca wyjątki**, nie komunikaty dla użytkownika. Tłumaczenie w warstwie prezentacji.
- Każdy plik PHP poza `src/Domain/` i `tests/` zaczyna się od `defined( 'ABSPATH' ) || exit;`.
- Katalogi testów pisane **wielką literą**: `tests/Unit/…`, `tests/Integration/…`. Nazwy suit PHPUnit małą literą (`--testsuite unit`).
- Metody obiektów wartości używają camelCase (`assemble`, `fromArray`); globalne funkcje/hooki snake_case z prefiksem `evreg_`. `phpcs.xml.dist` już wyklucza trzy sniffy nie do pogodzenia z tą architekturą — nowy kod musi przejść `phpcs` czysto pod tą konfiguracją.
- PHPStan poziom 6 bez obniżania i bez rozproszonych `@phpstan-ignore`. Uzupełniaj adnotacje typów tablicowych.
- Wszystkie testy i narzędzia uruchamiane w kontenerze — host nie ma PHP.

### Komendy referencyjne

Na tej maszynie wp-env uruchamia się przez wrapper (nie goły `npx wp-env`) — patrz `scripts/wp-env.cjs`.

Testy jednostkowe (bez WordPressa):

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
```

Testy integracyjne (z WordPressem):

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
```

PHPStan i WPCS:

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
```

Środowiskowy kwirk (z Planu 1): konsola PHPUnit w tym kontenerze zniekształca komunikat każdego niezłapanego `Error`. Fazę RED weryfikuj jawnym try/catch drukującym `get_class( $e )`, `$e->getMessage()`, `$e->getFile()`, `$e->getLine()`; usuń skrypt pomocniczy po użyciu. `.phpunit.result.cache` jest już ignorowane.

---

## File Structure

| Plik | Odpowiedzialność |
|------|------------------|
| `src/Domain/Schema/SchemaAssembler.php` | CZYSTA domena: `(schema, types, accommodation)` → kompletny `FormSchema`; walidacja złożonej schemy |
| `src/Admin/EventPostType.php` | Rejestracja CPT `evreg_event`, wyłączenie edytora blokowego |
| `src/Admin/Capabilities.php` | Capability `edit_evreg_events`, nadanie roli administrator |
| `src/Persistence/EventConfigRepository.php` | Odczyt/zapis czterech meta jako JSON |
| `src/Rest/EventConfigController.php` | REST `GET`/`PUT /evreg/v1/events/{id}/config`, autoryzacja, sanityzacja, raport walidacji |
| `event-registration.php` | Modyfikacja: rejestracja hooków nowych usług + activation dla capability |

Wzorzec rejestracji: każda klasa adaptera wystawia statyczną metodę `register(): void` dodającą swoje hooki; główny plik wtyczki ją wywołuje — analogicznie do obecnej rejestracji `Migrations`.

---

## Task 1: CPT `evreg_event` i capability

**Files:**
- Create: `src/Admin/EventPostType.php`
- Create: `src/Admin/Capabilities.php`
- Modify: `event-registration.php` (rejestracja hooków + activation)
- Test: `tests/Integration/Admin/EventPostTypeTest.php`
- Test: `tests/Integration/Admin/CapabilitiesTest.php`

**Interfaces:**
- Consumes: nic z wcześniejszych tasków
- Produces:
  - `EvReg\Admin\EventPostType::POST_TYPE` (string `'evreg_event'`)
  - `EvReg\Admin\EventPostType::register(): void` — dodaje `init` (rejestracja CPT) i `use_block_editor_for_post_type` (wyłączenie Gutenberga)
  - `EvReg\Admin\Capabilities::CAP` (string `'edit_evreg_events'`)
  - `EvReg\Admin\Capabilities::grant(): void` — nadaje `CAP` roli `administrator`
  - `EvReg\Admin\Capabilities::register(): void` — no-op poza activation (miejsce na przyszłe rozszerzenia)

- [ ] **Step 1: Napisz failujący test rejestracji CPT**

`tests/Integration/Admin/EventPostTypeTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Admin;

use EvReg\Admin\EventPostType;
use WP_UnitTestCase;

final class EventPostTypeTest extends WP_UnitTestCase {

	public function test_post_type_is_registered(): void {
		EventPostType::register();
		do_action( 'init' );

		$this->assertTrue( post_type_exists( EventPostType::POST_TYPE ) );
	}

	public function test_post_type_is_not_public_but_admin_editable(): void {
		EventPostType::register();
		do_action( 'init' );

		$object = get_post_type_object( EventPostType::POST_TYPE );

		$this->assertNotNull( $object );
		$this->assertFalse( $object->public );
		$this->assertTrue( $object->show_ui );
		$this->assertTrue( (bool) $object->show_in_menu );
	}

	public function test_block_editor_disabled_for_post_type(): void {
		EventPostType::register();
		do_action( 'init' );

		$this->assertFalse(
			apply_filters( 'use_block_editor_for_post_type', true, EventPostType::POST_TYPE )
		);
		$this->assertTrue(
			apply_filters( 'use_block_editor_for_post_type', true, 'page' )
		);
	}
}
```

- [ ] **Step 2: Napisz failujący test capability**

`tests/Integration/Admin/CapabilitiesTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Admin;

use EvReg\Admin\Capabilities;
use WP_UnitTestCase;

final class CapabilitiesTest extends WP_UnitTestCase {

	public function test_grant_gives_capability_to_administrator(): void {
		get_role( 'administrator' )->remove_cap( Capabilities::CAP );

		Capabilities::grant();

		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::CAP ) );
	}

	public function test_subscriber_does_not_have_capability(): void {
		Capabilities::grant();

		$this->assertFalse( get_role( 'subscriber' )->has_cap( Capabilities::CAP ) );
	}
}
```

- [ ] **Step 3: Uruchom oba testy i potwierdź fail**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
```

Oczekiwane: FAIL — `Class "EvReg\Admin\EventPostType" not found` (i analogicznie `Capabilities`).

- [ ] **Step 4: Zaimplementuj `Capabilities`**

`src/Admin/Capabilities.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Capability bramkująca dostęp do konfiguracji eventów.
 */
final class Capabilities {

	public const CAP = 'edit_evreg_events';

	/**
	 * Nadaje capability roli administrator. Wywoływane przy aktywacji wtyczki.
	 */
	public static function grant(): void {
		$role = get_role( 'administrator' );

		if ( null !== $role && ! $role->has_cap( self::CAP ) ) {
			$role->add_cap( self::CAP );
		}
	}

	/**
	 * Miejsce na hooki runtime związane z capability. Obecnie brak.
	 */
	public static function register(): void {
	}
}
```

- [ ] **Step 5: Zaimplementuj `EventPostType`**

`src/Admin/EventPostType.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Rejestracja typu wpisu evreg_event i wyłączenie edytora blokowego.
 */
final class EventPostType {

	public const POST_TYPE = 'evreg_event';

	/**
	 * Podpina rejestrację CPT i wyłączenie Gutenberga.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_post_type' ) );
		add_filter( 'use_block_editor_for_post_type', array( self::class, 'disable_block_editor' ), 10, 2 );
	}

	/**
	 * Rejestruje typ wpisu.
	 */
	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Wydarzenia', 'event-registration' ),
					'singular_name' => __( 'Wydarzenie', 'event-registration' ),
					'add_new_item'  => __( 'Dodaj wydarzenie', 'event-registration' ),
					'edit_item'     => __( 'Edytuj wydarzenie', 'event-registration' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => true,
				'show_in_rest' => false,
				'menu_icon'    => 'dashicons-tickets-alt',
				'supports'     => array( 'title' ),
				'has_archive'  => false,
				'rewrite'      => false,
			)
		);
	}

	/**
	 * Wyłącza edytor blokowy dla evreg_event.
	 *
	 * @param bool   $use_block_editor Czy użyć edytora blokowego.
	 * @param string $post_type        Typ wpisu.
	 */
	public static function disable_block_editor( bool $use_block_editor, string $post_type ): bool {
		if ( self::POST_TYPE === $post_type ) {
			return false;
		}

		return $use_block_editor;
	}
}
```

- [ ] **Step 6: Podepnij rejestrację w `event-registration.php`**

Dodaj po istniejącej linii `\EvReg\Plugin::boot( __FILE__ );`, przed rejestracją migracji:

```php
add_action( 'plugins_loaded', array( \EvReg\Admin\EventPostType::class, 'register' ) );
add_action( 'plugins_loaded', array( \EvReg\Admin\Capabilities::class, 'register' ) );
```

Rozszerz istniejący hook aktywacji, aby nadał capability. Zamień linię:

```php
register_activation_hook( __FILE__, array( \EvReg\Persistence\Migrations::class, 'install' ) );
```

na:

```php
register_activation_hook(
	__FILE__,
	static function (): void {
		\EvReg\Persistence\Migrations::install();
		\EvReg\Admin\Capabilities::grant();
	}
);
```

- [ ] **Step 7: Uruchom testy integracyjne**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
```

Oczekiwane: PASS, 5 testów (3 CPT + 2 capability).

- [ ] **Step 8: Sprawdź PHPStan i WPCS**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
```

Oczekiwane: oba czyste. Napraw zgłoszenia w nowych plikach (adnotacje typów, Yoda, docblocki). Jeśli `use EvReg\Plugin;` jest nieużywane — usuń.

- [ ] **Step 9: Commit**

```bash
git add src/Admin event-registration.php tests/Integration/Admin
git commit -m "feat: register event CPT with block editor disabled and gating capability"
```

---

## Task 2: SchemaAssembler

**Files:**
- Create: `src/Domain/Schema/SchemaAssembler.php`
- Test: `tests/Unit/Domain/Schema/SchemaAssemblerTest.php`

**Interfaces:**
- Consumes (z Planu 1): `EvReg\Domain\Schema\FormSchema` (`fromArray`, `TYPE_FIELD_KEY = '__type'`), `EvReg\Domain\Schema\FieldType` (`Accommodation = 'accommodation'`), `EvReg\Domain\Schema\SchemaException`, `EvReg\Domain\Schema\SchemaIntegrityChecker` (`check( FormSchema ): void`), `EvReg\Domain\Registration\RegistrationTypeCollection` (`fromArray`, `active(): RegistrationType[]`), `RegistrationType` (readonly `key`, `label`, `active`)
- Produces:
  - `EvReg\Domain\Schema\SchemaAssembler::assemble( array $schema, array $types, array $accommodation ): FormSchema`
  - `EvReg\Domain\Schema\SchemaAssembler::validate( array $schema, array $types, array $accommodation ): void` — rzuca `SchemaException`, gdy złożona schema jest niepoprawna

**Zasada:** assembler pracuje na surowej tablicy schemy PRZED zbudowaniem obiektów wartości. Wstrzykuje `options` w pole o kluczu `__type` (z aktywnych typów: `{value: key, label: label}`) oraz `config` w każde pole typu `accommodation`. Następnie buduje `FormSchema::fromArray()`. Nie dotyka WordPressa. Typy nieaktywne pomijane w opcjach `__type`.

- [ ] **Step 1: Napisz failujące testy**

`tests/Unit/Domain/Schema/SchemaAssemblerTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Schema;

use EvReg\Domain\Schema\FieldType;
use EvReg\Domain\Schema\SchemaAssembler;
use EvReg\Domain\Schema\SchemaException;
use PHPUnit\Framework\TestCase;

final class SchemaAssemblerTest extends TestCase {

	private SchemaAssembler $assembler;

	protected function setUp(): void {
		$this->assembler = new SchemaAssembler();
	}

	/**
	 * @return array<string,mixed>
	 */
	private function raw_schema(): array {
		return array(
			'version'  => 1,
			'sections' => array(
				array(
					'key'    => 'dane',
					'title'  => 'Dane',
					'fields' => array(
						array( 'key' => '__type', 'type' => 'radio', 'label' => 'Typ zgłoszenia' ),
						array( 'key' => 'email', 'type' => 'email', 'label' => 'E-mail', 'required' => true ),
					),
				),
				array(
					'key'    => 'noclegi',
					'title'  => 'Noclegi',
					'fields' => array(
						array( 'key' => 'nocleg', 'type' => 'accommodation', 'label' => 'Nocleg' ),
					),
				),
			),
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function raw_types(): array {
		return array(
			array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 450.0, 'capacity' => 100 ),
			array( 'key' => 'online', 'label' => 'Uczestnik on-line', 'price' => 150.0 ),
			array( 'key' => 'wykladowca', 'label' => 'Wykładowca', 'active' => false ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function raw_accommodation(): array {
		return array(
			'packages'   => array( array( 'key' => 'n12', 'label' => 'Noc 1–2' ) ),
			'rooms'      => array( array( 'key' => 'double', 'label' => 'Pokój 2-os.', 'roommate_field' => true ) ),
			'inventory'  => array(
				array( 'package' => 'n12', 'room' => 'double', 'capacity' => 20, 'price' => 180.0 ),
			),
			'allow_none' => true,
		);
	}

	public function test_injects_active_types_as_type_field_options(): void {
		$schema = $this->assembler->assemble( $this->raw_schema(), $this->raw_types(), $this->raw_accommodation() );

		$type_field = $schema->findField( '__type' );

		$this->assertNotNull( $type_field );
		$values = array_map( static fn ( $option ) => $option->value, $type_field->options );
		$this->assertSame( array( 'uczestnik', 'online' ), $values );
		$labels = array_map( static fn ( $option ) => $option->label, $type_field->options );
		$this->assertSame( array( 'Uczestnik', 'Uczestnik on-line' ), $labels );
	}

	public function test_injects_accommodation_config_into_accommodation_field(): void {
		$schema = $this->assembler->assemble( $this->raw_schema(), $this->raw_types(), $this->raw_accommodation() );

		$field = $schema->findField( 'nocleg' );

		$this->assertNotNull( $field );
		$this->assertSame( FieldType::Accommodation, $field->type );
		$this->assertSame( $this->raw_accommodation(), $field->config );
	}

	public function test_assembled_schema_passes_integrity_check(): void {
		$this->assembler->validate( $this->raw_schema(), $this->raw_types(), $this->raw_accommodation() );

		$this->addToAssertionCount( 1 );
	}

	public function test_validate_throws_when_no_active_types_leave_type_field_optionless(): void {
		$types = array(
			array( 'key' => 'wykladowca', 'label' => 'Wykładowca', 'active' => false ),
		);

		$this->expectException( SchemaException::class );

		$this->assembler->validate( $this->raw_schema(), $types, $this->raw_accommodation() );
	}

	public function test_validate_throws_when_type_field_missing(): void {
		$schema = $this->raw_schema();
		// Usuń pole __type — złożona schema łamie regułę integralności.
		array_shift( $schema['sections'][0]['fields'] );

		$this->expectException( SchemaException::class );

		$this->assembler->validate( $schema, $this->raw_types(), $this->raw_accommodation() );
	}

	public function test_accommodation_field_without_config_gets_empty_when_no_accommodation_data(): void {
		$schema = $this->assembler->assemble( $this->raw_schema(), $this->raw_types(), array() );

		$field = $schema->findField( 'nocleg' );

		$this->assertNotNull( $field );
		$this->assertSame( array(), $field->config );
	}
}
```

- [ ] **Step 2: Uruchom i potwierdź fail**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit --filter SchemaAssemblerTest
```

Oczekiwane: FAIL — `Class "EvReg\Domain\Schema\SchemaAssembler" not found`.

- [ ] **Step 3: Zaimplementuj `SchemaAssembler`**

`src/Domain/Schema/SchemaAssembler.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Domain\Schema;

use EvReg\Domain\Registration\RegistrationType;
use EvReg\Domain\Registration\RegistrationTypeCollection;

/**
 * Składa kompletną FormSchema z rozdzielonych źródeł: surowej schemy,
 * typów zgłoszenia i konfiguracji noclegów.
 *
 * Pole __type dostaje opcje z aktywnych typów; pola accommodation dostają
 * config z konfiguracji noclegów. Wynik jest poprawną, samodzielną FormSchema.
 */
final class SchemaAssembler {

	/**
	 * @param array<string,mixed>            $schema        Surowy _evreg_schema.
	 * @param array<int,array<string,mixed>> $types         Surowy _evreg_types.
	 * @param array<string,mixed>            $accommodation Surowy _evreg_accommodation.
	 */
	public function assemble( array $schema, array $types, array $accommodation ): FormSchema {
		$options = $this->typeOptions( $types );

		$sections = $schema['sections'] ?? array();

		if ( is_array( $sections ) ) {
			foreach ( $sections as $s => $section ) {
				$fields = $section['fields'] ?? array();

				if ( ! is_array( $fields ) ) {
					continue;
				}

				foreach ( $fields as $f => $field ) {
					$key  = $field['key'] ?? '';
					$type = $field['type'] ?? '';

					if ( FormSchema::TYPE_FIELD_KEY === $key ) {
						$fields[ $f ]['options'] = $options;
					}

					if ( FieldType::Accommodation->value === $type ) {
						$fields[ $f ]['config'] = $accommodation;
					}
				}

				$sections[ $s ]['fields'] = $fields;
			}
		}

		$schema['sections'] = $sections;

		return FormSchema::fromArray( $schema );
	}

	/**
	 * Waliduje złożoną schemę. Rzuca SchemaException, gdy niepoprawna.
	 *
	 * @param array<string,mixed>            $schema        Surowy _evreg_schema.
	 * @param array<int,array<string,mixed>> $types         Surowy _evreg_types.
	 * @param array<string,mixed>            $accommodation Surowy _evreg_accommodation.
	 */
	public function validate( array $schema, array $types, array $accommodation ): void {
		$form_schema = $this->assemble( $schema, $types, $accommodation );

		( new SchemaIntegrityChecker() )->check( $form_schema );
	}

	/**
	 * Buduje listę opcji pola __type z aktywnych typów zgłoszenia.
	 *
	 * @param array<int,array<string,mixed>> $types Surowy _evreg_types.
	 * @return array<int,array<string,string>>
	 */
	private function typeOptions( array $types ): array {
		$collection = RegistrationTypeCollection::fromArray( $types );

		return array_map(
			static fn ( RegistrationType $type ): array => array(
				'value' => $type->key,
				'label' => $type->label,
			),
			$collection->active()
		);
	}
}
```

- [ ] **Step 4: Uruchom testy**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
```

Oczekiwane: PASS, 6 testów w `SchemaAssemblerTest`.

- [ ] **Step 5: Potwierdź czystość domeny + narzędzia**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit --filter DomainPurityTest
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
```

Oczekiwane: `DomainPurityTest` PASS (assembler nie dotyka WP), PHPStan i WPCS czyste.

- [ ] **Step 6: Commit**

```bash
git add src/Domain/Schema/SchemaAssembler.php tests/Unit/Domain/Schema/SchemaAssemblerTest.php
git commit -m "feat: add schema assembler merging types and accommodation into complete schema"
```

---

## Task 3: EventConfigRepository

**Files:**
- Create: `src/Persistence/EventConfigRepository.php`
- Test: `tests/Integration/Persistence/EventConfigRepositoryTest.php`

**Interfaces:**
- Consumes: nic z domeny (czysta persystencja meta)
- Produces:
  - Stałe meta: `EventConfigRepository::META_SCHEMA` (`'_evreg_schema'`), `META_TYPES` (`'_evreg_types'`), `META_ACCOMMODATION` (`'_evreg_accommodation'`), `META_SETTINGS` (`'_evreg_settings'`)
  - `EventConfigRepository::get( int $event_id ): array` — zwraca `['schema' => array, 'types' => array, 'accommodation' => array, 'settings' => array]`; brakujące meta jako puste tablice
  - `EventConfigRepository::save( int $event_id, array $config ): void` — zapisuje cztery klucze jako JSON; klucze nieobecne w `$config` pomijane (nie kasowane)

**Zasada:** meta zapisywane jako łańcuch JSON (`wp_json_encode`), odczytywane przez `json_decode( …, true )`. Brak meta → pusta tablica. Repozytorium nie waliduje treści — to zadanie kontrolera REST.

- [ ] **Step 1: Napisz failujący test round-trip**

`tests/Integration/Persistence/EventConfigRepositoryTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Persistence;

use EvReg\Persistence\EventConfigRepository;
use WP_UnitTestCase;

final class EventConfigRepositoryTest extends WP_UnitTestCase {

	private EventConfigRepository $repository;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		$this->repository = new EventConfigRepository();
		$this->event_id  = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function sample_config(): array {
		return array(
			'schema'        => array(
				'version'  => 1,
				'sections' => array(
					array(
						'key'    => 'dane',
						'title'  => 'Dane',
						'fields' => array( array( 'key' => '__type', 'type' => 'radio', 'label' => 'Typ' ) ),
					),
				),
			),
			'types'         => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 450.0 ) ),
			'accommodation' => array( 'packages' => array(), 'rooms' => array(), 'inventory' => array() ),
			'settings'      => array( 'global_cap' => 200, 'waitlist_enabled' => true ),
		);
	}

	public function test_get_returns_empty_arrays_for_unconfigured_event(): void {
		$config = $this->repository->get( $this->event_id );

		$this->assertSame(
			array(
				'schema'        => array(),
				'types'         => array(),
				'accommodation' => array(),
				'settings'      => array(),
			),
			$config
		);
	}

	public function test_save_then_get_round_trips(): void {
		$this->repository->save( $this->event_id, $this->sample_config() );

		$this->assertSame( $this->sample_config(), $this->repository->get( $this->event_id ) );
	}

	public function test_save_persists_as_json_string(): void {
		$this->repository->save( $this->event_id, $this->sample_config() );

		$raw = get_post_meta( $this->event_id, EventConfigRepository::META_SETTINGS, true );

		$this->assertIsString( $raw );
		$this->assertSame( array( 'global_cap' => 200, 'waitlist_enabled' => true ), json_decode( $raw, true ) );
	}

	public function test_save_ignores_absent_keys(): void {
		$this->repository->save( $this->event_id, $this->sample_config() );
		$this->repository->save( $this->event_id, array( 'settings' => array( 'global_cap' => 50 ) ) );

		$config = $this->repository->get( $this->event_id );

		$this->assertSame( array( 'global_cap' => 50 ), $config['settings'] );
		$this->assertSame( $this->sample_config()['types'], $config['types'] );
	}
}
```

- [ ] **Step 2: Uruchom i potwierdź fail**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter EventConfigRepositoryTest
```

Oczekiwane: FAIL — `Class "EvReg\Persistence\EventConfigRepository" not found`.

- [ ] **Step 3: Zaimplementuj `EventConfigRepository`**

`src/Persistence/EventConfigRepository.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Persistence;

defined( 'ABSPATH' ) || exit;

/**
 * Odczyt i zapis konfiguracji eventu w czterech meta CPT jako JSON.
 */
final class EventConfigRepository {

	public const META_SCHEMA        = '_evreg_schema';
	public const META_TYPES         = '_evreg_types';
	public const META_ACCOMMODATION = '_evreg_accommodation';
	public const META_SETTINGS      = '_evreg_settings';

	private const KEY_MAP = array(
		'schema'        => self::META_SCHEMA,
		'types'         => self::META_TYPES,
		'accommodation' => self::META_ACCOMMODATION,
		'settings'      => self::META_SETTINGS,
	);

	/**
	 * Zwraca całą konfigurację eventu. Brakujące meta jako puste tablice.
	 *
	 * @return array<string,mixed>
	 */
	public function get( int $event_id ): array {
		$config = array();

		foreach ( self::KEY_MAP as $key => $meta_key ) {
			$config[ $key ] = $this->read( $event_id, $meta_key );
		}

		return $config;
	}

	/**
	 * Zapisuje przekazane fragmenty konfiguracji. Klucze nieobecne pomijane.
	 *
	 * @param array<string,mixed> $config Dowolny podzbiór kluczy schema/types/accommodation/settings.
	 */
	public function save( int $event_id, array $config ): void {
		foreach ( self::KEY_MAP as $key => $meta_key ) {
			if ( ! array_key_exists( $key, $config ) ) {
				continue;
			}

			$encoded = wp_json_encode( $config[ $key ] );

			update_post_meta( $event_id, $meta_key, false === $encoded ? '' : $encoded );
		}
	}

	/**
	 * Czyta pojedyncze meta i dekoduje z JSON.
	 *
	 * @return array<mixed>
	 */
	private function read( int $event_id, string $meta_key ): array {
		$raw = get_post_meta( $event_id, $meta_key, true );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : array();
	}
}
```

Uwaga: `update_post_meta` z łańcuchem JSON — WordPress nie serializuje ponownie łańcucha, więc w bazie ląduje czysty JSON. Odczyt `get_post_meta( …, true )` zwraca ten łańcuch.

- [ ] **Step 4: Uruchom testy**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter EventConfigRepositoryTest
```

Oczekiwane: PASS, 4 testy.

- [ ] **Step 5: PHPStan i WPCS**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
```

Oczekiwane: czyste.

- [ ] **Step 6: Commit**

```bash
git add src/Persistence/EventConfigRepository.php tests/Integration/Persistence/EventConfigRepositoryTest.php
git commit -m "feat: add event config repository storing four meta as JSON"
```

---

## Task 4: EventConfigController (REST)

**Files:**
- Create: `src/Rest/EventConfigController.php`
- Modify: `event-registration.php` (rejestracja `rest_api_init`)
- Test: `tests/Integration/Rest/EventConfigControllerTest.php`

**Interfaces:**
- Consumes: `EventConfigRepository` (`get`, `save`, stałe `META_*`), `EvReg\Domain\Schema\SchemaAssembler` (`validate`), `EvReg\Domain\Schema\SchemaException`, `EvReg\Admin\Capabilities` (`CAP`), `EvReg\Admin\EventPostType` (`POST_TYPE`)
- Produces:
  - `EvReg\Rest\EventConfigController::REST_NAMESPACE` (`'evreg/v1'`)
  - `EventConfigController::register(): void` — dodaje `rest_api_init`
  - Trasa `GET`/`PUT /evreg/v1/events/{id}/config`
  - `GET` zwraca `{schema, types, accommodation, settings, validation}`
  - `PUT` zapisuje (nieblokująco) i zwraca to samo z aktualnym `validation`
  - `validation` = `{valid: bool, errors: array<int, array{code: string, detail: string}>}`

**Zasada:** serwer zawsze zapisuje surowe meta (polityka B). Walidacja przez `SchemaAssembler::validate`; przy `SchemaException` raport ma `valid: false` i jeden wpis `{code: 'schema_invalid', detail: <message>}`. Bogatszy podział na kody per reguła to przyszłe rozszerzenie (poza Planem 2A). Autoryzacja: `current_user_can( 'edit_post', id )` ORAZ `current_user_can( Capabilities::CAP )`.

- [ ] **Step 1: Napisz failujące testy REST**

`tests/Integration/Rest/EventConfigControllerTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Rest;

use EvReg\Rest\EventConfigController;
use WP_REST_Request;
use WP_UnitTestCase;

final class EventConfigControllerTest extends WP_UnitTestCase {

	private int $event_id;

	private int $admin_id;

	protected function setUp(): void {
		parent::setUp();

		do_action( 'rest_api_init' );

		$this->event_id = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_role( 'administrator' )->add_cap( \EvReg\Admin\Capabilities::CAP );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function valid_body(): array {
		return array(
			'schema'        => array(
				'version'  => 1,
				'sections' => array(
					array(
						'key'    => 'dane',
						'title'  => 'Dane',
						'fields' => array(
							array( 'key' => '__type', 'type' => 'radio', 'label' => 'Typ' ),
							array( 'key' => 'email', 'type' => 'email', 'label' => 'E-mail', 'required' => true ),
						),
					),
				),
			),
			'types'         => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 450.0 ) ),
			'accommodation' => array( 'packages' => array(), 'rooms' => array(), 'inventory' => array() ),
			'settings'      => array( 'global_cap' => 200, 'waitlist_enabled' => true ),
		);
	}

	public function test_get_requires_capability(): void {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', "/evreg/v1/events/{$this->event_id}/config" );
		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_put_saves_and_returns_valid_report(): void {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'PUT', "/evreg/v1/events/{$this->event_id}/config" );
		$request->set_body_params( $this->valid_body() );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['validation']['valid'] );
		$this->assertSame( array(), $data['validation']['errors'] );
	}

	public function test_get_after_put_returns_saved_config(): void {
		wp_set_current_user( $this->admin_id );

		$put = new WP_REST_Request( 'PUT', "/evreg/v1/events/{$this->event_id}/config" );
		$put->set_body_params( $this->valid_body() );
		rest_do_request( $put );

		$get      = new WP_REST_Request( 'GET', "/evreg/v1/events/{$this->event_id}/config" );
		$response = rest_do_request( $get );
		$data     = $response->get_data();

		$this->assertSame( $this->valid_body()['types'], $data['types'] );
		$this->assertSame( 200, $data['settings']['global_cap'] );
	}

	public function test_put_saves_invalid_config_but_reports_invalid(): void {
		wp_set_current_user( $this->admin_id );

		$body = $this->valid_body();
		// Usuń typy — pole __type zostanie bez opcji, złożona schema niepoprawna.
		$body['types'] = array();

		$request = new WP_REST_Request( 'PUT', "/evreg/v1/events/{$this->event_id}/config" );
		$request->set_body_params( $body );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( $data['validation']['valid'] );
		$this->assertNotEmpty( $data['validation']['errors'] );
		$this->assertArrayHasKey( 'code', $data['validation']['errors'][0] );

		// Mimo niepoprawności dane zostały zapisane.
		$get      = new WP_REST_Request( 'GET', "/evreg/v1/events/{$this->event_id}/config" );
		$get_data = rest_do_request( $get )->get_data();
		$this->assertSame( array(), $get_data['types'] );
	}

	public function test_put_sanitizes_string_values(): void {
		wp_set_current_user( $this->admin_id );

		$body                            = $this->valid_body();
		$body['types'][0]['label']       = '<script>alert(1)</script>Uczestnik';
		$body['settings']['global_cap']  = 200;

		$request = new WP_REST_Request( 'PUT', "/evreg/v1/events/{$this->event_id}/config" );
		$request->set_body_params( $body );
		rest_do_request( $request );

		$get      = new WP_REST_Request( 'GET', "/evreg/v1/events/{$this->event_id}/config" );
		$data     = rest_do_request( $get )->get_data();
		$saved    = $data['types'][0]['label'];

		$this->assertStringNotContainsString( '<script>', $saved );
		$this->assertStringContainsString( 'Uczestnik', $saved );
		// Wartości nie-łańcuchowe zachowane bez zmian typu.
		$this->assertSame( 200, $data['settings']['global_cap'] );
	}
}
```

- [ ] **Step 2: Uruchom i potwierdź fail**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter EventConfigControllerTest
```

Oczekiwane: FAIL — `Class "EvReg\Rest\EventConfigController" not found`.

- [ ] **Step 3: Zaimplementuj `EventConfigController`**

`src/Rest/EventConfigController.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Rest;

use EvReg\Admin\Capabilities;
use EvReg\Domain\Schema\SchemaException;
use EvReg\Domain\Schema\SchemaAssembler;
use EvReg\Persistence\EventConfigRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Endpoint REST konfiguracji eventu — jeden zasób na cały config.
 */
final class EventConfigController {

	public const REST_NAMESPACE = 'evreg/v1';

	private EventConfigRepository $repository;

	private SchemaAssembler $assembler;

	public function __construct() {
		$this->repository = new EventConfigRepository();
		$this->assembler  = new SchemaAssembler();
	}

	/**
	 * Podpina rejestrację tras.
	 */
	public static function register(): void {
		add_action(
			'rest_api_init',
			static function (): void {
				( new self() )->register_routes();
			}
		);
	}

	/**
	 * Rejestruje trasy GET/PUT.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/events/(?P<id>\d+)/config',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_config' ),
					'permission_callback' => array( $this, 'can_edit' ),
					'args'                => array(
						'id' => array( 'validate_callback' => static fn ( $value ): bool => is_numeric( $value ) ),
					),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_config' ),
					'permission_callback' => array( $this, 'can_edit' ),
				),
			)
		);
	}

	/**
	 * Sprawdza uprawnienia do edycji konfiguracji eventu.
	 *
	 * @return bool|WP_Error
	 */
	public function can_edit( WP_REST_Request $request ) {
		$event_id = (int) $request['id'];

		if ( ! current_user_can( Capabilities::CAP ) || ! current_user_can( 'edit_post', $event_id ) ) {
			return new WP_Error(
				'evreg_forbidden',
				__( 'Brak uprawnień do edycji tego wydarzenia.', 'event-registration' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Zwraca konfigurację eventu wraz z raportem walidacji.
	 */
	public function get_config( WP_REST_Request $request ): WP_REST_Response {
		$event_id = (int) $request['id'];

		return new WP_REST_Response( $this->build_payload( $event_id ), 200 );
	}

	/**
	 * Zapisuje konfigurację (nieblokująco) i zwraca ją z raportem walidacji.
	 */
	public function update_config( WP_REST_Request $request ): WP_REST_Response {
		$event_id = (int) $request['id'];

		$incoming = array();

		foreach ( array( 'schema', 'types', 'accommodation', 'settings' ) as $key ) {
			$value = $request->get_param( $key );

			if ( is_array( $value ) ) {
				$incoming[ $key ] = $this->sanitize( $value );
			}
		}

		$this->repository->save( $event_id, $incoming );

		return new WP_REST_Response( $this->build_payload( $event_id ), 200 );
	}

	/**
	 * Rekurencyjnie sanityzuje łańcuchowe liście konfiguracji, zachowując
	 * strukturę i typy nie-łańcuchowe. Config nie zawiera treści wieloliniowej
	 * ani HTML — etykiety, klucze, daty, e-maile i liczby są jednoliniowe.
	 *
	 * @param array<mixed> $value Fragment konfiguracji.
	 * @return array<mixed>
	 */
	private function sanitize( array $value ): array {
		$clean = array();

		foreach ( $value as $k => $v ) {
			if ( is_array( $v ) ) {
				$clean[ $k ] = $this->sanitize( $v );
			} elseif ( is_string( $v ) ) {
				$clean[ $k ] = sanitize_text_field( $v );
			} else {
				$clean[ $k ] = $v;
			}
		}

		return $clean;
	}

	/**
	 * Buduje odpowiedź: config z repozytorium + raport walidacji złożonej schemy.
	 *
	 * @return array<string,mixed>
	 */
	private function build_payload( int $event_id ): array {
		$config = $this->repository->get( $event_id );

		return array(
			'schema'        => $config['schema'],
			'types'         => $config['types'],
			'accommodation' => $config['accommodation'],
			'settings'      => $config['settings'],
			'validation'    => $this->validation_report( $config ),
		);
	}

	/**
	 * Waliduje złożoną schemę i zwraca raport.
	 *
	 * @param array<string,mixed> $config Config z repozytorium.
	 * @return array{valid: bool, errors: array<int, array{code: string, detail: string}>}
	 */
	private function validation_report( array $config ): array {
		try {
			$this->assembler->validate(
				is_array( $config['schema'] ) ? $config['schema'] : array(),
				is_array( $config['types'] ) ? $config['types'] : array(),
				is_array( $config['accommodation'] ) ? $config['accommodation'] : array()
			);

			return array(
				'valid'  => true,
				'errors' => array(),
			);
		} catch ( SchemaException $e ) {
			return array(
				'valid'  => false,
				'errors' => array(
					array(
						'code'   => 'schema_invalid',
						'detail' => $e->getMessage(),
					),
				),
			);
		}
	}
}
```

- [ ] **Step 4: Podepnij rejestrację w `event-registration.php`**

Dodaj obok pozostałych rejestracji usług:

```php
add_action( 'plugins_loaded', array( \EvReg\Rest\EventConfigController::class, 'register' ) );
```

- [ ] **Step 5: Uruchom testy REST**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter EventConfigControllerTest
```

Oczekiwane: PASS, 5 testów.

- [ ] **Step 6: Pełna suita + narzędzia**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
```

Oczekiwane: obie suity zielone, PHPStan i WPCS czyste.

- [ ] **Step 7: Commit**

```bash
git add src/Rest/EventConfigController.php event-registration.php tests/Integration/Rest/EventConfigControllerTest.php
git commit -m "feat: add REST controller for event config with non-blocking validation report"
```

---

## Definicja ukończenia Planu 2A

- [ ] CPT `evreg_event` rejestruje się, edytor blokowy wyłączony, capability `edit_evreg_events` nadana administratorowi przy aktywacji
- [ ] `SchemaAssembler` składa poprawną `FormSchema` z rozdzielonych meta; czysty (przechodzi `DomainPurityTest`)
- [ ] `EventConfigRepository` robi round-trip czterech meta jako JSON
- [ ] REST `GET`/`PUT /evreg/v1/events/{id}/config` działa: autoryzacja przez capability, zapis nieblokujący, raport walidacji ze złożonej schemy
- [ ] Suita unit i integration zielone, PHPStan 6 i WPCS czyste

## Czego Plan 2A świadomie nie robi

Brak jakiegokolwiek UI — build tooling, React, cztery zakładki, raport w interfejsie i ścieżka E2E to Plan 2B. Brak gatingu publikacji (Plan 3). Raport walidacji ma pojedynczy kod `schema_invalid` z komunikatem-detalem; podział na kody per reguła (`missing_type_field` itd.) wymagałby typowanych błędów w `SchemaIntegrityChecker` z Planu 1 — świadomie odłożone, nie modyfikujemy Planu 1 tutaj.
