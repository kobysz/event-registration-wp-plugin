# Reservation Backend (Plan 3A) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Zbudować backend cyklu życia zgłoszenia — transakcyjną rezerwację miejsc z blokadą per event, double opt-in i wygasanie niepotwierdzonych rezerwacji — w pełni testowalny bez UI.

**Architecture:** `ReservationService` opakowuje czysty `CapacityCalculator` (Plan 1) transakcją InnoDB, która blokuje jeden wiersz-zamek per event (`SELECT … FOR UPDATE`), liczy zajętość świeżo z tabeli zgłoszeń, decyduje i zapisuje — wszystko w jednej transakcji. `RegistrationRepository` to jedyny adapter dotykający `$wpdb`. Nowa czysta jednostka domenowa to tylko enum `RegistrationStatus`.

**Tech Stack:** PHP 8.1, WordPress 6.4+, MySQL/MariaDB InnoDB, PHPUnit 9.6, `wp-env` (Docker). Bez JS — frontend to Plan 3B.

**Spec:** `docs/superpowers/specs/2026-08-18-reservation-backend-design.md`

## Global Constraints

- Minimalne PHP 8.1, minimalne WordPress 6.4. Silnik tabel InnoDB (transakcje).
- Namespace `EvReg\`, PSR-4, `src/`. Prefiks `evreg_` / meta `_evreg_`.
- `src/Domain/**` bez WordPressa i bez `$wpdb` (dotyczy `RegistrationStatus`). Pilnuje `DomainPurityTest`.
- Każdy plik PHP poza `src/Domain/` i `tests/` zaczyna od `defined( 'ABSPATH' ) || exit;`.
- Domena zwraca kody/wyjątki, nie komunikaty użytkownika.
- Katalogi testów wielką literą (`tests/Integration/…`), suity małą (`--testsuite unit`).
- Metody obiektów wartości camelCase; globalne funkcje/hooki snake_case z prefiksem. `phpcs.xml.dist` ma 3 wykluczenia sniffów — nowy kod musi przejść `phpcs` czysto.
- PHPStan poziom 6, bez obniżania i rozproszonych ignore. Adnotacje typów tablicowych z kształtem.
- Wyłącznie `$wpdb->prepare`; zero konkatenacji SQL. Token 32-hex z `random_bytes`, porównanie `hash_equals`.
- Wszystkie testy w kontenerze — host nie ma PHP.

### Komendy referencyjne

Testy przez wrapper (nie gołe `npx wp-env`):

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
```

Środowiskowy kwirk (z Planu 1): konsola PHPUnit zniekształca komunikat niezłapanego `Error` — fazę RED weryfikuj jawnym try/catch. Usuń skrypty pomocnicze po użyciu.

**Izolacja testów transakcyjnych — WAŻNE.** `WP_UnitTestCase` owija każdy test w transakcję cofaną w `tearDown`. Ale `ReservationService` (i test locka) wołają własne `COMMIT` — commit utrwala wiersze, defeatując rollback, więc dane wyciekają między testami w tej samej klasie używającymi tego samego twardego `event_id`. Dlatego **każda klasa testów integracyjnych tego planu czyści tabele w `setUp()`** po `Migrations::install()`, żeby każdy test startował z pustych tabel niezależnie od zachowania commit/rollback:

```php
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'locks' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
```

Kod testów poniżej zawiera już to czyszczenie w `setUp`.

### Interfejsy z Planów 1–2A (konsumowane — zweryfikowane sygnatury)

- `EvReg\Domain\Capacity\CapacityCalculator::decide( CapacityLimits $limits, OccupancySnapshot $taken, string $typeKey, ?AccommodationSelection $selection = null ): CapacityDecision`
- `EvReg\Domain\Capacity\CapacityLimits::__construct( ?int $globalLimit, array $perType, array $perSlot, bool $waitlistEnabled = true )` — `$perType`: `array<string,int|null>`, `$perSlot`: `array<string,int>`
- `EvReg\Domain\Capacity\OccupancySnapshot::__construct( int $globalCount, array $perType = [], array $perSlot = [] )`
- `EvReg\Domain\Capacity\CapacityDecision` — readonly `outcome` (`Outcome`), `accommodationGranted` (bool), `reason` (?string); `isAccepted()`
- `EvReg\Domain\Capacity\Outcome` — `Accepted`, `Waitlisted`, `Rejected`
- `EvReg\Domain\Pricing\PriceCalculator::total( RegistrationType $type, ?AccommodationConfig $config = null, ?AccommodationSelection $selection = null ): float`
- `EvReg\Domain\Registration\RegistrationTypeCollection::fromArray( array ): self`, `get( string ): ?RegistrationType`, `capacities(): array<string,int|null>`
- `EvReg\Domain\Accommodation\AccommodationConfig::fromArray( array ): self`, `capacities(): array<string,int>` (klucz `"pakiet|pokój"`), `item( string $pkg, string $room ): ?InventoryItem`
- `EvReg\Domain\Accommodation\AccommodationSelection` — readonly `packageKey`, `roomKey`, `roommatePref`; `slotKey(): string`
- `EvReg\Persistence\EventConfigRepository::get( int $eventId ): array` → `['schema'=>array,'types'=>array,'accommodation'=>array,'settings'=>array]`
- `EvReg\Persistence\Migrations` — `DB_VERSION` (obecnie 1), `VERSION_OPTION`, `table( string ): string`, `install()`, `maybe_upgrade()`

---

## File Structure

| Plik | Odpowiedzialność |
|------|------------------|
| `src/Domain/Registration/RegistrationStatus.php` | CZYSTA: enum stanu + `occupiesSeat()` |
| `src/Persistence/Migrations.php` | Modyfikacja: `DB_VERSION` 2, tabela `evreg_locks` |
| `src/Persistence/RegistrationRepository.php` | Zapis/odczyt zgłoszeń + rezerwacji noclegowych; COUNT zajętości; lock |
| `src/Services/ReservationRequest.php` | Wejście rezerwacji (wartość) |
| `src/Services/ReservationResult.php` | Wynik rezerwacji (wartość) |
| `src/Services/ConfirmationResult.php` | Wynik potwierdzenia (wartość) |
| `src/Services/ReservationService.php` | Transakcja rezerwacji + potwierdzanie |
| `src/Cron/ExpirePending.php` | Cron wygaszający niepotwierdzone rezerwacje |
| `event-registration.php` | Modyfikacja: rejestracja interwału + hooka crona |

---

## Task 1: RegistrationStatus i tabela evreg_locks

**Files:**
- Create: `src/Domain/Registration/RegistrationStatus.php`
- Modify: `src/Persistence/Migrations.php`
- Test: `tests/Unit/Domain/Registration/RegistrationStatusTest.php`
- Test: `tests/Integration/Persistence/LocksMigrationTest.php`

**Interfaces:**
- Produces:
  - `EvReg\Domain\Registration\RegistrationStatus` (enum string): `Pending='pending'`, `Confirmed='confirmed'`, `Waitlist='waitlist'`, `Cancelled='cancelled'`; `occupiesSeat(): bool` (true dla Pending, Confirmed); `static occupyingValues(): string[]` (`['pending','confirmed']`)
  - `Migrations::table('locks')` → tabela `evreg_locks`; `Migrations::DB_VERSION === 2`

- [ ] **Step 1: Napisz failujący test enuma**

`tests/Unit/Domain/Registration/RegistrationStatusTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Registration;

use EvReg\Domain\Registration\RegistrationStatus;
use PHPUnit\Framework\TestCase;

final class RegistrationStatusTest extends TestCase {

	public function test_pending_and_confirmed_occupy_a_seat(): void {
		$this->assertTrue( RegistrationStatus::Pending->occupiesSeat() );
		$this->assertTrue( RegistrationStatus::Confirmed->occupiesSeat() );
	}

	public function test_waitlist_and_cancelled_do_not_occupy_a_seat(): void {
		$this->assertFalse( RegistrationStatus::Waitlist->occupiesSeat() );
		$this->assertFalse( RegistrationStatus::Cancelled->occupiesSeat() );
	}

	public function test_occupying_values_are_pending_and_confirmed(): void {
		$this->assertSame( array( 'pending', 'confirmed' ), RegistrationStatus::occupyingValues() );
	}
}
```

- [ ] **Step 2: Uruchom i potwierdź fail**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit --filter RegistrationStatusTest
```

Oczekiwane: FAIL — `Class "EvReg\Domain\Registration\RegistrationStatus" not found`.

- [ ] **Step 3: Zaimplementuj `RegistrationStatus`**

```php
<?php

declare( strict_types=1 );

namespace EvReg\Domain\Registration;

/**
 * Stan zgłoszenia w cyklu życia rezerwacji.
 */
enum RegistrationStatus: string {
	case Pending   = 'pending';
	case Confirmed = 'confirmed';
	case Waitlist  = 'waitlist';
	case Cancelled = 'cancelled';

	/**
	 * Czy ten status blokuje miejsce (liczy się do zajętości).
	 */
	public function occupiesSeat(): bool {
		return in_array( $this, array( self::Pending, self::Confirmed ), true );
	}

	/**
	 * Wartości statusów blokujących miejsce — do zapytań COUNT.
	 *
	 * @return string[]
	 */
	public static function occupyingValues(): array {
		return array_values(
			array_map(
				static fn ( self $status ): string => $status->value,
				array_filter( self::cases(), static fn ( self $status ): bool => $status->occupiesSeat() )
			)
		);
	}
}
```

- [ ] **Step 4: Napisz failujący test migracji locks**

`tests/Integration/Persistence/LocksMigrationTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Persistence;

use EvReg\Persistence\Migrations;
use WP_UnitTestCase;

final class LocksMigrationTest extends WP_UnitTestCase {

	public function test_install_creates_locks_table(): void {
		global $wpdb;

		Migrations::install();

		$table  = Migrations::table( 'locks' );
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		$this->assertSame( $table, $exists );
	}

	public function test_locks_table_has_event_id_primary_key(): void {
		global $wpdb;

		Migrations::install();

		$columns = $wpdb->get_col( 'DESC ' . Migrations::table( 'locks' ), 0 );

		$this->assertSame( array( 'event_id' ), $columns );
	}

	public function test_db_version_is_two(): void {
		$this->assertSame( 2, Migrations::DB_VERSION );
	}
}
```

- [ ] **Step 5: Uruchom i potwierdź fail**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter LocksMigrationTest
```

Oczekiwane: FAIL — brak tabeli `evreg_locks` / `DB_VERSION` = 1.

- [ ] **Step 6: Zmodyfikuj `Migrations.php`**

Zmień stałą:

```php
	public const DB_VERSION = 2;
```

W metodzie `statements()` (zwracającej tablicę `CREATE TABLE`) dodaj do zwracanej tablicy nową pozycję, obok istniejących tabel:

```php
			"CREATE TABLE {$locks} (
				event_id bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (event_id)
			) ENGINE=InnoDB {$charset};",
```

i wcześniej w tej metodzie, obok pozostałych zmiennych nazw tabel, dodaj:

```php
		$locks = self::table( 'locks' );
```

Zachowaj format `dbDelta`: dwie spacje po `PRIMARY KEY`, `ENGINE=InnoDB` i `$charset`.

- [ ] **Step 7: Uruchom testy**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit --filter RegistrationStatusTest
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter LocksMigrationTest
```

Oczekiwane: oba zielone (3 + 3 testy).

- [ ] **Step 8: PHPStan + WPCS + DomainPurity**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit --filter DomainPurityTest
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
```

Oczekiwane: czyste.

- [ ] **Step 9: Commit**

```bash
git add src/Domain/Registration/RegistrationStatus.php src/Persistence/Migrations.php tests/Unit/Domain/Registration tests/Integration/Persistence/LocksMigrationTest.php
git commit -m "feat: add registration status enum and evreg_locks table"
```

---

## Task 2: RegistrationRepository

**Files:**
- Create: `src/Persistence/RegistrationRepository.php`
- Test: `tests/Integration/Persistence/RegistrationRepositoryTest.php`

**Interfaces:**
- Consumes: `Migrations::table(...)`, `RegistrationStatus`, `AccommodationSelection`, `OccupancySnapshot`
- Produces:
  - `RegistrationRepository::lockEvent( int $eventId ): void`
  - `RegistrationRepository::activeRegistrationExists( int $eventId, string $email ): bool`
  - `RegistrationRepository::occupancy( int $eventId ): OccupancySnapshot`
  - `RegistrationRepository::insertRegistration( array $row ): int` — `$row` klucze: `event_id`, `type_key`, `status`, `email`, `name`, `token`, `data` (JSON string), `price_total`, `expires_at` (string|null)
  - `RegistrationRepository::insertAccommodationBooking( int $registrationId, AccommodationSelection $selection, float $price ): void`
  - `RegistrationRepository::findByToken( string $token ): ?array`
  - `RegistrationRepository::markConfirmed( int $registrationId ): void`
  - `RegistrationRepository::expirePending( string $now ): int`

**Zasada:** repozytorium tylko czyta/pisze; nie decyduje o limitach. Zajętość liczona zapytaniami `COUNT` po statusach z `RegistrationStatus::occupyingValues()`. Wszystkie zapytania przez `$wpdb->prepare`.

- [ ] **Step 1: Napisz failujące testy**

`tests/Integration/Persistence/RegistrationRepositoryTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Persistence;

use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use WP_UnitTestCase;

final class RegistrationRepositoryTest extends WP_UnitTestCase {

	private RegistrationRepository $repository;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'locks' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$this->repository = new RegistrationRepository();
		$this->event_id  = 42;
	}

	/**
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function row( array $overrides = array() ): array {
		return array_merge(
			array(
				'event_id'    => $this->event_id,
				'type_key'    => 'uczestnik',
				'status'      => 'pending',
				'email'       => 'jan@example.com',
				'name'        => 'Jan',
				'token'       => str_repeat( 'a', 32 ),
				'data'        => '{}',
				'price_total' => 450.0,
				'expires_at'  => '2099-01-01 00:00:00',
			),
			$overrides
		);
	}

	public function test_insert_returns_id_and_row_is_findable_by_token(): void {
		$id = $this->repository->insertRegistration( $this->row( array( 'token' => 'tok123' . str_repeat( '0', 26 ) ) ) );

		$this->assertGreaterThan( 0, $id );

		$found = $this->repository->findByToken( 'tok123' . str_repeat( '0', 26 ) );
		$this->assertNotNull( $found );
		$this->assertSame( 'jan@example.com', $found['email'] );
	}

	public function test_occupancy_counts_only_pending_and_confirmed(): void {
		$this->repository->insertRegistration( $this->row( array( 'status' => 'pending', 'token' => str_repeat( 'b', 32 ) ) ) );
		$this->repository->insertRegistration( $this->row( array( 'status' => 'confirmed', 'token' => str_repeat( 'c', 32 ) ) ) );
		$this->repository->insertRegistration( $this->row( array( 'status' => 'waitlist', 'token' => str_repeat( 'd', 32 ) ) ) );
		$this->repository->insertRegistration( $this->row( array( 'status' => 'cancelled', 'token' => str_repeat( 'e', 32 ) ) ) );

		$occupancy = $this->repository->occupancy( $this->event_id );

		$this->assertSame( 2, $occupancy->global() );
		$this->assertSame( 2, $occupancy->forType( 'uczestnik' ) );
	}

	public function test_occupancy_counts_accommodation_slots(): void {
		$id = $this->repository->insertRegistration( $this->row( array( 'token' => str_repeat( 'f', 32 ) ) ) );
		$this->repository->insertAccommodationBooking( $id, new AccommodationSelection( 'n12', 'double', 'Anna' ), 180.0 );

		$occupancy = $this->repository->occupancy( $this->event_id );

		$this->assertSame( 1, $occupancy->forSlot( 'n12|double' ) );
	}

	public function test_active_registration_exists_for_duplicate_email(): void {
		$this->repository->insertRegistration( $this->row( array( 'status' => 'pending', 'token' => str_repeat( 'g', 32 ) ) ) );

		$this->assertTrue( $this->repository->activeRegistrationExists( $this->event_id, 'jan@example.com' ) );
		$this->assertFalse( $this->repository->activeRegistrationExists( $this->event_id, 'inny@example.com' ) );
	}

	public function test_cancelled_registration_does_not_count_as_active(): void {
		$this->repository->insertRegistration( $this->row( array( 'status' => 'cancelled', 'token' => str_repeat( 'h', 32 ) ) ) );

		$this->assertFalse( $this->repository->activeRegistrationExists( $this->event_id, 'jan@example.com' ) );
	}

	public function test_mark_confirmed_sets_status_and_timestamp(): void {
		$id = $this->repository->insertRegistration( $this->row( array( 'token' => str_repeat( 'i', 32 ) ) ) );

		$this->repository->markConfirmed( $id );

		$found = $this->repository->findByToken( str_repeat( 'i', 32 ) );
		$this->assertSame( 'confirmed', $found['status'] );
		$this->assertNotNull( $found['confirmed_at'] );
	}

	public function test_expire_pending_cancels_past_due_and_returns_count(): void {
		$this->repository->insertRegistration( $this->row( array( 'status' => 'pending', 'expires_at' => '2000-01-01 00:00:00', 'token' => str_repeat( 'j', 32 ) ) ) );
		$this->repository->insertRegistration( $this->row( array( 'status' => 'pending', 'expires_at' => '2099-01-01 00:00:00', 'token' => str_repeat( 'k', 32 ) ) ) );

		$count = $this->repository->expirePending( '2020-01-01 00:00:00' );

		$this->assertSame( 1, $count );
		$this->assertSame( 'cancelled', $this->repository->findByToken( str_repeat( 'j', 32 ) )['status'] );
		$this->assertSame( 'pending', $this->repository->findByToken( str_repeat( 'k', 32 ) )['status'] );
	}

	public function test_lock_event_runs_without_error_inside_transaction(): void {
		global $wpdb;

		$wpdb->query( 'START TRANSACTION' );
		$this->repository->lockEvent( $this->event_id );
		$wpdb->query( 'COMMIT' );

		// Wiersz-zamek został utworzony.
		$exists = $wpdb->get_var(
			$wpdb->prepare( 'SELECT event_id FROM ' . Migrations::table( 'locks' ) . ' WHERE event_id = %d', $this->event_id )
		);
		$this->assertSame( (string) $this->event_id, (string) $exists );
	}
}
```

- [ ] **Step 2: Uruchom i potwierdź fail**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RegistrationRepositoryTest
```

Oczekiwane: FAIL — brak klasy.

- [ ] **Step 3: Zaimplementuj `RegistrationRepository`**

```php
<?php

declare( strict_types=1 );

namespace EvReg\Persistence;

use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Domain\Capacity\OccupancySnapshot;
use EvReg\Domain\Registration\RegistrationStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Odczyt i zapis zgłoszeń, rezerwacji noclegowych oraz zajętości.
 */
final class RegistrationRepository {

	private function registrations(): string {
		return Migrations::table( 'registrations' );
	}

	private function bookings(): string {
		return Migrations::table( 'accommodation_bookings' );
	}

	private function locks(): string {
		return Migrations::table( 'locks' );
	}

	/**
	 * Zapewnia i blokuje wiersz-zamek eventu. Wywoływać tylko w transakcji.
	 */
	public function lockEvent( int $event_id ): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . $this->locks() . ' (event_id) VALUES (%d)', $event_id ) );
		$wpdb->query( $wpdb->prepare( 'SELECT event_id FROM ' . $this->locks() . ' WHERE event_id = %d FOR UPDATE', $event_id ) );
	}

	public function activeRegistrationExists( int $event_id, string $email ): bool {
		global $wpdb;

		$statuses = array_merge( RegistrationStatus::occupyingValues(), array( RegistrationStatus::Waitlist->value ) );
		$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		$sql = "SELECT COUNT(*) FROM {$this->registrations()} WHERE event_id = %d AND email = %s AND status IN ($in)";

		$count = (int) $wpdb->get_var( $wpdb->prepare( $sql, array_merge( array( $event_id, $email ), $statuses ) ) );

		return $count > 0;
	}

	public function occupancy( int $event_id ): OccupancySnapshot {
		global $wpdb;

		$statuses = RegistrationStatus::occupyingValues();
		$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		$global = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->registrations()} WHERE event_id = %d AND status IN ($in)",
				array_merge( array( $event_id ), $statuses )
			)
		);

		$per_type = array();
		$rows     = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT type_key, COUNT(*) AS c FROM {$this->registrations()} WHERE event_id = %d AND status IN ($in) GROUP BY type_key",
				array_merge( array( $event_id ), $statuses )
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$per_type[ (string) $row['type_key'] ] = (int) $row['c'];
		}

		$per_slot  = array();
		$slot_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT CONCAT(b.package_key, '|', b.room_type_key) AS slot, COUNT(*) AS c
				 FROM {$this->bookings()} b
				 INNER JOIN {$this->registrations()} r ON b.registration_id = r.id
				 WHERE r.event_id = %d AND r.status IN ($in)
				 GROUP BY slot",
				array_merge( array( $event_id ), $statuses )
			),
			ARRAY_A
		);
		foreach ( (array) $slot_rows as $row ) {
			$per_slot[ (string) $row['slot'] ] = (int) $row['c'];
		}

		return new OccupancySnapshot( $global, $per_type, $per_slot );
	}

	/**
	 * @param array<string,mixed> $row
	 */
	public function insertRegistration( array $row ): int {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$wpdb->insert(
			$this->registrations(),
			array(
				'event_id'    => (int) $row['event_id'],
				'type_key'    => (string) $row['type_key'],
				'status'      => (string) $row['status'],
				'email'       => (string) $row['email'],
				'name'        => (string) $row['name'],
				'token'       => (string) $row['token'],
				'data'        => (string) $row['data'],
				'price_total' => (float) $row['price_total'],
				'created_at'  => $now,
				'updated_at'  => $now,
				'expires_at'  => $row['expires_at'],
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	public function insertAccommodationBooking( int $registration_id, AccommodationSelection $selection, float $price ): void {
		global $wpdb;

		$wpdb->insert(
			$this->bookings(),
			array(
				'registration_id' => $registration_id,
				'package_key'     => $selection->packageKey,
				'room_type_key'   => $selection->roomKey,
				'roommate_pref'   => '' === $selection->roommatePref ? null : $selection->roommatePref,
				'price'           => $price,
			),
			array( '%d', '%s', '%s', '%s', '%f' )
		);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function findByToken( string $token ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->registrations()} WHERE token = %s", $token ),
			ARRAY_A
		);

		return null === $row ? null : $row;
	}

	public function markConfirmed( int $registration_id ): void {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$wpdb->update(
			$this->registrations(),
			array(
				'status'       => RegistrationStatus::Confirmed->value,
				'confirmed_at' => $now,
				'updated_at'   => $now,
			),
			array( 'id' => $registration_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	public function expirePending( string $now ): int {
		global $wpdb;

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->registrations()} SET status = %s, updated_at = %s
				 WHERE status = %s AND expires_at IS NOT NULL AND expires_at < %s",
				RegistrationStatus::Cancelled->value,
				current_time( 'mysql', true ),
				RegistrationStatus::Pending->value,
				$now
			)
		);
	}
}
```

Uwaga: kolumny `evreg_accommodation_bookings` z Planu 1 to `package_key`, `room_type_key`, `roommate_pref`, `price` — użyj dokładnie tych nazw.

- [ ] **Step 4: Uruchom testy**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RegistrationRepositoryTest
```

Oczekiwane: PASS, 8 testów.

- [ ] **Step 5: PHPStan + WPCS**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
```

Oczekiwane: czyste. Zapytania z interpolacją nazw tabel mogą wymagać adnotacji `// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared` — nazwy tabel pochodzą z `Migrations::table()`, nie z wejścia użytkownika. Dodaj wyłącznie tam, gdzie interpolowana jest nazwa tabeli, nie wartość.

- [ ] **Step 6: Commit**

```bash
git add src/Persistence/RegistrationRepository.php tests/Integration/Persistence/RegistrationRepositoryTest.php
git commit -m "feat: add registration repository with occupancy counts and per-event lock"
```

---

## Task 3: ReservationService::reserve

**Files:**
- Create: `src/Services/ReservationRequest.php`
- Create: `src/Services/ReservationResult.php`
- Create: `src/Services/ReservationService.php`
- Test: `tests/Integration/Services/ReservationServiceTest.php`
- Test: `tests/Integration/Services/ReservationConcurrencyTest.php`

**Interfaces:**
- Consumes: `RegistrationRepository`, `EventConfigRepository`, `CapacityCalculator`, `PriceCalculator`, `RegistrationTypeCollection`, `AccommodationConfig`, `AccommodationSelection`, `RegistrationStatus`, `Outcome`
- Produces:
  - `ReservationRequest::__construct( string $email, string $name, string $typeKey, array $data, ?AccommodationSelection $selection = null )` — `$data`: `array<string,mixed>` (znormalizowane odpowiedzi)
  - `ReservationResult` — statyczne `reserved(int $id, string $token, bool $accGranted, ?string $reason)`, `waitlisted(int $id, string $token, string $reason)`, `rejected(string $reason)`, `duplicate()`; właściwości `code` (`'reserved'|'waitlisted'|'rejected'|'duplicate'`), `registrationId` (?int), `token` (?string), `accommodationGranted` (bool), `reason` (?string)
  - `ReservationService::__construct( RegistrationRepository $repo, EventConfigRepository $config )` (wewnętrznie tworzy `CapacityCalculator`, `PriceCalculator`)
  - `ReservationService::reserve( int $eventId, ReservationRequest $request ): ReservationResult`

**Zasada:** jedna transakcja InnoDB; blokada per event PRZED liczeniem zajętości; guard duplikatu i decyzja limitów w tej samej transakcji; `ROLLBACK` przy rejected/duplicate/wyjątku. Token `bin2hex( random_bytes( 16 ) )`. `expires_at = now + 48h`.

- [ ] **Step 1: Napisz failujące testy logiki rezerwacji**

`tests/Integration/Services/ReservationServiceTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Services;

use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Services\ReservationRequest;
use EvReg\Services\ReservationService;
use WP_UnitTestCase;

final class ReservationServiceTest extends WP_UnitTestCase {

	private ReservationService $service;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'locks' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$this->event_id = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
		$this->service  = new ReservationService( new RegistrationRepository(), new EventConfigRepository() );
	}

	/**
	 * @param array<string,mixed> $overrides
	 */
	private function configure( array $overrides = array() ): void {
		( new EventConfigRepository() )->save(
			$this->event_id,
			array_merge(
				array(
					'types'         => array(
						array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 450.0, 'capacity' => 2 ),
						array( 'key' => 'online', 'label' => 'Online', 'price' => 150.0 ),
					),
					'accommodation' => array(
						'packages'  => array( array( 'key' => 'n12', 'label' => 'Noc 1–2' ) ),
						'rooms'     => array( array( 'key' => 'double', 'label' => '2-os.' ) ),
						'inventory' => array( array( 'package' => 'n12', 'room' => 'double', 'capacity' => 1, 'price' => 180.0 ) ),
					),
					'settings'      => array( 'global_cap' => 3, 'waitlist_enabled' => true ),
				),
				$overrides
			)
		);
	}

	private function request( string $email, string $type = 'uczestnik', ?AccommodationSelection $sel = null ): ReservationRequest {
		return new ReservationRequest( $email, 'Jan', $type, array( 'email' => $email ), $sel );
	}

	public function test_first_registration_is_reserved_with_price(): void {
		$this->configure();

		$result = $this->service->reserve( $this->event_id, $this->request( 'a@example.com' ) );

		$this->assertSame( 'reserved', $result->code );
		$this->assertGreaterThan( 0, $result->registrationId );
		$this->assertNotEmpty( $result->token );
	}

	public function test_reserved_registration_stores_price_from_type_and_accommodation(): void {
		$this->configure();

		$result = $this->service->reserve(
			$this->event_id,
			$this->request( 'a@example.com', 'uczestnik', new AccommodationSelection( 'n12', 'double' ) )
		);

		global $wpdb;
		$price = $wpdb->get_var(
			$wpdb->prepare( 'SELECT price_total FROM ' . Migrations::table( 'registrations' ) . ' WHERE id = %d', $result->registrationId )
		);
		$this->assertSame( '630.00', $price ); // 450 typ + 180 nocleg
	}

	public function test_duplicate_email_is_rejected_without_storing(): void {
		$this->configure();
		$this->service->reserve( $this->event_id, $this->request( 'dup@example.com' ) );

		$result = $this->service->reserve( $this->event_id, $this->request( 'dup@example.com' ) );

		$this->assertSame( 'duplicate', $result->code );
		$this->assertNull( $result->registrationId );
	}

	public function test_type_full_waitlists_further_registrations(): void {
		$this->configure(); // typ uczestnik capacity=2
		$this->service->reserve( $this->event_id, $this->request( 'a@example.com' ) );
		$this->service->reserve( $this->event_id, $this->request( 'b@example.com' ) );

		$result = $this->service->reserve( $this->event_id, $this->request( 'c@example.com' ) );

		$this->assertSame( 'waitlisted', $result->code );
		$this->assertSame( 'type_full', $result->reason );
	}

	public function test_rejected_when_full_and_waitlist_disabled(): void {
		$this->configure( array( 'settings' => array( 'global_cap' => 1, 'waitlist_enabled' => false ) ) );
		$this->service->reserve( $this->event_id, $this->request( 'a@example.com' ) );

		$result = $this->service->reserve( $this->event_id, $this->request( 'b@example.com' ) );

		$this->assertSame( 'rejected', $result->code );
		$this->assertSame( 'event_full', $result->reason );
	}

	public function test_accommodation_full_accepts_without_room(): void {
		$this->configure(); // slot n12|double capacity=1
		$this->service->reserve( $this->event_id, $this->request( 'a@example.com', 'uczestnik', new AccommodationSelection( 'n12', 'double' ) ) );

		$result = $this->service->reserve( $this->event_id, $this->request( 'b@example.com', 'uczestnik', new AccommodationSelection( 'n12', 'double' ) ) );

		$this->assertSame( 'reserved', $result->code );
		$this->assertFalse( $result->accommodationGranted );
		$this->assertSame( 'accommodation_full', $result->reason );
	}
}
```

- [ ] **Step 2: Napisz failujący test współbieżności**

`tests/Integration/Services/ReservationConcurrencyTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Services;

use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use WP_UnitTestCase;
use wpdb;

/**
 * Dowodzi, że blokada per event serializuje rezerwacje: gdy jedno połączenie
 * trzyma FOR UPDATE na wierszu-zamku, drugie blokuje się do timeoutu.
 */
final class ReservationConcurrencyTest extends WP_UnitTestCase {

	public function test_second_connection_blocks_on_event_lock(): void {
		global $wpdb;

		Migrations::install();
		foreach ( array( 'registrations', 'accommodation_bookings', 'locks' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$event_id = 777;
		$repo     = new RegistrationRepository();

		// Połączenie A (główne $wpdb): zablokuj wiersz-zamek i NIE commituj.
		$wpdb->query( 'START TRANSACTION' );
		$repo->lockEvent( $event_id );

		// Połączenie B: osobne $wpdb, krótki timeout blokady.
		$second = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$second->query( 'SET SESSION innodb_lock_wait_timeout = 1' );
		$second->query( 'START TRANSACTION' );
		$second->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . Migrations::table( 'locks' ) . ' (event_id) VALUES (%d)', $event_id ) );

		$second->suppress_errors( true );
		$blocked = $second->query(
			$second->prepare( 'SELECT event_id FROM ' . Migrations::table( 'locks' ) . ' WHERE event_id = %d FOR UPDATE', $event_id )
		);
		$error = $second->last_error;
		$second->query( 'ROLLBACK' );

		// Zwolnij A.
		$wpdb->query( 'COMMIT' );

		$this->assertFalse( $blocked, 'Drugie połączenie powinno zostać zablokowane przez FOR UPDATE.' );
		$this->assertStringContainsStringIgnoringCase( 'lock wait timeout', $error );
	}
}
```

- [ ] **Step 3: Uruchom oba testy i potwierdź fail**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter Reservation
```

Oczekiwane: FAIL — brak `ReservationService`. (Test współbieżności użyje `lockEvent` z Taska 2 — jeśli failuje z innego powodu, zdiagnozuj.)

- [ ] **Step 4: Zaimplementuj `ReservationRequest` i `ReservationResult`**

`src/Services/ReservationRequest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Services;

use EvReg\Domain\Accommodation\AccommodationSelection;

defined( 'ABSPATH' ) || exit;

/**
 * Wejście rezerwacji — zwalidowane dane zgłoszenia.
 */
final class ReservationRequest {

	/**
	 * @param array<string,mixed> $data Znormalizowane odpowiedzi z Validatora.
	 */
	public function __construct(
		public readonly string $email,
		public readonly string $name,
		public readonly string $typeKey,
		public readonly array $data,
		public readonly ?AccommodationSelection $selection = null
	) {
	}
}
```

`src/Services/ReservationResult.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Wynik rezerwacji.
 */
final class ReservationResult {

	private function __construct(
		public readonly string $code,
		public readonly ?int $registrationId,
		public readonly ?string $token,
		public readonly bool $accommodationGranted,
		public readonly ?string $reason
	) {
	}

	public static function reserved( int $id, string $token, bool $acc_granted, ?string $reason ): self {
		return new self( 'reserved', $id, $token, $acc_granted, $reason );
	}

	public static function waitlisted( int $id, string $token, string $reason ): self {
		return new self( 'waitlisted', $id, $token, false, $reason );
	}

	public static function rejected( string $reason ): self {
		return new self( 'rejected', null, null, false, $reason );
	}

	public static function duplicate(): self {
		return new self( 'duplicate', null, null, false, null );
	}
}
```

- [ ] **Step 5: Zaimplementuj `ReservationService::reserve`**

`src/Services/ReservationService.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Services;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Capacity\CapacityCalculator;
use EvReg\Domain\Capacity\CapacityLimits;
use EvReg\Domain\Capacity\Outcome;
use EvReg\Domain\Pricing\PriceCalculator;
use EvReg\Domain\Registration\RegistrationStatus;
use EvReg\Domain\Registration\RegistrationTypeCollection;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\RegistrationRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Transakcyjna rezerwacja miejsc i potwierdzanie zgłoszeń.
 */
final class ReservationService {

	private const PENDING_TTL = '+48 hours';

	private CapacityCalculator $calculator;

	private PriceCalculator $pricing;

	public function __construct(
		private readonly RegistrationRepository $repository,
		private readonly EventConfigRepository $config
	) {
		$this->calculator = new CapacityCalculator();
		$this->pricing    = new PriceCalculator();
	}

	public function reserve( int $event_id, ReservationRequest $request ): ReservationResult {
		global $wpdb;

		$config        = $this->config->get( $event_id );
		$types         = RegistrationTypeCollection::fromArray( is_array( $config['types'] ) ? $config['types'] : array() );
		$accommodation = AccommodationConfig::fromArray( is_array( $config['accommodation'] ) ? $config['accommodation'] : array() );
		$settings      = is_array( $config['settings'] ) ? $config['settings'] : array();

		$wpdb->query( 'START TRANSACTION' );

		try {
			$this->repository->lockEvent( $event_id );

			if ( $this->repository->activeRegistrationExists( $event_id, $request->email ) ) {
				$wpdb->query( 'ROLLBACK' );
				return ReservationResult::duplicate();
			}

			$limits = new CapacityLimits(
				isset( $settings['global_cap'] ) && null !== $settings['global_cap'] ? (int) $settings['global_cap'] : null,
				$types->capacities(),
				$accommodation->capacities(),
				(bool) ( $settings['waitlist_enabled'] ?? true )
			);

			$decision = $this->calculator->decide(
				$limits,
				$this->repository->occupancy( $event_id ),
				$request->typeKey,
				$request->selection
			);

			if ( Outcome::Rejected === $decision->outcome ) {
				$wpdb->query( 'ROLLBACK' );
				return ReservationResult::rejected( (string) $decision->reason );
			}

			$is_waitlist = Outcome::Waitlisted === $decision->outcome;
			$status      = $is_waitlist ? RegistrationStatus::Waitlist : RegistrationStatus::Pending;
			$expires_at  = $is_waitlist ? null : gmdate( 'Y-m-d H:i:s', strtotime( self::PENDING_TTL, (int) current_time( 'timestamp', true ) ) );

			$type  = $types->get( $request->typeKey );
			$price = null === $type ? 0.0 : $this->pricing->total( $type, $accommodation, $request->selection );
			$token = bin2hex( random_bytes( 16 ) );

			$id = $this->repository->insertRegistration(
				array(
					'event_id'    => $event_id,
					'type_key'    => $request->typeKey,
					'status'      => $status->value,
					'email'       => $request->email,
					'name'        => $request->name,
					'token'       => $token,
					'data'        => (string) wp_json_encode( $request->data ),
					'price_total' => $price,
					'expires_at'  => $expires_at,
				)
			);

			if ( $decision->accommodationGranted && null !== $request->selection ) {
				$item      = $accommodation->item( $request->selection->packageKey, $request->selection->roomKey );
				$acc_price = null === $item ? 0.0 : $item->price;
				$this->repository->insertAccommodationBooking( $id, $request->selection, $acc_price );
			}

			$wpdb->query( 'COMMIT' );

			if ( $is_waitlist ) {
				return ReservationResult::waitlisted( $id, $token, (string) $decision->reason );
			}

			return ReservationResult::reserved( $id, $token, $decision->accommodationGranted, $decision->reason );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}
	}
}
```

Uwaga: `current_time( 'timestamp', true )` zwraca int GMT; `strtotime( '+48 hours', $ts )` daje termin wygaśnięcia. `data` zapisywane jako JSON (`wp_json_encode`).

- [ ] **Step 6: Uruchom testy**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter Reservation
```

Oczekiwane: PASS — 6 testów logiki + 1 współbieżności.

- [ ] **Step 7: PHPStan + WPCS**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
```

Oczekiwane: czyste.

- [ ] **Step 8: Commit**

```bash
git add src/Services/ReservationRequest.php src/Services/ReservationResult.php src/Services/ReservationService.php tests/Integration/Services
git commit -m "feat: add transactional reservation service with per-event lock and concurrency test"
```

---

## Task 4: ReservationService::confirm

**Files:**
- Create: `src/Services/ConfirmationResult.php`
- Modify: `src/Services/ReservationService.php` (dodaj `confirm`)
- Test: `tests/Integration/Services/ConfirmationTest.php`

**Interfaces:**
- Consumes: `RegistrationRepository::findByToken`, `markConfirmed`, `RegistrationStatus`
- Produces:
  - `ConfirmationResult` — statyczne `confirmed()`, `alreadyConfirmed()`, `expired()`, `onWaitlist()`, `notFound()`; właściwość `code` (`'confirmed'|'already_confirmed'|'expired'|'waitlist'|'not_found'`)
  - `ReservationService::confirm( string $token ): ConfirmationResult`

**Zasada:** porównanie tokenu przez `findByToken` (dokładne dopasowanie w SQL) + mapowanie statusu na wynik. `cancelled` traktowane jako `expired` (wygasłe rezerwacje są anulowane).

- [ ] **Step 1: Napisz failujące testy**

`tests/Integration/Services/ConfirmationTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Services;

use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Services\ReservationService;
use WP_UnitTestCase;

final class ConfirmationTest extends WP_UnitTestCase {

	private ReservationService $service;

	private RegistrationRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'locks' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$this->repository = new RegistrationRepository();
		$this->service    = new ReservationService( $this->repository, new EventConfigRepository() );
	}

	/**
	 * @param array<string,mixed> $overrides
	 */
	private function insert( array $overrides = array() ): string {
		$token = bin2hex( random_bytes( 16 ) );
		$this->repository->insertRegistration(
			array_merge(
				array(
					'event_id'    => 1,
					'type_key'    => 'uczestnik',
					'status'      => 'pending',
					'email'       => 'jan@example.com',
					'name'        => 'Jan',
					'token'       => $token,
					'data'        => '{}',
					'price_total' => 0.0,
					'expires_at'  => '2099-01-01 00:00:00',
				),
				$overrides
			)
		);
		return $token;
	}

	public function test_pending_token_confirms(): void {
		$token = $this->insert();

		$result = $this->service->confirm( $token );

		$this->assertSame( 'confirmed', $result->code );
		$this->assertSame( 'confirmed', $this->repository->findByToken( $token )['status'] );
	}

	public function test_already_confirmed_token_reports_already(): void {
		$token = $this->insert( array( 'status' => 'confirmed' ) );

		$this->assertSame( 'already_confirmed', $this->service->confirm( $token )->code );
	}

	public function test_cancelled_token_reports_expired(): void {
		$token = $this->insert( array( 'status' => 'cancelled' ) );

		$this->assertSame( 'expired', $this->service->confirm( $token )->code );
	}

	public function test_waitlist_token_reports_waitlist(): void {
		$token = $this->insert( array( 'status' => 'waitlist' ) );

		$this->assertSame( 'waitlist', $this->service->confirm( $token )->code );
	}

	public function test_unknown_token_reports_not_found(): void {
		$this->assertSame( 'not_found', $this->service->confirm( 'nieistnieje' )->code );
	}
}
```

- [ ] **Step 2: Uruchom i potwierdź fail**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter ConfirmationTest
```

Oczekiwane: FAIL — brak `ConfirmationResult` / metody `confirm`.

- [ ] **Step 3: Zaimplementuj `ConfirmationResult`**

```php
<?php

declare( strict_types=1 );

namespace EvReg\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Wynik próby potwierdzenia zgłoszenia.
 */
final class ConfirmationResult {

	private function __construct( public readonly string $code ) {
	}

	public static function confirmed(): self {
		return new self( 'confirmed' );
	}

	public static function alreadyConfirmed(): self {
		return new self( 'already_confirmed' );
	}

	public static function expired(): self {
		return new self( 'expired' );
	}

	public static function onWaitlist(): self {
		return new self( 'waitlist' );
	}

	public static function notFound(): self {
		return new self( 'not_found' );
	}
}
```

- [ ] **Step 4: Dodaj `confirm` do `ReservationService`**

Dodaj metodę oraz import `use EvReg\Domain\Registration\RegistrationStatus;` (jeśli nieobecny):

```php
	public function confirm( string $token ): ConfirmationResult {
		$row = $this->repository->findByToken( $token );

		if ( null === $row ) {
			return ConfirmationResult::notFound();
		}

		$status = RegistrationStatus::tryFrom( (string) $row['status'] );

		return match ( $status ) {
			RegistrationStatus::Pending   => $this->doConfirm( (int) $row['id'] ),
			RegistrationStatus::Confirmed => ConfirmationResult::alreadyConfirmed(),
			RegistrationStatus::Cancelled => ConfirmationResult::expired(),
			RegistrationStatus::Waitlist  => ConfirmationResult::onWaitlist(),
			default                       => ConfirmationResult::notFound(),
		};
	}

	private function doConfirm( int $registration_id ): ConfirmationResult {
		$this->repository->markConfirmed( $registration_id );
		return ConfirmationResult::confirmed();
	}
```

- [ ] **Step 5: Uruchom testy**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter ConfirmationTest
```

Oczekiwane: PASS, 5 testów.

- [ ] **Step 6: PHPStan + WPCS + Commit**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
git add src/Services/ConfirmationResult.php src/Services/ReservationService.php tests/Integration/Services/ConfirmationTest.php
git commit -m "feat: add token confirmation to reservation service"
```

---

## Task 5: ExpirePending cron

**Files:**
- Create: `src/Cron/ExpirePending.php`
- Modify: `event-registration.php` (interwał + rejestracja + harmonogram)
- Test: `tests/Integration/Cron/ExpirePendingTest.php`

**Interfaces:**
- Consumes: `RegistrationRepository::expirePending`
- Produces:
  - `ExpirePending::HOOK` (`'evreg_expire_pending'`), `ExpirePending::INTERVAL` (`'evreg_15min'`)
  - `ExpirePending::register(): void` — dodaje filtr `cron_schedules` (interwał 15 min) i akcję hooka
  - `ExpirePending::schedule(): void` — `wp_schedule_event` jeśli niezaplanowane (wołane przy aktywacji/`maybe_upgrade`)
  - `ExpirePending::run(): void` — handler; wygasza pending

- [ ] **Step 1: Napisz failujący test**

`tests/Integration/Cron/ExpirePendingTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Cron;

use EvReg\Cron\ExpirePending;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use WP_UnitTestCase;

final class ExpirePendingTest extends WP_UnitTestCase {

	private RegistrationRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'locks' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$this->repository = new RegistrationRepository();
	}

	/**
	 * @param array<string,mixed> $overrides
	 */
	private function insert( array $overrides ): string {
		$token = bin2hex( random_bytes( 16 ) );
		$this->repository->insertRegistration(
			array_merge(
				array(
					'event_id'    => 1,
					'type_key'    => 'uczestnik',
					'status'      => 'pending',
					'email'       => 'jan@example.com',
					'name'        => 'Jan',
					'token'       => $token,
					'data'        => '{}',
					'price_total' => 0.0,
					'expires_at'  => '2099-01-01 00:00:00',
				),
				$overrides
			)
		);
		return $token;
	}

	public function test_run_cancels_past_due_pending(): void {
		$due    = $this->insert( array( 'expires_at' => '2000-01-01 00:00:00', 'email' => 'due@example.com' ) );
		$future = $this->insert( array( 'expires_at' => '2099-01-01 00:00:00', 'email' => 'future@example.com' ) );

		ExpirePending::run();

		$this->assertSame( 'cancelled', $this->repository->findByToken( $due )['status'] );
		$this->assertSame( 'pending', $this->repository->findByToken( $future )['status'] );
	}

	public function test_interval_is_registered(): void {
		ExpirePending::register();

		$schedules = apply_filters( 'cron_schedules', array() );

		$this->assertArrayHasKey( ExpirePending::INTERVAL, $schedules );
		$this->assertSame( 900, $schedules[ ExpirePending::INTERVAL ]['interval'] );
	}

	public function test_schedule_registers_the_event(): void {
		ExpirePending::register();
		ExpirePending::schedule();

		$this->assertNotFalse( wp_next_scheduled( ExpirePending::HOOK ) );
	}
}
```

- [ ] **Step 2: Uruchom i potwierdź fail**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter ExpirePendingTest
```

Oczekiwane: FAIL — brak klasy.

- [ ] **Step 3: Zaimplementuj `ExpirePending`**

```php
<?php

declare( strict_types=1 );

namespace EvReg\Cron;

use EvReg\Persistence\RegistrationRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Cron wygaszający niepotwierdzone rezerwacje po upływie okna potwierdzenia.
 */
final class ExpirePending {

	public const HOOK     = 'evreg_expire_pending';
	public const INTERVAL = 'evreg_15min';

	/**
	 * Podpina interwał i handler.
	 */
	public static function register(): void {
		add_filter( 'cron_schedules', array( self::class, 'add_interval' ) );
		add_action( self::HOOK, array( self::class, 'run' ) );
	}

	/**
	 * Planuje zadanie, jeśli jeszcze niezaplanowane.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), self::INTERVAL, self::HOOK );
		}
	}

	/**
	 * Usuwa zaplanowane zadanie (dezaktywacja).
	 */
	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::HOOK );

		if ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}

	/**
	 * Dodaje interwał 15-minutowy.
	 *
	 * @param array<string,array<string,mixed>> $schedules Zarejestrowane interwały.
	 * @return array<string,array<string,mixed>>
	 */
	public static function add_interval( array $schedules ): array {
		$schedules[ self::INTERVAL ] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Co 15 minut (Event Registration)', 'event-registration' ),
		);

		return $schedules;
	}

	/**
	 * Wygasza przeterminowane rezerwacje pending.
	 */
	public static function run(): void {
		$count = ( new RegistrationRepository() )->expirePending( current_time( 'mysql', true ) );

		if ( $count > 0 ) {
			do_action( 'evreg_pending_expired', $count );
		}
	}
}
```

- [ ] **Step 4: Podepnij w `event-registration.php`**

Dodaj rejestrację obok pozostałych `plugins_loaded`:

```php
add_action( 'plugins_loaded', array( \EvReg\Cron\ExpirePending::class, 'register' ) );
```

Rozszerz closure aktywacji, aby zaplanować cron (obok `Migrations::install()` i `Capabilities::grant()`):

```php
		\EvReg\Cron\ExpirePending::schedule();
```

Dodaj hook dezaktywacji:

```php
register_deactivation_hook( __FILE__, array( \EvReg\Cron\ExpirePending::class, 'unschedule' ) );
```

Oraz w `maybe_upgrade` cronu — najprościej: dopnij `schedule()` również na `plugins_loaded` przez `ExpirePending::register()` nie wystarczy (register tylko podpina). Zamiast tego dodaj osobny `add_action( 'plugins_loaded', array( \EvReg\Cron\ExpirePending::class, 'schedule' ) );` — `schedule()` jest idempotentne (planuje tylko gdy brak), więc bezpieczne przy każdym ładowaniu i pokrywa istniejące instalacje bez reaktywacji.

- [ ] **Step 5: Uruchom testy**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter ExpirePendingTest
```

Oczekiwane: PASS, 3 testy.

- [ ] **Step 6: Pełna suita + narzędzia**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
```

Oczekiwane: wszystko zielone/czyste.

- [ ] **Step 7: Commit**

```bash
git add src/Cron/ExpirePending.php event-registration.php tests/Integration/Cron
git commit -m "feat: add cron to expire unconfirmed pending reservations"
```

---

## Definicja ukończenia Planu 3A

- [ ] `RegistrationStatus` czysty (przechodzi DomainPurityTest); `occupiesSeat()` jedynym źródłem prawdy o zajętości
- [ ] Tabela `evreg_locks` powstaje; `DB_VERSION` = 2; istniejące instalacje dostają ją przez `maybe_upgrade`
- [ ] `RegistrationRepository` robi insert/count/lock/find/confirm/expire, wszystko przez `$wpdb->prepare`
- [ ] `ReservationService::reserve` — transakcja z blokadą per event, guard duplikatu, decyzja limitów, cena; test współbieżności dowodzi serializacji
- [ ] `ReservationService::confirm` — token → confirmed z poprawnym mapowaniem stanów
- [ ] `ExpirePending` — cron 15 min wygasza pending, zwalnia miejsca
- [ ] Suita unit i integration zielone, PHPStan 6 i WPCS czyste

## Czego Plan 3A świadomie nie robi

Brak renderowania formularza, submisji, bloku, shortcode, JS i E2E — to Plan 3B, który wywołuje `ReservationService::reserve` po walidacji odpowiedzi (`Validator` + `SchemaAssembler`) i `ReservationService::confirm` z endpointu potwierdzenia. Brak wysyłki maila niosącego link potwierdzający — to Plan 4 (token i logika potwierdzenia już działają). Brak automatycznej promocji z waitlisty (Plan 5).
