# Registrations Admin Panel (Plan 5A) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dać organizatorowi panel zgłoszeń — `WP_List_Table` pod submenu menu CPT `evreg_event` z filtrami status/typ/event, ekranem szczegółów (read-only odpowiedzi + notatka), i akcjami cyklu życia: ręczne potwierdzenie (bez maila), anulowanie (miękkie, zwalnia miejsce), promocja z waitlisty (pod lock→count, mail opt-in), trwałe usunięcie (tylko anulowanych), notatka.

**Architecture:** Akcje zmieniające zajętość (`confirmManually`, `cancel`, `promoteFromWaitlist`, `deleteRegistration`) żyją w `ReservationService` — jedynym miejscu z transakcją i inwariantem lock→count z 3A; `promoteFromWaitlist` powtarza wzorzec `reserve` (lockEvent przed occupancy). `RegistrationRepository` dostaje zapytania listujące i mutacje statusu/notatki/kasowania (jedyne SQL zgłoszeń). `RegistrationsListTable extends WP_List_Table` renderuje; `RegistrationsScreen` orkiestruje submenu, ekran szczegółów i pięć handlerów admin-post (nonce+cap+PRG). Wzorzec ekranu z 4B-Kolejki.

**Tech Stack:** PHP 8.1, WordPress 6.4+, `WP_List_Table`, admin-post, PHPUnit integration (wp-env). Zero JS, zero buildów.

**Spec:** `docs/superpowers/specs/2026-08-19-registrations-admin-design.md`

**Gałąź:** `plan-5a-registrations` od `master`.

## Global Constraints

- Minimalne PHP 8.1, minimalne WordPress 6.4.
- Namespace `EvReg\`, PSR-4, `src/`. Prefiks hooków/akcji `evreg_`.
- Każdy plik PHP poza `src/Domain/` i `tests/` zaczyna od `defined( 'ABSPATH' ) || exit;`.
- **SQL zgłoszeń wyłącznie w `RegistrationRepository`.** Warstwa admina i serwis nie piszą SQL bezpośrednio (serwis woła repozytorium). Wszystkie zapytania przez `$wpdb->prepare`.
- **Akcje zmieniające zajętość idą przez `ReservationService`.** `promoteFromWaitlist` MUSI zachować inwariant lock→count: `lockEvent()` (SELECT … FOR UPDATE) przed pierwszym `occupancy()` COUNT. NIE zmieniać kolejności (nośny inwariant 3A).
- **`confirmManually` NIE emituje `evreg_registration_confirmed`** — potwierdzenie ręczne jest bez maila; hook uruchomiłby Subscriber z 4A i wysłał mail.
- **`deleteRegistration` guard `status='cancelled'`** — nie kasuje aktywnych; hooki/kasowania w jednej transakcji.
- **Uprawnienia + nonce + PRG:** `current_user_can( Capabilities::CAP )` (`edit_evreg_events`) na renderze i każdym handlerze; `check_admin_referer` przed zmianą stanu; `wp_safe_redirect` + `exit`.
- **Escaping obowiązkowy:** odpowiedzi uczestnika i notatka przez `esc_html`/`esc_textarea`; linki `esc_url`; `id`/`event_id`/`paged` int; status filtra whitelist z `RegistrationStatus`; notatka przez `sanitize_textarea_field` przy zapisie.
- Czas UTC: `current_time( 'mysql', true )`; `gmdate( 'Y-m-d H:i:s', strtotime( '+48 hours', time() ) )` dla `expires_at` promocji.
- Text domain `event-registration` dla stringów UI przez `__()`/`esc_html__()`.
- Styl PHP jak w repo: tabulatory, `array()` zamiast `[]`, warunki Yody, docblock `@param`/`@return` na metodach publicznych. Metody prywatne/VO camelCase.
- PHPStan poziom 6 (skanuje `src` + `event-registration.php`, nie testy), bez nowych `@phpstan-ignore` poza wzorcem `WordPress.DB.*` z 4A. `phpcs` czysto pod istniejącym `phpcs.xml.dist`.
- Katalogi testów PHP wielką literą; suity małą. Commity po każdym tasku, po angielsku, Conventional Commits.

### Komendy referencyjne

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs src
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=512M
```

Host nie ma PHP — komendy PHP wyłącznie przez `node scripts/wp-env.cjs`. Nie wołaj gołego `npx wp-env`. **phpcs tylko na ścieżkach `src`** (nie `tests/...`). PHPStan wymaga `--memory-limit=512M` (przy przejściowym OOM równoległego workera — powtórz identyczną komendę raz). Konsola PHPUnit zniekształca komunikat niezłapanego `Error` — fazę RED weryfikuj po nazwie klasy błędu. Weryfikacja w przeglądarce: kontroler (człowiek), nie subagent.

### Krytyczne fakty środowiskowe (z 4B-Kolejki — powtórzone)

- **`WP_List_Table` NIE jest autoloadowane** — plik podklasy MUSI je załadować przed deklaracją: po guardzie ABSPATH `if ( ! class_exists( 'WP_List_Table' ) ) { require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php'; }`.
- **`WP_List_Table` w testach** wymaga `set_current_screen( '...' )` przed instancjonowaniem.
- **Metody nadpisywane `WP_List_Table` (`column_default( $item, $column )`, `handle_row_actions( $item, $column, $primary )`, `extra_tablenav( $which )`) bez deklaracji typów parametrów** (zgodność sygnatur; typy w docblocku). `: string`/`: void` return dozwolone.
- **Handler redirectu w testach:** `wp_safe_redirect(...); exit;` — test przechwytuje filtrem `wp_redirect` rzucającym wyjątek (`RedirectException` w pliku testu). Zły nonce/cap → `check_admin_referer`/uprawnienia wołają `wp_die` → suite zamienia na `WPDieException` (`$this->expectException( \WPDieException::class )`).

### Interfejsy z Planów 3A/4A/4B (konsumowane — sygnatury zweryfikowane w kodzie)

- `EvReg\Services\ReservationService` — ctor `( RegistrationRepository $repository, EventConfigRepository $config )`; prywatne `$this->repository`, `$this->config`, `$this->calculator` (`CapacityCalculator`), `$this->pricing`; `const PENDING_TTL = '+48 hours'`. `reserve()` wzorzec transakcji: `START` → `lockEvent` → `occupancy` → `CapacityCalculator::decide` → insert → `COMMIT` → `do_action` po try/catch.
- `EvReg\Persistence\RegistrationRepository` — `findById( int ): ?array`, `findAccommodationBooking( int ): ?array` (kolumny `package_key`, `room_type_key`, `roommate_pref`, `price`), `markConfirmed( int ): void` (status=confirmed + confirmed_at), `occupancy( int ): OccupancySnapshot`, `lockEvent( int ): void`; prywatne `registrations()`/`bookings()` (nazwy tabel), `Migrations::table('mail_queue')`. Kolumny `evreg_registrations`: `id, event_id, type_key, status, email, name, token, data, price_total, note, created_at, expires_at, confirmed_at, updated_at`.
- `EvReg\Domain\Registration\RegistrationStatus` — enum `Pending='pending'`, `Confirmed='confirmed'`, `Waitlist='waitlist'`, `Cancelled='cancelled'`; `occupiesSeat(): bool`, `static occupyingValues(): array<int,string>`.
- `EvReg\Domain\Capacity\CapacityCalculator::decide( CapacityLimits, OccupancySnapshot, string $typeKey, ?AccommodationSelection ): CapacityDecision` — `->outcome` (`Outcome::Approved|Waitlisted|Rejected`), `->reason`, `->accommodationGranted`.
- `EvReg\Domain\Capacity\CapacityLimits::__construct( ?int $globalCap, array $typeCaps, array $slotCaps, bool $waitlistEnabled )`; `EvReg\Domain\Capacity\Outcome`.
- `EvReg\Domain\Registration\RegistrationTypeCollection::fromArray( array ): self`, `capacities(): array`, `get( string ): ?RegistrationType`; `EvReg\Domain\Accommodation\AccommodationConfig::fromArray( array ): self`, `capacities(): array`, `item( string, string ): ?InventoryItem`; `EvReg\Domain\Accommodation\AccommodationSelection::__construct( string $packageKey, string $roomKey, string $roommatePref = '' )`.
- `EvReg\Persistence\EventConfigRepository::get( int ): array` → `schema|types|accommodation|settings`.
- `EvReg\Domain\Schema\SchemaAssembler::assemble( array $schema, array $types, array $accommodation ): FormSchema`; `EvReg\Frontend\EventFormLoader::__construct( EventConfigRepository )`, `load( int ): ?FormSchema` (składa złożoną schemę); `FormSchema::allFields(): Field[]`, `Field` readonly `key`,`type`,`label`,`options`; `FieldType`.
- `EvReg\Mail\Subscriber::register(): void` (4A — testy `confirmManually`/promote potrzebują go do sprawdzenia (nie)kolejkowania maila); tabela `evreg_mail_queue`.
- `EvReg\Admin\Capabilities::CAP = 'edit_evreg_events'`, `Capabilities::grant()`; `EvReg\Admin\EventPostType::POST_TYPE = 'evreg_event'`; `EvReg\Admin\MailQueueListTable` (wzorzec `status_label` helpera i `WP_List_Table`), `EvReg\Admin\MailQueueScreen` (wzorzec submenu/handlera/PRG).
- `EvReg\Plugin::VERSION`, `Plugin::TEXT_DOMAIN='event-registration'`.

---

## File Structure

**Nowe pliki produkcyjne**

| Plik | Odpowiedzialność |
|---|---|
| `src/Services/AdminActionResult.php` | Obiekt wartości wyniku akcji admina (`code` + `reason`) |
| `src/Admin/RegistrationsListTable.php` | `WP_List_Table`: kolumny, filtry status/typ/event, akcje wierszy wg statusu |
| `src/Admin/RegistrationsScreen.php` | Submenu, ekran szczegółów, pięć handlerów admin-post |

**Modyfikowane pliki produkcyjne**

| Plik | Zmiana |
|---|---|
| `src/Persistence/RegistrationRepository.php` | Zapytania listujące + mutacje statusu/notatki/kasowania |
| `src/Services/ReservationService.php` | `confirmManually`, `cancel`, `promoteFromWaitlist`, `deleteRegistration` |
| `event-registration.php` | Rejestracja `RegistrationsScreen::register` na `plugins_loaded` |

**Nowe pliki testowe**

`tests/Integration/Persistence/RegistrationListingTest.php`,
`tests/Integration/Persistence/RegistrationMutationsTest.php`,
`tests/Integration/Services/AdminActionsTest.php`,
`tests/Integration/Services/PromoteFromWaitlistTest.php`,
`tests/Integration/Admin/RegistrationsListTableTest.php`,
`tests/Integration/Admin/RegistrationsScreenTest.php`.

**Świadomie nietykane:** `reserve()`/`confirm()` (istniejące ścieżki 3A), silnik 4A, `phpcs.xml.dist`, `phpstan.neon.dist`. Edycja odpowiedzi (5B) i eksport (5C) poza tym planem.

---
## Task 1: RegistrationRepository — zapytania listujące

**Files:**
- Modify: `src/Persistence/RegistrationRepository.php`
- Test: `tests/Integration/Persistence/RegistrationListingTest.php`

**Interfaces:**
- Consumes: `insertRegistration()`, `RegistrationStatus`, prywatne `registrations()`
- Produces:
  - `paginateRegistrations( array $filters, int $per_page, int $offset ): array<int,array<string,mixed>>`
  - `countRegistrations( array $filters ): int`
  - `distinctTypeKeys( int $event_id ): array<int,string>`
  - `$filters` = `array{ status?: string, type_key?: string, event_id?: int }`; nieznany status, pusty type_key, `event_id<=0` ignorowane

- [ ] **Step 1: Napisz test (RED)**

`tests/Integration/Persistence/RegistrationListingTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Persistence;

use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use WP_UnitTestCase;

final class RegistrationListingTest extends WP_UnitTestCase {

	private RegistrationRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( 'registrations' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->repository = new RegistrationRepository();
	}

	/**
	 * @param array<string,mixed> $overrides
	 */
	private function seed( array $overrides = array() ): int {
		return $this->repository->insertRegistration(
			array_merge(
				array(
					'event_id'    => 1,
					'type_key'    => 'uczestnik',
					'status'      => 'pending',
					'email'       => 'jan@example.com',
					'name'        => 'Jan',
					'token'       => str_repeat( 'a', 32 ),
					'data'        => '{}',
					'price_total' => 0.0,
					'expires_at'  => '2099-01-01 00:00:00',
				),
				$overrides
			)
		);
	}

	public function test_paginate_all_newest_first(): void {
		$this->seed( array( 'name' => 'A', 'token' => str_repeat( 'a', 32 ) ) );
		$this->seed( array( 'name' => 'B', 'token' => str_repeat( 'b', 32 ) ) );

		$rows = $this->repository->paginateRegistrations( array(), 20, 0 );

		$this->assertCount( 2, $rows );
		$this->assertSame( 'B', $rows[0]['name'] );
		$this->assertSame( 'A', $rows[1]['name'] );
	}

	public function test_paginate_filters_by_status(): void {
		$this->seed( array( 'status' => 'pending', 'token' => str_repeat( 'a', 32 ) ) );
		$this->seed( array( 'status' => 'waitlist', 'name' => 'W', 'token' => str_repeat( 'b', 32 ) ) );

		$rows = $this->repository->paginateRegistrations( array( 'status' => 'waitlist' ), 20, 0 );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'W', $rows[0]['name'] );
	}

	public function test_paginate_filters_by_type_and_event(): void {
		$this->seed( array( 'event_id' => 1, 'type_key' => 'uczestnik', 'token' => str_repeat( 'a', 32 ) ) );
		$this->seed( array( 'event_id' => 1, 'type_key' => 'wykladowca', 'name' => 'T', 'token' => str_repeat( 'b', 32 ) ) );
		$this->seed( array( 'event_id' => 2, 'type_key' => 'wykladowca', 'token' => str_repeat( 'c', 32 ) ) );

		$rows = $this->repository->paginateRegistrations( array( 'event_id' => 1, 'type_key' => 'wykladowca' ), 20, 0 );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'T', $rows[0]['name'] );
	}

	public function test_paginate_limit_offset(): void {
		foreach ( array( 'a', 'b', 'c' ) as $i => $t ) {
			$this->seed( array( 'name' => strtoupper( $t ), 'token' => str_repeat( $t, 32 ) ) );
		}

		$page2 = $this->repository->paginateRegistrations( array(), 2, 2 );

		$this->assertCount( 1, $page2 );
		$this->assertSame( 'A', $page2[0]['name'] );
	}

	public function test_paginate_ignores_unknown_status(): void {
		$this->seed();

		$this->assertCount( 1, $this->repository->paginateRegistrations( array( 'status' => 'nonsense' ), 20, 0 ) );
	}

	public function test_count_matches_filter(): void {
		$this->seed( array( 'status' => 'pending', 'token' => str_repeat( 'a', 32 ) ) );
		$this->seed( array( 'status' => 'waitlist', 'token' => str_repeat( 'b', 32 ) ) );
		$this->seed( array( 'status' => 'waitlist', 'token' => str_repeat( 'c', 32 ) ) );

		$this->assertSame( 3, $this->repository->countRegistrations( array() ) );
		$this->assertSame( 2, $this->repository->countRegistrations( array( 'status' => 'waitlist' ) ) );
	}

	public function test_distinct_type_keys(): void {
		$this->seed( array( 'event_id' => 1, 'type_key' => 'uczestnik', 'token' => str_repeat( 'a', 32 ) ) );
		$this->seed( array( 'event_id' => 1, 'type_key' => 'uczestnik', 'token' => str_repeat( 'b', 32 ) ) );
		$this->seed( array( 'event_id' => 1, 'type_key' => 'wykladowca', 'token' => str_repeat( 'c', 32 ) ) );
		$this->seed( array( 'event_id' => 2, 'type_key' => 'online', 'token' => str_repeat( 'd', 32 ) ) );

		$types = $this->repository->distinctTypeKeys( 1 );

		sort( $types );
		$this->assertSame( array( 'uczestnik', 'wykladowca' ), $types );
	}
}
```

- [ ] **Step 2: Uruchom test i potwierdź RED**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RegistrationListingTest
```
Expected: FAIL — `Call to undefined method ...::paginateRegistrations()`.

- [ ] **Step 3: Zaimplementuj zapytania listujące**

W `src/Persistence/RegistrationRepository.php`, po `findById()`, dodaj builder filtrów i trzy metody:

```php
	/**
	 * Buduje klauzulę WHERE i argumenty z filtrów listy zgłoszeń.
	 *
	 * @param array{status?: string, type_key?: string, event_id?: int} $filters Filtry.
	 *
	 * @return array{0: string, 1: array<int,mixed>}
	 */
	private function registrationWhere( array $filters ): array {
		$clauses  = array();
		$args     = array();
		$statuses = array(
			RegistrationStatus::Pending->value,
			RegistrationStatus::Confirmed->value,
			RegistrationStatus::Waitlist->value,
			RegistrationStatus::Cancelled->value,
		);

		if ( isset( $filters['status'] ) && in_array( $filters['status'], $statuses, true ) ) {
			$clauses[] = 'status = %s';
			$args[]    = $filters['status'];
		}

		if ( isset( $filters['type_key'] ) && '' !== (string) $filters['type_key'] ) {
			$clauses[] = 'type_key = %s';
			$args[]    = (string) $filters['type_key'];
		}

		if ( isset( $filters['event_id'] ) && (int) $filters['event_id'] > 0 ) {
			$clauses[] = 'event_id = %d';
			$args[]    = (int) $filters['event_id'];
		}

		$where = array() === $clauses ? '' : ' WHERE ' . implode( ' AND ', $clauses );

		return array( $where, $args );
	}

	/**
	 * Zwraca stronę zgłoszeń wg filtrów, najnowsze naprzód.
	 *
	 * @param array{status?: string, type_key?: string, event_id?: int} $filters  Filtry.
	 * @param int                                                        $per_page Wiersze na stronę.
	 * @param int                                                        $offset   Przesunięcie.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function paginateRegistrations( array $filters, int $per_page, int $offset ): array {
		global $wpdb;

		list( $where, $args ) = $this->registrationWhere( $filters );
		$args[]               = $per_page;
		$args[]               = $offset;

		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->prepare( "SELECT * FROM {$this->registrations()}{$where} ORDER BY id DESC LIMIT %d OFFSET %d", $args ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Liczy zgłoszenia spełniające filtry.
	 *
	 * @param array{status?: string, type_key?: string, event_id?: int} $filters Filtry.
	 */
	public function countRegistrations( array $filters ): int {
		global $wpdb;

		list( $where, $args ) = $this->registrationWhere( $filters );

		if ( array() === $args ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->registrations()}" );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->registrations()}{$where}", $args ) );
	}

	/**
	 * Zwraca unikalne klucze typów zgłoszeń obecne w evencie.
	 *
	 * @param int $event_id ID eventu.
	 *
	 * @return array<int,string>
	 */
	public function distinctTypeKeys( int $event_id ): array {
		global $wpdb;

		$keys = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT DISTINCT type_key FROM {$this->registrations()} WHERE event_id = %d ORDER BY type_key ASC", $event_id )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return array_map( 'strval', is_array( $keys ) ? $keys : array() );
	}
```

- [ ] **Step 4: Uruchom test i potwierdź GREEN**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RegistrationListingTest
```
Expected: PASS, 7 testów.

- [ ] **Step 5: Styl, statyka, regresja repo**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RegistrationRepositoryTest
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs src/Persistence
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=512M
```
Expected: zielone, zero błędów.

- [ ] **Step 6: Commit**

```bash
git add src/Persistence/RegistrationRepository.php tests/Integration/Persistence/RegistrationListingTest.php
git commit -m "feat: add registration listing queries (paginate, count, distinct types)"
```

---

## Task 2: RegistrationRepository — mutacje statusu, notatki, kasowania

**Files:**
- Modify: `src/Persistence/RegistrationRepository.php`
- Test: `tests/Integration/Persistence/RegistrationMutationsTest.php`

**Interfaces:**
- Consumes: `insertRegistration()`, `insertAccommodationBooking()`, `findById()`, `findAccommodationBooking()`, `RegistrationStatus`, prywatne `registrations()`/`bookings()`, `Migrations::table('mail_queue')`
- Produces:
  - `markCancelled( int $id ): void`
  - `markPending( int $id, string $expires_at ): void`
  - `deleteAccommodationBooking( int $id ): void`
  - `deleteMailQueueByRegistration( int $id ): void`
  - `hardDelete( int $id ): void`
  - `updateNote( int $id, string $note ): void`

- [ ] **Step 1: Napisz test (RED)**

`tests/Integration/Persistence/RegistrationMutationsTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Persistence;

use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use WP_UnitTestCase;

final class RegistrationMutationsTest extends WP_UnitTestCase {

	private RegistrationRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'mail_queue' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$this->repository = new RegistrationRepository();
	}

	private function seed( string $status = 'pending' ): int {
		return $this->repository->insertRegistration(
			array(
				'event_id'    => 1,
				'type_key'    => 'uczestnik',
				'status'      => $status,
				'email'       => 'jan@example.com',
				'name'        => 'Jan',
				'token'       => str_repeat( 'a', 32 ),
				'data'        => '{}',
				'price_total' => 0.0,
				'expires_at'  => '2099-01-01 00:00:00',
			)
		);
	}

	public function test_mark_cancelled(): void {
		$id = $this->seed( 'pending' );

		$this->repository->markCancelled( $id );

		$this->assertSame( 'cancelled', $this->repository->findById( $id )['status'] );
	}

	public function test_mark_pending_sets_expires_at(): void {
		$id = $this->seed( 'waitlist' );

		$this->repository->markPending( $id, '2030-01-01 12:00:00' );

		$row = $this->repository->findById( $id );
		$this->assertSame( 'pending', $row['status'] );
		$this->assertSame( '2030-01-01 12:00:00', $row['expires_at'] );
	}

	public function test_delete_accommodation_booking(): void {
		$id = $this->seed();
		$this->repository->insertAccommodationBooking( $id, new AccommodationSelection( 'n12', 'double' ), 100.0 );
		$this->assertNotNull( $this->repository->findAccommodationBooking( $id ) );

		$this->repository->deleteAccommodationBooking( $id );

		$this->assertNull( $this->repository->findAccommodationBooking( $id ) );
	}

	public function test_delete_mail_queue_by_registration(): void {
		$id = $this->seed();
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Migrations::table( 'mail_queue' ),
			array(
				'registration_id' => $id,
				'event_id'        => 1,
				'template_key'    => 'optin',
				'recipient'       => 'jan@example.com',
				'subject'         => 'S',
				'body'            => 'B',
				'headers'         => '',
				'status'          => 'sent',
				'attempts'        => 1,
				'scheduled_at'    => '2026-08-19 10:00:00',
			)
		);

		$this->repository->deleteMailQueueByRegistration( $id );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::table( 'mail_queue' ) . ' WHERE registration_id = %d', $id ) );
		$this->assertSame( 0, $count );
	}

	public function test_hard_delete(): void {
		$id = $this->seed( 'cancelled' );

		$this->repository->hardDelete( $id );

		$this->assertNull( $this->repository->findById( $id ) );
	}

	public function test_update_note(): void {
		$id = $this->seed();

		$this->repository->updateNote( $id, "Zadzwonił,\npotwierdził telefonicznie." );

		$this->assertSame( "Zadzwonił,\npotwierdził telefonicznie.", $this->repository->findById( $id )['note'] );
	}
}
```

- [ ] **Step 2: Uruchom test i potwierdź RED**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RegistrationMutationsTest
```
Expected: FAIL — `Call to undefined method ...::markCancelled()`.

- [ ] **Step 3: Zaimplementuj mutacje**

W `src/Persistence/RegistrationRepository.php`, po `markConfirmed()`, dodaj:

```php
	/**
	 * Ustawia status zgłoszenia na anulowany.
	 *
	 * @param int $id ID zgłoszenia.
	 */
	public function markCancelled( int $id ): void {
		global $wpdb;

		$wpdb->update(
			$this->registrations(),
			array(
				'status'     => RegistrationStatus::Cancelled->value,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Ustawia status na pending i nowy termin wygaśnięcia (promocja z waitlisty).
	 *
	 * @param int    $id         ID zgłoszenia.
	 * @param string $expires_at Termin wygaśnięcia (Y-m-d H:i:s, UTC).
	 */
	public function markPending( int $id, string $expires_at ): void {
		global $wpdb;

		$wpdb->update(
			$this->registrations(),
			array(
				'status'     => RegistrationStatus::Pending->value,
				'expires_at' => $expires_at,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Kasuje rezerwacje noclegowe zgłoszenia.
	 *
	 * @param int $id ID zgłoszenia.
	 */
	public function deleteAccommodationBooking( int $id ): void {
		global $wpdb;

		$wpdb->delete( $this->bookings(), array( 'registration_id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Kasuje wiersze kolejki maili powiązane ze zgłoszeniem (sprzątanie osieroconych).
	 *
	 * @param int $id ID zgłoszenia.
	 */
	public function deleteMailQueueByRegistration( int $id ): void {
		global $wpdb;

		$wpdb->delete( Migrations::table( 'mail_queue' ), array( 'registration_id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Trwale kasuje wiersz zgłoszenia.
	 *
	 * @param int $id ID zgłoszenia.
	 */
	public function hardDelete( int $id ): void {
		global $wpdb;

		$wpdb->delete( $this->registrations(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Zapisuje notatkę organizatora.
	 *
	 * @param int    $id   ID zgłoszenia.
	 * @param string $note Treść notatki.
	 */
	public function updateNote( int $id, string $note ): void {
		global $wpdb;

		$wpdb->update(
			$this->registrations(),
			array(
				'note'       => $note,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
```

Uwaga: `Migrations` jest w tej samej przestrzeni `EvReg\Persistence` co `RegistrationRepository` (`registrations()`/`bookings()` już wołają `Migrations::table(...)` bez `use`) — `deleteMailQueueByRegistration` używa `Migrations::table( 'mail_queue' )` tak samo, bez dodatkowego importu.

- [ ] **Step 4: Uruchom test i potwierdź GREEN**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RegistrationMutationsTest
```
Expected: PASS, 6 testów.

- [ ] **Step 5: Styl i statyka**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs src/Persistence
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=512M
```
Expected: zero błędów.

- [ ] **Step 6: Commit**

```bash
git add src/Persistence/RegistrationRepository.php tests/Integration/Persistence/RegistrationMutationsTest.php
git commit -m "feat: add registration status/note/delete mutations"
```

---
## Task 3: AdminActionResult, confirmManually, cancel

**Files:**
- Create: `src/Services/AdminActionResult.php`
- Modify: `src/Services/ReservationService.php`
- Test: `tests/Integration/Services/AdminActionsTest.php`

**Interfaces:**
- Consumes: `RegistrationRepository` (`findById`, `markConfirmed`, `markCancelled`, `deleteAccommodationBooking`), `RegistrationStatus`
- Produces:
  - `EvReg\Services\AdminActionResult` — `code` (string), `reason` (?string); statyczne `confirmed()`, `cancelled()`, `promoted()`, `deleted()`, `rejected(?string)`, `invalidStatus()`, `notFound()`
  - `ReservationService::confirmManually( int $id ): AdminActionResult`
  - `ReservationService::cancel( int $id ): AdminActionResult`

- [ ] **Step 1: Napisz test (RED)**

`tests/Integration/Services/AdminActionsTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Services;

use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Mail\Subscriber;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Services\ReservationService;
use WP_UnitTestCase;

final class AdminActionsTest extends WP_UnitTestCase {

	private ReservationService $service;

	private RegistrationRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'locks', 'mail_queue' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$this->repository = new RegistrationRepository();
		$this->service    = new ReservationService( $this->repository, new EventConfigRepository() );
	}

	protected function tearDown(): void {
		foreach ( array( 'evreg_registration_reserved', 'evreg_registration_waitlisted', 'evreg_registration_confirmed', 'evreg_registration_expired' ) as $hook ) {
			remove_all_actions( $hook );
		}
		parent::tearDown();
	}

	private function seed( string $status = 'pending' ): int {
		return $this->repository->insertRegistration(
			array(
				'event_id'    => 1,
				'type_key'    => 'uczestnik',
				'status'      => $status,
				'email'       => 'jan@example.com',
				'name'        => 'Jan',
				'token'       => str_repeat( 'a', 32 ),
				'data'        => '{}',
				'price_total' => 0.0,
				'expires_at'  => '2099-01-01 00:00:00',
			)
		);
	}

	/**
	 * Liczy wiersze kolejki maili danego typu.
	 */
	private function mail_count( string $template_key ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::table( 'mail_queue' ) . ' WHERE template_key = %s', $template_key ) );
	}

	public function test_confirm_manually_sets_confirmed_and_sends_no_mail(): void {
		Subscriber::register();
		$id = $this->seed( 'pending' );

		$result = $this->service->confirmManually( $id );

		$this->assertSame( 'confirmed', $result->code );
		$row = $this->repository->findById( $id );
		$this->assertSame( 'confirmed', $row['status'] );
		$this->assertNotNull( $row['confirmed_at'] );
		$this->assertSame( 0, $this->mail_count( 'confirmed' ) );
	}

	public function test_confirm_manually_rejects_non_pending(): void {
		$id = $this->seed( 'waitlist' );

		$result = $this->service->confirmManually( $id );

		$this->assertSame( 'invalid_status', $result->code );
		$this->assertSame( 'waitlist', $this->repository->findById( $id )['status'] );
	}

	public function test_confirm_manually_not_found(): void {
		$this->assertSame( 'not_found', $this->service->confirmManually( 987654 )->code );
	}

	public function test_cancel_releases_seat_and_deletes_booking(): void {
		$id = $this->seed( 'confirmed' );
		$this->repository->insertAccommodationBooking( $id, new AccommodationSelection( 'n12', 'double' ), 100.0 );

		$result = $this->service->cancel( $id );

		$this->assertSame( 'cancelled', $result->code );
		$this->assertSame( 'cancelled', $this->repository->findById( $id )['status'] );
		$this->assertNull( $this->repository->findAccommodationBooking( $id ) );
	}

	public function test_cancel_works_from_waitlist(): void {
		$id = $this->seed( 'waitlist' );

		$this->assertSame( 'cancelled', $this->service->cancel( $id )->code );
	}

	public function test_cancel_rejects_already_cancelled(): void {
		$id = $this->seed( 'cancelled' );

		$this->assertSame( 'invalid_status', $this->service->cancel( $id )->code );
	}
}
```

- [ ] **Step 2: Uruchom test i potwierdź RED**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter AdminActionsTest
```
Expected: FAIL — `Class "EvReg\Services\AdminActionResult" not found` / `Call to undefined method ...::confirmManually()`.

- [ ] **Step 3: Zaimplementuj AdminActionResult**

`src/Services/AdminActionResult.php`:

```php
<?php
/**
 * Wynik akcji administracyjnej na zgłoszeniu.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Wynik akcji administracyjnej na zgłoszeniu.
 */
final class AdminActionResult {

	/**
	 * Tworzy wynik.
	 *
	 * @param string      $code   Kod: confirmed|cancelled|promoted|deleted|rejected|invalid_status|not_found.
	 * @param string|null $reason Kod powodu (np. przy rejected), jeśli dotyczy.
	 */
	private function __construct(
		public readonly string $code,
		public readonly ?string $reason = null
	) {
	}

	/** Zgłoszenie potwierdzone ręcznie. */
	public static function confirmed(): self {
		return new self( 'confirmed' );
	}

	/** Zgłoszenie anulowane. */
	public static function cancelled(): self {
		return new self( 'cancelled' );
	}

	/** Zgłoszenie awansowane z listy rezerwowej. */
	public static function promoted(): self {
		return new self( 'promoted' );
	}

	/** Zgłoszenie trwale usunięte. */
	public static function deleted(): self {
		return new self( 'deleted' );
	}

	/**
	 * Akcja odrzucona przez limity (promocja bez miejsca).
	 *
	 * @param string|null $reason Kod powodu.
	 */
	public static function rejected( ?string $reason = null ): self {
		return new self( 'rejected', $reason );
	}

	/** Status startowy nie pozwala na akcję. */
	public static function invalidStatus(): self {
		return new self( 'invalid_status' );
	}

	/** Nie znaleziono zgłoszenia. */
	public static function notFound(): self {
		return new self( 'not_found' );
	}
}
```

- [ ] **Step 4: Zaimplementuj confirmManually i cancel**

W `src/Services/ReservationService.php`, po `confirm()`, dodaj (importy `AdminActionResult` nie trzeba — ta sama przestrzeń `EvReg\Services`):

```php
	/**
	 * Ręcznie potwierdza zgłoszenie pending BEZ wysyłki maila.
	 *
	 * Nie emituje evreg_registration_confirmed — inaczej Subscriber (4A) wysłałby mail.
	 * Dla przypadków, gdy uczestnik potwierdził telefonicznie.
	 *
	 * @param int $id ID zgłoszenia.
	 */
	public function confirmManually( int $id ): AdminActionResult {
		$row = $this->repository->findById( $id );

		if ( null === $row ) {
			return AdminActionResult::notFound();
		}

		if ( RegistrationStatus::Pending->value !== $row['status'] ) {
			return AdminActionResult::invalidStatus();
		}

		$this->repository->markConfirmed( $id );

		return AdminActionResult::confirmed();
	}

	/**
	 * Anuluje zgłoszenie (miękkie): status=cancelled, kasuje nocleg, zwalnia miejsce.
	 *
	 * Anulowanie tylko zmniejsza zajętość, więc nie wymaga blokady lock→count.
	 *
	 * @param int $id ID zgłoszenia.
	 */
	public function cancel( int $id ): AdminActionResult {
		global $wpdb;

		$row = $this->repository->findById( $id );

		if ( null === $row ) {
			return AdminActionResult::notFound();
		}

		$cancellable = array(
			RegistrationStatus::Pending->value,
			RegistrationStatus::Confirmed->value,
			RegistrationStatus::Waitlist->value,
		);

		if ( ! in_array( (string) $row['status'], $cancellable, true ) ) {
			return AdminActionResult::invalidStatus();
		}

		$wpdb->query( 'START TRANSACTION' );

		try {
			$this->repository->markCancelled( $id );
			$this->repository->deleteAccommodationBooking( $id );
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}

		return AdminActionResult::cancelled();
	}
```

Dodaj `use EvReg\Services\AdminActionResult;`? Nie — `AdminActionResult` jest w `EvReg\Services`, tej samej co `ReservationService`. Bez `use`.

- [ ] **Step 5: Uruchom test i potwierdź GREEN**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter AdminActionsTest
```
Expected: PASS, 6 testów.

- [ ] **Step 6: Regresja 3A, styl, statyka**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter 'ReservationServiceTest|ConfirmationTest'
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs src/Services
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=512M
```
Expected: istniejące testy serwisu zielone, zero błędów.

- [ ] **Step 7: Commit**

```bash
git add src/Services/AdminActionResult.php src/Services/ReservationService.php tests/Integration/Services/AdminActionsTest.php
git commit -m "feat: add manual confirm and soft cancel admin actions"
```

---

## Task 4: promoteFromWaitlist, deleteRegistration

**Files:**
- Modify: `src/Services/ReservationService.php`
- Test: `tests/Integration/Services/PromoteFromWaitlistTest.php`

**Interfaces:**
- Consumes: `AdminActionResult`, `RegistrationRepository` (`findById`, `findAccommodationBooking`, `lockEvent`, `occupancy`, `markPending`, `deleteAccommodationBooking`, `deleteMailQueueByRegistration`, `hardDelete`), `EventConfigRepository::get`, `CapacityCalculator`, `CapacityLimits`, `Outcome`, `RegistrationTypeCollection`, `AccommodationConfig`, `AccommodationSelection`, `RegistrationStatus`, `PENDING_TTL`
- Produces:
  - `ReservationService::promoteFromWaitlist( int $id ): AdminActionResult`
  - `ReservationService::deleteRegistration( int $id ): AdminActionResult`

- [ ] **Step 1: Napisz test (RED)**

`tests/Integration/Services/PromoteFromWaitlistTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Services;

use EvReg\Mail\Subscriber;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Services\ReservationService;
use WP_UnitTestCase;

final class PromoteFromWaitlistTest extends WP_UnitTestCase {

	private ReservationService $service;

	private RegistrationRepository $repository;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'locks', 'mail_queue' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$config           = new EventConfigRepository();
		$this->repository = new RegistrationRepository();
		$this->service    = new ReservationService( $this->repository, $config );
		$this->event_id   = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );

		// Typ z limitem 1 miejsca.
		$config->save(
			$this->event_id,
			array(
				'types'    => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 0.0, 'capacity' => 1 ) ),
				'settings' => array( 'waitlist_enabled' => true ),
			)
		);
	}

	protected function tearDown(): void {
		foreach ( array( 'evreg_registration_reserved', 'evreg_registration_waitlisted', 'evreg_registration_confirmed', 'evreg_registration_expired' ) as $hook ) {
			remove_all_actions( $hook );
		}
		parent::tearDown();
	}

	private function seed( string $status, string $token ): int {
		return $this->repository->insertRegistration(
			array(
				'event_id'    => $this->event_id,
				'type_key'    => 'uczestnik',
				'status'      => $status,
				'email'       => $token . '@example.com',
				'name'        => 'Jan',
				'token'       => $token,
				'data'        => '{}',
				'price_total' => 0.0,
				'expires_at'  => null,
			)
		);
	}

	private function mail_count( string $template_key ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::table( 'mail_queue' ) . ' WHERE template_key = %s', $template_key ) );
	}

	public function test_promote_when_seat_free(): void {
		Subscriber::register();
		$id = $this->seed( 'waitlist', str_repeat( 'a', 32 ) );

		$result = $this->service->promoteFromWaitlist( $id );

		$this->assertSame( 'promoted', $result->code );
		$row = $this->repository->findById( $id );
		$this->assertSame( 'pending', $row['status'] );
		$this->assertNotNull( $row['expires_at'] );
		$this->assertSame( 1, $this->mail_count( 'optin' ) );
	}

	public function test_promote_rejected_when_full(): void {
		$this->seed( 'confirmed', str_repeat( 'a', 32 ) );      // zajmuje jedyne miejsce
		$waitlisted = $this->seed( 'waitlist', str_repeat( 'b', 32 ) );

		$result = $this->service->promoteFromWaitlist( $waitlisted );

		$this->assertSame( 'rejected', $result->code );
		$this->assertSame( 'waitlist', $this->repository->findById( $waitlisted )['status'] );
	}

	public function test_promote_rejects_non_waitlist(): void {
		$id = $this->seed( 'pending', str_repeat( 'a', 32 ) );

		$this->assertSame( 'invalid_status', $this->service->promoteFromWaitlist( $id )->code );
	}

	public function test_delete_registration_removes_cancelled_and_orphans(): void {
		$id = $this->seed( 'cancelled', str_repeat( 'a', 32 ) );
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Migrations::table( 'mail_queue' ),
			array(
				'registration_id' => $id,
				'event_id'        => $this->event_id,
				'template_key'    => 'optin',
				'recipient'       => 'jan@example.com',
				'subject'         => 'S',
				'body'            => 'B',
				'headers'         => '',
				'status'          => 'sent',
				'attempts'        => 1,
				'scheduled_at'    => '2026-08-19 10:00:00',
			)
		);

		$result = $this->service->deleteRegistration( $id );

		$this->assertSame( 'deleted', $result->code );
		$this->assertNull( $this->repository->findById( $id ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::table( 'mail_queue' ) . ' WHERE registration_id = %d', $id ) ) );
	}

	public function test_delete_registration_rejects_active(): void {
		$id = $this->seed( 'pending', str_repeat( 'a', 32 ) );

		$this->assertSame( 'invalid_status', $this->service->deleteRegistration( $id )->code );
		$this->assertNotNull( $this->repository->findById( $id ) );
	}
}
```

- [ ] **Step 2: Uruchom test i potwierdź RED**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter PromoteFromWaitlistTest
```
Expected: FAIL — `Call to undefined method ...::promoteFromWaitlist()`.

- [ ] **Step 3: Zaimplementuj promoteFromWaitlist**

W `src/Services/ReservationService.php`, po `cancel()`, dodaj. **Najpierw dodaj import** `use EvReg\Domain\Accommodation\AccommodationSelection;` na górze pliku (obok innych `use` z `EvReg\Domain\Accommodation\`) — `reserve()` go nie importuje (dostaje `selection` przez `ReservationRequest`), ale `promoteFromWaitlist` konstruuje `new AccommodationSelection(...)`. Metoda powtarza wzorzec `reserve` z inwariantem lock→count:

```php
	/**
	 * Awansuje zgłoszenie z listy rezerwowej na pending, jeśli jest miejsce.
	 *
	 * Powtarza inwariant lock→count z reserve(): lockEvent PRZED occupancy. Przy wolnym
	 * miejscu: waitlist→pending + nowy expires_at, emituje evreg_registration_reserved
	 * (mail opt-in z 4A). Brak miejsc: rejected. Hook emitowany po COMMIT.
	 *
	 * @param int $id ID zgłoszenia.
	 */
	public function promoteFromWaitlist( int $id ): AdminActionResult {
		global $wpdb;

		$row = $this->repository->findById( $id );

		if ( null === $row ) {
			return AdminActionResult::notFound();
		}

		if ( RegistrationStatus::Waitlist->value !== $row['status'] ) {
			return AdminActionResult::invalidStatus();
		}

		$event_id      = (int) $row['event_id'];
		$config        = $this->config->get( $event_id );
		$types         = RegistrationTypeCollection::fromArray( is_array( $config['types'] ) ? $config['types'] : array() );
		$accommodation = AccommodationConfig::fromArray( is_array( $config['accommodation'] ) ? $config['accommodation'] : array() );
		$settings      = is_array( $config['settings'] ) ? $config['settings'] : array();

		$booking   = $this->repository->findAccommodationBooking( $id );
		$selection = null === $booking
			? null
			: new AccommodationSelection( (string) $booking['package_key'], (string) $booking['room_type_key'], (string) ( $booking['roommate_pref'] ?? '' ) );

		$wpdb->query( 'START TRANSACTION' );

		try {
			// KRYTYCZNA KOLEJNOŚĆ: lockEvent PRZED occupancy (jak reserve). NIE ZMIENIAJ.
			$this->repository->lockEvent( $event_id );

			$limits = new CapacityLimits(
				isset( $settings['global_cap'] ) && null !== $settings['global_cap'] ? (int) $settings['global_cap'] : null,
				$types->capacities(),
				$accommodation->capacities(),
				(bool) ( $settings['waitlist_enabled'] ?? true )
			);

			$decision = $this->calculator->decide(
				$limits,
				$this->repository->occupancy( $event_id ),
				(string) $row['type_key'],
				$selection
			);

			if ( Outcome::Approved !== $decision->outcome ) {
				$wpdb->query( 'ROLLBACK' );
				return AdminActionResult::rejected( null === $decision->reason ? null : (string) $decision->reason );
			}

			$expires_at = gmdate( 'Y-m-d H:i:s', strtotime( self::PENDING_TTL, time() ) );
			$this->repository->markPending( $id, $expires_at );

			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}

		do_action( 'evreg_registration_reserved', $id, $event_id, (string) $row['token'] );

		return AdminActionResult::promoted();
	}

	/**
	 * Trwale usuwa anulowane zgłoszenie wraz z noclegiem i osieroconymi wierszami kolejki.
	 *
	 * @param int $id ID zgłoszenia.
	 */
	public function deleteRegistration( int $id ): AdminActionResult {
		global $wpdb;

		$row = $this->repository->findById( $id );

		if ( null === $row ) {
			return AdminActionResult::notFound();
		}

		if ( RegistrationStatus::Cancelled->value !== $row['status'] ) {
			return AdminActionResult::invalidStatus();
		}

		$wpdb->query( 'START TRANSACTION' );

		try {
			$this->repository->deleteAccommodationBooking( $id );
			$this->repository->deleteMailQueueByRegistration( $id );
			$this->repository->hardDelete( $id );
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}

		return AdminActionResult::deleted();
	}
```

Uwaga: promocja awansuje tylko przy `Outcome::Approved` (jest miejsce). `Waitlisted` (brak miejsca w głównej puli, ale waitlist włączony) traktujemy jak odrzucenie — awans z waitlisty na waitlist nie ma sensu. Stąd `Outcome::Approved !== $decision->outcome` → rejected.

- [ ] **Step 4: Uruchom test i potwierdź GREEN**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter PromoteFromWaitlistTest
```
Expected: PASS, 5 testów.

- [ ] **Step 5: Regresja współbieżności 3A (inwariant nietknięty), styl, statyka**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter 'ReservationConcurrencyTest|ReservationServiceTest'
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs src/Services
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=512M
```
Expected: test współbieżności 3A zielony (mechanizm blokady nietknięty — promocja go reużywa), zero błędów.

- [ ] **Step 6: Commit**

```bash
git add src/Services/ReservationService.php tests/Integration/Services/PromoteFromWaitlistTest.php
git commit -m "feat: add waitlist promotion under lock and permanent delete"
```

---
## Task 5: RegistrationsListTable (WP_List_Table)

**Files:**
- Create: `src/Admin/RegistrationsListTable.php`
- Test: `tests/Integration/Admin/RegistrationsListTableTest.php`

**Interfaces:**
- Consumes: `RegistrationRepository::paginateRegistrations/countRegistrations/distinctTypeKeys`, `EventConfigRepository::get`, `RegistrationStatus`, `RegistrationTypeCollection`, `EventPostType`, `\WP_List_Table`
- Produces:
  - `RegistrationsListTable::PER_PAGE = 20`
  - `RegistrationsListTable::status_label( string $status ): string` (public static — użyta też przez ekran w Tasku 6)
  - `get_columns(): array<string,string>`, `prepare_items(): void`, `column_default( $item, $column ): string`, `handle_row_actions( $item, $column, $primary ): string`
  - Slug strony (literał): `'evreg-registrations'` (stała `RegistrationsScreen::SLUG` powstaje w Tasku 6, ta sama wartość)
  - Nazwy akcji (literały): `evreg_reg_confirm`, `evreg_reg_cancel`, `evreg_reg_promote`, `evreg_reg_delete` (stałe w Tasku 6)

- [ ] **Step 1: Napisz test (RED)**

`tests/Integration/Admin/RegistrationsListTableTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Admin;

use EvReg\Admin\RegistrationsListTable;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use WP_UnitTestCase;

final class RegistrationsListTableTest extends WP_UnitTestCase {

	private RegistrationRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( 'registrations' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->repository = new RegistrationRepository();
		set_current_screen( 'evreg_event_page_evreg-registrations' );
	}

	private function seed( string $status, string $token ): int {
		return $this->repository->insertRegistration(
			array(
				'event_id'    => 1,
				'type_key'    => 'uczestnik',
				'status'      => $status,
				'email'       => $token . '@example.com',
				'name'        => 'Jan',
				'token'       => $token,
				'data'        => '{}',
				'price_total' => 0.0,
				'expires_at'  => null,
			)
		);
	}

	public function test_columns_present(): void {
		$table = new RegistrationsListTable();

		$cols = $table->get_columns();

		foreach ( array( 'name', 'email', 'type', 'status', 'event' ) as $c ) {
			$this->assertArrayHasKey( $c, $cols );
		}
	}

	public function test_prepare_items_loads_and_filters(): void {
		$this->seed( 'pending', str_repeat( 'a', 32 ) );
		$this->seed( 'waitlist', str_repeat( 'b', 32 ) );

		$_REQUEST['status'] = 'waitlist';
		$_GET['status']     = 'waitlist';

		$table = new RegistrationsListTable();
		$table->prepare_items();

		unset( $_REQUEST['status'], $_GET['status'] );

		$this->assertCount( 1, $table->items );
		$this->assertSame( 'waitlist', $table->items[0]['status'] );
	}

	public function test_status_label_translates(): void {
		$this->assertNotSame( 'failed', RegistrationsListTable::status_label( 'confirmed' ) );
		$this->assertNotSame( '', RegistrationsListTable::status_label( 'confirmed' ) );
		$this->assertSame( 'nieznany', RegistrationsListTable::status_label( 'nieznany' ) );
	}

	public function test_column_default_escapes(): void {
		$table = new RegistrationsListTable();

		$out = $table->column_default( array( 'name' => '<b>Jan</b>', 'status' => 'pending', 'event_id' => 0 ), 'name' );

		$this->assertStringNotContainsString( '<b>', $out );
	}

	public function test_row_actions_depend_on_status(): void {
		$table = new RegistrationsListTable();

		$pending  = $table->handle_row_actions( array( 'id' => 1, 'status' => 'pending' ), 'name', 'name' );
		$waitlist = $table->handle_row_actions( array( 'id' => 2, 'status' => 'waitlist' ), 'name', 'name' );
		$cancelled = $table->handle_row_actions( array( 'id' => 3, 'status' => 'cancelled' ), 'name', 'name' );

		$this->assertStringContainsString( 'evreg_reg_confirm', $pending );
		$this->assertStringNotContainsString( 'evreg_reg_confirm', $waitlist );
		$this->assertStringContainsString( 'evreg_reg_promote', $waitlist );
		$this->assertStringContainsString( 'evreg_reg_delete', $cancelled );
		$this->assertStringNotContainsString( 'evreg_reg_delete', $pending );
	}
}
```

- [ ] **Step 2: Uruchom test i potwierdź RED**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RegistrationsListTableTest
```
Expected: FAIL — `Class "EvReg\Admin\RegistrationsListTable" not found`.

- [ ] **Step 3: Zaimplementuj RegistrationsListTable**

`src/Admin/RegistrationsListTable.php`:

```php
<?php
/**
 * Tabela zgłoszeń w adminie.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Admin;

use EvReg\Domain\Registration\RegistrationStatus;
use EvReg\Domain\Registration\RegistrationTypeCollection;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\RegistrationRepository;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Tabela zgłoszeń: lista wierszy evreg_registrations z filtrami i akcjami cyklu życia.
 */
final class RegistrationsListTable extends \WP_List_Table {

	public const PER_PAGE = 20;

	/**
	 * Repozytorium zgłoszeń.
	 *
	 * @var RegistrationRepository
	 */
	private RegistrationRepository $repository;

	/**
	 * Repozytorium konfiguracji (etykiety typów).
	 *
	 * @var EventConfigRepository
	 */
	private EventConfigRepository $config;

	/**
	 * Tworzy tabelę.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'evreg_registration',
				'plural'   => 'evreg_registrations',
				'ajax'     => false,
			)
		);

		$this->repository = new RegistrationRepository();
		$this->config     = new EventConfigRepository();
	}

	/**
	 * Zwraca przetłumaczoną etykietę statusu (fallback: surowa wartość).
	 *
	 * @param string $status Wartość statusu.
	 */
	public static function status_label( string $status ): string {
		$labels = array(
			RegistrationStatus::Pending->value   => __( 'Oczekuje', 'event-registration' ),
			RegistrationStatus::Confirmed->value => __( 'Potwierdzone', 'event-registration' ),
			RegistrationStatus::Waitlist->value  => __( 'Lista rezerwowa', 'event-registration' ),
			RegistrationStatus::Cancelled->value => __( 'Anulowane', 'event-registration' ),
		);

		return $labels[ $status ] ?? $status;
	}

	/**
	 * Definiuje kolumny listy.
	 *
	 * @return array<string,string>
	 */
	public function get_columns(): array {
		return array(
			'name'        => __( 'Imię i nazwisko', 'event-registration' ),
			'email'       => __( 'E-mail', 'event-registration' ),
			'type'        => __( 'Typ', 'event-registration' ),
			'status'      => __( 'Status', 'event-registration' ),
			'event'       => __( 'Wydarzenie', 'event-registration' ),
			'price_total' => __( 'Kwota', 'event-registration' ),
			'created_at'  => __( 'Zgłoszono', 'event-registration' ),
		);
	}

	/**
	 * Ładuje wiersze wg filtrów z żądania i ustawia paginację.
	 */
	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$filters = $this->currentFilters();
		$paged   = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$offset  = ( $paged - 1 ) * self::PER_PAGE;

		$total = $this->repository->countRegistrations( $filters );

		$this->items = $this->repository->paginateRegistrations( $filters, self::PER_PAGE, $offset );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) ceil( $total / self::PER_PAGE ),
			)
		);
	}

	/**
	 * Renderuje kolumnę domyślną, escapując wartość.
	 *
	 * @param array<string,mixed> $item   Wiersz zgłoszenia.
	 * @param string              $column Klucz kolumny.
	 */
	public function column_default( $item, $column ): string {
		if ( 'status' === $column ) {
			return esc_html( self::status_label( (string) ( $item['status'] ?? '' ) ) );
		}

		if ( 'type' === $column ) {
			return esc_html( $this->typeLabel( (int) ( $item['event_id'] ?? 0 ), (string) ( $item['type_key'] ?? '' ) ) );
		}

		if ( 'event' === $column ) {
			$title = get_the_title( (int) ( $item['event_id'] ?? 0 ) );

			return '' === $title ? '#' . (int) ( $item['event_id'] ?? 0 ) : esc_html( $title );
		}

		return esc_html( (string) ( $item[ $column ] ?? '' ) );
	}

	/**
	 * Dokłada akcje wiersza wg statusu pod pierwszą kolumną.
	 *
	 * @param array<string,mixed> $item    Wiersz zgłoszenia.
	 * @param string              $column  Aktualna kolumna.
	 * @param string              $primary Kolumna główna.
	 */
	public function handle_row_actions( $item, $column, $primary ): string {
		if ( $column !== $primary ) {
			return '';
		}

		$id       = (int) ( $item['id'] ?? 0 );
		$status   = (string) ( $item['status'] ?? '' );
		$base     = admin_url( 'edit.php?post_type=' . EventPostType::POST_TYPE );
		$actions  = array(
			'view' => sprintf(
				'<a href="%s">%s</a>',
				esc_url(
					add_query_arg(
						array(
							'page'   => 'evreg-registrations',
							'action' => 'view',
							'id'     => $id,
						),
						$base
					)
				),
				esc_html__( 'Podgląd', 'event-registration' )
			),
		);

		if ( RegistrationStatus::Pending->value === $status ) {
			$actions['confirm'] = $this->actionLink( 'evreg_reg_confirm', $id, __( 'Potwierdź', 'event-registration' ) );
		}

		if ( RegistrationStatus::Waitlist->value === $status ) {
			$actions['promote'] = $this->actionLink( 'evreg_reg_promote', $id, __( 'Promuj', 'event-registration' ) );
		}

		if ( in_array( $status, array( RegistrationStatus::Pending->value, RegistrationStatus::Confirmed->value, RegistrationStatus::Waitlist->value ), true ) ) {
			$actions['cancel'] = $this->actionLink( 'evreg_reg_cancel', $id, __( 'Anuluj', 'event-registration' ) );
		}

		if ( RegistrationStatus::Cancelled->value === $status ) {
			$actions['delete'] = $this->actionLink( 'evreg_reg_delete', $id, __( 'Usuń trwale', 'event-registration' ) );
		}

		return $this->row_actions( $actions );
	}

	/**
	 * Buduje link akcji admin-post z nonce.
	 *
	 * @param string $action Nazwa akcji.
	 * @param int    $id     ID zgłoszenia.
	 * @param string $label  Etykieta linku.
	 */
	private function actionLink( string $action, int $id, string $label ): string {
		return sprintf(
			'<a href="%s">%s</a>',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . $action . '&id=' . $id ), $action . '_' . $id ) ),
			esc_html( $label )
		);
	}

	/**
	 * Renderuje selekty filtrów nad tabelą.
	 *
	 * @param string $which Górny/dolny tablenav.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		$filters = $this->currentFilters();
		$status  = $filters['status'] ?? '';
		$type    = $filters['type_key'] ?? '';
		$event   = $filters['event_id'] ?? 0;

		echo '<div class="alignleft actions">';

		echo '<select name="status">';
		echo '<option value="">' . esc_html__( 'Wszystkie statusy', 'event-registration' ) . '</option>';
		foreach ( array( RegistrationStatus::Pending, RegistrationStatus::Confirmed, RegistrationStatus::Waitlist, RegistrationStatus::Cancelled ) as $case ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $case->value ), selected( $status, $case->value, false ), esc_html( self::status_label( $case->value ) ) );
		}
		echo '</select>';

		if ( $event > 0 ) {
			echo '<select name="type_key">';
			echo '<option value="">' . esc_html__( 'Wszystkie typy', 'event-registration' ) . '</option>';
			foreach ( $this->repository->distinctTypeKeys( $event ) as $type_key ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $type_key ), selected( $type, $type_key, false ), esc_html( $this->typeLabel( $event, $type_key ) ) );
			}
			echo '</select>';
		}

		submit_button( __( 'Filtruj', 'event-registration' ), '', 'filter_action', false );

		echo '</div>';
	}

	/**
	 * Zwraca etykietę typu z configu eventu, fallback: sam klucz.
	 *
	 * @param int    $event_id ID eventu.
	 * @param string $type_key Klucz typu.
	 */
	private function typeLabel( int $event_id, string $type_key ): string {
		if ( 0 === $event_id || '' === $type_key ) {
			return $type_key;
		}

		$config = $this->config->get( $event_id );
		$types  = RegistrationTypeCollection::fromArray( is_array( $config['types'] ) ? $config['types'] : array() );
		$type   = $types->get( $type_key );

		return null === $type ? $type_key : $type->label;
	}

	/**
	 * Odczytuje filtry z żądania (sanityzacja + whitelist statusu).
	 *
	 * @return array{status?: string, type_key?: string, event_id?: int}
	 */
	private function currentFilters(): array {
		$filters = array();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['status'] ) ) : '';
		if ( '' !== $status ) {
			$filters['status'] = $status;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type_key = isset( $_GET['type_key'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['type_key'] ) ) : '';
		if ( '' !== $type_key ) {
			$filters['type_key'] = $type_key;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$event_id = isset( $_GET['event_id'] ) ? (int) $_GET['event_id'] : 0;
		if ( $event_id > 0 ) {
			$filters['event_id'] = $event_id;
		}

		return $filters;
	}
}
```

Uwaga sygnatur (jak 4B-Kolejka): `column_default`/`handle_row_actions`/`extra_tablenav` bez typów parametrów (zgodność z `WP_List_Table`); typy w docblocku. `status_label` publiczna statyczna — ekran (Task 6) jej użyje.

- [ ] **Step 4: Uruchom test i potwierdź GREEN**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RegistrationsListTableTest
```
Expected: PASS, 5 testów.

- [ ] **Step 5: Styl i statyka**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs src/Admin
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=512M
```
Expected: zero błędów.

- [ ] **Step 6: Commit**

```bash
git add src/Admin/RegistrationsListTable.php tests/Integration/Admin/RegistrationsListTableTest.php
git commit -m "feat: add registrations list table with status/type/event filters"
```

---
## Task 6: RegistrationsScreen (submenu, ekran szczegółów, pięć handlerów) i rejestracja

**Files:**
- Create: `src/Admin/RegistrationsScreen.php`
- Modify: `event-registration.php` (rejestracja na `plugins_loaded`)
- Test: `tests/Integration/Admin/RegistrationsScreenTest.php` (z pomocniczym `RegRedirectException`)

**Interfaces:**
- Consumes: `RegistrationsListTable`, `ReservationService` (`confirmManually`, `cancel`, `promoteFromWaitlist`, `deleteRegistration`), `RegistrationRepository` (`findById`, `findAccommodationBooking`, `updateNote`), `EventConfigRepository`, `EventFormLoader::load`, `FormSchema::allFields`, `AdminActionResult`, `Capabilities::CAP`, `EventPostType::POST_TYPE`
- Produces:
  - `RegistrationsScreen::SLUG = 'evreg-registrations'`; `ACTION_CONFIRM='evreg_reg_confirm'`, `ACTION_CANCEL='evreg_reg_cancel'`, `ACTION_PROMOTE='evreg_reg_promote'`, `ACTION_DELETE='evreg_reg_delete'`, `ACTION_NOTE='evreg_reg_note'`
  - `static register()`, `add_menu()`, `render()`, `handle_confirm()`, `handle_cancel()`, `handle_promote()`, `handle_delete()`, `handle_note()`

- [ ] **Step 1: Napisz test (RED)**

`tests/Integration/Admin/RegistrationsScreenTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Admin;

use EvReg\Admin\Capabilities;
use EvReg\Admin\RegistrationsScreen;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use WP_UnitTestCase;

/**
 * Wyjątek przenoszący adres redirectu, by przerwać przed exit handlera.
 */
final class RegRedirectException extends \Exception {

	public string $location;

	public function __construct( string $location ) {
		parent::__construct( 'redirect' );
		$this->location = $location;
	}
}

final class RegistrationsScreenTest extends WP_UnitTestCase {

	private RegistrationRepository $repository;

	private int $admin_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'locks', 'mail_queue' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$this->repository = new RegistrationRepository();
		Capabilities::grant();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	protected function tearDown(): void {
		remove_all_filters( 'wp_redirect' );
		unset( $_POST['id'], $_REQUEST['id'], $_REQUEST['_wpnonce'], $_GET['id'], $_POST['note'] );
		parent::tearDown();
	}

	private function seed( string $status ): int {
		return $this->repository->insertRegistration(
			array(
				'event_id'    => self::factory()->post->create( array( 'post_type' => 'evreg_event' ) ),
				'type_key'    => 'uczestnik',
				'status'      => $status,
				'email'       => 'jan@example.com',
				'name'        => 'Jan',
				'token'       => str_repeat( 'a', 32 ),
				'data'        => '{}',
				'price_total' => 0.0,
				'expires_at'  => '2099-01-01 00:00:00',
			)
		);
	}

	private function catchRedirect( callable $fn ): ?RegRedirectException {
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new RegRedirectException( (string) $location );
			}
		);

		try {
			$fn();
		} catch ( RegRedirectException $e ) {
			return $e;
		}

		return null;
	}

	public function test_submenu_registered(): void {
		wp_set_current_user( $this->admin_id );
		set_current_screen( 'dashboard' );

		RegistrationsScreen::add_menu();

		global $submenu;
		$slugs = array();
		foreach ( $submenu['edit.php?post_type=evreg_event'] ?? array() as $entry ) {
			$slugs[] = $entry[2];
		}

		$this->assertContains( RegistrationsScreen::SLUG, $slugs );
	}

	public function test_confirm_happy_path(): void {
		wp_set_current_user( $this->admin_id );
		$id = $this->seed( 'pending' );

		$_POST['id']          = $id;
		$_REQUEST['id']       = $id;
		$_REQUEST['_wpnonce'] = wp_create_nonce( RegistrationsScreen::ACTION_CONFIRM . '_' . $id );

		$redirect = $this->catchRedirect( array( RegistrationsScreen::class, 'handle_confirm' ) );

		$this->assertNotNull( $redirect );
		$this->assertStringContainsString( 'evreg_msg=confirmed', $redirect->location );
		$this->assertSame( 'confirmed', $this->repository->findById( $id )['status'] );
	}

	public function test_cancel_happy_path(): void {
		wp_set_current_user( $this->admin_id );
		$id = $this->seed( 'confirmed' );

		$_POST['id']          = $id;
		$_REQUEST['id']       = $id;
		$_REQUEST['_wpnonce'] = wp_create_nonce( RegistrationsScreen::ACTION_CANCEL . '_' . $id );

		$redirect = $this->catchRedirect( array( RegistrationsScreen::class, 'handle_cancel' ) );

		$this->assertStringContainsString( 'evreg_msg=cancelled', $redirect->location );
		$this->assertSame( 'cancelled', $this->repository->findById( $id )['status'] );
	}

	public function test_note_saved(): void {
		wp_set_current_user( $this->admin_id );
		$id = $this->seed( 'pending' );

		$_POST['id']          = $id;
		$_REQUEST['id']       = $id;
		$_POST['note']        = 'Notatka testowa';
		$_REQUEST['_wpnonce'] = wp_create_nonce( RegistrationsScreen::ACTION_NOTE . '_' . $id );

		$this->catchRedirect( array( RegistrationsScreen::class, 'handle_note' ) );

		$this->assertSame( 'Notatka testowa', $this->repository->findById( $id )['note'] );
	}

	public function test_confirm_without_cap_dies(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		$id = $this->seed( 'pending' );

		$_POST['id']          = $id;
		$_REQUEST['id']       = $id;
		$_REQUEST['_wpnonce'] = wp_create_nonce( RegistrationsScreen::ACTION_CONFIRM . '_' . $id );

		$this->expectException( \WPDieException::class );
		RegistrationsScreen::handle_confirm();
	}

	public function test_confirm_bad_nonce_dies(): void {
		wp_set_current_user( $this->admin_id );
		$id = $this->seed( 'pending' );

		$_POST['id']          = $id;
		$_REQUEST['id']       = $id;
		$_REQUEST['_wpnonce'] = 'zły';

		$this->expectException( \WPDieException::class );
		RegistrationsScreen::handle_confirm();
	}
}
```

- [ ] **Step 2: Uruchom test i potwierdź RED**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RegistrationsScreenTest
```
Expected: FAIL — `Class "EvReg\Admin\RegistrationsScreen" not found`.

- [ ] **Step 3: Zaimplementuj RegistrationsScreen**

`src/Admin/RegistrationsScreen.php`:

```php
<?php
/**
 * Ekran admina panelu zgłoszeń: submenu, lista, szczegóły, akcje cyklu życia.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Admin;

use EvReg\Frontend\EventFormLoader;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Services\ReservationService;

defined( 'ABSPATH' ) || exit;

/**
 * Ekran admina panelu zgłoszeń.
 */
final class RegistrationsScreen {

	public const SLUG           = 'evreg-registrations';
	public const ACTION_CONFIRM = 'evreg_reg_confirm';
	public const ACTION_CANCEL  = 'evreg_reg_cancel';
	public const ACTION_PROMOTE = 'evreg_reg_promote';
	public const ACTION_DELETE  = 'evreg_reg_delete';
	public const ACTION_NOTE    = 'evreg_reg_note';

	/**
	 * Podpina submenu i handlery akcji.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_post_' . self::ACTION_CONFIRM, array( self::class, 'handle_confirm' ) );
		add_action( 'admin_post_' . self::ACTION_CANCEL, array( self::class, 'handle_cancel' ) );
		add_action( 'admin_post_' . self::ACTION_PROMOTE, array( self::class, 'handle_promote' ) );
		add_action( 'admin_post_' . self::ACTION_DELETE, array( self::class, 'handle_delete' ) );
		add_action( 'admin_post_' . self::ACTION_NOTE, array( self::class, 'handle_note' ) );
	}

	/**
	 * Rejestruje submenu pod menu CPT wydarzeń.
	 */
	public static function add_menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . EventPostType::POST_TYPE,
			__( 'Zgłoszenia', 'event-registration' ),
			__( 'Zgłoszenia', 'event-registration' ),
			Capabilities::CAP,
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * Renderuje listę albo ekran szczegółów.
	 */
	public static function render(): void {
		if ( ! current_user_can( Capabilities::CAP ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'event-registration' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing widoku.
		$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['action'] ) ) : '';

		if ( 'view' === $action ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::render_detail( (int) ( $_GET['id'] ?? 0 ) );
			return;
		}

		self::render_list();
	}

	/**
	 * Renderuje tabelę listy z filtrami i komunikatem akcji.
	 */
	private static function render_list(): void {
		$table = new RegistrationsListTable();
		$table->prepare_items();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Zgłoszenia', 'event-registration' ) . '</h1>';

		self::render_notice();

		echo '<form method="get">';
		printf( '<input type="hidden" name="post_type" value="%s" />', esc_attr( EventPostType::POST_TYPE ) );
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( self::SLUG ) );
		$table->display();
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Renderuje komunikat wyniku akcji z parametru evreg_msg.
	 */
	private static function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['evreg_msg'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code     = sanitize_text_field( wp_unslash( (string) $_GET['evreg_msg'] ) );
		$messages = array(
			'confirmed'      => array( 'success', __( 'Zgłoszenie potwierdzone.', 'event-registration' ) ),
			'cancelled'      => array( 'success', __( 'Zgłoszenie anulowane, miejsce zwolnione.', 'event-registration' ) ),
			'promoted'       => array( 'success', __( 'Awansowano z listy rezerwowej — wysłano prośbę o potwierdzenie.', 'event-registration' ) ),
			'deleted'        => array( 'success', __( 'Zgłoszenie trwale usunięte.', 'event-registration' ) ),
			'note'           => array( 'success', __( 'Notatka zapisana.', 'event-registration' ) ),
			'rejected'       => array( 'error', __( 'Brak wolnych miejsc — nie można awansować.', 'event-registration' ) ),
			'invalid_status' => array( 'error', __( 'Akcja niedozwolona dla tego statusu.', 'event-registration' ) ),
			'not_found'      => array( 'error', __( 'Nie znaleziono zgłoszenia.', 'event-registration' ) ),
		);

		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $messages[ $code ][0] ),
			esc_html( $messages[ $code ][1] )
		);
	}

	/**
	 * Renderuje ekran szczegółów zgłoszenia (read-only + notatka).
	 *
	 * @param int $id ID zgłoszenia.
	 */
	private static function render_detail( int $id ): void {
		$repository = new RegistrationRepository();
		$row        = $repository->findById( $id );
		$back       = add_query_arg(
			array( 'post_type' => EventPostType::POST_TYPE, 'page' => self::SLUG ),
			admin_url( 'edit.php' )
		);

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Szczegóły zgłoszenia', 'event-registration' ) . '</h1>';
		printf( '<p><a href="%s">%s</a></p>', esc_url( $back ), esc_html__( '← wróć do listy', 'event-registration' ) );

		if ( null === $row ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Nie znaleziono zgłoszenia.', 'event-registration' ) . '</p></div></div>';
			return;
		}

		self::render_notice();

		$event_id = (int) $row['event_id'];

		echo '<table class="widefat striped"><tbody>';
		self::detail_row( __( 'Status', 'event-registration' ), RegistrationsListTable::status_label( (string) $row['status'] ) );
		self::detail_row( __( 'Imię i nazwisko', 'event-registration' ), (string) $row['name'] );
		self::detail_row( __( 'E-mail', 'event-registration' ), (string) $row['email'] );
		self::detail_row( __( 'Typ', 'event-registration' ), (string) $row['type_key'] );
		self::detail_row( __( 'Kwota', 'event-registration' ), (string) $row['price_total'] );
		self::detail_row( __( 'Zgłoszono', 'event-registration' ), (string) $row['created_at'] );
		self::detail_row( __( 'Potwierdzono', 'event-registration' ), (string) ( $row['confirmed_at'] ?? '' ) );
		self::detail_row( __( 'Wygasa', 'event-registration' ), (string) ( $row['expires_at'] ?? '' ) );
		echo '</tbody></table>';

		self::render_answers( $event_id, (string) $row['data'] );
		self::render_booking( $repository->findAccommodationBooking( $id ) );
		self::render_note_form( $id, (string) ( $row['note'] ?? '' ) );
		self::render_actions( $id, (string) $row['status'] );

		echo '</div>';
	}

	/**
	 * Renderuje odpowiedzi uczestnika ze złożonej schemy.
	 *
	 * @param int    $event_id ID eventu.
	 * @param string $data     JSON odpowiedzi.
	 */
	private static function render_answers( int $event_id, string $data ): void {
		$schema = ( new EventFormLoader( new EventConfigRepository() ) )->load( $event_id );

		if ( null === $schema ) {
			return;
		}

		$answers = json_decode( $data, true );
		$answers = is_array( $answers ) ? $answers : array();

		echo '<h2>' . esc_html__( 'Odpowiedzi', 'event-registration' ) . '</h2>';
		echo '<table class="widefat striped"><tbody>';

		foreach ( $schema->allFields() as $field ) {
			if ( ! $field->type->isInput() ) {
				continue;
			}

			$value = $answers[ $field->key ] ?? '';
			$text  = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
			self::detail_row( $field->label, $text );
		}

		echo '</tbody></table>';
	}

	/**
	 * Renderuje rezerwację noclegową, jeśli istnieje.
	 *
	 * @param array<string,mixed>|null $booking Wiersz bookingu albo null.
	 */
	private static function render_booking( ?array $booking ): void {
		if ( null === $booking ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Nocleg', 'event-registration' ) . '</h2>';
		echo '<table class="widefat striped"><tbody>';
		self::detail_row( __( 'Pakiet', 'event-registration' ), (string) $booking['package_key'] );
		self::detail_row( __( 'Pokój', 'event-registration' ), (string) $booking['room_type_key'] );
		self::detail_row( __( 'Współlokator', 'event-registration' ), (string) ( $booking['roommate_pref'] ?? '' ) );
		echo '</tbody></table>';
	}

	/**
	 * Renderuje formularz notatki.
	 *
	 * @param int    $id   ID zgłoszenia.
	 * @param string $note Bieżąca notatka.
	 */
	private static function render_note_form( int $id, string $note ): void {
		echo '<h2>' . esc_html__( 'Notatka organizatora', 'event-registration' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( self::ACTION_NOTE ) );
		printf( '<input type="hidden" name="id" value="%d" />', $id );
		wp_nonce_field( self::ACTION_NOTE . '_' . $id );
		printf( '<textarea name="note" rows="4" class="large-text">%s</textarea>', esc_textarea( $note ) );
		echo '<p>';
		submit_button( __( 'Zapisz notatkę', 'event-registration' ), 'secondary', 'submit', false );
		echo '</p></form>';
	}

	/**
	 * Renderuje przyciski akcji cyklu życia wg statusu.
	 *
	 * @param int    $id     ID zgłoszenia.
	 * @param string $status Status zgłoszenia.
	 */
	private static function render_actions( int $id, string $status ): void {
		$buttons = array();

		if ( 'pending' === $status ) {
			$buttons[] = self::action_button( self::ACTION_CONFIRM, $id, __( 'Potwierdź', 'event-registration' ), 'primary' );
		}
		if ( 'waitlist' === $status ) {
			$buttons[] = self::action_button( self::ACTION_PROMOTE, $id, __( 'Promuj', 'event-registration' ), 'primary' );
		}
		if ( in_array( $status, array( 'pending', 'confirmed', 'waitlist' ), true ) ) {
			$buttons[] = self::action_button( self::ACTION_CANCEL, $id, __( 'Anuluj', 'event-registration' ), 'secondary' );
		}
		if ( 'cancelled' === $status ) {
			$buttons[] = self::action_button( self::ACTION_DELETE, $id, __( 'Usuń trwale', 'event-registration' ), 'delete' );
		}

		if ( array() === $buttons ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Akcje', 'event-registration' ) . '</h2><p>' . implode( ' ', $buttons ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- przyciski już escapowane w action_button.
	}

	/**
	 * Buduje przycisk akcji (link z nonce).
	 *
	 * @param string $action Nazwa akcji.
	 * @param int    $id     ID zgłoszenia.
	 * @param string $label  Etykieta.
	 * @param string $variant Wariant klasy (primary|secondary|delete).
	 */
	private static function action_button( string $action, int $id, string $label, string $variant ): string {
		$class = 'delete' === $variant ? 'button button-link-delete' : ( 'primary' === $variant ? 'button button-primary' : 'button' );

		return sprintf(
			'<a class="%s" href="%s">%s</a>',
			esc_attr( $class ),
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . $action . '&id=' . $id ), $action . '_' . $id ) ),
			esc_html( $label )
		);
	}

	/**
	 * Renderuje jeden wiersz tabeli szczegółów.
	 *
	 * @param string $label Etykieta.
	 * @param string $value Wartość (escapowana).
	 */
	private static function detail_row( string $label, string $value ): void {
		printf( '<tr><th scope="row" style="width:180px;">%s</th><td>%s</td></tr>', esc_html( $label ), esc_html( $value ) );
	}

	/**
	 * Wspólny guard nonce+cap dla akcji, zwraca ID zgłoszenia.
	 *
	 * @param string $action Nazwa akcji (do nonce).
	 */
	private static function guard( string $action ): int {
		$id = (int) ( $_POST['id'] ?? $_GET['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		check_admin_referer( $action . '_' . $id );

		if ( ! current_user_can( Capabilities::CAP ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'event-registration' ) );
		}

		return $id;
	}

	/**
	 * Składa ReservationService.
	 */
	private static function service(): ReservationService {
		return new ReservationService( new RegistrationRepository(), new EventConfigRepository() );
	}

	/**
	 * Przekierowuje na listę z kodem komunikatu (PRG).
	 *
	 * @param string $code Kod wyniku.
	 */
	private static function redirect( string $code ): void {
		wp_safe_redirect(
			add_query_arg(
				array( 'post_type' => EventPostType::POST_TYPE, 'page' => self::SLUG, 'evreg_msg' => $code ),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/** Handler potwierdzenia. */
	public static function handle_confirm(): void {
		$id = self::guard( self::ACTION_CONFIRM );
		self::redirect( self::service()->confirmManually( $id )->code );
	}

	/** Handler anulowania. */
	public static function handle_cancel(): void {
		$id = self::guard( self::ACTION_CANCEL );
		self::redirect( self::service()->cancel( $id )->code );
	}

	/** Handler promocji z waitlisty. */
	public static function handle_promote(): void {
		$id = self::guard( self::ACTION_PROMOTE );
		self::redirect( self::service()->promoteFromWaitlist( $id )->code );
	}

	/** Handler trwałego usunięcia. */
	public static function handle_delete(): void {
		$id = self::guard( self::ACTION_DELETE );
		self::redirect( self::service()->deleteRegistration( $id )->code );
	}

	/** Handler zapisu notatki. */
	public static function handle_note(): void {
		$id   = self::guard( self::ACTION_NOTE );
		$note = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['note'] ) ) : '';

		( new RegistrationRepository() )->updateNote( $id, $note );

		self::redirect( 'note' );
	}
}
```

Uwaga: `redirect('confirmed'|...)` używa kodu z `AdminActionResult->code` — kody (`confirmed`, `cancelled`, `promoted`, `deleted`, `rejected`, `invalid_status`, `not_found`) i klucz `note` pokrywają się z mapą w `render_notice`. Handler notatki nie woła serwisu (brak wpływu na zajętość) — wprost `repository->updateNote`.

- [ ] **Step 4: Zarejestruj ekran w bootstrapie**

W `event-registration.php`, obok innych rejestracji admina na `plugins_loaded`:

```php
add_action( 'plugins_loaded', array( \EvReg\Admin\RegistrationsScreen::class, 'register' ) );
```

- [ ] **Step 5: Uruchom testy i potwierdź GREEN**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter 'RegistrationsScreenTest|RegistrationsListTableTest'
```
Expected: PASS. Gdyby `EscapeOutput` phpcs marudził na `render_actions` mimo komentarza `phpcs:ignore`, przenieś escaping tak, by każdy przycisk był budowany i escapowany osobno bez `implode` na już-escapowanych stringach — ale `action_button` zwraca już bezpieczny HTML (esc_attr/esc_url/esc_html w środku), więc `phpcs:ignore` z uzasadnieniem jest poprawny.

- [ ] **Step 6: Pełna suita, styl, statyka**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs src event-registration.php
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=512M
```
Expected: cała suita integracyjna zielona (w tym 3A/4A), zero błędów.

- [ ] **Step 7: Commit**

```bash
git add src/Admin/RegistrationsScreen.php event-registration.php tests/Integration/Admin/RegistrationsScreenTest.php
git commit -m "feat: add registrations admin screen with detail view and lifecycle actions"
```

- [ ] **Step 8: Weryfikacja w przeglądarce (kontroler, nie subagent)**

Kontroler: menu Wydarzenia → „Zgłoszenia"; lista z filtrami; Podgląd → odpowiedzi + notatka; Potwierdź (pending→confirmed, bez maila), Anuluj (zwalnia miejsce), Promuj (waitlist→pending + mail opt-in), Usuń trwale (tylko cancelled). Subagent pomija.

---

## Task 7: Dokumentacja

**Files:**
- Modify: `README.md` (Status)
- Modify: `CLAUDE.md` (panel zgłoszeń, roadmapa)

- [ ] **Step 1: Zaktualizuj README**

W sekcji Status znajdź punkt o panelu zgłoszeń (Plan 5). Oznacz część 5A jako gotową, utrzymując listę consecutively numbered. Przykład:

```
6. 🔶 Panel zgłoszeń — lista + akcje cyklu życia gotowe (5A); edycja odpowiedzi (5B) i eksport (5C) osobne plany
```

- [ ] **Step 2: Zaktualizuj CLAUDE.md**

W akapicie o roadmapie dopisz do scalonych „Plan 5A (panel zgłoszeń: lista + akcje)"; zaznacz, że 5B (edycja) i 5C (eksport) zostają.

Pod akapitami o adminie dodaj:

```
Panel zgłoszeń (5A): `RegistrationsScreen` — submenu „Zgłoszenia" pod menu CPT `evreg_event`, cap `edit_evreg_events`. `RegistrationsListTable` (WP_List_Table) listuje `evreg_registrations` z filtrami status/typ/event. Ekran szczegółów (`?action=view`) pokazuje odpowiedzi (złożona schema przez `EventFormLoader`), nocleg, notatkę (edytowalną). Akcje cyklu życia w `ReservationService` (jedyne miejsce z transakcją lock→count): `confirmManually` (pending→confirmed, BEZ maila — nie emituje hooka), `cancel` (miękkie, status=cancelled + kasuje booking, zwalnia miejsce), `promoteFromWaitlist` (waitlist→pending pod lock→count jak `reserve`, emituje `evreg_registration_reserved` → mail opt-in z 4A), `deleteRegistration` (twarde, tylko na cancelled, sprząta osierocone wiersze kolejki). Handlery admin-post nonce→cap→akcja→PRG. `AdminActionResult` niesie kod wyniku. Edycja odpowiedzi to Plan 5B, eksport CSV/XLSX to Plan 5C.
```

W sekcji Gotchas dopisz:

```
- **Ręczne potwierdzenie (`confirmManually`) NIE emituje `evreg_registration_confirmed`** — inaczej Subscriber z 4A wysłałby mail. Świadome; test pilnuje braku wiersza kolejki. Promocja z waitlisty PODLEGA temu samemu inwariantowi lock→count co `reserve` — nie zmieniać kolejności lockEvent→occupancy.
```

- [ ] **Step 3: Commit**

```bash
git add README.md CLAUDE.md
git commit -m "docs: document registrations admin panel (Plan 5A)"
```

---

## Definicja ukończenia Planu 5A

- `vendor/bin/phpunit -c phpunit-integration.xml.dist` — zielone, w tym testy 3A/4A (współbieżność, reserve, confirm, dispatcher)
- `vendor/bin/phpcs src` i `vendor/bin/phpstan analyse --memory-limit=512M` — zero błędów
- Submenu „Zgłoszenia" widoczne pod menu Wydarzenia dla `edit_evreg_events`
- Lista filtruje po statusie/typie/evencie, paginacja działa
- Podgląd pokazuje odpowiedzi, nocleg, notatkę
- Potwierdź: pending→confirmed BEZ maila (test: brak wiersza kolejki `confirmed`)
- Anuluj: → cancelled, booking skasowany, miejsce wraca
- Promuj: waitlist→pending pod lock→count + mail opt-in; brak miejsc → odrzucone; test współbieżności/limitów zielony
- Usuń trwale: tylko cancelled → wiersz + booking + wiersze kolejki skasowane
- Weryfikacja w przeglądarce (kontroler): lista, filtry, podgląd, wszystkie akcje

## Czego Plan 5A świadomie nie robi

Brak edycji odpowiedzi uczestnika (zmiana typu/noclegu, re-walidacja, przeliczenie limitów) — Plan 5B. Brak eksportu CSV/XLSX — Plan 5C. Brak bulk actions. Brak maila przy ręcznym anulowaniu. Brak promocji od razu na confirmed (wybrano ścieżkę pending z opt-in).
