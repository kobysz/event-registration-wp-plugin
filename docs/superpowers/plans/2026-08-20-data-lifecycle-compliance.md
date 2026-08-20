# Cykl życia danych i compliance (Plan 6A) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wtyczka sprząta swój stan przy odinstalowaniu (za bramką, default zachowaj) i obsługuje WP Privacy API (eksport + anonimizujący wymaz danych osobowych).

**Architecture:** Logika wipe w testowalnym `Uninstaller` (cienki `uninstall.php` deleguje). Bramka = opcja `evreg_delete_data_on_uninstall` LUB stała `EVREG_DELETE_DATA_ON_UNINSTALL`, ustawiana na małej stronie `SettingsScreen` (WP Settings API). `PrivacyProvider` rejestruje exporter (reuse `RegistrationExportMapper` z 5C) + eraser (anonimizacja, wiersze/status zostają) + policy content. Nowe metody repo dają SQL po emailu i anonimizację.

**Tech Stack:** PHP 8.1, WordPress (Settings API, Privacy API, cron, roles), PHPUnit (kontener wp-env), PSR-4 `EvReg\`.

**Spec:** [docs/superpowers/specs/2026-08-20-data-lifecycle-compliance-design.md](../specs/2026-08-20-data-lifecycle-compliance-design.md)

## Global Constraints

- `src/Domain/**` = zero WordPressa/`$wpdb`. Nowy kod idzie do `src/Persistence/`, `src/Admin/`, `src/Privacy/`. `DomainPurityTest` zielony.
- SQL zgłoszeń/kolejki wyłącznie w `RegistrationRepository`/`MailQueueRepository`. Anonimizacja = `$wpdb->update` prepared.
- **Reuse magic stringów:** nazwy tabel z `Migrations::table()`, hooki cronów z `ExpirePending::HOOK`/`DispatchMail::HOOK`/`MailQueue::DISPATCH_HOOK`/`PurgeMailQueue::HOOK`, capy przez `Capabilities` — nie hardkoduj na nowo.
- **Wipe za bramką:** `Uninstaller::run()` domyślnie NIC nie kasuje; DROP tylko gdy opcja truthy LUB stała zdefiniowana-truthy (stała priorytet).
- Anonimizacja NIE zmienia `status`/`type_key`/`price_total`/dat — zajętość i liczniki spójne.
- Pliki PHP poza `src/Domain/`: `defined('ABSPATH')||exit;`. `uninstall.php` zamiast tego: `defined('WP_UNINSTALL_PLUGIN')||exit;`.
- Wszystkie stringi UI/privacy przez i18n, text domain `event-registration`. Placeholder emaili w domenie `example.invalid` (RFC 6761).
- phpcs (pod `phpcs.xml.dist`) i phpstan (poziom 6) czysto. Katalogi testów wielką literą.

---

### Task 1: Metody repozytorium — find-by-email + anonimizacja

**Files:**
- Modify: `src/Persistence/RegistrationRepository.php`
- Modify: `src/Persistence/MailQueueRepository.php`
- Test: `tests/Integration/Persistence/PrivacyQueriesTest.php`

**Interfaces:**
- Produces: `RegistrationRepository::findByEmailPaged(string $email, int $limit, int $offset): array`, `anonymizeById(int $id): void`, `anonymizeBookingByRegistration(int $id): void`; `MailQueueRepository::anonymizeByRegistration(int $id): void`.

- [ ] **Step 1: Test — findByEmailPaged filtruje po emailu, ASC, LIMIT/OFFSET**

W `tests/Integration/Persistence/PrivacyQueriesTest.php` (wzór seedowania z `RegistrationExportQueriesTest`). Wstaw zgłoszenia dwóch emaili. Asercje:
```php
$rows = $repo->findByEmailPaged( 'a@b.pl', 50, 0 );
$this->assertCount( 2, $rows );                       // tylko a@b.pl
$this->assertSame( $first_id, (int) $rows[0]['id'] ); // ASC
$this->assertCount( 1, $repo->findByEmailPaged( 'a@b.pl', 1, 0 ) ); // LIMIT
$this->assertSame( $second_id, (int) $repo->findByEmailPaged( 'a@b.pl', 1, 1 )[0]['id'] ); // OFFSET
```

- [ ] **Step 2: Test — anonymize nadpisuje tylko PII**

Wstaw zgłoszenie (status confirmed, type vip, price 150, z bookingiem roommate 'Jan', z wierszem mail_queue). Wywołaj trzy anonymize. Asercje przez `findById`/`findAccommodationBooking` + odczyt kolejki:
```php
$repo->anonymizeById( $id );
$row = $repo->findById( $id );
$this->assertStringStartsWith( 'deleted-', (string) $row['email'] );
$this->assertStringEndsWith( '@example.invalid', (string) $row['email'] );
$this->assertSame( '', (string) $row['name'] );
$this->assertSame( '{}', (string) $row['data'] );
$this->assertSame( '', (string) $row['note'] );
$this->assertSame( 'confirmed', (string) $row['status'] ); // NIEZMIENIONE
$this->assertSame( 'vip', (string) $row['type_key'] );      // NIEZMIENIONE
```
Analogicznie booking `roommate_pref===''`, mail_queue `recipient/subject/body/headers===''`.

- [ ] **Step 3: Uruchom — ma paść**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter PrivacyQueries`
Expected: FAIL. RED weryfikuj jawnym try/catch gdy podsumowanie niejasne.

- [ ] **Step 4: RegistrationRepository — trzy metody**

Wstaw obok istniejących. `$this->registrations()`/`$this->bookings()` istnieją (prywatne).
```php
/**
 * Zwraca zgłoszenia danego e-maila (wszystkie eventy), stronicowane — dla WP Privacy API.
 *
 * @return array<int,array<string,mixed>>
 */
public function findByEmailPaged( string $email, int $limit, int $offset ): array {
	global $wpdb;
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$this->registrations()} WHERE email = %s ORDER BY id ASC LIMIT %d OFFSET %d",
			$email,
			$limit,
			$offset
		),
		ARRAY_A
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return is_array( $rows ) ? $rows : array();
}

/**
 * Anonimizuje pola PII zgłoszenia (bez zmiany statusu/typu/ceny/dat) — WP Privacy eraser.
 */
public function anonymizeById( int $id ): void {
	global $wpdb;
	$wpdb->update(
		$this->registrations(),
		array(
			'email'      => 'deleted-' . $id . '@example.invalid',
			'name'       => '',
			'data'       => '{}',
			'note'       => '',
			'token'      => '',
			'updated_at' => current_time( 'mysql', true ),
		),
		array( 'id' => $id ),
		array( '%s', '%s', '%s', '%s', '%s', '%s' ),
		array( '%d' )
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

/**
 * Czyści preferencję współlokatora w rezerwacji noclegu zgłoszenia.
 */
public function anonymizeBookingByRegistration( int $id ): void {
	global $wpdb;
	$wpdb->update(
		$this->bookings(),
		array( 'roommate_pref' => '' ),
		array( 'registration_id' => $id ),
		array( '%s' ),
		array( '%d' )
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
```

- [ ] **Step 5: MailQueueRepository — anonymizeByRegistration**

```php
/**
 * Czyści treść osobową wierszy kolejki maili powiązanych ze zgłoszeniem — WP Privacy eraser.
 */
public function anonymizeByRegistration( int $id ): void {
	global $wpdb;
	$wpdb->update(
		Migrations::table( 'mail_queue' ),
		array( 'recipient' => '', 'subject' => '', 'body' => '', 'headers' => '' ),
		array( 'registration_id' => $id ),
		array( '%s', '%s', '%s', '%s' ),
		array( '%d' )
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
```
Sprawdź `use EvReg\Persistence\Migrations;` w `MailQueueRepository` (już używa `Migrations::table` w insert — powinno być).

- [ ] **Step 6: Uruchom — ma przejść**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter PrivacyQueries`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Persistence/RegistrationRepository.php src/Persistence/MailQueueRepository.php tests/Integration/Persistence/PrivacyQueriesTest.php
git commit -m "feat: add find-by-email and PII anonymization repository queries"
```

---

### Task 2: Uninstaller + Capabilities::remove() + uninstall.php

**Files:**
- Create: `src/Persistence/Uninstaller.php`
- Create: `uninstall.php` (root)
- Modify: `src/Admin/Capabilities.php` (dodaj `remove()`)
- Test: `tests/Integration/Persistence/UninstallerTest.php`

**Interfaces:**
- Produces: `Uninstaller::run(): void`; `Capabilities::remove(): void`.
- Consumes: `Migrations::table()`, cron `HOOK` consts, `Capabilities::remove()`.

- [ ] **Step 1: Test — bramka OFF (default) nic nie kasuje**

W `tests/Integration/Persistence/UninstallerTest.php`. Zainstaluj schemat (`Migrations::install()`), nadaj capy (`Capabilities::grant()`), utwórz event `evreg_event` z meta. Bez opcji/stałej:
```php
Uninstaller::run();
$this->assertNotEmpty( $wpdb->get_var( "SHOW TABLES LIKE '" . Migrations::table( 'registrations' ) . "'" ) );
$this->assertTrue( get_role( 'administrator' )->has_cap( 'edit_evreg_events' ) );
$this->assertNotFalse( get_post_status( $event_id ) );
```

- [ ] **Step 2: Test — bramka ON (opcja) kasuje wszystko**

```php
update_option( 'evreg_delete_data_on_uninstall', 1 );
Uninstaller::run();
$this->assertEmpty( $wpdb->get_var( "SHOW TABLES LIKE '" . Migrations::table( 'registrations' ) . "'" ) );
$this->assertEmpty( $wpdb->get_var( "SHOW TABLES LIKE '" . Migrations::table( 'mail_queue' ) . "'" ) );
$this->assertFalse( get_option( 'evreg_db_version' ) );
$this->assertFalse( get_option( 'evreg_caps_version' ) );
$this->assertFalse( get_role( 'administrator' )->has_cap( 'edit_evreg_events' ) );
$this->assertFalse( get_post_status( $event_id ) );      // CPT skasowany
$this->assertFalse( wp_next_scheduled( \EvReg\Cron\DispatchMail::HOOK ) );
```
(Pamiętaj zainstalować schemat/capy/event PONOWNIE w tym teście — Step 1 nic nie skasował, ale test niech ma własny setup; kolejność testów nie gwarantowana.)

- [ ] **Step 3: Uruchom — ma paść**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter Uninstaller`
Expected: FAIL.

- [ ] **Step 4: Capabilities::remove()**

W `src/Admin/Capabilities.php` dodaj (obok `grant`), reuse prywatnych `CAPS`/`VERSION_OPTION`:
```php
/**
 * Zdejmuje pełny zestaw capabilities z roli administrator i kasuje znacznik wersji. Dla uninstall.
 */
public static function remove(): void {
	$role = get_role( 'administrator' );
	if ( null !== $role ) {
		foreach ( self::CAPS as $cap ) {
			$role->remove_cap( $cap );
		}
	}
	delete_option( self::VERSION_OPTION );
}
```

- [ ] **Step 5: Uninstaller**

`src/Persistence/Uninstaller.php`:
```php
<?php
declare( strict_types=1 );
namespace EvReg\Persistence;
use EvReg\Admin\Capabilities;
use EvReg\Admin\EventPostType;
use EvReg\Cron\ExpirePending;
use EvReg\Cron\DispatchMail;
use EvReg\Cron\PurgeMailQueue;
use EvReg\Mail\MailQueue;
defined( 'ABSPATH' ) || exit;

final class Uninstaller {

	/** Meta CPT do skasowania. */
	private const META_KEYS = array( '_evreg_schema', '_evreg_types', '_evreg_accommodation', '_evreg_settings', '_evreg_mail_templates' );

	/** Tabele wtyczki (bez prefiksu). */
	private const TABLES = array( 'registrations', 'accommodation_bookings', 'mail_queue', 'locks' );

	/** Opcja-bramka. */
	public const DELETE_OPTION = 'evreg_delete_data_on_uninstall';

	/**
	 * Czyści cały stan wtyczki — TYLKO gdy bramka włączona. Wywoływane z uninstall.php.
	 */
	public static function run(): void {
		if ( ! self::shouldDeleteData() ) {
			return;
		}

		global $wpdb;

		foreach ( self::TABLES as $name ) {
			$table = Migrations::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		}

		delete_option( Migrations::VERSION_OPTION );
		delete_option( self::DELETE_OPTION );

		self::deleteEvents();

		foreach ( array( ExpirePending::HOOK, DispatchMail::HOOK, MailQueue::DISPATCH_HOOK, PurgeMailQueue::HOOK ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		Capabilities::remove(); // zdejmuje capy + kasuje evreg_caps_version

		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_evreg\\_rate\\_%' OR option_name LIKE '\\_transient\\_timeout\\_evreg\\_rate\\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	private static function shouldDeleteData(): bool {
		if ( defined( 'EVREG_DELETE_DATA_ON_UNINSTALL' ) && EVREG_DELETE_DATA_ON_UNINSTALL ) {
			return true;
		}
		return (bool) get_option( self::DELETE_OPTION, false );
	}

	private static function deleteEvents(): void {
		do {
			$ids = get_posts(
				array(
					'post_type'      => EventPostType::POST_TYPE,
					'post_status'    => 'any',
					'fields'         => 'ids',
					'posts_per_page' => 100,
					'no_found_rows'  => true,
				)
			);
			foreach ( $ids as $id ) {
				foreach ( self::META_KEYS as $meta_key ) {
					delete_post_meta( (int) $id, $meta_key );
				}
				wp_delete_post( (int) $id, true );
			}
		} while ( array() !== $ids );
	}
}
```
Uwaga: `Migrations::VERSION_OPTION` jest public (`'evreg_db_version'`). `EventPostType::POST_TYPE` public. Cron `HOOK`/`DISPATCH_HOOK` public. Jeśli któryś okaże się prywatny — dostosuj (rozważ upublicznienie stałej, nie hardkoduj literału).

- [ ] **Step 6: uninstall.php (root)**

```php
<?php
/**
 * Odinstalowanie Event Registration — czyszczenie stanu za bramką.
 *
 * @package EvReg
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

if ( class_exists( \EvReg\Persistence\Uninstaller::class ) ) {
	\EvReg\Persistence\Uninstaller::run();
}
```

- [ ] **Step 7: Uruchom — ma przejść**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter "Uninstaller|Capabilities"`
Expected: PASS. (Jeśli istnieje test Capabilities — niech zostanie zielony.)

- [ ] **Step 8: Commit**

```bash
git add src/Persistence/Uninstaller.php uninstall.php src/Admin/Capabilities.php tests/Integration/Persistence/UninstallerTest.php
git commit -m "feat: add gated uninstall cleanup and capability removal"
```

---

### Task 3: SettingsScreen — checkbox bramki

**Files:**
- Create: `src/Admin/SettingsScreen.php`
- Modify: `event-registration.php` (rejestracja `SettingsScreen::register`)
- Test: `tests/Integration/Admin/SettingsScreenTest.php`

**Interfaces:**
- Produces: `SettingsScreen::register(): void`, `SettingsScreen::sanitize( $value ): int`.
- Consumes: `Uninstaller::DELETE_OPTION`, `Capabilities::CAP`, `EventPostType::POST_TYPE`.

- [ ] **Step 1: Test — sanitize + register_setting**

W `tests/Integration/Admin/SettingsScreenTest.php`:
```php
$this->assertSame( 1, SettingsScreen::sanitize( 'on' ) );
$this->assertSame( 1, SettingsScreen::sanitize( '1' ) );
$this->assertSame( 0, SettingsScreen::sanitize( '' ) );
$this->assertSame( 0, SettingsScreen::sanitize( null ) );
```
Drugi test: po `SettingsScreen::register()` + `do_action('admin_init')` opcja jest zarejestrowana:
```php
SettingsScreen::register();
do_action( 'admin_init' );
$registered = get_registered_settings();
$this->assertArrayHasKey( Uninstaller::DELETE_OPTION, $registered );
```

- [ ] **Step 2: Uruchom — ma paść**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter SettingsScreen`
Expected: FAIL.

- [ ] **Step 3: SettingsScreen**

`src/Admin/SettingsScreen.php`:
```php
<?php
declare( strict_types=1 );
namespace EvReg\Admin;
use EvReg\Persistence\Uninstaller;
defined( 'ABSPATH' ) || exit;

final class SettingsScreen {

	private const SLUG          = 'evreg-settings';
	private const OPTION_GROUP  = 'evreg_settings';

	/** Podpina menu i rejestrację ustawienia. */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_init', array( self::class, 'register_setting' ) );
	}

	public static function add_menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . EventPostType::POST_TYPE,
			__( 'Ustawienia', 'event-registration' ),
			__( 'Ustawienia', 'event-registration' ),
			Capabilities::CAP,
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	public static function register_setting(): void {
		register_setting(
			self::OPTION_GROUP,
			Uninstaller::DELETE_OPTION,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => false,
			)
		);
		add_settings_section( 'evreg_settings_main', '', '__return_null', self::SLUG );
		add_settings_field(
			Uninstaller::DELETE_OPTION,
			__( 'Usuwanie danych', 'event-registration' ),
			array( self::class, 'render_field' ),
			self::SLUG,
			'evreg_settings_main'
		);
	}

	/** @param mixed $value */
	public static function sanitize( $value ): int {
		return ( '' === $value || null === $value || '0' === $value || false === $value ) ? 0 : 1;
	}

	public static function render_field(): void {
		$forced  = defined( 'EVREG_DELETE_DATA_ON_UNINSTALL' ) && EVREG_DELETE_DATA_ON_UNINSTALL;
		$checked = $forced || (bool) get_option( Uninstaller::DELETE_OPTION, false );
		printf(
			'<label><input type="checkbox" name="%s" value="1" %s %s /> %s</label>',
			esc_attr( Uninstaller::DELETE_OPTION ),
			checked( $checked, true, false ),
			disabled( $forced, true, false ),
			esc_html__( 'Usuń wszystkie dane wtyczki (zgłoszenia, ustawienia) przy odinstalowaniu. Nieodwracalne.', 'event-registration' )
		);
		if ( $forced ) {
			echo '<p class="description">' . esc_html__( 'Wymuszone stałą EVREG_DELETE_DATA_ON_UNINSTALL w wp-config.', 'event-registration' ) . '</p>';
		}
	}

	public static function render(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Event Registration — Ustawienia', 'event-registration' ) . '</h1>';
		echo '<form action="options.php" method="post">';
		settings_fields( self::OPTION_GROUP );
		do_settings_sections( self::SLUG );
		submit_button();
		echo '</form></div>';
	}
}
```

- [ ] **Step 4: Rejestracja w bootstrapie**

W `event-registration.php` przy innych `*::register()` na `plugins_loaded` dodaj `\EvReg\Admin\SettingsScreen::register();`.

- [ ] **Step 5: Uruchom — ma przejść**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter SettingsScreen`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Admin/SettingsScreen.php event-registration.php tests/Integration/Admin/SettingsScreenTest.php
git commit -m "feat: add settings screen with uninstall data-deletion toggle"
```

---

### Task 4: PrivacyProvider — exporter + eraser + policy

**Files:**
- Create: `src/Privacy/PrivacyProvider.php`
- Modify: `event-registration.php` (rejestracja `PrivacyProvider::register`)
- Test: `tests/Integration/Privacy/PrivacyProviderTest.php`

**Interfaces:**
- Produces: `PrivacyProvider::register(): void`, `export(string $email, int $page = 1): array`, `erase(string $email, int $page = 1): array`.
- Consumes: `RegistrationRepository::{findByEmailPaged, anonymizeById, anonymizeBookingByRegistration, findAccommodationBooking, findById}`, `MailQueueRepository::anonymizeByRegistration`, `RegistrationExportMapper` (5C), `EventFormLoader`, `EventConfigRepository`, `RegistrationTypeCollection`, `AccommodationConfig`, `RegistrationsListTable::status_label`.

- [ ] **Step 1: Test — exporter zwraca dane osoby**

W `tests/Integration/Privacy/PrivacyProviderTest.php`. Event ze schematem (email+text+checkbox-group) + typ + nocleg. Zgłoszenie po `a@b.pl` z odpowiedziami, bookingiem, wierszem mail_queue.
```php
$result = ( new PrivacyProvider() )->export( 'a@b.pl', 1 );
$this->assertTrue( $result['done'] );
$this->assertNotEmpty( $result['data'] );
$group = $result['data'][0];
$this->assertSame( 'evreg_registration', $group['group_id'] );
// spłaszcz name/value do mapy dla asercji
$values = wp_list_pluck( $group['data'], 'value', 'name' );
$this->assertContains( 'a@b.pl', $values );            // e-mail obecny
$this->assertArrayHasKey( 'Status', $values );          // pole tożsamości (i18n label)
```
Drugi test: inny email → `data` puste, `done=true`. Trzeci: >50 zgłoszeń → strona 1 `done=false`.

- [ ] **Step 2: Test — eraser anonimizuje, status zostaje**

```php
$result = ( new PrivacyProvider() )->erase( 'a@b.pl', 1 );
$this->assertTrue( $result['items_retained'] );
$this->assertFalse( $result['items_removed'] );
$this->assertNotEmpty( $result['messages'] );
$row = $repo->findById( $id );
$this->assertStringEndsWith( '@example.invalid', (string) $row['email'] );
$this->assertSame( 'confirmed', (string) $row['status'] ); // NIEZMIENIONE
// idempotencja: drugi erase po pierwotnym mailu → 0 zgłoszeń
$again = ( new PrivacyProvider() )->erase( 'a@b.pl', 1 );
$this->assertTrue( $again['done'] );
```

- [ ] **Step 3: Uruchom — ma paść**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter PrivacyProvider`
Expected: FAIL.

- [ ] **Step 4: PrivacyProvider**

`src/Privacy/PrivacyProvider.php`. Struktura:
```php
<?php
declare( strict_types=1 );
namespace EvReg\Privacy;
use EvReg\Admin\RegistrationsListTable;
use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Export\RegistrationExportMapper;
use EvReg\Domain\Registration\RegistrationTypeCollection;
use EvReg\Frontend\EventFormLoader;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\MailQueueRepository;
use EvReg\Persistence\RegistrationRepository;
defined( 'ABSPATH' ) || exit;

final class PrivacyProvider {

	private const PER_PAGE = 50;

	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'register_eraser' ) );
		add_action( 'admin_init', array( self::class, 'add_policy_content' ) );
	}

	/** @param array<string,mixed> $exporters @return array<string,mixed> */
	public static function register_exporter( array $exporters ): array {
		$exporters['event-registration'] = array(
			'exporter_friendly_name' => __( 'Zgłoszenia (Event Registration)', 'event-registration' ),
			'callback'               => array( new self(), 'export' ),
		);
		return $exporters;
	}

	/** @param array<string,mixed> $erasers @return array<string,mixed> */
	public static function register_eraser( array $erasers ): array {
		$erasers['event-registration'] = array(
			'eraser_friendly_name' => __( 'Zgłoszenia (Event Registration)', 'event-registration' ),
			'callback'             => array( new self(), 'erase' ),
		);
		return $erasers;
	}

	public static function add_policy_content(): void {
		$content = __( 'Wtyczka Event Registration przechowuje dane zgłoszeń na wydarzenia: adres e-mail, imię i nazwisko, odpowiedzi z formularza, wybór noclegu oraz treść wysłanych potwierdzeń. Dane są przechowywane do czasu usunięcia zgłoszenia lub odinstalowania wtyczki.', 'event-registration' );
		wp_add_privacy_policy_content( __( 'Event Registration', 'event-registration' ), wp_kses_post( wpautop( $content ) ) );
	}

	/** @return array{data:array<int,array<string,mixed>>,done:bool} */
	public function export( string $email, int $page = 1 ): array {
		$repo   = new RegistrationRepository();
		$offset = ( max( 1, $page ) - 1 ) * self::PER_PAGE;
		$rows   = $repo->findByEmailPaged( $email, self::PER_PAGE, $offset );
		$mapper = new RegistrationExportMapper();
		$loader = new EventFormLoader( new EventConfigRepository() );
		$config_repo = new EventConfigRepository();

		$export = array();
		foreach ( $rows as $row ) {
			$id       = (int) $row['id'];
			$event_id = (int) $row['event_id'];
			$schema   = $loader->load( $event_id );
			$config   = $config_repo->get( $event_id );
			$types    = RegistrationTypeCollection::fromArray( is_array( $config['types'] ) ? $config['types'] : array() );
			$acc      = AccommodationConfig::fromArray( is_array( $config['accommodation'] ) ? $config['accommodation'] : array() );

			$data = array(
				array( 'name' => __( 'Status', 'event-registration' ), 'value' => RegistrationsListTable::status_label( (string) $row['status'] ) ),
				array( 'name' => __( 'Typ', 'event-registration' ), 'value' => ( $types->get( (string) $row['type_key'] )?->label ) ?? (string) $row['type_key'] ),
				array( 'name' => __( 'E-mail', 'event-registration' ), 'value' => (string) $row['email'] ),
				array( 'name' => __( 'Imię i nazwisko', 'event-registration' ), 'value' => (string) $row['name'] ),
				array( 'name' => __( 'Cena', 'event-registration' ), 'value' => (string) $row['price_total'] ),
				array( 'name' => __( 'Utworzono', 'event-registration' ), 'value' => (string) $row['created_at'] ),
				array( 'name' => __( 'Notatka', 'event-registration' ), 'value' => (string) ( $row['note'] ?? '' ) ),
			);
			// Odpowiedzi (reuse mapper 5C)
			if ( null !== $schema ) {
				$answers = json_decode( (string) $row['data'], true );
				$answers = is_array( $answers ) ? $answers : array();
				foreach ( $mapper->answerColumns( $schema ) as $i => $col ) {
					$cells = $mapper->answerCells( $answers, $schema );
					$data[] = array( 'name' => $col['label'], 'value' => $cells[ $i ] ?? '' );
				}
				$acc_cells = $mapper->accommodationCells( $repo->findAccommodationBooking( $id ), $acc );
				$data[] = array( 'name' => __( 'Nocleg', 'event-registration' ), 'value' => trim( $acc_cells['package'] . ' ' . $acc_cells['room'] . ' ' . $acc_cells['roommate'] ) );
			}

			$export[] = array(
				'group_id'    => 'evreg_registration',
				'group_label' => __( 'Zgłoszenia (Event Registration)', 'event-registration' ),
				'item_id'     => 'evreg-registration-' . $id,
				'data'        => $data,
			);
		}

		return array( 'data' => $export, 'done' => count( $rows ) < self::PER_PAGE );
	}

	/** @return array{items_removed:bool,items_retained:bool,messages:array<int,string>,done:bool} */
	public function erase( string $email, int $page = 1 ): array {
		$repo    = new RegistrationRepository();
		$mails   = new MailQueueRepository();
		$offset  = ( max( 1, $page ) - 1 ) * self::PER_PAGE;
		$rows    = $repo->findByEmailPaged( $email, self::PER_PAGE, $offset );

		foreach ( $rows as $row ) {
			$id = (int) $row['id'];
			$repo->anonymizeById( $id );
			$repo->anonymizeBookingByRegistration( $id );
			$mails->anonymizeByRegistration( $id );
		}

		$count    = count( $rows );
		$messages = $count > 0
			? array( sprintf( /* translators: %d liczba zgłoszeń */ __( 'Zanonimizowano dane %d zgłoszeń.', 'event-registration' ), $count ) )
			: array();

		return array(
			'items_removed'  => false,
			'items_retained' => $count > 0,
			'messages'       => $messages,
			'done'           => $count < self::PER_PAGE,
		);
	}
}
```
Uwaga: WP woła callbacki z sygnaturą `($email, $page)`; nasze `export`/`erase` to dopasowują. Sprawdź, czy `RegistrationExportMapper::answerColumns/answerCells/accommodationCells` (5C) i `RegistrationsListTable::status_label` (5A) mają dokładnie te sygnatury (są w repo).

- [ ] **Step 5: Rejestracja w bootstrapie**

W `event-registration.php` przy innych `register()` dodaj `\EvReg\Privacy\PrivacyProvider::register();`.

- [ ] **Step 6: Uruchom — ma przejść**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter PrivacyProvider`
Expected: PASS.

- [ ] **Step 7: Pełny zestaw + statyka**

Run:
```
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=1G
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
```
Expected: wszystko zielone. `DomainPurityTest` zielony.

- [ ] **Step 8: Commit**

```bash
git add src/Privacy/PrivacyProvider.php event-registration.php tests/Integration/Privacy/PrivacyProviderTest.php
git commit -m "feat: add WP privacy exporter and anonymizing eraser"
```

---

## Uwagi wykonawcze

- **RED faza:** konsola PHPUnit zniekształca `Error` — weryfikuj jawnym try/catch drukującym `get_class($e)`.
- **Kolejność:** Task 4 zależy od Task 1 (metody repo). Task 2 dodaje `Capabilities::remove()` — nie koliduje z T1. Zachowaj 1→4.
- **Reuse stałych:** jeśli któraś stała (`Migrations::VERSION_OPTION`, cron `HOOK`, `EventPostType::POST_TYPE`) okaże się prywatna — upublicznij ją, NIE hardkoduj literału w `Uninstaller`.
- **`uninstall.php` a phpcs:** `phpcs.xml.dist` skanuje `src` + `event-registration.php`, więc `uninstall.php` w rootcie NIE jest domyślnie sprawdzany — zostaw poza skanem (spójne z konwencją) albo dołącz świadomie do `<file>` jeśli chcesz go lintować (opcjonalne).
- **Bramka domyślnie OFF** — najważniejszy inwariant: `Uninstaller::run()` bez opcji/stałej nie kasuje NIC. Test Step 1 (Task 2) to pilnuje.
- **Weryfikacja przeglądarkowa** (poza subagentami): strona Ustawienia renderuje checkbox i zapisuje opcję; Narzędzia → Eksport danych osobowych zwraca zgłoszenia po mailu; Narzędzia → Usuń dane osobowe anonimizuje; realne odinstalowanie z włączoną bramką czyści bazę.
