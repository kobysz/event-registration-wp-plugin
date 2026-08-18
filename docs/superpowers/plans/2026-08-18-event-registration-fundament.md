# Event Registration — Plan 1: Fundament i domena

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Zbudować szkielet wtyczki, tabele bazy i całą logikę domenową (schema formularza, warunki, walidacja, typy zgłoszeń, noclegi, ceny, limity) pokrytą testami jednostkowymi — bez żadnego UI.

**Architecture:** Katalog `src/Domain/` nie dotyka WordPressa ani `$wpdb`; to czysty PHP testowany bez ładowania WP. `src/Persistence/` zawiera migracje tabel. Bootstrap wtyczki rejestruje wyłącznie autoloader i hook aktywacji. Wszystkie komunikaty błędów z domeny to kody (`required`, `invalid_email`), tłumaczone dopiero w warstwie prezentacji.

**Tech Stack:** PHP 8.1, WordPress 6.4+, Composer PSR-4 (`EvReg\`), PHPUnit 9.6, `wp-env` (Docker), PHPStan, WPCS.

**Spec:** `docs/superpowers/specs/2026-08-18-event-registration-design.md`

## Global Constraints

- Minimalne PHP: **8.1**. Minimalne WordPress: **6.4**.
- Namespace root: `EvReg\`, PSR-4, katalog `src/`.
- Prefiks funkcji/opcji/hooków: `evreg_`. Prefiks tabel: `{$wpdb->prefix}evreg_`.
- Text domain: `event-registration`.
- `src/Domain/**` nie może zawierać ani jednego wywołania funkcji WordPressa ani `$wpdb`. Naruszenie = błąd, nie preferencja.
- Domena zwraca **kody błędów**, nie teksty. Tłumaczenie w warstwie prezentacji.
- Każdy plik PHP zaczyna się od `defined('ABSPATH') || exit;` — z wyjątkiem plików w `src/Domain/` i `tests/`, które nie są ładowane bezpośrednio przez WP.
- Silnik tabel: InnoDB (wymagany przez transakcyjną rezerwację miejsc w Planie 3).
- Wersja schematu bazy w opcji `evreg_db_version`.
- Wszystkie testy uruchamiane w kontenerze — na hoście nie ma PHP.

### Komendy referencyjne

Instalacja zależności PHP:

```bash
docker run --rm -v "$PWD:/app" -w /app composer:2 install
```

Start środowiska:

```bash
npx wp-env start
```

Testy jednostkowe (bez WordPressa):

```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
```

Testy integracyjne (z WordPressem):

```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite integration
```

---

## File Structure

| Plik | Odpowiedzialność |
|------|------------------|
| `event-registration.php` | Nagłówek wtyczki, guard, autoloader, hook aktywacji |
| `src/Plugin.php` | Punkt kompozycji: stałe wersji, `boot()` |
| `src/Persistence/Migrations.php` | Tworzenie i wersjonowanie tabel |
| `src/Domain/Schema/FieldType.php` | Enum typów pól + ich właściwości |
| `src/Domain/Schema/Option.php` | Opcja pola wyboru |
| `src/Domain/Schema/Field.php` | Pojedyncze pole formularza |
| `src/Domain/Schema/Section.php` | Sekcja z polami |
| `src/Domain/Schema/FormSchema.php` | Cała schema, parsowanie i serializacja |
| `src/Domain/Schema/SchemaException.php` | Błąd struktury schemy |
| `src/Domain/Schema/SchemaIntegrityChecker.php` | Reguły spójności schemy |
| `src/Domain/Schema/VisibilityResolver.php` | Wyliczanie widoczności sekcji i pól |
| `src/Domain/Schema/VisibilitySet.php` | Wynik wyliczenia widoczności |
| `src/Domain/Conditions/Operator.php` | Enum operatorów |
| `src/Domain/Conditions/Condition.php` | Warunek jako dane |
| `src/Domain/Conditions/ConditionEngine.php` | Ewaluacja warunku |
| `src/Domain/Validation/Validator.php` | Walidacja odpowiedzi względem schemy |
| `src/Domain/Validation/ValidationResult.php` | Błędy + znormalizowane wartości |
| `src/Domain/Validation/FieldValidator.php` | Interfejs walidatora pola |
| `src/Domain/Validation/FieldValidatorRegistry.php` | Mapa typ pola → walidator |
| `src/Domain/Validation/Validators/*.php` | Walidatory per typ |
| `src/Domain/Registration/RegistrationType.php` | Typ zgłoszenia |
| `src/Domain/Registration/RegistrationTypeCollection.php` | Zbiór typów |
| `src/Domain/Accommodation/*.php` | Pakiety, pokoje, inwentarz, wybór, konfiguracja |
| `src/Domain/Pricing/PriceCalculator.php` | Suma ceny zgłoszenia |
| `src/Domain/Capacity/*.php` | Limity, zajętość, decyzja |

---

## Task 1: Szkielet wtyczki i harness testów

**Files:**
- Create: `event-registration.php`
- Create: `src/Plugin.php`
- Create: `composer.json`
- Create: `phpunit.xml.dist`
- Create: `tests/bootstrap-unit.php`
- Create: `tests/bootstrap-integration.php`
- Create: `.wp-env.json`
- Create: `.editorconfig`
- Test: `tests/unit/PluginTest.php`

**Interfaces:**
- Consumes: nic
- Produces: `EvReg\Plugin::VERSION` (string), `EvReg\Plugin::boot(string $pluginFile): void`, działające suity `unit` i `integration`

- [ ] **Step 1: Utwórz `composer.json`**

```json
{
  "name": "kuba/event-registration",
  "description": "Formularze rejestracji na wydarzenia dla WordPressa",
  "type": "wordpress-plugin",
  "license": "GPL-2.0-or-later",
  "require": {
    "php": ">=8.1"
  },
  "require-dev": {
    "phpunit/phpunit": "^9.6",
    "yoast/phpunit-polyfills": "^1.1"
  },
  "autoload": {
    "psr-4": {
      "EvReg\\": "src/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "EvReg\\Tests\\": "tests/"
    }
  },
  "config": {
    "sort-packages": true
  }
}
```

- [ ] **Step 2: Utwórz `.wp-env.json`**

```json
{
  "phpVersion": "8.1",
  "plugins": ["."],
  "config": {
    "WP_DEBUG": true,
    "WP_DEBUG_LOG": true,
    "SCRIPT_DEBUG": true
  }
}
```

- [ ] **Step 3: Utwórz `.editorconfig`**

```ini
root = true

[*]
charset = utf-8
end_of_line = lf
insert_final_newline = true
trim_trailing_whitespace = true

[*.php]
indent_style = tab

[*.{js,json,yml,yaml,md}]
indent_style = space
indent_size = 2
```

- [ ] **Step 4: Utwórz `phpunit.xml.dist`**

```xml
<?xml version="1.0"?>
<phpunit
    bootstrap="tests/bootstrap-unit.php"
    colors="true"
    convertErrorsToExceptions="true"
    convertNoticesToExceptions="true"
    convertWarningsToExceptions="true"
    beStrictAboutTestsThatDoNotTestAnything="true">
    <testsuites>
        <testsuite name="unit">
            <directory suffix="Test.php">tests/unit</directory>
        </testsuite>
        <testsuite name="integration">
            <directory suffix="Test.php">tests/integration</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 5: Utwórz oba bootstrapy**

`tests/bootstrap-unit.php`:

```php
<?php
/**
 * Bootstrap dla testów jednostkowych — bez WordPressa.
 */

declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
```

`tests/bootstrap-integration.php`:

```php
<?php
/**
 * Bootstrap dla testów integracyjnych — ładuje suite testowy WordPressa.
 */

declare( strict_types=1 );

$tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/wordpress-phpunit';

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once $tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/event-registration.php';
	}
);

require $tests_dir . '/includes/bootstrap.php';
```

Suite `integration` wymaga innego bootstrapu niż `unit`, więc dodaj do `phpunit.xml.dist` w bloku `<testsuite name="integration">` nadpisanie — PHPUnit 9 nie wspiera bootstrapu per suite, więc zamiast tego utwórz drugi plik konfiguracyjny `phpunit-integration.xml.dist`:

```xml
<?xml version="1.0"?>
<phpunit
    bootstrap="tests/bootstrap-integration.php"
    colors="true"
    beStrictAboutTestsThatDoNotTestAnything="true">
    <testsuites>
        <testsuite name="integration">
            <directory suffix="Test.php">tests/integration</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

i usuń blok `<testsuite name="integration">` z `phpunit.xml.dist`. Komenda testów integracyjnych:

```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
```

- [ ] **Step 6: Utwórz `src/Plugin.php`**

```php
<?php

declare( strict_types=1 );

namespace EvReg;

/**
 * Punkt kompozycji wtyczki.
 */
final class Plugin {

	public const VERSION = '0.1.0';

	public const TEXT_DOMAIN = 'event-registration';

	private static string $plugin_file = '';

	public static function boot( string $plugin_file ): void {
		self::$plugin_file = $plugin_file;
	}

	public static function plugin_file(): string {
		return self::$plugin_file;
	}
}
```

- [ ] **Step 7: Utwórz `event-registration.php`**

```php
<?php
/**
 * Plugin Name:       Event Registration
 * Description:       Formularze rejestracji na wydarzenia z limitami miejsc, noclegami i potwierdzeniami mailowymi.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Kuba
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       event-registration
 *
 * @package EvReg
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

\EvReg\Plugin::boot( __FILE__ );
```

- [ ] **Step 8: Napisz test weryfikujący harness**

`tests/unit/PluginTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit;

use EvReg\Plugin;
use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase {

	public function test_autoloader_resolves_plugin_class(): void {
		$this->assertTrue( class_exists( Plugin::class ) );
	}

	public function test_version_is_semver(): void {
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', Plugin::VERSION );
	}
}
```

- [ ] **Step 9: Zainstaluj zależności i uruchom testy**

```bash
docker run --rm -v "$PWD:/app" -w /app composer:2 install
```

```bash
npx wp-env start
```

```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
```

Oczekiwane: `OK (2 tests, 2 assertions)`.

- [ ] **Step 10: Commit**

```bash
git add composer.json composer.lock phpunit.xml.dist phpunit-integration.xml.dist .wp-env.json .editorconfig event-registration.php src tests
git commit -m "chore: scaffold plugin with composer autoloading and phpunit harness"
```

---

## Task 2: Tabele bazy i migracje

**Files:**
- Create: `src/Persistence/Migrations.php`
- Modify: `event-registration.php` (rejestracja hooka aktywacji)
- Test: `tests/integration/MigrationsTest.php`

**Interfaces:**
- Consumes: `EvReg\Plugin::plugin_file()`
- Produces:
  - `EvReg\Persistence\Migrations::DB_VERSION` (int)
  - `EvReg\Persistence\Migrations::VERSION_OPTION` (string `evreg_db_version`)
  - `EvReg\Persistence\Migrations::install(): void`
  - `EvReg\Persistence\Migrations::table(string $name): string` — zwraca pełną nazwę tabeli, np. `table('registrations')`

- [ ] **Step 1: Napisz failujący test integracyjny**

`tests/integration/MigrationsTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration;

use EvReg\Persistence\Migrations;
use WP_UnitTestCase;

final class MigrationsTest extends WP_UnitTestCase {

	public function test_install_creates_all_tables(): void {
		global $wpdb;

		Migrations::install();

		foreach ( array( 'registrations', 'accommodation_bookings', 'mail_queue' ) as $name ) {
			$table  = Migrations::table( $name );
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			$this->assertSame( $table, $exists, "Brak tabeli {$table}" );
		}
	}

	public function test_install_is_idempotent(): void {
		Migrations::install();
		Migrations::install();

		$this->assertSame( Migrations::DB_VERSION, (int) get_option( Migrations::VERSION_OPTION ) );
	}

	public function test_registrations_table_has_expected_columns(): void {
		global $wpdb;

		Migrations::install();

		$columns = $wpdb->get_col( 'DESC ' . Migrations::table( 'registrations' ), 0 );

		$expected = array(
			'id',
			'event_id',
			'type_key',
			'status',
			'email',
			'name',
			'token',
			'data',
			'price_total',
			'note',
			'created_at',
			'expires_at',
			'confirmed_at',
			'updated_at',
		);

		foreach ( $expected as $column ) {
			$this->assertContains( $column, $columns, "Brak kolumny {$column}" );
		}
	}
}
```

- [ ] **Step 2: Uruchom test i potwierdź, że failuje**

```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
```

Oczekiwane: FAIL — `Class "EvReg\Persistence\Migrations" not found`.

- [ ] **Step 3: Zaimplementuj `src/Persistence/Migrations.php`**

```php
<?php

declare( strict_types=1 );

namespace EvReg\Persistence;

defined( 'ABSPATH' ) || exit;

/**
 * Tworzenie i wersjonowanie tabel wtyczki.
 */
final class Migrations {

	public const DB_VERSION = 1;

	public const VERSION_OPTION = 'evreg_db_version';

	private const TABLE_PREFIX = 'evreg_';

	public static function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_PREFIX . $name;
	}

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		foreach ( self::statements( $charset ) as $sql ) {
			dbDelta( $sql );
		}

		update_option( self::VERSION_OPTION, self::DB_VERSION );
	}

	public static function maybe_upgrade(): void {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) === self::DB_VERSION ) {
			return;
		}

		self::install();
	}

	/**
	 * @return string[]
	 */
	private static function statements( string $charset ): array {
		$registrations = self::table( 'registrations' );
		$bookings      = self::table( 'accommodation_bookings' );
		$mail_queue    = self::table( 'mail_queue' );

		return array(
			"CREATE TABLE {$registrations} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL,
				type_key varchar(64) NOT NULL,
				status varchar(20) NOT NULL,
				email varchar(191) NOT NULL,
				name varchar(191) NOT NULL,
				token char(32) NOT NULL,
				data longtext NOT NULL,
				price_total decimal(10,2) NOT NULL DEFAULT 0,
				note text NULL,
				created_at datetime NOT NULL,
				expires_at datetime NULL,
				confirmed_at datetime NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY idx_event_email (event_id, email),
				KEY idx_event_status (event_id, status),
				KEY idx_token (token),
				KEY idx_expiry (status, expires_at)
			) ENGINE=InnoDB {$charset};",

			"CREATE TABLE {$bookings} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				registration_id bigint(20) unsigned NOT NULL,
				package_key varchar(64) NOT NULL,
				room_type_key varchar(64) NOT NULL,
				roommate_pref varchar(191) NULL,
				price decimal(10,2) NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY idx_registration (registration_id),
				KEY idx_inventory (package_key, room_type_key)
			) ENGINE=InnoDB {$charset};",

			"CREATE TABLE {$mail_queue} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				registration_id bigint(20) unsigned NULL,
				event_id bigint(20) unsigned NOT NULL,
				template_key varchar(64) NOT NULL,
				recipient varchar(191) NOT NULL,
				subject text NOT NULL,
				body longtext NOT NULL,
				status varchar(20) NOT NULL,
				attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
				last_error text NULL,
				scheduled_at datetime NOT NULL,
				sent_at datetime NULL,
				PRIMARY KEY  (id),
				KEY idx_dispatch (status, scheduled_at)
			) ENGINE=InnoDB {$charset};",
		);
	}
}
```

Uwaga na formatowanie wymagane przez `dbDelta`: dwie spacje po `PRIMARY KEY`, klucze deklarowane jako `KEY`, każda kolumna w osobnej linii.

- [ ] **Step 4: Podepnij aktywację w `event-registration.php`**

Dodaj na końcu pliku, po `Plugin::boot()`:

```php
register_activation_hook( __FILE__, array( \EvReg\Persistence\Migrations::class, 'install' ) );
add_action( 'plugins_loaded', array( \EvReg\Persistence\Migrations::class, 'maybe_upgrade' ) );
```

- [ ] **Step 5: Uruchom testy integracyjne**

```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
```

Oczekiwane: PASS, 3 testy.

- [ ] **Step 6: Commit**

```bash
git add src/Persistence/Migrations.php event-registration.php tests/integration/MigrationsTest.php
git commit -m "feat: create plugin database tables with versioned migrations"
```

---

## Task 3: Typy pól, pola, sekcje i schema

**Files:**
- Create: `src/Domain/Schema/FieldType.php`
- Create: `src/Domain/Schema/Option.php`
- Create: `src/Domain/Schema/SchemaException.php`
- Create: `src/Domain/Conditions/Operator.php`
- Create: `src/Domain/Conditions/Condition.php`
- Create: `src/Domain/Schema/Field.php`
- Create: `src/Domain/Schema/Section.php`
- Create: `src/Domain/Schema/FormSchema.php`
- Test: `tests/unit/Domain/Schema/FormSchemaTest.php`

**Interfaces:**
- Consumes: nic
- Produces:
  - `EvReg\Domain\Schema\FieldType` (enum string): `Text`, `Email`, `Tel`, `Textarea`, `Number`, `Date`, `Select`, `Radio`, `Checkbox`, `CheckboxGroup`, `Hidden`, `Heading`, `Paragraph`, `Accommodation`; metody `isInput(): bool`, `hasOptions(): bool`
  - `EvReg\Domain\Schema\Option` — `__construct(string $value, string $label)`, `fromArray(array): self`, `toArray(): array`
  - `EvReg\Domain\Conditions\Operator` (enum string): `Equals`, `NotEquals`, `In`, `NotIn`, `IsEmpty`, `IsNotEmpty`
  - `EvReg\Domain\Conditions\Condition` — `__construct(string $field, Operator $operator, array|string|null $value)`, `fromArray(array): self`, `toArray(): array`
  - `EvReg\Domain\Schema\Field` — właściwości `key`, `type`, `label`, `required`, `options` (`Option[]`), `config` (`array`), `description`, `condition` (`?Condition`); `fromArray(array): self`, `toArray(): array`
  - `EvReg\Domain\Schema\Section` — `key`, `title`, `description`, `condition`, `fields` (`Field[]`); `fromArray/toArray`
  - `EvReg\Domain\Schema\FormSchema` — stałe `CURRENT_VERSION = 1`, `TYPE_FIELD_KEY = '__type'`; `fromArray(array): self`, `toArray(): array`, `sections(): Section[]`, `allFields(): Field[]`, `findField(string $key): ?Field`, `empty(): self`
  - `EvReg\Domain\Schema\SchemaException extends \InvalidArgumentException`

- [ ] **Step 1: Napisz failujące testy**

`tests/unit/Domain/Schema/FormSchemaTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Schema;

use EvReg\Domain\Conditions\Operator;
use EvReg\Domain\Schema\FieldType;
use EvReg\Domain\Schema\FormSchema;
use EvReg\Domain\Schema\SchemaException;
use PHPUnit\Framework\TestCase;

final class FormSchemaTest extends TestCase {

	/**
	 * @return array<string,mixed>
	 */
	private function valid_schema(): array {
		return array(
			'version'  => 1,
			'sections' => array(
				array(
					'key'    => 'dane',
					'title'  => 'Dane uczestnika',
					'fields' => array(
						array(
							'key'     => '__type',
							'type'    => 'radio',
							'label'   => 'Typ zgłoszenia',
							'options' => array(
								array(
									'value' => 'uczestnik',
									'label' => 'Uczestnik',
								),
							),
						),
						array(
							'key'      => 'email',
							'type'     => 'email',
							'label'    => 'E-mail',
							'required' => true,
						),
					),
				),
				array(
					'key'       => 'noclegi',
					'title'     => 'Noclegi',
					'condition' => array(
						'field'    => '__type',
						'operator' => 'in',
						'value'    => array( 'uczestnik' ),
					),
					'fields'    => array(
						array(
							'key'   => 'nocleg',
							'type'  => 'accommodation',
							'label' => 'Nocleg',
						),
					),
				),
			),
		);
	}

	public function test_parses_sections_and_fields(): void {
		$schema = FormSchema::fromArray( $this->valid_schema() );

		$this->assertCount( 2, $schema->sections() );
		$this->assertCount( 3, $schema->allFields() );
		$this->assertSame( FieldType::Email, $schema->findField( 'email' )->type );
		$this->assertTrue( $schema->findField( 'email' )->required );
	}

	public function test_parses_section_condition(): void {
		$schema    = FormSchema::fromArray( $this->valid_schema() );
		$condition = $schema->sections()[1]->condition;

		$this->assertNotNull( $condition );
		$this->assertSame( '__type', $condition->field );
		$this->assertSame( Operator::In, $condition->operator );
		$this->assertSame( array( 'uczestnik' ), $condition->value );
	}

	public function test_round_trips_through_array(): void {
		$input = $this->valid_schema();

		$this->assertSame( $input, FormSchema::fromArray( $input )->toArray() );
	}

	public function test_rejects_unknown_field_type(): void {
		$data = $this->valid_schema();
		$data['sections'][0]['fields'][1]['type'] = 'signature';

		$this->expectException( SchemaException::class );
		$this->expectExceptionMessage( 'signature' );

		FormSchema::fromArray( $data );
	}

	public function test_rejects_field_without_key(): void {
		$data = $this->valid_schema();
		unset( $data['sections'][0]['fields'][1]['key'] );

		$this->expectException( SchemaException::class );

		FormSchema::fromArray( $data );
	}

	public function test_rejects_unknown_operator(): void {
		$data = $this->valid_schema();
		$data['sections'][1]['condition']['operator'] = 'matches';

		$this->expectException( SchemaException::class );

		FormSchema::fromArray( $data );
	}

	public function test_empty_schema_has_no_sections(): void {
		$this->assertSame( array(), FormSchema::empty()->sections() );
	}

	public function test_field_type_knows_whether_it_collects_input(): void {
		$this->assertTrue( FieldType::Text->isInput() );
		$this->assertFalse( FieldType::Heading->isInput() );
		$this->assertTrue( FieldType::Select->hasOptions() );
		$this->assertFalse( FieldType::Text->hasOptions() );
	}
}
```

- [ ] **Step 2: Uruchom i potwierdź fail**

```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
```

Oczekiwane: FAIL — `Class "EvReg\Domain\Schema\FormSchema" not found`.

- [ ] **Step 3: Zaimplementuj enumy i wyjątek**

`src/Domain/Schema/SchemaException.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Domain\Schema;

final class SchemaException extends \InvalidArgumentException {
}
```

`src/Domain/Schema/FieldType.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Domain\Schema;

enum FieldType: string {
	case Text          = 'text';
	case Email         = 'email';
	case Tel           = 'tel';
	case Textarea      = 'textarea';
	case Number        = 'number';
	case Date          = 'date';
	case Select        = 'select';
	case Radio         = 'radio';
	case Checkbox      = 'checkbox';
	case CheckboxGroup = 'checkbox-group';
	case Hidden        = 'hidden';
	case Heading       = 'heading';
	case Paragraph     = 'paragraph';
	case Accommodation = 'accommodation';

	/** Czy pole zbiera dane od użytkownika. */
	public function isInput(): bool {
		return ! in_array( $this, array( self::Heading, self::Paragraph ), true );
	}

	/** Czy pole wymaga listy opcji. */
	public function hasOptions(): bool {
		return in_array( $this, array( self::Select, self::Radio, self::CheckboxGroup ), true );
	}

	/** Czy odpowiedź jest tablicą wartości. */
	public function isMultiValue(): bool {
		return self::CheckboxGroup === $this;
	}
}
```

`src/Domain/Conditions/Operator.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Domain\Conditions;

enum Operator: string {
	case Equals     = 'equals';
	case NotEquals  = 'not_equals';
	case In         = 'in';
	case NotIn      = 'not_in';
	case IsEmpty    = 'empty';
	case IsNotEmpty = 'not_empty';

	/** Czy operator porównuje z wartością. */
	public function needsValue(): bool {
		return ! in_array( $this, array( self::IsEmpty, self::IsNotEmpty ), true );
	}
}
```

- [ ] **Step 4: Zaimplementuj `Condition` i `Option`**

`src/Domain/Conditions/Condition.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Domain\Conditions;

use EvReg\Domain\Schema\SchemaException;

final class Condition {

	/**
	 * @param string[]|string|null $value
	 */
	public function __construct(
		public readonly string $field,
		public readonly Operator $operator,
		public readonly array|string|null $value = null
	) {
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function fromArray( array $data ): self {
		if ( ! isset( $data['field'] ) || ! is_string( $data['field'] ) || '' === $data['field'] ) {
			throw new SchemaException( 'Warunek wymaga niepustego klucza "field".' );
		}

		$operator = Operator::tryFrom( (string) ( $data['operator'] ?? '' ) );

		if ( null === $operator ) {
			throw new SchemaException(
				sprintf( 'Nieznany operator warunku: "%s".', (string) ( $data['operator'] ?? '' ) )
			);
		}

		$value = $data['value'] ?? null;

		if ( $operator->needsValue() && null === $value ) {
			throw new SchemaException(
				sprintf( 'Operator "%s" wymaga wartości.', $operator->value )
			);
		}

		if ( null !== $value && ! is_array( $value ) && ! is_string( $value ) ) {
			$value = (string) $value;
		}

		return new self( $data['field'], $operator, $value );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		$data = array(
			'field'    => $this->field,
			'operator' => $this->operator->value,
		);

		if ( $this->operator->needsValue() ) {
			$data['value'] = $this->value;
		}

		return $data;
	}
}
```

`src/Domain/Schema/Option.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Domain\Schema;

final class Option {

	public function __construct(
		public readonly string $value,
		public readonly string $label
	) {
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function fromArray( array $data ): self {
		if ( ! isset( $data['value'] ) || '' === (string) $data['value'] ) {
			throw new SchemaException( 'Opcja wymaga niepustej wartości.' );
		}

		return new self( (string) $data['value'], (string) ( $data['label'] ?? $data['value'] ) );
	}

	/**
	 * @return array<string,string>
	 */
	public function toArray(): array {
		return array(
			'value' => $this->value,
			'label' => $this->label,
		);
	}
}
```

- [ ] **Step 5: Zaimplementuj `Field`, `Section`, `FormSchema`**

`src/Domain/Schema/Field.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Domain\Schema;

use EvReg\Domain\Conditions\Condition;

final class Field {

	/**
	 * @param Option[]            $options
	 * @param array<string,mixed> $config
	 */
	public function __construct(
		public readonly string $key,
		public readonly FieldType $type,
		public readonly string $label,
		public readonly bool $required = false,
		public readonly array $options = array(),
		public readonly array $config = array(),
		public readonly string $description = '',
		public readonly ?Condition $condition = null
	) {
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function fromArray( array $data ): self {
		if ( ! isset( $data['key'] ) || ! is_string( $data['key'] ) || '' === $data['key'] ) {
			throw new SchemaException( 'Pole wymaga niepustego klucza "key".' );
		}

		$type = FieldType::tryFrom( (string) ( $data['type'] ?? '' ) );

		if ( null === $type ) {
			throw new SchemaException(
				sprintf( 'Nieznany typ pola "%s" w polu "%s".', (string) ( $data['type'] ?? '' ), $data['key'] )
			);
		}

		$options = array();

		foreach ( (array) ( $data['options'] ?? array() ) as $option ) {
			$options[] = Option::fromArray( (array) $option );
		}

		if ( $type->hasOptions() && array() === $options ) {
			throw new SchemaException(
				sprintf( 'Pole "%s" typu "%s" wymaga listy opcji.', $data['key'], $type->value )
			);
		}

		return new self(
			$data['key'],
			$type,
			(string) ( $data['label'] ?? '' ),
			(bool) ( $data['required'] ?? false ),
			$options,
			(array) ( $data['config'] ?? array() ),
			(string) ( $data['description'] ?? '' ),
			isset( $data['condition'] ) && is_array( $data['condition'] )
				? Condition::fromArray( $data['condition'] )
				: null
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		$data = array(
			'key'   => $this->key,
			'type'  => $this->type->value,
			'label' => $this->label,
		);

		if ( $this->required ) {
			$data['required'] = true;
		}

		if ( array() !== $this->options ) {
			$data['options'] = array_map(
				static fn ( Option $option ): array => $option->toArray(),
				$this->options
			);
		}

		if ( array() !== $this->config ) {
			$data['config'] = $this->config;
		}

		if ( '' !== $this->description ) {
			$data['description'] = $this->description;
		}

		if ( null !== $this->condition ) {
			$data['condition'] = $this->condition->toArray();
		}

		return $data;
	}
}
```

`src/Domain/Schema/Section.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Domain\Schema;

use EvReg\Domain\Conditions\Condition;

final class Section {

	/**
	 * @param Field[] $fields
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $title,
		public readonly array $fields,
		public readonly string $description = '',
		public readonly ?Condition $condition = null
	) {
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function fromArray( array $data ): self {
		if ( ! isset( $data['key'] ) || ! is_string( $data['key'] ) || '' === $data['key'] ) {
			throw new SchemaException( 'Sekcja wymaga niepustego klucza "key".' );
		}

		$fields = array();

		foreach ( (array) ( $data['fields'] ?? array() ) as $field ) {
			$fields[] = Field::fromArray( (array) $field );
		}

		return new self(
			$data['key'],
			(string) ( $data['title'] ?? '' ),
			$fields,
			(string) ( $data['description'] ?? '' ),
			isset( $data['condition'] ) && is_array( $data['condition'] )
				? Condition::fromArray( $data['condition'] )
				: null
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		$data = array(
			'key'   => $this->key,
			'title' => $this->title,
		);

		if ( '' !== $this->description ) {
			$data['description'] = $this->description;
		}

		if ( null !== $this->condition ) {
			$data['condition'] = $this->condition->toArray();
		}

		$data['fields'] = array_map(
			static fn ( Field $field ): array => $field->toArray(),
			$this->fields
		);

		return $data;
	}
}
```

`src/Domain/Schema/FormSchema.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Domain\Schema;

final class FormSchema {

	public const CURRENT_VERSION = 1;

	public const TYPE_FIELD_KEY = '__type';

	/**
	 * @param Section[] $sections
	 */
	private function __construct(
		private readonly array $sections,
		private readonly int $version = self::CURRENT_VERSION
	) {
	}

	public static function empty(): self {
		return new self( array() );
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function fromArray( array $data ): self {
		$sections = array();

		foreach ( (array) ( $data['sections'] ?? array() ) as $section ) {
			$sections[] = Section::fromArray( (array) $section );
		}

		return new self( $sections, (int) ( $data['version'] ?? self::CURRENT_VERSION ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'version'  => $this->version,
			'sections' => array_map(
				static fn ( Section $section ): array => $section->toArray(),
				$this->sections
			),
		);
	}

	/**
	 * @return Section[]
	 */
	public function sections(): array {
		return $this->sections;
	}

	/**
	 * @return Field[]
	 */
	public function allFields(): array {
		$fields = array();

		foreach ( $this->sections as $section ) {
			foreach ( $section->fields as $field ) {
				$fields[] = $field;
			}
		}

		return $fields;
	}

	public function findField( string $key ): ?Field {
		foreach ( $this->allFields() as $field ) {
			if ( $field->key === $key ) {
				return $field;
			}
		}

		return null;
	}

	public function sectionOf( string $fieldKey ): ?Section {
		foreach ( $this->sections as $section ) {
			foreach ( $section->fields as $field ) {
				if ( $field->key === $fieldKey ) {
					return $section;
				}
			}
		}

		return null;
	}
}
```

- [ ] **Step 6: Uruchom testy**

```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
```

Oczekiwane: PASS, 8 testów w `FormSchemaTest`.

- [ ] **Step 7: Commit**

```bash
git add src/Domain tests/unit/Domain
git commit -m "feat: add form schema value objects with parsing and serialization"
```

---

## Task 4: Silnik warunków

**Files:**
- Create: `src/Domain/Conditions/ConditionEngine.php`
- Test: `tests/unit/Domain/Conditions/ConditionEngineTest.php`

**Interfaces:**
- Consumes: `EvReg\Domain\Conditions\Condition`, `Operator`
- Produces: `EvReg\Domain\Conditions\ConditionEngine::isMet(?Condition $condition, array $answers): bool` — `null` oznacza „zawsze spełniony"

**Reguły porównania** (zaimplementuj dokładnie tak):
- Brak klucza w `$answers` traktuj jak wartość pustą (`''`).
- Wartości skalarne porównuj po rzutowaniu na `string`.
- Gdy odpowiedź jest tablicą (checkbox-group): `equals` = tablica zawiera dokładnie tę jedną wartość; `in` = przecięcie niepuste; `not_in` = przecięcie puste.
- `empty` = `''`, `null` lub pusta tablica.

- [ ] **Step 1: Napisz failujące testy**

`tests/unit/Domain/Conditions/ConditionEngineTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Conditions;

use EvReg\Domain\Conditions\Condition;
use EvReg\Domain\Conditions\ConditionEngine;
use EvReg\Domain\Conditions\Operator;
use PHPUnit\Framework\TestCase;

final class ConditionEngineTest extends TestCase {

	private ConditionEngine $engine;

	protected function setUp(): void {
		$this->engine = new ConditionEngine();
	}

	public function test_null_condition_is_always_met(): void {
		$this->assertTrue( $this->engine->isMet( null, array() ) );
	}

	public function test_equals_matches_scalar(): void {
		$condition = new Condition( '__type', Operator::Equals, 'uczestnik' );

		$this->assertTrue( $this->engine->isMet( $condition, array( '__type' => 'uczestnik' ) ) );
		$this->assertFalse( $this->engine->isMet( $condition, array( '__type' => 'wykladowca' ) ) );
	}

	public function test_not_equals_is_true_for_missing_answer(): void {
		$condition = new Condition( '__type', Operator::NotEquals, 'uczestnik' );

		$this->assertTrue( $this->engine->isMet( $condition, array() ) );
	}

	public function test_in_matches_any_listed_value(): void {
		$condition = new Condition( '__type', Operator::In, array( 'uczestnik', 'wykladowca' ) );

		$this->assertTrue( $this->engine->isMet( $condition, array( '__type' => 'wykladowca' ) ) );
		$this->assertFalse( $this->engine->isMet( $condition, array( '__type' => 'online' ) ) );
	}

	public function test_not_in_negates_in(): void {
		$condition = new Condition( '__type', Operator::NotIn, array( 'online' ) );

		$this->assertTrue( $this->engine->isMet( $condition, array( '__type' => 'uczestnik' ) ) );
		$this->assertFalse( $this->engine->isMet( $condition, array( '__type' => 'online' ) ) );
	}

	public function test_in_matches_intersection_for_multi_value_answers(): void {
		$condition = new Condition( 'atrakcje', Operator::In, array( 'kolacja' ) );

		$this->assertTrue(
			$this->engine->isMet( $condition, array( 'atrakcje' => array( 'zwiedzanie', 'kolacja' ) ) )
		);
		$this->assertFalse(
			$this->engine->isMet( $condition, array( 'atrakcje' => array( 'zwiedzanie' ) ) )
		);
	}

	public function test_equals_on_multi_value_requires_exactly_one_match(): void {
		$condition = new Condition( 'atrakcje', Operator::Equals, 'kolacja' );

		$this->assertTrue( $this->engine->isMet( $condition, array( 'atrakcje' => array( 'kolacja' ) ) ) );
		$this->assertFalse(
			$this->engine->isMet( $condition, array( 'atrakcje' => array( 'kolacja', 'zwiedzanie' ) ) )
		);
	}

	public function test_empty_and_not_empty(): void {
		$empty     = new Condition( 'uwagi', Operator::IsEmpty, null );
		$not_empty = new Condition( 'uwagi', Operator::IsNotEmpty, null );

		$this->assertTrue( $this->engine->isMet( $empty, array() ) );
		$this->assertTrue( $this->engine->isMet( $empty, array( 'uwagi' => '' ) ) );
		$this->assertTrue( $this->engine->isMet( $empty, array( 'uwagi' => array() ) ) );
		$this->assertFalse( $this->engine->isMet( $empty, array( 'uwagi' => 'tekst' ) ) );

		$this->assertTrue( $this->engine->isMet( $not_empty, array( 'uwagi' => 'tekst' ) ) );
		$this->assertFalse( $this->engine->isMet( $not_empty, array() ) );
	}
}
```

- [ ] **Step 2: Uruchom i potwierdź fail**

```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit --filter ConditionEngineTest
```

Oczekiwane: FAIL — `Class "EvReg\Domain\Conditions\ConditionEngine" not found`.

- [ ] **Step 3: Zaimplementuj silnik**

`src/Domain/Conditions/ConditionEngine.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Domain\Conditions;

final class ConditionEngine {

	/**
	 * @param array<string,mixed> $answers
	 */
	public function isMet( ?Condition $condition, array $answers ): bool {
		if ( null === $condition ) {
			return true;
		}

		$answer = $answers[ $condition->field ] ?? '';

		return match ( $condition->operator ) {
			Operator::Equals     => $this->equals( $answer, $condition->value ),
			Operator::NotEquals  => ! $this->equals( $answer, $condition->value ),
			Operator::In         => $this->intersects( $answer, $condition->value ),
			Operator::NotIn      => ! $this->intersects( $answer, $condition->value ),
			Operator::IsEmpty    => $this->isEmpty( $answer ),
			Operator::IsNotEmpty => ! $this->isEmpty( $answer ),
		};
	}

	private function equals( mixed $answer, array|string|null $expected ): bool {
		$expected_list = $this->toList( $expected );
		$answer_list   = $this->toList( $answer );

		return $answer_list === $expected_list;
	}

	private function intersects( mixed $answer, array|string|null $expected ): bool {
		$expected_list = $this->toList( $expected );
		$answer_list   = $this->toList( $answer );

		return array() !== array_intersect( $answer_list, $expected_list );
	}

	private function isEmpty( mixed $answer ): bool {
		if ( is_array( $answer ) ) {
			return array() === $answer;
		}

		return null === $answer || '' === (string) $answer;
	}

	/**
	 * @return string[]
	 */
	private function toList( mixed $value ): array {
		if ( null === $value ) {
			return array();
		}

		if ( is_array( $value ) ) {
			return array_values( array_map( static fn ( $item ): string => (string) $item, $value ) );
		}

		if ( '' === (string) $value ) {
			return array();
		}

		return array( (string) $value );
	}
}
```

- [ ] **Step 4: Uruchom testy**

```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
```

Oczekiwane: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Domain/Conditions/ConditionEngine.php tests/unit/Domain/Conditions
git commit -m "feat: add condition engine with comparison operators"
```

---

## Task 5: Spójność schemy i widoczność

**Files:**
- Create: `src/Domain/Schema/SchemaIntegrityChecker.php`
- Create: `src/Domain/Schema/VisibilitySet.php`
- Create: `src/Domain/Schema/VisibilityResolver.php`
- Test: `tests/unit/Domain/Schema/SchemaIntegrityCheckerTest.php`
- Test: `tests/unit/Domain/Schema/VisibilityResolverTest.php`

**Interfaces:**
- Consumes: `FormSchema`, `ConditionEngine`
- Produces:
  - `SchemaIntegrityChecker::check(FormSchema $schema): void` — rzuca `SchemaException`
  - `VisibilitySet::isSectionVisible(string $key): bool`, `isFieldVisible(string $key): bool`, `visibleFields(): Field[]`
  - `VisibilityResolver::__construct(ConditionEngine $engine)`, `resolve(FormSchema $schema, array $answers): VisibilitySet`

**Reguły spójności:**
1. Klucze sekcji unikalne.
2. Klucze pól unikalne w całej schemie.
3. Schema zawiera pole `__type` typu `radio` lub `select`.
4. Każdy warunek wskazuje na istniejące pole.
5. Pole warunkujące występuje **wcześniej** niż element, który od niego zależy (kolejność: sekcje po kolei, pola w sekcji po kolei).
6. Warunek nie może wskazywać na pole z tej samej lub późniejszej sekcji, jeśli sam dotyczy sekcji.

- [ ] **Step 1: Napisz failujące testy spójności**

`tests/unit/Domain/Schema/SchemaIntegrityCheckerTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Schema;

use EvReg\Domain\Schema\FormSchema;
use EvReg\Domain\Schema\SchemaException;
use EvReg\Domain\Schema\SchemaIntegrityChecker;
use PHPUnit\Framework\TestCase;

final class SchemaIntegrityCheckerTest extends TestCase {

	private SchemaIntegrityChecker $checker;

	protected function setUp(): void {
		$this->checker = new SchemaIntegrityChecker();
	}

	/**
	 * @param array<int,array<string,mixed>> $sections
	 */
	private function schema( array $sections ): FormSchema {
		return FormSchema::fromArray(
			array(
				'version'  => 1,
				'sections' => $sections,
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function type_field(): array {
		return array(
			'key'     => '__type',
			'type'    => 'radio',
			'label'   => 'Typ',
			'options' => array( array( 'value' => 'uczestnik', 'label' => 'Uczestnik' ) ),
		);
	}

	public function test_accepts_valid_schema(): void {
		$schema = $this->schema(
			array(
				array(
					'key'    => 'a',
					'title'  => 'A',
					'fields' => array( $this->type_field() ),
				),
				array(
					'key'       => 'b',
					'title'     => 'B',
					'condition' => array( 'field' => '__type', 'operator' => 'in', 'value' => array( 'uczestnik' ) ),
					'fields'    => array( array( 'key' => 'x', 'type' => 'text', 'label' => 'X' ) ),
				),
			)
		);

		$this->checker->check( $schema );

		$this->addToAssertionCount( 1 );
	}

	public function test_rejects_duplicate_field_keys(): void {
		$schema = $this->schema(
			array(
				array(
					'key'    => 'a',
					'title'  => 'A',
					'fields' => array(
						$this->type_field(),
						array( 'key' => 'x', 'type' => 'text', 'label' => 'X' ),
						array( 'key' => 'x', 'type' => 'text', 'label' => 'X2' ),
					),
				),
			)
		);

		$this->expectException( SchemaException::class );
		$this->expectExceptionMessage( 'x' );

		$this->checker->check( $schema );
	}

	public function test_rejects_missing_type_field(): void {
		$schema = $this->schema(
			array(
				array(
					'key'    => 'a',
					'title'  => 'A',
					'fields' => array( array( 'key' => 'x', 'type' => 'text', 'label' => 'X' ) ),
				),
			)
		);

		$this->expectException( SchemaException::class );
		$this->expectExceptionMessage( '__type' );

		$this->checker->check( $schema );
	}

	public function test_rejects_condition_pointing_at_unknown_field(): void {
		$schema = $this->schema(
			array(
				array(
					'key'    => 'a',
					'title'  => 'A',
					'fields' => array( $this->type_field() ),
				),
				array(
					'key'       => 'b',
					'title'     => 'B',
					'condition' => array( 'field' => 'nieistnieje', 'operator' => 'equals', 'value' => 'x' ),
					'fields'    => array( array( 'key' => 'x', 'type' => 'text', 'label' => 'X' ) ),
				),
			)
		);

		$this->expectException( SchemaException::class );
		$this->expectExceptionMessage( 'nieistnieje' );

		$this->checker->check( $schema );
	}

	public function test_rejects_condition_pointing_at_later_field(): void {
		$schema = $this->schema(
			array(
				array(
					'key'       => 'a',
					'title'     => 'A',
					'condition' => array( 'field' => 'pozniejsze', 'operator' => 'equals', 'value' => 'x' ),
					'fields'    => array( $this->type_field() ),
				),
				array(
					'key'    => 'b',
					'title'  => 'B',
					'fields' => array( array( 'key' => 'pozniejsze', 'type' => 'text', 'label' => 'P' ) ),
				),
			)
		);

		$this->expectException( SchemaException::class );
		$this->expectExceptionMessage( 'pozniejsze' );

		$this->checker->check( $schema );
	}
}
```

- [ ] **Step 2: Napisz failujące testy widoczności**

`tests/unit/Domain/Schema/VisibilityResolverTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Schema;

use EvReg\Domain\Conditions\ConditionEngine;
use EvReg\Domain\Schema\FormSchema;
use EvReg\Domain\Schema\VisibilityResolver;
use PHPUnit\Framework\TestCase;

final class VisibilityResolverTest extends TestCase {

	private VisibilityResolver $resolver;

	protected function setUp(): void {
		$this->resolver = new VisibilityResolver( new ConditionEngine() );
	}

	private function schema(): FormSchema {
		return FormSchema::fromArray(
			array(
				'version'  => 1,
				'sections' => array(
					array(
						'key'    => 'dane',
						'title'  => 'Dane',
						'fields' => array(
							array(
								'key'     => '__type',
								'type'    => 'radio',
								'label'   => 'Typ',
								'options' => array(
									array( 'value' => 'uczestnik', 'label' => 'Uczestnik' ),
									array( 'value' => 'online', 'label' => 'Online' ),
								),
							),
						),
					),
					array(
						'key'       => 'noclegi',
						'title'     => 'Noclegi',
						'condition' => array( 'field' => '__type', 'operator' => 'in', 'value' => array( 'uczestnik' ) ),
						'fields'    => array(
							array( 'key' => 'nocleg', 'type' => 'accommodation', 'label' => 'Nocleg' ),
							array(
								'key'       => 'uwagi_hotel',
								'type'      => 'textarea',
								'label'     => 'Uwagi',
								'condition' => array( 'field' => 'nocleg', 'operator' => 'not_empty' ),
							),
						),
					),
				),
			)
		);
	}

	public function test_section_hidden_when_condition_not_met(): void {
		$set = $this->resolver->resolve( $this->schema(), array( '__type' => 'online' ) );

		$this->assertTrue( $set->isSectionVisible( 'dane' ) );
		$this->assertFalse( $set->isSectionVisible( 'noclegi' ) );
		$this->assertFalse( $set->isFieldVisible( 'nocleg' ) );
	}

	public function test_field_hidden_when_own_condition_not_met(): void {
		$set = $this->resolver->resolve( $this->schema(), array( '__type' => 'uczestnik' ) );

		$this->assertTrue( $set->isFieldVisible( 'nocleg' ) );
		$this->assertFalse( $set->isFieldVisible( 'uwagi_hotel' ) );
	}

	public function test_field_visible_when_both_conditions_met(): void {
		$set = $this->resolver->resolve(
			$this->schema(),
			array(
				'__type' => 'uczestnik',
				'nocleg' => array( 'package' => 'n12', 'room' => 'single' ),
			)
		);

		$this->assertTrue( $set->isFieldVisible( 'uwagi_hotel' ) );
	}

	public function test_visible_fields_returns_only_visible(): void {
		$set  = $this->resolver->resolve( $this->schema(), array( '__type' => 'online' ) );
		$keys = array_map( static fn ( $field ) => $field->key, $set->visibleFields() );

		$this->assertSame( array( '__type' ), $keys );
	}
}
```

- [ ] **Step 3: Uruchom oba testy i potwierdź fail**

```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
```

Oczekiwane: FAIL — brakujące klasy `SchemaIntegrityChecker` i `VisibilityResolver`.

- [ ] **Step 4: Zaimplementuj `SchemaIntegrityChecker`**

`src/Domain/Schema/SchemaIntegrityChecker.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Domain\Schema;

final class SchemaIntegrityChecker {

	public function check( FormSchema $schema ): void {
		$this->assertUniqueKeys( $schema );
		$this->assertTypeFieldPresent( $schema );
		$this->assertConditionsResolvable( $schema );
	}

	private function assertUniqueKeys( FormSchema $schema ): void {
		$section_keys = array();
		$field_keys   = array();

		foreach ( $schema->sections() as $section ) {
			if ( in_array( $section->key, $section_keys, true ) ) {
				throw new SchemaException( sprintf( 'Zduplikowany klucz sekcji: "%s".', $section->key ) );
			}

			$section_keys[] = $section->key;

			foreach ( $section->fields as $field ) {
				if ( in_array( $field->key, $field_keys, true ) ) {
					throw new SchemaException( sprintf( 'Zduplikowany klucz pola: "%s".', $field->key ) );
				}

				$field_keys[] = $field->key;
			}
		}
	}

	private function assertTypeFieldPresent( FormSchema $schema ): void {
		$field = $schema->findField( FormSchema::TYPE_FIELD_KEY );

		if ( null === $field ) {
			throw new SchemaException(
				sprintf( 'Schema musi zawierać pole "%s".', FormSchema::TYPE_FIELD_KEY )
			);
		}

		if ( ! in_array( $field->type, array( FieldType::Radio, FieldType::Select ), true ) ) {
			throw new SchemaException(
				sprintf( 'Pole "%s" musi być typu radio lub select.', FormSchema::TYPE_FIELD_KEY )
			);
		}
	}

	/**
	 * Warunek może wskazywać wyłącznie na pole zadeklarowane wcześniej.
	 */
	private function assertConditionsResolvable( FormSchema $schema ): void {
		$declared = array();

		foreach ( $schema->sections() as $section ) {
			if ( null !== $section->condition && ! in_array( $section->condition->field, $declared, true ) ) {
				throw new SchemaException(
					sprintf(
						'Warunek sekcji "%s" wskazuje na pole "%s", które nie występuje wcześniej.',
						$section->key,
						$section->condition->field
					)
				);
			}

			foreach ( $section->fields as $field ) {
				if ( null !== $field->condition && ! in_array( $field->condition->field, $declared, true ) ) {
					throw new SchemaException(
						sprintf(
							'Warunek pola "%s" wskazuje na pole "%s", które nie występuje wcześniej.',
							$field->key,
							$field->condition->field
						)
					);
				}

				$declared[] = $field->key;
			}
		}
	}
}
```

- [ ] **Step 5: Zaimplementuj `VisibilitySet` i `VisibilityResolver`**

`src/Domain/Schema/VisibilitySet.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Domain\Schema;

final class VisibilitySet {

	/**
	 * @param string[] $visibleSections
	 * @param Field[]  $visibleFields
	 */
	public function __construct(
		private readonly array $visibleSections,
		private readonly array $visibleFields
	) {
	}

	public function isSectionVisible( string $key ): bool {
		return in_array( $key, $this->visibleSections, true );
	}

	public function isFieldVisible( string $key ): bool {
		foreach ( $this->visibleFields as $field ) {
			if ( $field->key === $key ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return Field[]
	 */
	public function visibleFields(): array {
		return $this->visibleFields;
	}
}
```

`src/Domain/Schema/VisibilityResolver.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Domain\Schema;

use EvReg\Domain\Conditions\ConditionEngine;

final class VisibilityResolver {

	public function __construct( private readonly ConditionEngine $engine ) {
	}

	/**
	 * @param array<string,mixed> $answers
	 */
	public function resolve( FormSchema $schema, array $answers ): VisibilitySet {
		$sections = array();
		$fields   = array();

		foreach ( $schema->sections() as $section ) {
			if ( ! $this->engine->isMet( $section->condition, $answers ) ) {
				continue;
			}

			$sections[] = $section->key;

			foreach ( $section->fields as $field ) {
				if ( ! $this->engine->isMet( $field->condition, $answers ) ) {
					continue;
				}

				$fields[] = $field;
			}
		}

		return new VisibilitySet( $sections, $fields );
	}
}
```

- [ ] **Step 6: Uruchom testy**

```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
```

Oczekiwane: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Domain/Schema tests/unit/Domain/Schema
git commit -m "feat: add schema integrity checks and visibility resolution"
```

---

## Task 6: Walidacja odpowiedzi

**Files:**
- Create: `src/Domain/Validation/FieldValidator.php`
- Create: `src/Domain/Validation/FieldOutcome.php`
- Create: `src/Domain/Validation/Validators/TextValidator.php`
- Create: `src/Domain/Validation/Validators/EmailValidator.php`
- Create: `src/Domain/Validation/Validators/TelValidator.php`
- Create: `src/Domain/Validation/Validators/NumberValidator.php`
- Create: `src/Domain/Validation/Validators/DateValidator.php`
- Create: `src/Domain/Validation/Validators/ChoiceValidator.php`
- Create: `src/Domain/Validation/Validators/MultiChoiceValidator.php`
- Create: `src/Domain/Validation/Validators/BooleanValidator.php`
- Create: `src/Domain/Validation/FieldValidatorRegistry.php`
- Create: `src/Domain/Validation/ValidationResult.php`
- Create: `src/Domain/Validation/Validator.php`
- Test: `tests/unit/Domain/Validation/ValidatorTest.php`

**Interfaces:**
- Consumes: `FormSchema`, `Field`, `FieldType`, `VisibilityResolver`
- Produces:
  - `FieldOutcome` — `::valid(mixed $value): self`, `::error(string $code): self`, właściwości `value`, `errorCode` (`?string`), `isValid(): bool`
  - `FieldValidator` (interface) — `validate(Field $field, mixed $raw): FieldOutcome`
  - `FieldValidatorRegistry` — `__construct()` rejestruje domyślne walidatory; `register(FieldType $type, FieldValidator $validator): void`; `for(FieldType $type): ?FieldValidator`
  - `ValidationResult` — `isValid(): bool`, `errors(): array<string,string>` (klucz pola → kod błędu), `values(): array<string,mixed>`
  - `Validator::__construct(VisibilityResolver $visibility, FieldValidatorRegistry $registry)`, `validate(FormSchema $schema, array $answers): ValidationResult`

**Kody błędów:** `required`, `invalid_email`, `invalid_tel`, `invalid_number`, `invalid_date`, `not_in_options`, `too_long`.

**Zasady:**
- Walidowane są wyłącznie pola widoczne i zbierające dane (`FieldType::isInput()`).
- Pola niewidoczne nie trafiają do `values()` — nawet jeśli przyszły w `$answers`.
- Pole bez zarejestrowanego walidatora (`accommodation` do Taska 8) jest przepuszczane bez zmian.
- Tekst dłuższy niż 5000 znaków → `too_long`.

- [ ] **Step 1: Napisz failujące testy**

`tests/unit/Domain/Validation/ValidatorTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Validation;

use EvReg\Domain\Conditions\ConditionEngine;
use EvReg\Domain\Schema\FormSchema;
use EvReg\Domain\Schema\VisibilityResolver;
use EvReg\Domain\Validation\FieldValidatorRegistry;
use EvReg\Domain\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase {

	private Validator $validator;

	protected function setUp(): void {
		$this->validator = new Validator(
			new VisibilityResolver( new ConditionEngine() ),
			new FieldValidatorRegistry()
		);
	}

	private function schema(): FormSchema {
		return FormSchema::fromArray(
			array(
				'version'  => 1,
				'sections' => array(
					array(
						'key'    => 'dane',
						'title'  => 'Dane',
						'fields' => array(
							array(
								'key'     => '__type',
								'type'    => 'radio',
								'label'   => 'Typ',
								'options' => array(
									array( 'value' => 'uczestnik', 'label' => 'Uczestnik' ),
									array( 'value' => 'online', 'label' => 'Online' ),
								),
							),
							array( 'key' => 'imie', 'type' => 'text', 'label' => 'Imię', 'required' => true ),
							array( 'key' => 'email', 'type' => 'email', 'label' => 'E-mail', 'required' => true ),
							array( 'key' => 'tel', 'type' => 'tel', 'label' => 'Telefon' ),
							array( 'key' => 'wiek', 'type' => 'number', 'label' => 'Wiek' ),
							array(
								'key'     => 'atrakcje',
								'type'    => 'checkbox-group',
								'label'   => 'Atrakcje',
								'options' => array(
									array( 'value' => 'kolacja', 'label' => 'Kolacja' ),
									array( 'value' => 'zwiedzanie', 'label' => 'Zwiedzanie' ),
								),
							),
							array( 'key' => 'zgoda', 'type' => 'checkbox', 'label' => 'Zgoda', 'required' => true ),
						),
					),
					array(
						'key'       => 'stacjonarne',
						'title'     => 'Stacjonarne',
						'condition' => array( 'field' => '__type', 'operator' => 'in', 'value' => array( 'uczestnik' ) ),
						'fields'    => array(
							array( 'key' => 'dojazd', 'type' => 'text', 'label' => 'Dojazd', 'required' => true ),
						),
					),
				),
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function valid_answers(): array {
		return array(
			'__type'   => 'uczestnik',
			'imie'     => '  Jan Kowalski  ',
			'email'    => 'jan@example.com',
			'tel'      => '+48 600 100 200',
			'wiek'     => '34',
			'atrakcje' => array( 'kolacja' ),
			'zgoda'    => '1',
			'dojazd'   => 'samochód',
		);
	}

	public function test_accepts_valid_answers_and_trims_text(): void {
		$result = $this->validator->validate( $this->schema(), $this->valid_answers() );

		$this->assertTrue( $result->isValid(), print_r( $result->errors(), true ) );
		$this->assertSame( 'Jan Kowalski', $result->values()['imie'] );
		$this->assertSame( 34, $result->values()['wiek'] );
		$this->assertTrue( $result->values()['zgoda'] );
	}

	public function test_reports_missing_required_field(): void {
		$answers = $this->valid_answers();
		unset( $answers['imie'] );

		$result = $this->validator->validate( $this->schema(), $answers );

		$this->assertFalse( $result->isValid() );
		$this->assertSame( 'required', $result->errors()['imie'] );
	}

	public function test_reports_invalid_email(): void {
		$answers          = $this->valid_answers();
		$answers['email'] = 'jan[at]example.com';

		$result = $this->validator->validate( $this->schema(), $answers );

		$this->assertSame( 'invalid_email', $result->errors()['email'] );
	}

	public function test_reports_value_outside_options(): void {
		$answers             = $this->valid_answers();
		$answers['atrakcje'] = array( 'kolacja', 'nurkowanie' );

		$result = $this->validator->validate( $this->schema(), $answers );

		$this->assertSame( 'not_in_options', $result->errors()['atrakcje'] );
	}

	public function test_hidden_fields_are_not_required(): void {
		$answers           = $this->valid_answers();
		$answers['__type'] = 'online';
		unset( $answers['dojazd'] );

		$result = $this->validator->validate( $this->schema(), $answers );

		$this->assertTrue( $result->isValid(), print_r( $result->errors(), true ) );
	}

	public function test_hidden_field_values_are_discarded(): void {
		$answers           = $this->valid_answers();
		$answers['__type'] = 'online';
		$answers['dojazd'] = 'przemycone';

		$result = $this->validator->validate( $this->schema(), $answers );

		$this->assertArrayNotHasKey( 'dojazd', $result->values() );
	}

	public function test_rejects_text_over_limit(): void {
		$answers         = $this->valid_answers();
		$answers['imie'] = str_repeat( 'a', 5001 );

		$result = $this->validator->validate( $this->schema(), $answers );

		$this->assertSame( 'too_long', $result->errors()['imie'] );
	}

	public function test_unchecked_required_consent_is_error(): void {
		$answers          = $this->valid_answers();
		$answers['zgoda'] = '';

		$result = $this->validator->validate( $this->schema(), $answers );

		$this->assertSame( 'required', $result->errors()['zgoda'] );
	}
}
```

- [ ] **Step 2: Uruchom i potwierdź fail**

```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit --filter ValidatorTest
```

Oczekiwane: FAIL — `Class "EvReg\Domain\Validation\Validator" not found`.

- [ ] **Step 3: Zaimplementuj `FieldOutcome` i interfejs**

`src/Domain/Validation/FieldOutcome.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Domain\Validation;

final class FieldOutcome {

	private function __construct(
		public readonly mixed $value,
		public readonly ?string $errorCode
	) {
	}

	public static function valid( mixed $value ): self {
		return new self( $value, null );
	}

	public static function error( string $code ): self {
		return new self( null, $code );
	}

	public function isValid(): bool {
		return null === $this->errorCode;
	}
}
```

`src/Domain/Validation/FieldValidator.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Domain\Validation;

use EvReg\Domain\Schema\Field;

interface FieldValidator {

	public function validate( Field $field, mixed $raw ): FieldOutcome;
}
```

- [ ] **Step 4: Zaimplementuj walidatory per typ**

`src/Domain/Validation/Validators/TextValidator.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Domain\Validation\Validators;

use EvReg\Domain\Schema\Field;
use EvReg\Domain\Validation\FieldOutcome;
use EvReg\Domain\Validation\FieldValidator;

final class TextValidator implements FieldValidator {

	public const MAX_LENGTH = 5000;

	public function validate( Field $field, mixed $raw ): FieldOutcome {
		$value = is_array( $raw ) ? '' : trim( (string) $raw );

		if ( mb_strlen( $value ) > self::MAX_LENGTH ) {
			return FieldOutcome::error( 'too_long' );
		}

		return FieldOutcome::valid( $value );
	}
}
```

`src/Domain/Validation/Validators/EmailValidator.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Domain\Validation\Validators;

use EvReg\Domain\Schema\Field;
use EvReg\Domain\Validation\FieldOutcome;
use EvReg\Domain\Validation\FieldValidator;

final class EmailValidator implements FieldValidator {

	public function validate( Field $field, mixed $raw ): FieldOutcome {
		$value = is_array( $raw ) ? '' : trim( (string) $raw );

		if ( '' === $value ) {
			return FieldOutcome::valid( '' );
		}

		if ( ! filter_var( $value, FILTER_VALIDATE_EMAIL ) ) {
			return FieldOutcome::error( 'invalid_email' );
		}

		return FieldOutcome::valid( mb_strtolower( $value ) );
	}
}
```

`src/Domain/Validation/Validators/TelValidator.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Domain\Validation\Validators;

use EvReg\Domain\Schema\Field;
use EvReg\Domain\Validation\FieldOutcome;
use EvReg\Domain\Validation\FieldValidator;

final class TelValidator implements FieldValidator {

	public function validate( Field $field, mixed $raw ): FieldOutcome {
		$value = is_array( $raw ) ? '' : trim( (string) $raw );

		if ( '' === $value ) {
			return FieldOutcome::valid( '' );
		}

		$normalized = preg_replace( '/[\s\-()]/', '', $value ) ?? '';

		if ( 1 !== preg_match( '/^\+?\d{6,15}$/', $normalized ) ) {
			return FieldOutcome::error( 'invalid_tel' );
		}

		return FieldOutcome::valid( $normalized );
	}
}
```

`src/Domain/Validation/Validators/NumberValidator.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Domain\Validation\Validators;

use EvReg\Domain\Schema\Field;
use EvReg\Domain\Validation\FieldOutcome;
use EvReg\Domain\Validation\FieldValidator;

final class NumberValidator implements FieldValidator {

	public function validate( Field $field, mixed $raw ): FieldOutcome {
		$value = is_array( $raw ) ? '' : trim( (string) $raw );

		if ( '' === $value ) {
			return FieldOutcome::valid( null );
		}

		if ( 1 !== preg_match( '/^-?\d+$/', $value ) ) {
			return FieldOutcome::error( 'invalid_number' );
		}

		return FieldOutcome::valid( (int) $value );
	}
}
```

`src/Domain/Validation/Validators/DateValidator.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Domain\Validation\Validators;

use DateTimeImmutable;
use EvReg\Domain\Schema\Field;
use EvReg\Domain\Validation\FieldOutcome;
use EvReg\Domain\Validation\FieldValidator;

final class DateValidator implements FieldValidator {

	public function validate( Field $field, mixed $raw ): FieldOutcome {
		$value = is_array( $raw ) ? '' : trim( (string) $raw );

		if ( '' === $value ) {
			return FieldOutcome::valid( '' );
		}

		$date = DateTimeImmutable::createFromFormat( 'Y-m-d', $value );

		if ( false === $date || $date->format( 'Y-m-d' ) !== $value ) {
			return FieldOutcome::error( 'invalid_date' );
		}

		return FieldOutcome::valid( $value );
	}
}
```

`src/Domain/Validation/Validators/ChoiceValidator.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Domain\Validation\Validators;

use EvReg\Domain\Schema\Field;
use EvReg\Domain\Schema\Option;
use EvReg\Domain\Validation\FieldOutcome;
use EvReg\Domain\Validation\FieldValidator;

final class ChoiceValidator implements FieldValidator {

	public function validate( Field $field, mixed $raw ): FieldOutcome {
		$value = is_array( $raw ) ? '' : trim( (string) $raw );

		if ( '' === $value ) {
			return FieldOutcome::valid( '' );
		}

		$allowed = array_map(
			static fn ( Option $option ): string => $option->value,
			$field->options
		);

		if ( ! in_array( $value, $allowed, true ) ) {
			return FieldOutcome::error( 'not_in_options' );
		}

		return FieldOutcome::valid( $value );
	}
}
```

`src/Domain/Validation/Validators/MultiChoiceValidator.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Domain\Validation\Validators;

use EvReg\Domain\Schema\Field;
use EvReg\Domain\Schema\Option;
use EvReg\Domain\Validation\FieldOutcome;
use EvReg\Domain\Validation\FieldValidator;

final class MultiChoiceValidator implements FieldValidator {

	public function validate( Field $field, mixed $raw ): FieldOutcome {
		if ( null === $raw || '' === $raw ) {
			return FieldOutcome::valid( array() );
		}

		$values = array_map(
			static fn ( $item ): string => trim( (string) $item ),
			is_array( $raw ) ? $raw : array( $raw )
		);

		$allowed = array_map(
			static fn ( Option $option ): string => $option->value,
			$field->options
		);

		foreach ( $values as $value ) {
			if ( ! in_array( $value, $allowed, true ) ) {
				return FieldOutcome::error( 'not_in_options' );
			}
		}

		return FieldOutcome::valid( array_values( array_unique( $values ) ) );
	}
}
```

`src/Domain/Validation/Validators/BooleanValidator.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Domain\Validation\Validators;

use EvReg\Domain\Schema\Field;
use EvReg\Domain\Validation\FieldOutcome;
use EvReg\Domain\Validation\FieldValidator;

final class BooleanValidator implements FieldValidator {

	public function validate( Field $field, mixed $raw ): FieldOutcome {
		if ( is_array( $raw ) ) {
			return FieldOutcome::valid( array() !== $raw );
		}

		return FieldOutcome::valid( in_array( (string) $raw, array( '1', 'true', 'on', 'yes' ), true ) );
	}
}
```

- [ ] **Step 5: Zaimplementuj rejestr, wynik i `Validator`**

`src/Domain/Validation/FieldValidatorRegistry.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Domain\Validation;

use EvReg\Domain\Schema\FieldType;
use EvReg\Domain\Validation\Validators\BooleanValidator;
use EvReg\Domain\Validation\Validators\ChoiceValidator;
use EvReg\Domain\Validation\Validators\DateValidator;
use EvReg\Domain\Validation\Validators\EmailValidator;
use EvReg\Domain\Validation\Validators\MultiChoiceValidator;
use EvReg\Domain\Validation\Validators\NumberValidator;
use EvReg\Domain\Validation\Validators\TelValidator;
use EvReg\Domain\Validation\Validators\TextValidator;

final class FieldValidatorRegistry {

	/** @var array<string,FieldValidator> */
	private array $validators = array();

	public function __construct() {
		$text   = new TextValidator();
		$choice = new ChoiceValidator();

		$this->register( FieldType::Text, $text );
		$this->register( FieldType::Textarea, $text );
		$this->register( FieldType::Hidden, $text );
		$this->register( FieldType::Email, new EmailValidator() );
		$this->register( FieldType::Tel, new TelValidator() );
		$this->register( FieldType::Number, new NumberValidator() );
		$this->register( FieldType::Date, new DateValidator() );
		$this->register( FieldType::Select, $choice );
		$this->register( FieldType::Radio, $choice );
		$this->register( FieldType::CheckboxGroup, new MultiChoiceValidator() );
		$this->register( FieldType::Checkbox, new BooleanValidator() );
	}

	public function register( FieldType $type, FieldValidator $validator ): void {
		$this->validators[ $type->value ] = $validator;
	}

	public function for( FieldType $type ): ?FieldValidator {
		return $this->validators[ $type->value ] ?? null;
	}
}
```

`src/Domain/Validation/ValidationResult.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Domain\Validation;

final class ValidationResult {

	/**
	 * @param array<string,string> $errors Klucz pola → kod błędu.
	 * @param array<string,mixed>  $values Znormalizowane wartości pól widocznych.
	 */
	public function __construct(
		private readonly array $errors,
		private readonly array $values
	) {
	}

	public function isValid(): bool {
		return array() === $this->errors;
	}

	/**
	 * @return array<string,string>
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function values(): array {
		return $this->values;
	}
}
```

`src/Domain/Validation/Validator.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Domain\Validation;

use EvReg\Domain\Schema\FormSchema;
use EvReg\Domain\Schema\VisibilityResolver;

final class Validator {

	public function __construct(
		private readonly VisibilityResolver $visibility,
		private readonly FieldValidatorRegistry $registry
	) {
	}

	/**
	 * @param array<string,mixed> $answers
	 */
	public function validate( FormSchema $schema, array $answers ): ValidationResult {
		$errors = array();
		$values = array();

		foreach ( $this->visibility->resolve( $schema, $answers )->visibleFields() as $field ) {
			if ( ! $field->type->isInput() ) {
				continue;
			}

			$raw       = $answers[ $field->key ] ?? null;
			$validator = $this->registry->for( $field->type );

			$outcome = null === $validator
				? FieldOutcome::valid( $raw )
				: $validator->validate( $field, $raw );

			if ( ! $outcome->isValid() ) {
				$errors[ $field->key ] = (string) $outcome->errorCode;
				continue;
			}

			if ( $field->required && $this->isBlank( $outcome->value ) ) {
				$errors[ $field->key ] = 'required';
				continue;
			}

			$values[ $field->key ] = $outcome->value;
		}

		return new ValidationResult( $errors, $values );
	}

	private function isBlank( mixed $value ): bool {
		if ( is_array( $value ) ) {
			return array() === $value;
		}

		if ( is_bool( $value ) ) {
			return false === $value;
		}

		// Wartości obiektowe (np. wybór noclegu) nigdy nie są puste — pusty wybór to null.
		if ( is_object( $value ) ) {
			return false;
		}

		return null === $value || '' === (string) $value;
	}
}
```

- [ ] **Step 6: Uruchom testy**

```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
```

Oczekiwane: PASS, 8 testów w `ValidatorTest`.

- [ ] **Step 7: Commit**

```bash
git add src/Domain/Validation tests/unit/Domain/Validation
git commit -m "feat: add answer validation with per-field-type validators"
```

---

## Task 7: Typy zgłoszenia

**Files:**
- Create: `src/Domain/Registration/RegistrationType.php`
- Create: `src/Domain/Registration/RegistrationTypeCollection.php`
- Test: `tests/unit/Domain/Registration/RegistrationTypeCollectionTest.php`

**Interfaces:**
- Consumes: `SchemaException`
- Produces:
  - `RegistrationType` — `__construct(string $key, string $label, float $price, ?int $capacity, bool $active)`, `fromArray(array): self`, `toArray(): array`
  - `RegistrationTypeCollection` — `fromArray(array $list): self`, `toArray(): array`, `get(string $key): ?RegistrationType`, `has(string $key): bool`, `active(): RegistrationType[]`, `all(): RegistrationType[]`, `capacities(): array<string,?int>`

- [ ] **Step 1: Napisz failujące testy**

`tests/unit/Domain/Registration/RegistrationTypeCollectionTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Registration;

use EvReg\Domain\Registration\RegistrationTypeCollection;
use EvReg\Domain\Schema\SchemaException;
use PHPUnit\Framework\TestCase;

final class RegistrationTypeCollectionTest extends TestCase {

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function raw(): array {
		return array(
			array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 450.0, 'capacity' => 100 ),
			array( 'key' => 'online', 'label' => 'Uczestnik on-line', 'price' => 150.0, 'capacity' => null ),
			array( 'key' => 'wykladowca', 'label' => 'Wykładowca', 'price' => 0.0, 'capacity' => 12, 'active' => false ),
		);
	}

	public function test_parses_types(): void {
		$types = RegistrationTypeCollection::fromArray( $this->raw() );

		$this->assertTrue( $types->has( 'uczestnik' ) );
		$this->assertSame( 450.0, $types->get( 'uczestnik' )->price );
		$this->assertNull( $types->get( 'online' )->capacity );
		$this->assertCount( 3, $types->all() );
	}

	public function test_active_excludes_inactive_types(): void {
		$types = RegistrationTypeCollection::fromArray( $this->raw() );
		$keys  = array_map( static fn ( $type ) => $type->key, $types->active() );

		$this->assertSame( array( 'uczestnik', 'online' ), $keys );
	}

	public function test_capacities_maps_key_to_limit(): void {
		$types = RegistrationTypeCollection::fromArray( $this->raw() );

		$this->assertSame(
			array( 'uczestnik' => 100, 'online' => null, 'wykladowca' => 12 ),
			$types->capacities()
		);
	}

	public function test_rejects_duplicate_keys(): void {
		$raw   = $this->raw();
		$raw[] = array( 'key' => 'uczestnik', 'label' => 'Duplikat', 'price' => 1.0 );

		$this->expectException( SchemaException::class );

		RegistrationTypeCollection::fromArray( $raw );
	}

	public function test_rejects_negative_price(): void {
		$this->expectException( SchemaException::class );

		RegistrationTypeCollection::fromArray(
			array( array( 'key' => 'x', 'label' => 'X', 'price' => -1.0 ) )
		);
	}

	public function test_round_trips(): void {
		$types = RegistrationTypeCollection::fromArray( $this->raw() );

		$this->assertSame( 'wykladowca', $types->toArray()[2]['key'] );
		$this->assertFalse( $types->toArray()[2]['active'] );
	}
}
```

- [ ] **Step 2: Uruchom i potwierdź fail**

```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit --filter RegistrationTypeCollectionTest
```

Oczekiwane: FAIL — brak klasy.

- [ ] **Step 3: Zaimplementuj `RegistrationType`**

```php
<?php

declare( strict_types=1 );

namespace EvReg\Domain\Registration;

use EvReg\Domain\Schema\SchemaException;

final class RegistrationType {

	public function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly float $price = 0.0,
		public readonly ?int $capacity = null,
		public readonly bool $active = true
	) {
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function fromArray( array $data ): self {
		if ( ! isset( $data['key'] ) || '' === (string) $data['key'] ) {
			throw new SchemaException( 'Typ zgłoszenia wymaga niepustego klucza.' );
		}

		$price = (float) ( $data['price'] ?? 0 );

		if ( $price < 0 ) {
			throw new SchemaException(
				sprintf( 'Cena typu "%s" nie może być ujemna.', (string) $data['key'] )
			);
		}

		$capacity = $data['capacity'] ?? null;

		if ( null !== $capacity && (int) $capacity < 0 ) {
			throw new SchemaException(
				sprintf( 'Limit typu "%s" nie może być ujemny.', (string) $data['key'] )
			);
		}

		return new self(
			(string) $data['key'],
			(string) ( $data['label'] ?? $data['key'] ),
			$price,
			null === $capacity ? null : (int) $capacity,
			(bool) ( $data['active'] ?? true )
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'key'      => $this->key,
			'label'    => $this->label,
			'price'    => $this->price,
			'capacity' => $this->capacity,
			'active'   => $this->active,
		);
	}
}
```

- [ ] **Step 4: Zaimplementuj `RegistrationTypeCollection`**

```php
<?php

declare( strict_types=1 );

namespace EvReg\Domain\Registration;

use EvReg\Domain\Schema\SchemaException;

final class RegistrationTypeCollection {

	/**
	 * @param RegistrationType[] $types
	 */
	private function __construct( private readonly array $types ) {
	}

	/**
	 * @param array<int,array<string,mixed>> $list
	 */
	public static function fromArray( array $list ): self {
		$types = array();
		$keys  = array();

		foreach ( $list as $item ) {
			$type = RegistrationType::fromArray( (array) $item );

			if ( in_array( $type->key, $keys, true ) ) {
				throw new SchemaException( sprintf( 'Zduplikowany typ zgłoszenia: "%s".', $type->key ) );
			}

			$keys[]  = $type->key;
			$types[] = $type;
		}

		return new self( $types );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function toArray(): array {
		return array_map(
			static fn ( RegistrationType $type ): array => $type->toArray(),
			$this->types
		);
	}

	/**
	 * @return RegistrationType[]
	 */
	public function all(): array {
		return $this->types;
	}

	/**
	 * @return RegistrationType[]
	 */
	public function active(): array {
		return array_values(
			array_filter( $this->types, static fn ( RegistrationType $type ): bool => $type->active )
		);
	}

	public function get( string $key ): ?RegistrationType {
		foreach ( $this->types as $type ) {
			if ( $type->key === $key ) {
				return $type;
			}
		}

		return null;
	}

	public function has( string $key ): bool {
		return null !== $this->get( $key );
	}

	/**
	 * @return array<string,int|null>
	 */
	public function capacities(): array {
		$map = array();

		foreach ( $this->types as $type ) {
			$map[ $type->key ] = $type->capacity;
		}

		return $map;
	}
}
```

- [ ] **Step 5: Uruchom testy**

```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
```

Oczekiwane: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Domain/Registration tests/unit/Domain/Registration
git commit -m "feat: add registration types with prices and per-type capacity"
```

---

## Task 8: Konfiguracja i walidacja noclegów

**Files:**
- Create: `src/Domain/Accommodation/Package.php`
- Create: `src/Domain/Accommodation/RoomType.php`
- Create: `src/Domain/Accommodation/InventoryItem.php`
- Create: `src/Domain/Accommodation/AccommodationConfig.php`
- Create: `src/Domain/Accommodation/AccommodationSelection.php`
- Create: `src/Domain/Validation/Validators/AccommodationValidator.php`
- Modify: `src/Domain/Validation/FieldValidatorRegistry.php` (rejestracja walidatora noclegu)
- Test: `tests/unit/Domain/Accommodation/AccommodationConfigTest.php`
- Test: `tests/unit/Domain/Validation/AccommodationValidatorTest.php`

**Interfaces:**
- Consumes: `Field`, `FieldOutcome`, `FieldValidator`, `SchemaException`
- Produces:
  - `Package` — `key`, `label`
  - `RoomType` — `key`, `label`, `roommateField` (bool)
  - `InventoryItem` — `packageKey`, `roomKey`, `capacity` (int), `price` (float); `slotKey(): string` zwracający `"{packageKey}|{roomKey}"`
  - `AccommodationConfig` — `fromArray(array $config): self`, `toArray(): array`, `packages(): Package[]`, `rooms(): RoomType[]`, `room(string $key): ?RoomType`, `item(string $packageKey, string $roomKey): ?InventoryItem`, `items(): InventoryItem[]`, `allowsNone(): bool`, `capacities(): array<string,int>` (klucz `slotKey()`)
  - `AccommodationSelection` — `__construct(string $packageKey, string $roomKey, string $roommatePref = '')`, `fromArray(array): ?self` (pusty wybór → `null`), `toArray(): array`, `slotKey(): string`
  - `AccommodationValidator implements FieldValidator` — kody błędów `required`, `invalid_accommodation`, `roommate_not_allowed`

**Format odpowiedzi pola `accommodation`:** tablica `['package' => 'n12', 'room' => 'double', 'roommate' => 'Anna Nowak']`. Pusty wybór to `[]`, `''` lub brak klucza.

- [ ] **Step 1: Napisz failujące testy konfiguracji**

`tests/unit/Domain/Accommodation/AccommodationConfigTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Accommodation;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Schema\SchemaException;
use PHPUnit\Framework\TestCase;

final class AccommodationConfigTest extends TestCase {

	/**
	 * @return array<string,mixed>
	 */
	private function raw(): array {
		return array(
			'packages'   => array(
				array( 'key' => 'n12', 'label' => 'Noc 1–2' ),
				array( 'key' => 'n13', 'label' => 'Noce 1–3' ),
			),
			'rooms'      => array(
				array( 'key' => 'single', 'label' => 'Pokój 1-osobowy' ),
				array( 'key' => 'double', 'label' => 'Pokój 2-osobowy', 'roommate_field' => true ),
			),
			'inventory'  => array(
				array( 'package' => 'n12', 'room' => 'single', 'capacity' => 10, 'price' => 250.0 ),
				array( 'package' => 'n12', 'room' => 'double', 'capacity' => 20, 'price' => 180.0 ),
				array( 'package' => 'n13', 'room' => 'double', 'capacity' => 5, 'price' => 340.0 ),
			),
			'allow_none' => true,
		);
	}

	public function test_parses_config(): void {
		$config = AccommodationConfig::fromArray( $this->raw() );

		$this->assertCount( 2, $config->packages() );
		$this->assertTrue( $config->room( 'double' )->roommateField );
		$this->assertFalse( $config->room( 'single' )->roommateField );
		$this->assertTrue( $config->allowsNone() );
	}

	public function test_item_lookup_returns_price_and_capacity(): void {
		$config = AccommodationConfig::fromArray( $this->raw() );
		$item   = $config->item( 'n12', 'double' );

		$this->assertNotNull( $item );
		$this->assertSame( 20, $item->capacity );
		$this->assertSame( 180.0, $item->price );
		$this->assertSame( 'n12|double', $item->slotKey() );
	}

	public function test_item_lookup_returns_null_for_unavailable_combination(): void {
		$config = AccommodationConfig::fromArray( $this->raw() );

		$this->assertNull( $config->item( 'n13', 'single' ) );
	}

	public function test_capacities_are_keyed_by_slot(): void {
		$config = AccommodationConfig::fromArray( $this->raw() );

		$this->assertSame(
			array( 'n12|single' => 10, 'n12|double' => 20, 'n13|double' => 5 ),
			$config->capacities()
		);
	}

	public function test_rejects_inventory_pointing_at_unknown_package(): void {
		$raw                = $this->raw();
		$raw['inventory'][] = array( 'package' => 'n99', 'room' => 'single', 'capacity' => 1, 'price' => 1.0 );

		$this->expectException( SchemaException::class );
		$this->expectExceptionMessage( 'n99' );

		AccommodationConfig::fromArray( $raw );
	}

	public function test_rejects_inventory_pointing_at_unknown_room(): void {
		$raw                = $this->raw();
		$raw['inventory'][] = array( 'package' => 'n12', 'room' => 'suite', 'capacity' => 1, 'price' => 1.0 );

		$this->expectException( SchemaException::class );
		$this->expectExceptionMessage( 'suite' );

		AccommodationConfig::fromArray( $raw );
	}

	public function test_rejects_duplicate_inventory_entry(): void {
		$raw                = $this->raw();
		$raw['inventory'][] = array( 'package' => 'n12', 'room' => 'single', 'capacity' => 3, 'price' => 200.0 );

		$this->expectException( SchemaException::class );

		AccommodationConfig::fromArray( $raw );
	}
}
```

- [ ] **Step 2: Napisz failujące testy walidatora**

`tests/unit/Domain/Validation/AccommodationValidatorTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Validation;

use EvReg\Domain\Schema\Field;
use EvReg\Domain\Schema\FieldType;
use EvReg\Domain\Validation\Validators\AccommodationValidator;
use PHPUnit\Framework\TestCase;

final class AccommodationValidatorTest extends TestCase {

	private AccommodationValidator $validator;

	protected function setUp(): void {
		$this->validator = new AccommodationValidator();
	}

	private function field( bool $required = false, bool $allow_none = true ): Field {
		return new Field(
			'nocleg',
			FieldType::Accommodation,
			'Nocleg',
			$required,
			array(),
			array(
				'packages'   => array( array( 'key' => 'n12', 'label' => 'Noc 1–2' ) ),
				'rooms'      => array(
					array( 'key' => 'single', 'label' => '1-os.' ),
					array( 'key' => 'double', 'label' => '2-os.', 'roommate_field' => true ),
				),
				'inventory'  => array(
					array( 'package' => 'n12', 'room' => 'single', 'capacity' => 5, 'price' => 250.0 ),
					array( 'package' => 'n12', 'room' => 'double', 'capacity' => 5, 'price' => 180.0 ),
				),
				'allow_none' => $allow_none,
			)
		);
	}

	public function test_accepts_valid_selection(): void {
		$outcome = $this->validator->validate(
			$this->field(),
			array( 'package' => 'n12', 'room' => 'double', 'roommate' => 'Anna Nowak' )
		);

		$this->assertTrue( $outcome->isValid() );
		$this->assertSame( 'n12|double', $outcome->value->slotKey() );
		$this->assertSame( 'Anna Nowak', $outcome->value->roommatePref );
	}

	public function test_accepts_empty_selection_when_allowed(): void {
		$outcome = $this->validator->validate( $this->field(), array() );

		$this->assertTrue( $outcome->isValid() );
		$this->assertNull( $outcome->value );
	}

	public function test_rejects_empty_selection_when_not_allowed(): void {
		$outcome = $this->validator->validate( $this->field( false, false ), array() );

		$this->assertSame( 'required', $outcome->errorCode );
	}

	public function test_rejects_combination_outside_inventory(): void {
		$outcome = $this->validator->validate(
			$this->field(),
			array( 'package' => 'n12', 'room' => 'suite' )
		);

		$this->assertSame( 'invalid_accommodation', $outcome->errorCode );
	}

	public function test_rejects_roommate_for_room_without_roommate_field(): void {
		$outcome = $this->validator->validate(
			$this->field(),
			array( 'package' => 'n12', 'room' => 'single', 'roommate' => 'Anna Nowak' )
		);

		$this->assertSame( 'roommate_not_allowed', $outcome->errorCode );
	}

	/**
	 * Regresja: wybór noclegu jest obiektem, a nie skalarem — walidator całego
	 * formularza nie może rzutować go na string przy sprawdzaniu pustości.
	 */
	public function test_full_validator_accepts_accommodation_selection(): void {
		$schema = FormSchema::fromArray(
			array(
				'version'  => 1,
				'sections' => array(
					array(
						'key'    => 'dane',
						'title'  => 'Dane',
						'fields' => array(
							array(
								'key'     => '__type',
								'type'    => 'radio',
								'label'   => 'Typ',
								'options' => array( array( 'value' => 'uczestnik', 'label' => 'Uczestnik' ) ),
							),
							array(
								'key'      => 'nocleg',
								'type'     => 'accommodation',
								'label'    => 'Nocleg',
								'required' => true,
								'config'   => $this->field()->config,
							),
						),
					),
				),
			)
		);

		$validator = new Validator(
			new VisibilityResolver( new ConditionEngine() ),
			new FieldValidatorRegistry()
		);

		$result = $validator->validate(
			$schema,
			array(
				'__type' => 'uczestnik',
				'nocleg' => array( 'package' => 'n12', 'room' => 'double', 'roommate' => 'Anna Nowak' ),
			)
		);

		$this->assertTrue( $result->isValid(), print_r( $result->errors(), true ) );
		$this->assertInstanceOf( AccommodationSelection::class, $result->values()['nocleg'] );
		$this->assertSame( 'n12|double', $result->values()['nocleg']->slotKey() );
	}
}
```

Uzupełnij importy na górze tego pliku testowego:

```php
use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Domain\Conditions\ConditionEngine;
use EvReg\Domain\Schema\FormSchema;
use EvReg\Domain\Schema\VisibilityResolver;
use EvReg\Domain\Validation\FieldValidatorRegistry;
use EvReg\Domain\Validation\Validator;
```

- [ ] **Step 3: Uruchom i potwierdź fail**

```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
```

Oczekiwane: FAIL — brak klas `AccommodationConfig` i `AccommodationValidator`.

- [ ] **Step 4: Zaimplementuj wartości noclegowe**

`src/Domain/Accommodation/Package.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Domain\Accommodation;

use EvReg\Domain\Schema\SchemaException;

final class Package {

	public function __construct(
		public readonly string $key,
		public readonly string $label
	) {
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function fromArray( array $data ): self {
		if ( ! isset( $data['key'] ) || '' === (string) $data['key'] ) {
			throw new SchemaException( 'Pakiet noclegowy wymaga niepustego klucza.' );
		}

		return new self( (string) $data['key'], (string) ( $data['label'] ?? $data['key'] ) );
	}

	/**
	 * @return array<string,string>
	 */
	public function toArray(): array {
		return array(
			'key'   => $this->key,
			'label' => $this->label,
		);
	}
}
```

`src/Domain/Accommodation/RoomType.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Domain\Accommodation;

use EvReg\Domain\Schema\SchemaException;

final class RoomType {

	public function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly bool $roommateField = false
	) {
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function fromArray( array $data ): self {
		if ( ! isset( $data['key'] ) || '' === (string) $data['key'] ) {
			throw new SchemaException( 'Typ pokoju wymaga niepustego klucza.' );
		}

		return new self(
			(string) $data['key'],
			(string) ( $data['label'] ?? $data['key'] ),
			(bool) ( $data['roommate_field'] ?? false )
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'key'            => $this->key,
			'label'          => $this->label,
			'roommate_field' => $this->roommateField,
		);
	}
}
```

`src/Domain/Accommodation/InventoryItem.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Domain\Accommodation;

use EvReg\Domain\Schema\SchemaException;

final class InventoryItem {

	public function __construct(
		public readonly string $packageKey,
		public readonly string $roomKey,
		public readonly int $capacity,
		public readonly float $price
	) {
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function fromArray( array $data ): self {
		$package = (string) ( $data['package'] ?? '' );
		$room    = (string) ( $data['room'] ?? '' );

		if ( '' === $package || '' === $room ) {
			throw new SchemaException( 'Pozycja inwentarza wymaga kluczy "package" i "room".' );
		}

		$capacity = (int) ( $data['capacity'] ?? 0 );
		$price    = (float) ( $data['price'] ?? 0 );

		if ( $capacity < 0 || $price < 0 ) {
			throw new SchemaException(
				sprintf( 'Limit i cena pozycji "%s|%s" nie mogą być ujemne.', $package, $room )
			);
		}

		return new self( $package, $room, $capacity, $price );
	}

	public function slotKey(): string {
		return $this->packageKey . '|' . $this->roomKey;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'package'  => $this->packageKey,
			'room'     => $this->roomKey,
			'capacity' => $this->capacity,
			'price'    => $this->price,
		);
	}
}
```

`src/Domain/Accommodation/AccommodationSelection.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Domain\Accommodation;

final class AccommodationSelection {

	public function __construct(
		public readonly string $packageKey,
		public readonly string $roomKey,
		public readonly string $roommatePref = ''
	) {
	}

	/**
	 * Zwraca null dla pustego wyboru.
	 *
	 * @param array<string,mixed> $data
	 */
	public static function fromArray( array $data ): ?self {
		$package = trim( (string) ( $data['package'] ?? '' ) );
		$room    = trim( (string) ( $data['room'] ?? '' ) );

		if ( '' === $package || '' === $room ) {
			return null;
		}

		return new self( $package, $room, trim( (string) ( $data['roommate'] ?? '' ) ) );
	}

	public function slotKey(): string {
		return $this->packageKey . '|' . $this->roomKey;
	}

	/**
	 * @return array<string,string>
	 */
	public function toArray(): array {
		return array(
			'package'  => $this->packageKey,
			'room'     => $this->roomKey,
			'roommate' => $this->roommatePref,
		);
	}
}
```

- [ ] **Step 5: Zaimplementuj `AccommodationConfig`**

```php
<?php

declare( strict_types=1 );

namespace EvReg\Domain\Accommodation;

use EvReg\Domain\Schema\SchemaException;

final class AccommodationConfig {

	/**
	 * @param Package[]       $packages
	 * @param RoomType[]      $rooms
	 * @param InventoryItem[] $items
	 */
	private function __construct(
		private readonly array $packages,
		private readonly array $rooms,
		private readonly array $items,
		private readonly bool $allowNone
	) {
	}

	/**
	 * @param array<string,mixed> $config
	 */
	public static function fromArray( array $config ): self {
		$packages = array();

		foreach ( (array) ( $config['packages'] ?? array() ) as $package ) {
			$packages[] = Package::fromArray( (array) $package );
		}

		$rooms = array();

		foreach ( (array) ( $config['rooms'] ?? array() ) as $room ) {
			$rooms[] = RoomType::fromArray( (array) $room );
		}

		$package_keys = array_map( static fn ( Package $item ): string => $item->key, $packages );
		$room_keys    = array_map( static fn ( RoomType $item ): string => $item->key, $rooms );

		$items = array();
		$slots = array();

		foreach ( (array) ( $config['inventory'] ?? array() ) as $entry ) {
			$item = InventoryItem::fromArray( (array) $entry );

			if ( ! in_array( $item->packageKey, $package_keys, true ) ) {
				throw new SchemaException(
					sprintf( 'Inwentarz wskazuje na nieznany pakiet "%s".', $item->packageKey )
				);
			}

			if ( ! in_array( $item->roomKey, $room_keys, true ) ) {
				throw new SchemaException(
					sprintf( 'Inwentarz wskazuje na nieznany pokój "%s".', $item->roomKey )
				);
			}

			if ( in_array( $item->slotKey(), $slots, true ) ) {
				throw new SchemaException(
					sprintf( 'Zduplikowana pozycja inwentarza "%s".', $item->slotKey() )
				);
			}

			$slots[] = $item->slotKey();
			$items[] = $item;
		}

		return new self( $packages, $rooms, $items, (bool) ( $config['allow_none'] ?? true ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'packages'   => array_map( static fn ( Package $item ): array => $item->toArray(), $this->packages ),
			'rooms'      => array_map( static fn ( RoomType $item ): array => $item->toArray(), $this->rooms ),
			'inventory'  => array_map( static fn ( InventoryItem $item ): array => $item->toArray(), $this->items ),
			'allow_none' => $this->allowNone,
		);
	}

	/**
	 * @return Package[]
	 */
	public function packages(): array {
		return $this->packages;
	}

	/**
	 * @return RoomType[]
	 */
	public function rooms(): array {
		return $this->rooms;
	}

	public function room( string $key ): ?RoomType {
		foreach ( $this->rooms as $room ) {
			if ( $room->key === $key ) {
				return $room;
			}
		}

		return null;
	}

	/**
	 * @return InventoryItem[]
	 */
	public function items(): array {
		return $this->items;
	}

	public function item( string $package_key, string $room_key ): ?InventoryItem {
		foreach ( $this->items as $item ) {
			if ( $item->packageKey === $package_key && $item->roomKey === $room_key ) {
				return $item;
			}
		}

		return null;
	}

	public function allowsNone(): bool {
		return $this->allowNone;
	}

	/**
	 * @return array<string,int>
	 */
	public function capacities(): array {
		$map = array();

		foreach ( $this->items as $item ) {
			$map[ $item->slotKey() ] = $item->capacity;
		}

		return $map;
	}
}
```

- [ ] **Step 6: Zaimplementuj walidator i zarejestruj go**

`src/Domain/Validation/Validators/AccommodationValidator.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Domain\Validation\Validators;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Domain\Schema\Field;
use EvReg\Domain\Validation\FieldOutcome;
use EvReg\Domain\Validation\FieldValidator;

final class AccommodationValidator implements FieldValidator {

	public const MAX_ROOMMATE_LENGTH = 191;

	public function validate( Field $field, mixed $raw ): FieldOutcome {
		$config    = AccommodationConfig::fromArray( $field->config );
		$selection = AccommodationSelection::fromArray( is_array( $raw ) ? $raw : array() );

		if ( null === $selection ) {
			if ( ! $config->allowsNone() || $field->required ) {
				return FieldOutcome::error( 'required' );
			}

			return FieldOutcome::valid( null );
		}

		if ( null === $config->item( $selection->packageKey, $selection->roomKey ) ) {
			return FieldOutcome::error( 'invalid_accommodation' );
		}

		$room = $config->room( $selection->roomKey );

		if ( '' !== $selection->roommatePref && ( null === $room || ! $room->roommateField ) ) {
			return FieldOutcome::error( 'roommate_not_allowed' );
		}

		if ( mb_strlen( $selection->roommatePref ) > self::MAX_ROOMMATE_LENGTH ) {
			return FieldOutcome::error( 'too_long' );
		}

		return FieldOutcome::valid( $selection );
	}
}
```

W `src/Domain/Validation/FieldValidatorRegistry.php` dodaj import
`use EvReg\Domain\Validation\Validators\AccommodationValidator;` oraz w konstruktorze, po pozostałych rejestracjach:

```php
		$this->register( FieldType::Accommodation, new AccommodationValidator() );
```

- [ ] **Step 7: Uruchom testy**

```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
```

Oczekiwane: PASS.

- [ ] **Step 8: Commit**

```bash
git add src/Domain/Accommodation src/Domain/Validation tests/unit/Domain/Accommodation tests/unit/Domain/Validation
git commit -m "feat: add accommodation configuration and selection validation"
```

---

## Task 9: Kalkulator ceny

**Files:**
- Create: `src/Domain/Pricing/PriceCalculator.php`
- Test: `tests/unit/Domain/Pricing/PriceCalculatorTest.php`

**Interfaces:**
- Consumes: `RegistrationType`, `AccommodationConfig`, `AccommodationSelection`
- Produces: `PriceCalculator::total(RegistrationType $type, ?AccommodationConfig $config, ?AccommodationSelection $selection): float`

**Zasada:** cena = cena typu + cena pozycji inwentarza dla wybranej pary. Wybór spoza inwentarza liczony jako `0.0` — walidacja odrzuca go wcześniej, kalkulator nie duplikuje tej odpowiedzialności. Wynik zaokrąglany do dwóch miejsc.

- [ ] **Step 1: Napisz failujący test**

`tests/unit/Domain/Pricing/PriceCalculatorTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Pricing;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Domain\Pricing\PriceCalculator;
use EvReg\Domain\Registration\RegistrationType;
use PHPUnit\Framework\TestCase;

final class PriceCalculatorTest extends TestCase {

	private PriceCalculator $calculator;

	protected function setUp(): void {
		$this->calculator = new PriceCalculator();
	}

	private function config(): AccommodationConfig {
		return AccommodationConfig::fromArray(
			array(
				'packages'  => array( array( 'key' => 'n12', 'label' => 'Noc 1–2' ) ),
				'rooms'     => array( array( 'key' => 'double', 'label' => '2-os.' ) ),
				'inventory' => array(
					array( 'package' => 'n12', 'room' => 'double', 'capacity' => 5, 'price' => 180.50 ),
				),
			)
		);
	}

	public function test_sums_type_and_accommodation_price(): void {
		$total = $this->calculator->total(
			new RegistrationType( 'uczestnik', 'Uczestnik', 450.0 ),
			$this->config(),
			new AccommodationSelection( 'n12', 'double' )
		);

		$this->assertSame( 630.50, $total );
	}

	public function test_returns_type_price_without_accommodation(): void {
		$total = $this->calculator->total(
			new RegistrationType( 'online', 'Online', 150.0 ),
			$this->config(),
			null
		);

		$this->assertSame( 150.0, $total );
	}

	public function test_ignores_selection_outside_inventory(): void {
		$total = $this->calculator->total(
			new RegistrationType( 'uczestnik', 'Uczestnik', 450.0 ),
			$this->config(),
			new AccommodationSelection( 'n99', 'double' )
		);

		$this->assertSame( 450.0, $total );
	}

	public function test_handles_missing_accommodation_config(): void {
		$total = $this->calculator->total(
			new RegistrationType( 'wykladowca', 'Wykładowca', 0.0 ),
			null,
			null
		);

		$this->assertSame( 0.0, $total );
	}
}
```

- [ ] **Step 2: Uruchom i potwierdź fail**

```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit --filter PriceCalculatorTest
```

Oczekiwane: FAIL — brak klasy.

- [ ] **Step 3: Zaimplementuj kalkulator**

```php
<?php

declare( strict_types=1 );

namespace EvReg\Domain\Pricing;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Domain\Registration\RegistrationType;

final class PriceCalculator {

	public function total(
		RegistrationType $type,
		?AccommodationConfig $config = null,
		?AccommodationSelection $selection = null
	): float {
		$total = $type->price;

		if ( null !== $config && null !== $selection ) {
			$item = $config->item( $selection->packageKey, $selection->roomKey );

			if ( null !== $item ) {
				$total += $item->price;
			}
		}

		return round( $total, 2 );
	}
}
```

- [ ] **Step 4: Uruchom testy**

```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
```

Oczekiwane: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Domain/Pricing tests/unit/Domain/Pricing
git commit -m "feat: add price calculator combining type and accommodation cost"
```

---

## Task 10: Decyzja o limitach miejsc

**Files:**
- Create: `src/Domain/Capacity/CapacityLimits.php`
- Create: `src/Domain/Capacity/OccupancySnapshot.php`
- Create: `src/Domain/Capacity/Outcome.php`
- Create: `src/Domain/Capacity/CapacityDecision.php`
- Create: `src/Domain/Capacity/CapacityCalculator.php`
- Test: `tests/unit/Domain/Capacity/CapacityCalculatorTest.php`

**Interfaces:**
- Consumes: `AccommodationSelection`
- Produces:
  - `Outcome` (enum string): `Accepted = 'accepted'`, `Waitlisted = 'waitlisted'`, `Rejected = 'rejected'`
  - `CapacityLimits` — `__construct(?int $global, array $perType, array $perSlot, bool $waitlistEnabled)`; `perType` to `array<string,?int>`, `perSlot` to `array<string,int>` kluczowane `"pakiet|pokój"`
  - `OccupancySnapshot` — `__construct(int $global, array $perType, array $perSlot)`; gettery `global(): int`, `forType(string $key): int`, `forSlot(string $key): int`
  - `CapacityDecision` — właściwości `outcome` (`Outcome`), `accommodationGranted` (`bool`), `reason` (`?string`)
  - `CapacityCalculator::decide(CapacityLimits $limits, OccupancySnapshot $taken, string $typeKey, ?AccommodationSelection $selection): CapacityDecision`

**Reguły decyzji (kolejność ma znaczenie):**
1. Limit globalny wyczerpany → `Waitlisted` (gdy waitlist włączona) albo `Rejected`, `reason = 'event_full'`. Nocleg nieprzyznany.
2. Limit typu wyczerpany → jak wyżej, `reason = 'type_full'`.
3. Miejsca są, ale slot noclegowy wyczerpany lub nieznany → `Accepted` z `accommodationGranted = false`, `reason = 'accommodation_full'`. Zgłoszenie nie ląduje na liście rezerwowej z powodu braku pokoju.
4. Wszystko dostępne → `Accepted`, `accommodationGranted = true` (gdy wybrano nocleg), `reason = null`.
5. Limit `null` oznacza brak limitu.

- [ ] **Step 1: Napisz failujące testy**

`tests/unit/Domain/Capacity/CapacityCalculatorTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Capacity;

use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Domain\Capacity\CapacityCalculator;
use EvReg\Domain\Capacity\CapacityLimits;
use EvReg\Domain\Capacity\OccupancySnapshot;
use EvReg\Domain\Capacity\Outcome;
use PHPUnit\Framework\TestCase;

final class CapacityCalculatorTest extends TestCase {

	private CapacityCalculator $calculator;

	protected function setUp(): void {
		$this->calculator = new CapacityCalculator();
	}

	private function limits( ?int $global = 100, bool $waitlist = true ): CapacityLimits {
		return new CapacityLimits(
			$global,
			array( 'uczestnik' => 50, 'online' => null ),
			array( 'n12|double' => 20 ),
			$waitlist
		);
	}

	private function taken( int $global = 0, int $type = 0, int $slot = 0 ): OccupancySnapshot {
		return new OccupancySnapshot(
			$global,
			array( 'uczestnik' => $type ),
			array( 'n12|double' => $slot )
		);
	}

	public function test_accepts_when_everything_is_available(): void {
		$decision = $this->calculator->decide(
			$this->limits(),
			$this->taken(),
			'uczestnik',
			new AccommodationSelection( 'n12', 'double' )
		);

		$this->assertSame( Outcome::Accepted, $decision->outcome );
		$this->assertTrue( $decision->accommodationGranted );
		$this->assertNull( $decision->reason );
	}

	public function test_waitlists_when_event_is_full(): void {
		$decision = $this->calculator->decide(
			$this->limits( 100 ),
			$this->taken( 100 ),
			'uczestnik',
			new AccommodationSelection( 'n12', 'double' )
		);

		$this->assertSame( Outcome::Waitlisted, $decision->outcome );
		$this->assertFalse( $decision->accommodationGranted );
		$this->assertSame( 'event_full', $decision->reason );
	}

	public function test_rejects_when_event_full_and_waitlist_disabled(): void {
		$decision = $this->calculator->decide(
			$this->limits( 100, false ),
			$this->taken( 100 ),
			'uczestnik',
			null
		);

		$this->assertSame( Outcome::Rejected, $decision->outcome );
		$this->assertSame( 'event_full', $decision->reason );
	}

	public function test_waitlists_when_type_is_full(): void {
		$decision = $this->calculator->decide(
			$this->limits(),
			$this->taken( 10, 50 ),
			'uczestnik',
			null
		);

		$this->assertSame( Outcome::Waitlisted, $decision->outcome );
		$this->assertSame( 'type_full', $decision->reason );
	}

	public function test_accepts_without_accommodation_when_rooms_are_full(): void {
		$decision = $this->calculator->decide(
			$this->limits(),
			$this->taken( 10, 10, 20 ),
			'uczestnik',
			new AccommodationSelection( 'n12', 'double' )
		);

		$this->assertSame( Outcome::Accepted, $decision->outcome );
		$this->assertFalse( $decision->accommodationGranted );
		$this->assertSame( 'accommodation_full', $decision->reason );
	}

	public function test_accepts_when_type_has_no_limit(): void {
		$decision = $this->calculator->decide(
			$this->limits(),
			$this->taken( 10, 9999 ),
			'online',
			null
		);

		$this->assertSame( Outcome::Accepted, $decision->outcome );
	}

	public function test_accepts_when_global_limit_is_null(): void {
		$decision = $this->calculator->decide(
			$this->limits( null ),
			$this->taken( 100000 ),
			'online',
			null
		);

		$this->assertSame( Outcome::Accepted, $decision->outcome );
	}

	public function test_unknown_slot_is_not_granted(): void {
		$decision = $this->calculator->decide(
			$this->limits(),
			$this->taken(),
			'uczestnik',
			new AccommodationSelection( 'n99', 'single' )
		);

		$this->assertSame( Outcome::Accepted, $decision->outcome );
		$this->assertFalse( $decision->accommodationGranted );
		$this->assertSame( 'accommodation_full', $decision->reason );
	}
}
```

- [ ] **Step 2: Uruchom i potwierdź fail**

```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit --filter CapacityCalculatorTest
```

Oczekiwane: FAIL — brak klas.

- [ ] **Step 3: Zaimplementuj wartości limitów**

`src/Domain/Capacity/Outcome.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Domain\Capacity;

enum Outcome: string {
	case Accepted   = 'accepted';
	case Waitlisted = 'waitlisted';
	case Rejected   = 'rejected';
}
```

`src/Domain/Capacity/CapacityLimits.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Domain\Capacity;

final class CapacityLimits {

	/**
	 * @param array<string,int|null> $perType Limit per typ zgłoszenia; null = bez limitu.
	 * @param array<string,int>      $perSlot Limit per "pakiet|pokój".
	 */
	public function __construct(
		public readonly ?int $global,
		private readonly array $perType,
		private readonly array $perSlot,
		public readonly bool $waitlistEnabled = true
	) {
	}

	public function forType( string $key ): ?int {
		return $this->perType[ $key ] ?? null;
	}

	/** Zwraca null, gdy slot nie istnieje w inwentarzu. */
	public function forSlot( string $key ): ?int {
		return $this->perSlot[ $key ] ?? null;
	}
}
```

`src/Domain/Capacity/OccupancySnapshot.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Domain\Capacity;

final class OccupancySnapshot {

	/**
	 * @param array<string,int> $perType
	 * @param array<string,int> $perSlot
	 */
	public function __construct(
		private readonly int $global,
		private readonly array $perType = array(),
		private readonly array $perSlot = array()
	) {
	}

	public function global(): int {
		return $this->global;
	}

	public function forType( string $key ): int {
		return $this->perType[ $key ] ?? 0;
	}

	public function forSlot( string $key ): int {
		return $this->perSlot[ $key ] ?? 0;
	}
}
```

`src/Domain/Capacity/CapacityDecision.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Domain\Capacity;

final class CapacityDecision {

	public function __construct(
		public readonly Outcome $outcome,
		public readonly bool $accommodationGranted = false,
		public readonly ?string $reason = null
	) {
	}

	public function isAccepted(): bool {
		return Outcome::Accepted === $this->outcome;
	}
}
```

- [ ] **Step 4: Zaimplementuj `CapacityCalculator`**

```php
<?php

declare( strict_types=1 );

namespace EvReg\Domain\Capacity;

use EvReg\Domain\Accommodation\AccommodationSelection;

final class CapacityCalculator {

	public function decide(
		CapacityLimits $limits,
		OccupancySnapshot $taken,
		string $type_key,
		?AccommodationSelection $selection = null
	): CapacityDecision {
		if ( null !== $limits->global && $taken->global() >= $limits->global ) {
			return $this->full( $limits, 'event_full' );
		}

		$type_limit = $limits->forType( $type_key );

		if ( null !== $type_limit && $taken->forType( $type_key ) >= $type_limit ) {
			return $this->full( $limits, 'type_full' );
		}

		if ( null === $selection ) {
			return new CapacityDecision( Outcome::Accepted );
		}

		$slot       = $selection->slotKey();
		$slot_limit = $limits->forSlot( $slot );

		if ( null === $slot_limit || $taken->forSlot( $slot ) >= $slot_limit ) {
			return new CapacityDecision( Outcome::Accepted, false, 'accommodation_full' );
		}

		return new CapacityDecision( Outcome::Accepted, true );
	}

	private function full( CapacityLimits $limits, string $reason ): CapacityDecision {
		return new CapacityDecision(
			$limits->waitlistEnabled ? Outcome::Waitlisted : Outcome::Rejected,
			false,
			$reason
		);
	}
}
```

- [ ] **Step 5: Uruchom pełną suitę jednostkową**

```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
```

Oczekiwane: PASS, wszystkie testy z Tasków 1–10.

- [ ] **Step 6: Commit**

```bash
git add src/Domain/Capacity tests/unit/Domain/Capacity
git commit -m "feat: add capacity decision logic for event, type and room limits"
```

---

## Task 11: Analiza statyczna, standardy kodu i CI

**Files:**
- Create: `phpstan.neon.dist`
- Create: `phpcs.xml.dist`
- Create: `.github/workflows/ci.yml`
- Create: `tests/unit/Architecture/DomainPurityTest.php`
- Modify: `composer.json` (sekcja `require-dev` i `scripts`)

**Interfaces:**
- Consumes: cały kod z Tasków 1–10
- Produces: `composer lint`, `composer analyse`, `composer test:unit`; test architektoniczny pilnujący czystości `src/Domain`

- [ ] **Step 1: Napisz test pilnujący czystości domeny**

`tests/unit/Architecture/DomainPurityTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * src/Domain musi pozostać wolne od WordPressa — inaczej testy jednostkowe przestaną działać bez WP.
 */
final class DomainPurityTest extends TestCase {

	private const FORBIDDEN = array(
		'$wpdb',
		'add_action(',
		'add_filter(',
		'apply_filters(',
		'do_action(',
		'get_option(',
		'update_option(',
		'wp_mail(',
		'sanitize_text_field(',
		'esc_html(',
		'__(',
		'ABSPATH',
	);

	public function test_domain_contains_no_wordpress_calls(): void {
		$root = dirname( __DIR__, 3 ) . '/src/Domain';

		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );

		$violations = array();

		foreach ( $files as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}

			$contents = (string) file_get_contents( $file->getPathname() );

			foreach ( self::FORBIDDEN as $needle ) {
				if ( str_contains( $contents, $needle ) ) {
					$violations[] = $file->getFilename() . ' → ' . $needle;
				}
			}
		}

		$this->assertSame( array(), $violations, "Wywołania WordPressa w src/Domain:\n" . implode( "\n", $violations ) );
	}
}
```

- [ ] **Step 2: Uruchom test — musi przejść na obecnym kodzie**

```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit --filter DomainPurityTest
```

Oczekiwane: PASS. Jeśli failuje, przenieś wskazane wywołanie poza `src/Domain` — nie osłabiaj testu.

- [ ] **Step 3: Dodaj narzędzia do `composer.json`**

W `require-dev` dodaj:

```json
    "phpstan/phpstan": "^1.11",
    "szepeviktor/phpstan-wordpress": "^1.3",
    "wp-coding-standards/wpcs": "^3.1",
    "dealerdirect/phpcodesniffer-composer-installer": "^1.0"
```

W `config` dodaj zgodę na plugin Composera:

```json
  "config": {
    "sort-packages": true,
    "allow-plugins": {
      "dealerdirect/phpcodesniffer-composer-installer": true
    }
  }
```

Dodaj sekcję `scripts`:

```json
  "scripts": {
    "test:unit": "phpunit --testsuite unit",
    "test:integration": "phpunit -c phpunit-integration.xml.dist",
    "analyse": "phpstan analyse",
    "lint": "phpcs",
    "lint:fix": "phpcbf"
  }
```

- [ ] **Step 4: Utwórz `phpstan.neon.dist`**

```neon
includes:
    - vendor/szepeviktor/phpstan-wordpress/extension.neon

parameters:
    level: 6
    paths:
        - src
    scanFiles:
        - event-registration.php
    treatPhpDocTypesAsCertain: false
```

- [ ] **Step 5: Utwórz `phpcs.xml.dist`**

```xml
<?xml version="1.0"?>
<ruleset name="Event Registration">
    <description>Standardy kodu dla wtyczki Event Registration.</description>

    <file>src</file>
    <file>event-registration.php</file>

    <exclude-pattern>*/vendor/*</exclude-pattern>
    <exclude-pattern>*/node_modules/*</exclude-pattern>

    <arg name="extensions" value="php"/>
    <arg value="ps"/>

    <rule ref="WordPress-Core"/>
    <rule ref="WordPress-Docs"/>

    <rule ref="WordPress.WP.I18n">
        <properties>
            <property name="text_domain" type="array" value="event-registration"/>
        </properties>
    </rule>

    <rule ref="WordPress.NamingConventions.PrefixAllGlobals">
        <properties>
            <property name="prefixes" type="array" value="evreg,EvReg"/>
        </properties>
    </rule>

    <config name="minimum_wp_version" value="6.4"/>
    <config name="testVersion" value="8.1-"/>
</ruleset>
```

- [ ] **Step 6: Zainstaluj i uruchom narzędzia**

```bash
docker run --rm -v "$PWD:/app" -w /app composer:2 update
```

```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse
```

```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
```

Popraw zgłoszone błędy. PHPStan na poziomie 6 wymaga adnotacji typów tablicowych — dodaj brakujące `@param`/`@return`. WPCS wymaga tabulacji, Yoda conditions i dokumentacji plików; użyj `vendor/bin/phpcbf` do automatycznych poprawek, resztę popraw ręcznie.

- [ ] **Step 7: Utwórz workflow CI**

`.github/workflows/ci.yml`:

```yaml
name: CI

on:
  push:
    branches: [main]
  pull_request:

jobs:
  static-analysis:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.1'
          coverage: none
          tools: composer:v2

      - run: composer install --prefer-dist --no-progress

      - name: PHPCS
        run: composer lint

      - name: PHPStan
        run: composer analyse

      - name: Testy jednostkowe
        run: composer test:unit

  integration:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - uses: actions/setup-node@v4
        with:
          node-version: '20'

      - run: composer install --prefer-dist --no-progress

      - run: npx wp-env start

      - name: Testy integracyjne
        run: npx wp-env run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
```

- [ ] **Step 8: Uruchom pełny zestaw lokalnie**

```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
```

```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
```

Oczekiwane: obie suity PASS, PHPStan i PHPCS bez błędów.

- [ ] **Step 9: Commit**

```bash
git add composer.json composer.lock phpstan.neon.dist phpcs.xml.dist .github tests/unit/Architecture
git commit -m "chore: add static analysis, coding standards and CI pipeline"
```

---

## Definicja ukończenia Planu 1

- [ ] Wtyczka aktywuje się na czystym WordPressie i tworzy trzy tabele
- [ ] Suita `unit` przechodzi bez ładowania WordPressa
- [ ] Suita `integration` przechodzi w `wp-env`
- [ ] PHPStan poziom 6 i WPCS bez błędów
- [ ] `src/Domain` nie zawiera żadnego wywołania WordPressa (pilnowane testem)
- [ ] CI zielone na GitHubie

## Czego Plan 1 świadomie nie robi

Brak jakiegokolwiek UI, CPT, endpointów i zapisu zgłoszeń do bazy. Repozytoria (`RegistrationRepository`
i pozostałe z `src/Persistence/`) powstają w Planie 3, razem z transakcyjnym `ReservationService`,
który używa `CapacityCalculator` z Taska 10. Tabele istnieją już teraz, bo migracje są fundamentem,
którego nie chcemy zmieniać w trakcie budowy warstw wyższych.
