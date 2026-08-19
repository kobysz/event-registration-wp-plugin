# Mail Queue Admin Screen (Plan 4B-Queue) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dać organizatorowi ekran admina do diagnostyki wysyłki — `WP_List_Table` pod submenu menu CPT `evreg_event` z listą wierszy `evreg_mail_queue` (filtry statusu/eventu, paginacja), ekranem szczegółów z pełną treścią, i ręcznym wznowieniem wierszy `failed`.

**Architecture:** `MailQueueRepository` dostaje zapytania listujące (`paginate`, `countByFilter`, `distinctEventIds`) i wznowienie (`requeueFailed`, chronione `WHERE status='failed'`) — jedyne miejsce z SQL kolejki. `MailQueueListTable extends WP_List_Table` renderuje wiersze (formatowanie kolumn, escaping). `MailQueueScreen` orkiestruje: submenu, routing listy vs `?action=view`, handler wznowienia na `admin-post` (nonce + cap + PRG). Silnik 4A (dispatcher/retry/purge) nietknięty — admin tylko resetuje wiersz, dispatcher złapie go w następnym przebiegu.

**Tech Stack:** PHP 8.1, WordPress 6.4+, `WP_List_Table`, admin-post, PHPUnit integration (wp-env). Zero JS, zero buildów.

**Spec:** `docs/superpowers/specs/2026-08-19-mail-queue-admin-design.md`

**Gałąź:** `plan-4b-queue` od `master`.

## Global Constraints

- Minimalne PHP 8.1, minimalne WordPress 6.4.
- Namespace `EvReg\`, PSR-4, `src/`. Prefiks hooków/akcji `evreg_`.
- Każdy plik PHP poza `src/Domain/` i `tests/` zaczyna od `defined( 'ABSPATH' ) || exit;`. (Ten plan nie dotyka `src/Domain/`.)
- **SQL kolejki wyłącznie w `MailQueueRepository`.** Warstwa admina nie pisze SQL. Wszystkie zapytania przez `$wpdb->prepare`.
- **`requeueFailed` chroni się warunkiem `WHERE status = 'failed'`** — nie w adminie. Podrobione `id` nie wskrzesi `sent`/`sending`/`queued`.
- **Uprawnienia:** `current_user_can( Capabilities::CAP )` (`edit_evreg_events`) na renderze ekranu i w handlerze wznowienia. Nonce (`check_admin_referer`) na wznowieniu; PRG (`wp_safe_redirect` + `exit`).
- **Escaping obowiązkowy:** treść maila i `last_error` przez `esc_html`; linki przez `esc_url`; `id`/`event_id`/`paged` rzutowane na `int`; status filtra walidowany względem whitelisty `STATUS_*`. Zero surowego `echo` z danymi.
- Czas UTC: `current_time( 'mysql', true )`.
- Styl PHP jak w repo: tabulatory, `array()` zamiast `[]`, warunki Yody, docblock `@param`/`@return` na metodach publicznych.
- PHPStan poziom 6 (skanuje `src` + `event-registration.php`, nie testy), bez nowych `@phpstan-ignore` poza wzorcem `WordPress.DB.*` z 4A. `phpcs` czysto pod istniejącym `phpcs.xml.dist`.
- Katalogi testów PHP wielką literą (`tests/Integration/Persistence/`, `tests/Integration/Admin/`), suity małą.
- Commity po każdym tasku, po angielsku, Conventional Commits.

### Komendy referencyjne

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs src
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=512M
```

Host nie ma PHP — komendy PHP wyłącznie przez `node scripts/wp-env.cjs`. Nie wołaj gołego `npx wp-env`. **phpcs tylko na ścieżkach `src`** (nie `tests/...`). PHPStan wymaga `--memory-limit=512M`. Konsola PHPUnit zniekształca komunikat niezłapanego `Error` — fazę RED weryfikuj po nazwie klasy błędu. Weryfikacja w przeglądarce: kontroler (człowiek), nie subagent.

### Krytyczne fakty środowiskowe

- **`WP_List_Table` NIE jest autoloadowane** — żyje w `wp-admin/includes/class-wp-list-table.php`. Plik definiujący podklasę MUSI je załadować **przed** deklaracją klasy: na górze pliku, po guardzie ABSPATH, `if ( ! class_exists( 'WP_List_Table' ) ) { require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php'; }`. Bez tego PSR-4 autoload podklasy rzuci fatalem „class WP_List_Table not found".
- **`WP_List_Table` w testach** wymaga ustawionego ekranu — `set_current_screen( 'toplevel_page_evreg-mail-queue' )` (albo dowolny) przed instancjonowaniem, inaczej konstruktor woła `get_current_screen()` na `null`.
- **Handler redirectu w testach:** `wp_safe_redirect(...); exit;` — `exit` jest nietestowalny wprost. Test przechwytuje przez filtr `wp_redirect` rzucający wyjątek z lokalizacją: `add_filter( 'wp_redirect', static function ( $loc ) { throw new \EvReg\Tests\Integration\Admin\RedirectException( $loc ); } )`. Filtr odpala się **wewnątrz** `wp_redirect`, przed `exit` handlera. Zły nonce/cap → `check_admin_referer`/uprawnienia wołają `wp_die`, które suite testowy zamienia na `WPDieException` — łap `$this->expectException( \WPDieException::class )`.

### Interfejsy z Planów 4A/2A (konsumowane — sygnatury zweryfikowane w kodzie)

- `EvReg\Persistence\MailQueueRepository` — `STATUS_QUEUED='queued'`, `STATUS_SENDING='sending'`, `STATUS_SENT='sent'`, `STATUS_FAILED='failed'`; `find( int $id ): ?array`; prywatne `table(): string` (nazwa tabeli). Ten plan dokłada `paginate`, `countByFilter`, `requeueFailed`, `distinctEventIds`. Wzorzec zapytań: `$wpdb->prepare` z `// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared` na nazwie tabeli i `WordPress.DB.DirectDatabaseQuery` na wywołaniu.
- Kolumny tabeli `evreg_mail_queue`: `id, registration_id, event_id, template_key, recipient, subject, body, headers, status, attempts, last_error, scheduled_at, sent_at` (odczyt `ARRAY_A` = stringi, rzutuj jawnie).
- `EvReg\Admin\Capabilities::CAP = 'edit_evreg_events'`
- `EvReg\Admin\EventPostType::POST_TYPE = 'evreg_event'` (menu CPT = `edit.php?post_type=evreg_event`)
- `EvReg\Plugin::plugin_file()`, `Plugin::VERSION`, `Plugin::TEXT_DOMAIN='event-registration'`
- Wzorzec testu admina: `WP_UnitTestCase`, `self::factory()->post->create( array( 'post_type' => 'evreg_event' ) )`, `self::factory()->user->create( array( 'role' => 'administrator' ) )`, `Capabilities::grant()`, `wp_set_current_user(...)`.

---

## File Structure

**Nowe pliki produkcyjne**

| Plik | Odpowiedzialność |
|---|---|
| `src/Admin/MailQueueListTable.php` | `WP_List_Table`: kolumny, filtry statusu/eventu, akcje wierszy, paginacja |
| `src/Admin/MailQueueScreen.php` | Submenu, render listy, ekran szczegółów (`?action=view`), handler wznowienia (admin-post) |

**Modyfikowane pliki produkcyjne**

| Plik | Zmiana |
|---|---|
| `src/Persistence/MailQueueRepository.php` | `paginate()`, `countByFilter()`, `distinctEventIds()`, `requeueFailed()` |
| `event-registration.php` | Rejestracja `MailQueueScreen::register` na `plugins_loaded` |

**Nowe pliki testowe**

`tests/Integration/Persistence/MailQueueListingTest.php`,
`tests/Integration/Persistence/MailQueueRequeueTest.php`,
`tests/Integration/Admin/MailQueueListTableTest.php`,
`tests/Integration/Admin/MailQueueScreenTest.php` (zawiera pomocniczy `RedirectException`).

**Świadomie nietykane:** `src/Mail/*` i `Cron/*` (silnik 4A — admin tylko czyta/resetuje wiersze), `phpcs.xml.dist`, `phpstan.neon.dist`.

---
## Task 1: Zapytania listujące (paginate, countByFilter, distinctEventIds)

**Files:**
- Modify: `src/Persistence/MailQueueRepository.php`
- Test: `tests/Integration/Persistence/MailQueueListingTest.php`

**Interfaces:**
- Consumes: `MailQueueRepository::insert()`, `STATUS_*`, prywatne `table()`
- Produces:
  - `paginate( array $filters, int $per_page, int $offset ): array<int,array<string,mixed>>`
  - `countByFilter( array $filters ): int`
  - `distinctEventIds(): array<int,int>`
  - `$filters` = `array{ status?: string, event_id?: int }`; nieznany status i `event_id <= 0` ignorowane

- [ ] **Step 1: Napisz test (RED)**

`tests/Integration/Persistence/MailQueueListingTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Persistence;

use EvReg\Persistence\MailQueueRepository;
use EvReg\Persistence\Migrations;
use WP_UnitTestCase;

final class MailQueueListingTest extends WP_UnitTestCase {

	private MailQueueRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( 'mail_queue' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->repository = new MailQueueRepository();
	}

	/**
	 * @param array<string,mixed> $overrides
	 */
	private function seed( array $overrides = array() ): void {
		$row = array_merge(
			array(
				'registration_id' => null,
				'event_id'        => 1,
				'template_key'    => 'optin',
				'recipient'       => 'jan@example.com',
				'subject'         => 'Temat',
				'body'            => 'Treść',
				'headers'         => '',
				'scheduled_at'    => '2026-08-19 10:00:00',
			),
			$overrides
		);
		$this->repository->insert( $row );

		if ( isset( $overrides['status'] ) && MailQueueRepository::STATUS_QUEUED !== $overrides['status'] ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$id = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . Migrations::table( 'mail_queue' ) );
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				Migrations::table( 'mail_queue' ),
				array( 'status' => (string) $overrides['status'] ),
				array( 'id' => $id ),
				array( '%s' ),
				array( '%d' )
			);
		}
	}

	public function test_paginate_returns_all_without_filters_newest_first(): void {
		$this->seed( array( 'template_key' => 'a' ) );
		$this->seed( array( 'template_key' => 'b' ) );

		$rows = $this->repository->paginate( array(), 20, 0 );

		$this->assertCount( 2, $rows );
		$this->assertSame( 'b', $rows[0]['template_key'] );
		$this->assertSame( 'a', $rows[1]['template_key'] );
	}

	public function test_paginate_filters_by_status(): void {
		$this->seed( array( 'template_key' => 'q' ) );
		$this->seed( array( 'template_key' => 'f', 'status' => MailQueueRepository::STATUS_FAILED ) );

		$rows = $this->repository->paginate( array( 'status' => MailQueueRepository::STATUS_FAILED ), 20, 0 );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'f', $rows[0]['template_key'] );
	}

	public function test_paginate_filters_by_event(): void {
		$this->seed( array( 'event_id' => 1 ) );
		$this->seed( array( 'event_id' => 2, 'template_key' => 'e2' ) );

		$rows = $this->repository->paginate( array( 'event_id' => 2 ), 20, 0 );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'e2', $rows[0]['template_key'] );
	}

	public function test_paginate_combines_status_and_event(): void {
		$this->seed( array( 'event_id' => 1, 'status' => MailQueueRepository::STATUS_FAILED, 'template_key' => 'hit' ) );
		$this->seed( array( 'event_id' => 2, 'status' => MailQueueRepository::STATUS_FAILED ) );
		$this->seed( array( 'event_id' => 1, 'template_key' => 'queued' ) );

		$rows = $this->repository->paginate( array( 'event_id' => 1, 'status' => MailQueueRepository::STATUS_FAILED ), 20, 0 );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'hit', $rows[0]['template_key'] );
	}

	public function test_paginate_respects_limit_and_offset(): void {
		$this->seed( array( 'template_key' => 'a' ) );
		$this->seed( array( 'template_key' => 'b' ) );
		$this->seed( array( 'template_key' => 'c' ) );

		$page1 = $this->repository->paginate( array(), 2, 0 );
		$page2 = $this->repository->paginate( array(), 2, 2 );

		$this->assertCount( 2, $page1 );
		$this->assertCount( 1, $page2 );
		$this->assertSame( 'a', $page2[0]['template_key'] );
	}

	public function test_paginate_ignores_unknown_status(): void {
		$this->seed( array( 'template_key' => 'a' ) );

		$rows = $this->repository->paginate( array( 'status' => 'nonsense' ), 20, 0 );

		$this->assertCount( 1, $rows );
	}

	public function test_count_by_filter_matches_paginate(): void {
		$this->seed( array( 'status' => MailQueueRepository::STATUS_FAILED ) );
		$this->seed();
		$this->seed( array( 'status' => MailQueueRepository::STATUS_FAILED ) );

		$this->assertSame( 3, $this->repository->countByFilter( array() ) );
		$this->assertSame( 2, $this->repository->countByFilter( array( 'status' => MailQueueRepository::STATUS_FAILED ) ) );
	}

	public function test_distinct_event_ids(): void {
		$this->seed( array( 'event_id' => 5 ) );
		$this->seed( array( 'event_id' => 5, 'template_key' => 'b' ) );
		$this->seed( array( 'event_id' => 9 ) );

		$ids = $this->repository->distinctEventIds();

		sort( $ids );
		$this->assertSame( array( 5, 9 ), $ids );
	}
}
```

- [ ] **Step 2: Uruchom test i potwierdź RED**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter MailQueueListingTest
```
Expected: FAIL — `Call to undefined method EvReg\Persistence\MailQueueRepository::paginate()`.

- [ ] **Step 3: Zaimplementuj metody listujące**

W `src/Persistence/MailQueueRepository.php`, po `find()`, dodaj prywatny builder klauzuli i trzy metody:

```php
	/**
	 * Buduje klauzulę WHERE i argumenty z filtrów. Nieznany status i event_id <= 0 są ignorowane.
	 *
	 * @param array{status?: string, event_id?: int} $filters Filtry listy.
	 *
	 * @return array{0: string, 1: array<int,mixed>} Para [klauzula WHERE (z wiodącym ' WHERE ' albo pusta), argumenty].
	 */
	private function whereFromFilters( array $filters ): array {
		$clauses = array();
		$args    = array();

		$statuses = array( self::STATUS_QUEUED, self::STATUS_SENDING, self::STATUS_SENT, self::STATUS_FAILED );

		if ( isset( $filters['status'] ) && in_array( $filters['status'], $statuses, true ) ) {
			$clauses[] = 'status = %s';
			$args[]    = $filters['status'];
		}

		if ( isset( $filters['event_id'] ) && (int) $filters['event_id'] > 0 ) {
			$clauses[] = 'event_id = %d';
			$args[]    = (int) $filters['event_id'];
		}

		$where = array() === $clauses ? '' : ' WHERE ' . implode( ' AND ', $clauses );

		return array( $where, $args );
	}

	/**
	 * Zwraca stronę wierszy kolejki wg filtrów, najnowsze naprzód.
	 *
	 * @param array{status?: string, event_id?: int} $filters  Filtry listy.
	 * @param int                                     $per_page Liczba wierszy na stronę.
	 * @param int                                     $offset   Przesunięcie.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function paginate( array $filters, int $per_page, int $offset ): array {
		global $wpdb;

		list( $where, $args ) = $this->whereFromFilters( $filters );
		$args[]               = $per_page;
		$args[]               = $offset;

		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM {$this->table()}{$where} ORDER BY id DESC LIMIT %d OFFSET %d", $args ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Liczy wiersze kolejki spełniające filtry.
	 *
	 * @param array{status?: string, event_id?: int} $filters Filtry listy.
	 */
	public function countByFilter( array $filters ): int {
		global $wpdb;

		list( $where, $args ) = $this->whereFromFilters( $filters );

		if ( array() === $args ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table()}" );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()}{$where}", $args ) );
	}

	/**
	 * Zwraca unikalne ID eventów obecnych w kolejce.
	 *
	 * @return array<int,int>
	 */
	public function distinctEventIds(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col( "SELECT DISTINCT event_id FROM {$this->table()} ORDER BY event_id ASC" );

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}
```

Uwaga na `$wpdb->prepare` z tablicą argumentów: WordPress akceptuje `prepare( $sql, $args_array )`, gdy drugim argumentem jest tablica — dlatego składamy `$args` i przekazujemy w całości. Gdy `$args` puste (count bez filtrów), NIE wołamy `prepare` (prepare bez placeholderów zgłasza notice) — stąd osobna gałąź w `countByFilter`.

- [ ] **Step 4: Uruchom test i potwierdź GREEN**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter MailQueueListingTest
```
Expected: PASS, 8 testów.

- [ ] **Step 5: Styl i statyka**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs src/Persistence
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=512M
```
Expected: zero błędów. Gdyby PHPStan marudził na `$wpdb->prepare( $sql, $args )` z tablicą, to znany, poprawny wzorzec WP — jeśli konieczne, dopisz wąski `// @phpstan-ignore-line` TYLKO na tej linii z komentarzem wyjaśniającym; preferuj jednak brak ignore (stub WP zwykle to akceptuje).

- [ ] **Step 6: Commit**

```bash
git add src/Persistence/MailQueueRepository.php tests/Integration/Persistence/MailQueueListingTest.php
git commit -m "feat: add mail queue listing queries (paginate, count, distinct events)"
```

---

## Task 2: Wznowienie wiersza (requeueFailed)

**Files:**
- Modify: `src/Persistence/MailQueueRepository.php`
- Test: `tests/Integration/Persistence/MailQueueRequeueTest.php`

**Interfaces:**
- Consumes: `insert()`, `find()`, `STATUS_*`, `table()`
- Produces: `requeueFailed( int $id ): bool` — `failed` → `queued`, `attempts=0`, `scheduled_at=teraz UTC`, `last_error`/`sent_at` NULL; zwraca `true` gdy jeden wiersz zmieniony

- [ ] **Step 1: Napisz test (RED)**

`tests/Integration/Persistence/MailQueueRequeueTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Persistence;

use EvReg\Persistence\MailQueueRepository;
use EvReg\Persistence\Migrations;
use WP_UnitTestCase;

final class MailQueueRequeueTest extends WP_UnitTestCase {

	private MailQueueRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( 'mail_queue' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->repository = new MailQueueRepository();
	}

	/**
	 * Wstawia wiersz o zadanym statusie/atrybutach i zwraca jego id.
	 *
	 * @param string $status  Status docelowy.
	 * @param string $error   last_error.
	 * @param int    $attempts Liczba prób.
	 */
	private function seed( string $status, string $error = 'SMTP down', int $attempts = 3 ): int {
		global $wpdb;

		$this->repository->insert(
			array(
				'registration_id' => null,
				'event_id'        => 1,
				'template_key'    => 'optin',
				'recipient'       => 'jan@example.com',
				'subject'         => 'Temat',
				'body'            => 'Treść',
				'headers'         => '',
				'scheduled_at'    => '2026-08-19 10:00:00',
			)
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$id = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . Migrations::table( 'mail_queue' ) );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Migrations::table( 'mail_queue' ),
			array( 'status' => $status, 'last_error' => $error, 'attempts' => $attempts, 'sent_at' => '2026-08-19 11:00:00' ),
			array( 'id' => $id ),
			array( '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);

		return $id;
	}

	public function test_requeue_resets_failed_row(): void {
		$id = $this->seed( MailQueueRepository::STATUS_FAILED );

		$this->assertTrue( $this->repository->requeueFailed( $id ) );

		$row = $this->repository->find( $id );
		$this->assertSame( MailQueueRepository::STATUS_QUEUED, $row['status'] );
		$this->assertSame( '0', (string) $row['attempts'] );
		$this->assertNull( $row['last_error'] );
		$this->assertNull( $row['sent_at'] );
		$this->assertNotSame( '2026-08-19 10:00:00', $row['scheduled_at'] );
	}

	public function test_requeue_ignores_sent_row(): void {
		$id = $this->seed( MailQueueRepository::STATUS_SENT );

		$this->assertFalse( $this->repository->requeueFailed( $id ) );
		$this->assertSame( MailQueueRepository::STATUS_SENT, $this->repository->find( $id )['status'] );
	}

	public function test_requeue_ignores_queued_and_sending_rows(): void {
		$queued  = $this->seed( MailQueueRepository::STATUS_QUEUED );
		$sending = $this->seed( MailQueueRepository::STATUS_SENDING );

		$this->assertFalse( $this->repository->requeueFailed( $queued ) );
		$this->assertFalse( $this->repository->requeueFailed( $sending ) );
	}

	public function test_requeue_returns_false_for_unknown_id(): void {
		$this->assertFalse( $this->repository->requeueFailed( 987654 ) );
	}
}
```

- [ ] **Step 2: Uruchom test i potwierdź RED**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter MailQueueRequeueTest
```
Expected: FAIL — `Call to undefined method ...::requeueFailed()`.

- [ ] **Step 3: Zaimplementuj requeueFailed**

W `src/Persistence/MailQueueRepository.php`, po `distinctEventIds()`:

```php
	/**
	 * Wznawia wiersz w stanie failed: zeruje próby i przywraca do kolejki.
	 * Wiersz w innym stanie jest nietknięty (warunek WHERE status='failed').
	 *
	 * @param int $id ID wiersza.
	 *
	 * @return bool True, gdy dokładnie jeden wiersz (failed) został wznowiony.
	 */
	public function requeueFailed( int $id ): bool {
		global $wpdb;

		$result = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$this->table()} SET status = %s, attempts = 0, scheduled_at = %s, last_error = NULL, sent_at = NULL WHERE id = %d AND status = %s",
				self::STATUS_QUEUED,
				current_time( 'mysql', true ),
				$id,
				self::STATUS_FAILED
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

		return 1 === (int) $result;
	}
```

- [ ] **Step 4: Uruchom test i potwierdź GREEN**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter MailQueueRequeueTest
```
Expected: PASS, 4 testy.

- [ ] **Step 5: Styl i statyka**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs src/Persistence
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=512M
```
Expected: zero błędów.

- [ ] **Step 6: Commit**

```bash
git add src/Persistence/MailQueueRepository.php tests/Integration/Persistence/MailQueueRequeueTest.php
git commit -m "feat: add requeue for failed mail queue rows"
```

---
## Task 3: MailQueueListTable (WP_List_Table)

**Files:**
- Create: `src/Admin/MailQueueListTable.php`
- Test: `tests/Integration/Admin/MailQueueListTableTest.php`

**Interfaces:**
- Consumes: `MailQueueRepository::paginate()`, `countByFilter()`, `STATUS_*`, `\WP_List_Table`, `Capabilities::CAP`
- Produces:
  - `MailQueueListTable::__construct()` (woła `parent::__construct` z args listy)
  - `MailQueueListTable::PER_PAGE = 20`
  - `get_columns(): array<string,string>`, `prepare_items(): void`, `column_default( array $item, string $column ): string`, `handle_row_actions( array $item, string $column, string $primary ): string`
  - Publiczne `->items` (z `WP_List_Table`) po `prepare_items()` — testowane

- [ ] **Step 1: Napisz test (RED)**

`tests/Integration/Admin/MailQueueListTableTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Admin;

use EvReg\Admin\MailQueueListTable;
use EvReg\Persistence\MailQueueRepository;
use EvReg\Persistence\Migrations;
use WP_UnitTestCase;

final class MailQueueListTableTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( 'mail_queue' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		set_current_screen( 'toplevel_page_evreg-mail-queue' );
	}

	/**
	 * @param array<string,mixed> $overrides
	 */
	private function seed( array $overrides = array() ): void {
		( new MailQueueRepository() )->insert(
			array_merge(
				array(
					'registration_id' => null,
					'event_id'        => 1,
					'template_key'    => 'optin',
					'recipient'       => 'jan@example.com',
					'subject'         => 'Temat',
					'body'            => 'Treść',
					'headers'         => '',
					'scheduled_at'    => '2026-08-19 10:00:00',
				),
				$overrides
			)
		);
	}

	public function test_columns_include_status_recipient_event(): void {
		$table = new MailQueueListTable();

		$columns = $table->get_columns();

		$this->assertArrayHasKey( 'status', $columns );
		$this->assertArrayHasKey( 'recipient', $columns );
		$this->assertArrayHasKey( 'event', $columns );
	}

	public function test_prepare_items_loads_rows(): void {
		$this->seed( array( 'template_key' => 'a' ) );
		$this->seed( array( 'template_key' => 'b' ) );

		$table = new MailQueueListTable();
		$table->prepare_items();

		$this->assertCount( 2, $table->items );
	}

	public function test_prepare_items_applies_status_filter_from_request(): void {
		$this->seed( array( 'template_key' => 'q' ) );
		$id = ( new MailQueueRepository() )->insert(
			array(
				'registration_id' => null,
				'event_id'        => 1,
				'template_key'    => 'f',
				'recipient'       => 'x@example.com',
				'subject'         => 'S',
				'body'            => 'B',
				'headers'         => '',
				'scheduled_at'    => '2026-08-19 10:00:00',
			)
		);
		global $wpdb;
		$max = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . Migrations::table( 'mail_queue' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->update( Migrations::table( 'mail_queue' ), array( 'status' => 'failed' ), array( 'id' => $max ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$_REQUEST['status'] = 'failed';
		$_GET['status']     = 'failed';

		$table = new MailQueueListTable();
		$table->prepare_items();

		unset( $_REQUEST['status'], $_GET['status'] );

		$this->assertCount( 1, $table->items );
		$this->assertSame( 'f', $table->items[0]['template_key'] );
	}

	public function test_column_default_escapes_recipient(): void {
		$table  = new MailQueueListTable();
		$output = $table->column_default(
			array( 'recipient' => '<b>x@example.com</b>', 'status' => 'queued' ),
			'recipient'
		);

		$this->assertStringNotContainsString( '<b>', $output );
	}

	public function test_requeue_action_only_for_failed_rows(): void {
		$table = new MailQueueListTable();

		$failed = $table->handle_row_actions( array( 'id' => 1, 'status' => 'failed' ), 'status', 'status' );
		$sent   = $table->handle_row_actions( array( 'id' => 2, 'status' => 'sent' ), 'status', 'status' );

		$this->assertStringContainsString( 'evreg_requeue_mail', $failed );
		$this->assertStringNotContainsString( 'evreg_requeue_mail', $sent );
	}
}
```

- [ ] **Step 2: Uruchom test i potwierdź RED**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter MailQueueListTableTest
```
Expected: FAIL — `Class "EvReg\Admin\MailQueueListTable" not found`.

- [ ] **Step 3: Zaimplementuj MailQueueListTable**

`src/Admin/MailQueueListTable.php`:

```php
<?php
/**
 * Tabela kolejki mailowej w adminie.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Admin;

use EvReg\Persistence\MailQueueRepository;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Tabela kolejki mailowej: lista wierszy evreg_mail_queue z filtrami i akcjami.
 */
final class MailQueueListTable extends \WP_List_Table {

	public const PER_PAGE = 20;

	/**
	 * Repozytorium kolejki.
	 *
	 * @var MailQueueRepository
	 */
	private MailQueueRepository $repository;

	/**
	 * Tworzy tabelę.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'evreg_mail',
				'plural'   => 'evreg_mails',
				'ajax'     => false,
			)
		);

		$this->repository = new MailQueueRepository();
	}

	/**
	 * Definiuje kolumny listy.
	 *
	 * @return array<string,string>
	 */
	public function get_columns(): array {
		return array(
			'status'       => __( 'Status', 'event-registration' ),
			'template_key' => __( 'Typ maila', 'event-registration' ),
			'recipient'    => __( 'Odbiorca', 'event-registration' ),
			'event'        => __( 'Wydarzenie', 'event-registration' ),
			'attempts'     => __( 'Próby', 'event-registration' ),
			'scheduled_at' => __( 'Zaplanowano', 'event-registration' ),
			'sent_at'      => __( 'Wysłano', 'event-registration' ),
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

		$total = $this->repository->countByFilter( $filters );

		$this->items = $this->repository->paginate( $filters, self::PER_PAGE, $offset );

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
	 * @param array<string,mixed> $item   Wiersz kolejki.
	 * @param string              $column Klucz kolumny.
	 */
	public function column_default( $item, $column ): string {
		if ( 'event' === $column ) {
			$title = get_the_title( (int) ( $item['event_id'] ?? 0 ) );

			return '' === $title ? '#' . (int) ( $item['event_id'] ?? 0 ) : esc_html( $title );
		}

		return esc_html( (string) ( $item[ $column ] ?? '' ) );
	}

	/**
	 * Dokłada akcje wiersza pod pierwszą kolumną.
	 *
	 * @param array<string,mixed> $item    Wiersz kolejki.
	 * @param string              $column  Aktualna kolumna.
	 * @param string              $primary Kolumna główna.
	 */
	public function handle_row_actions( $item, $column, $primary ): string {
		if ( $column !== $primary ) {
			return '';
		}

		$id      = (int) ( $item['id'] ?? 0 );
		$actions = array(
			'view' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( add_query_arg( array( 'page' => 'evreg-mail-queue', 'action' => 'view', 'id' => $id ), admin_url( 'edit.php?post_type=' . EventPostType::POST_TYPE ) ) ),
				esc_html__( 'Podgląd', 'event-registration' )
			),
		);

		if ( MailQueueRepository::STATUS_FAILED === ( $item['status'] ?? '' ) ) {
			$actions['requeue'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=evreg_requeue_mail&id=' . $id ), 'evreg_requeue_mail_' . $id ) ),
				esc_html__( 'Wznów', 'event-registration' )
			);
		}

		return $this->row_actions( $actions );
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

		$current_status = $this->currentFilters()['status'] ?? '';
		$current_event  = $this->currentFilters()['event_id'] ?? 0;

		echo '<div class="alignleft actions">';

		echo '<select name="status">';
		echo '<option value="">' . esc_html__( 'Wszystkie statusy', 'event-registration' ) . '</option>';
		foreach ( array( MailQueueRepository::STATUS_QUEUED, MailQueueRepository::STATUS_SENDING, MailQueueRepository::STATUS_SENT, MailQueueRepository::STATUS_FAILED ) as $status ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $status ),
				selected( $current_status, $status, false ),
				esc_html( $status )
			);
		}
		echo '</select>';

		echo '<select name="event_id">';
		echo '<option value="0">' . esc_html__( 'Wszystkie wydarzenia', 'event-registration' ) . '</option>';
		foreach ( $this->repository->distinctEventIds() as $event_id ) {
			$title = get_the_title( $event_id );
			printf(
				'<option value="%d"%s>%s</option>',
				$event_id,
				selected( $current_event, $event_id, false ),
				esc_html( '' === $title ? '#' . $event_id : $title )
			);
		}
		echo '</select>';

		submit_button( __( 'Filtruj', 'event-registration' ), '', 'filter_action', false );

		echo '</div>';
	}

	/**
	 * Odczytuje filtry z żądania (sanityzacja + whitelist).
	 *
	 * @return array{status?: string, event_id?: int}
	 */
	private function currentFilters(): array {
		$filters = array();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filtr GET listy, nie akcja zmieniająca stan.
		$status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['status'] ) ) : '';
		if ( '' !== $status ) {
			$filters['status'] = $status;
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

Uwaga: sygnatury `column_default`/`handle_row_actions`/`extra_tablenav` NIE mają deklaracji typów parametrów (`$item`, `$column`) — nadpisują metody `WP_List_Table` o luźnych sygnaturach; dodanie `array`/`string` złamałoby zgodność sygnatur (PHP fatal „declaration must be compatible"). Typy tylko w docblocku. Zwracany `: string` jest dozwolony (rodzic nie deklaruje typu zwrotu, kowariancja OK).

- [ ] **Step 4: Uruchom test i potwierdź GREEN**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter MailQueueListTableTest
```
Expected: PASS, 5 testów. Slug strony (`'evreg-mail-queue'`) jest tu literałem — `MailQueueScreen` (z jego stałą `SLUG` o tej samej wartości) powstaje w Tasku 4, żeby Task 3 był samodzielny. `EventPostType::POST_TYPE` już istnieje. Task 4 może opcjonalnie podmienić literał na `MailQueueScreen::SLUG` przy okazji rejestracji — nie jest to wymagane (wartość identyczna).

- [ ] **Step 5: Styl i statyka**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs src/Admin
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=512M
```
Expected: zero błędów. `WP_List_Table` jest znane phpstan-wordpress. Gdyby `column_default` sygnatura marudziła w phpcs (WPCS lubi `snake_case` nazwy metod — to metody rodzica, wykluczone przez konfigurację repo dla camelCase), sprawdź, że `currentFilters` (camelCase, prywatna) przechodzi jak inne metody VO w repo.

- [ ] **Step 6: Commit**

```bash
git add src/Admin/MailQueueListTable.php tests/Integration/Admin/MailQueueListTableTest.php
git commit -m "feat: add mail queue list table with status/event filters"
```

---
## Task 4: MailQueueScreen (submenu, render, ekran szczegółów, wznowienie) i rejestracja

**Files:**
- Create: `src/Admin/MailQueueScreen.php`
- Modify: `event-registration.php` (rejestracja na `plugins_loaded`)
- Test: `tests/Integration/Admin/MailQueueScreenTest.php` (z pomocniczym `RedirectException`)

**Interfaces:**
- Consumes: `MailQueueListTable`, `MailQueueRepository::find()`, `requeueFailed()`, `Capabilities::CAP`, `EventPostType::POST_TYPE`
- Produces:
  - `MailQueueScreen::SLUG = 'evreg-mail-queue'`, `REQUEUE_ACTION = 'evreg_requeue_mail'`
  - `static register(): void` (podpina `admin_menu` + `admin_post_evreg_requeue_mail`)
  - `static add_menu(): void`, `static render(): void`, `static handle_requeue(): void`

- [ ] **Step 1: Napisz test (RED)**

`tests/Integration/Admin/MailQueueScreenTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Admin;

use EvReg\Admin\Capabilities;
use EvReg\Admin\MailQueueScreen;
use EvReg\Persistence\MailQueueRepository;
use EvReg\Persistence\Migrations;
use WP_UnitTestCase;

/**
 * Wyjątek przenoszący docelowy adres redirectu, by przerwać przed exit handlera.
 */
final class RedirectException extends \Exception {

	public string $location;

	public function __construct( string $location ) {
		parent::__construct( 'redirect' );
		$this->location = $location;
	}
}

final class MailQueueScreenTest extends WP_UnitTestCase {

	private MailQueueRepository $repository;

	private int $admin_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( 'mail_queue' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->repository = new MailQueueRepository();
		Capabilities::grant();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	protected function tearDown(): void {
		remove_all_filters( 'wp_redirect' );
		unset( $_POST['id'], $_REQUEST['id'], $_REQUEST['_wpnonce'], $_GET['id'] );
		parent::tearDown();
	}

	private function seedFailed(): int {
		global $wpdb;

		$this->repository->insert(
			array(
				'registration_id' => null,
				'event_id'        => 1,
				'template_key'    => 'optin',
				'recipient'       => 'jan@example.com',
				'subject'         => 'Temat',
				'body'            => 'Treść',
				'headers'         => '',
				'scheduled_at'    => '2026-08-19 10:00:00',
			)
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$id = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . Migrations::table( 'mail_queue' ) );
		$wpdb->update( Migrations::table( 'mail_queue' ), array( 'status' => 'failed' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $id;
	}

	public function test_submenu_registered_under_event_cpt(): void {
		wp_set_current_user( $this->admin_id );
		set_current_screen( 'dashboard' );

		MailQueueScreen::add_menu();

		global $submenu;
		$parent = 'edit.php?post_type=evreg_event';
		$slugs  = array();
		foreach ( $submenu[ $parent ] ?? array() as $entry ) {
			$slugs[] = $entry[2];
		}

		$this->assertContains( MailQueueScreen::SLUG, $slugs );
	}

	public function test_requeue_happy_path_resets_row_and_redirects(): void {
		wp_set_current_user( $this->admin_id );
		$id = $this->seedFailed();

		$_POST['id']            = $id;
		$_REQUEST['id']         = $id;
		$_REQUEST['_wpnonce']   = wp_create_nonce( 'evreg_requeue_mail_' . $id );

		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new RedirectException( (string) $location );
			}
		);

		$caught = null;
		try {
			MailQueueScreen::handle_requeue();
		} catch ( RedirectException $e ) {
			$caught = $e;
		}

		$this->assertNotNull( $caught );
		$this->assertStringContainsString( 'evreg_requeued=1', $caught->location );
		$this->assertSame( MailQueueRepository::STATUS_QUEUED, $this->repository->find( $id )['status'] );
	}

	public function test_requeue_without_capability_dies(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		$id = $this->seedFailed();

		$_POST['id']          = $id;
		$_REQUEST['id']       = $id;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'evreg_requeue_mail_' . $id );

		$this->expectException( \WPDieException::class );
		MailQueueScreen::handle_requeue();
	}

	public function test_requeue_with_bad_nonce_dies(): void {
		wp_set_current_user( $this->admin_id );
		$id = $this->seedFailed();

		$_POST['id']          = $id;
		$_REQUEST['id']       = $id;
		$_REQUEST['_wpnonce'] = 'zły-nonce';

		$this->expectException( \WPDieException::class );
		MailQueueScreen::handle_requeue();
	}
}
```

- [ ] **Step 2: Uruchom test i potwierdź RED**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter MailQueueScreenTest
```
Expected: FAIL — `Class "EvReg\Admin\MailQueueScreen" not found`.

- [ ] **Step 3: Zaimplementuj MailQueueScreen**

`src/Admin/MailQueueScreen.php`:

```php
<?php
/**
 * Ekran admina kolejki mailowej: submenu, lista, szczegóły, wznowienie.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Admin;

use EvReg\Persistence\MailQueueRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Ekran admina kolejki mailowej.
 */
final class MailQueueScreen {

	public const SLUG           = 'evreg-mail-queue';
	public const REQUEUE_ACTION = 'evreg_requeue_mail';

	/**
	 * Podpina submenu i handler wznowienia.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_post_' . self::REQUEUE_ACTION, array( self::class, 'handle_requeue' ) );
	}

	/**
	 * Rejestruje submenu pod menu CPT wydarzeń.
	 */
	public static function add_menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . EventPostType::POST_TYPE,
			__( 'Kolejka maili', 'event-registration' ),
			__( 'Kolejka maili', 'event-registration' ),
			Capabilities::CAP,
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * Renderuje listę albo ekran szczegółów wg parametru action.
	 */
	public static function render(): void {
		if ( ! current_user_can( Capabilities::CAP ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'event-registration' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing widoku, nie akcja.
		$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['action'] ) ) : '';

		if ( 'view' === $action ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::render_detail( (int) ( $_GET['id'] ?? 0 ) );
			return;
		}

		self::render_list();
	}

	/**
	 * Renderuje tabelę listy z formularzem filtrów.
	 */
	private static function render_list(): void {
		$table = new MailQueueListTable();
		$table->prepare_items();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Kolejka maili', 'event-registration' ) . '</h1>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['evreg_requeued'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$ok = '1' === (string) $_GET['evreg_requeued'];
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				$ok ? 'success' : 'error',
				$ok ? esc_html__( 'Mail wznowiony — trafi do najbliższego przebiegu wysyłki.', 'event-registration' ) : esc_html__( 'Nie udało się wznowić maila.', 'event-registration' )
			);
		}

		echo '<form method="get">';
		printf( '<input type="hidden" name="post_type" value="%s" />', esc_attr( EventPostType::POST_TYPE ) );
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( self::SLUG ) );
		$table->display();
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Renderuje ekran szczegółów jednego wiersza (read-only).
	 *
	 * @param int $id ID wiersza.
	 */
	private static function render_detail( int $id ): void {
		$row      = ( new MailQueueRepository() )->find( $id );
		$back_url = add_query_arg(
			array( 'post_type' => EventPostType::POST_TYPE, 'page' => self::SLUG ),
			admin_url( 'edit.php' )
		);

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Szczegóły maila', 'event-registration' ) . '</h1>';
		printf( '<p><a href="%s">%s</a></p>', esc_url( $back_url ), esc_html__( '← wróć do kolejki', 'event-registration' ) );

		if ( null === $row ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Nie znaleziono wiersza kolejki.', 'event-registration' ) . '</p></div></div>';
			return;
		}

		$title = get_the_title( (int) $row['event_id'] );

		echo '<table class="widefat striped"><tbody>';
		self::detail_row( __( 'Status', 'event-registration' ), (string) $row['status'] );
		self::detail_row( __( 'Odbiorca', 'event-registration' ), (string) $row['recipient'] );
		self::detail_row( __( 'Wydarzenie', 'event-registration' ), '' === $title ? '#' . (int) $row['event_id'] : $title );
		self::detail_row( __( 'Typ maila', 'event-registration' ), (string) $row['template_key'] );
		self::detail_row( __( 'Próby', 'event-registration' ), (string) $row['attempts'] );
		self::detail_row( __( 'Zaplanowano', 'event-registration' ), (string) $row['scheduled_at'] );
		self::detail_row( __( 'Wysłano', 'event-registration' ), (string) ( $row['sent_at'] ?? '' ) );
		self::detail_row( __( 'Nagłówki', 'event-registration' ), (string) ( $row['headers'] ?? '' ) );
		self::detail_row( __( 'Ostatni błąd', 'event-registration' ), (string) ( $row['last_error'] ?? '' ) );
		self::detail_row( __( 'Temat', 'event-registration' ), (string) $row['subject'] );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Treść', 'event-registration' ) . '</h2>';
		echo '<pre style="white-space:pre-wrap;background:#fff;border:1px solid #ccd0d4;padding:1em;">' . esc_html( (string) $row['body'] ) . '</pre>';

		if ( MailQueueRepository::STATUS_FAILED === $row['status'] ) {
			printf(
				'<p><a class="button button-primary" href="%s">%s</a></p>',
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::REQUEUE_ACTION . '&id=' . $id ), self::REQUEUE_ACTION . '_' . $id ) ),
				esc_html__( 'Wznów', 'event-registration' )
			);
		}

		echo '</div>';
	}

	/**
	 * Renderuje jeden wiersz tabeli szczegółów.
	 *
	 * @param string $label Etykieta.
	 * @param string $value Wartość (escapowana).
	 */
	private static function detail_row( string $label, string $value ): void {
		printf(
			'<tr><th scope="row" style="width:180px;">%s</th><td>%s</td></tr>',
			esc_html( $label ),
			esc_html( $value )
		);
	}

	/**
	 * Obsługuje wznowienie: nonce + cap → requeueFailed → PRG redirect.
	 */
	public static function handle_requeue(): void {
		$id = (int) ( $_POST['id'] ?? $_GET['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		check_admin_referer( self::REQUEUE_ACTION . '_' . $id );

		if ( ! current_user_can( Capabilities::CAP ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'event-registration' ) );
		}

		$ok = ( new MailQueueRepository() )->requeueFailed( $id );

		$redirect = add_query_arg(
			array(
				'post_type'      => EventPostType::POST_TYPE,
				'page'           => self::SLUG,
				'evreg_requeued' => $ok ? '1' : '0',
			),
			admin_url( 'edit.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}
}
```

Uwaga: `check_admin_referer` sprawdza `$_REQUEST['_wpnonce']` przeciw akcji `self::REQUEUE_ACTION . '_' . $id`. Link buduje `wp_nonce_url` z tą samą akcją. Kolejność w `handle_requeue`: najpierw nonce (odrzuca CSRF), potem cap (odrzuca brak uprawnień) — obie przed `requeueFailed`.

- [ ] **Step 4: Zarejestruj ekran w bootstrapie**

W `event-registration.php`, obok innych rejestracji admina na `plugins_loaded`:

```php
add_action( 'plugins_loaded', array( \EvReg\Admin\MailQueueScreen::class, 'register' ) );
```

- [ ] **Step 5: (opcjonalnie) podmień literał slug w MailQueueListTable**

W `src/Admin/MailQueueListTable.php`, w `handle_row_actions`, literał `'evreg-mail-queue'` możesz podmienić na `MailQueueScreen::SLUG` (identyczna wartość, spójność). Niekonieczne — jeśli podmieniasz, dodaj `use` nie jest potrzebny (ta sama przestrzeń `EvReg\Admin`).

- [ ] **Step 6: Uruchom testy i potwierdź GREEN**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter 'MailQueueScreenTest|MailQueueListTableTest'
```
Expected: PASS. Gdyby `test_requeue_with_bad_nonce_dies` nie rzucił `WPDieException`: w tym środowisku `check_admin_referer` przy złym nonce woła `wp_nonce_ays` → `wp_die`; suite testowy WP instaluje die-handler rzucający `WPDieException`. Jeśli mimo to nie leci wyjątek, sprawdź, czy test ustawia `$_REQUEST['_wpnonce']` (nie tylko `$_POST`).

- [ ] **Step 7: Pełna suita, styl, statyka**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs src event-registration.php
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=512M
```
Expected: cała suita integracyjna zielona (w tym testy kolejki z 4A), zero błędów stylu i statyki.

- [ ] **Step 8: Commit**

```bash
git add src/Admin/MailQueueScreen.php event-registration.php tests/Integration/Admin/MailQueueScreenTest.php
git commit -m "feat: add mail queue admin screen with detail view and requeue"
```

- [ ] **Step 9: Weryfikacja w przeglądarce (kontroler, nie subagent)**

Kontroler: menu Wydarzenia → „Kolejka maili"; lista pokazuje wiersze; filtr statusu/eventu działa; „Podgląd" otwiera szczegóły z treścią; na wierszu `failed` „Wznów" → wraca na listę z notice, wiersz staje się `queued`; po przebiegu crona wychodzi mail. Subagent pomija ten krok.

---

## Task 5: Dokumentacja

**Files:**
- Modify: `README.md` (Status)
- Modify: `CLAUDE.md` (opis ekranu kolejki, roadmapa)

- [ ] **Step 1: Zaktualizuj README**

W sekcji Status znajdź punkt o adminie maili (po 4B-Szablony). Rozbij/zaktualizuj tak, by odzwierciedlał ukończenie ekranu kolejki. Utrzymaj listę consecutively numbered. Przykład, jeśli punkt 5 brzmi „edytor szablonów gotowy; ekran kolejki osobny plan":

```
5. ✅ Admin maili — edytor szablonów per event + ekran kolejki (lista, filtry, podgląd, wznowienie failed)
```

- [ ] **Step 2: Zaktualizuj CLAUDE.md**

W akapicie o roadmapie dopisz do scalonych „Plan 4B-Kolejka (ekran kolejki mailowej)". Cały Plan 4 (kolejka mailowa) jest wtedy zamknięty — zmień „Kolejny" na Plan 5 (panel zgłoszeń).

Pod akapitem o kolejce/edytorze maili dodaj:

```
Ekran kolejki (4B-Kolejka): `MailQueueScreen` — submenu „Kolejka maili" pod menu CPT `evreg_event`, cap `edit_evreg_events`. `MailQueueListTable` (WP_List_Table) listuje `evreg_mail_queue` z filtrami statusu/eventu, paginacja 20/stronę, `ORDER BY id DESC`. Akcja „Podgląd" → ekran szczegółów (`?action=view&id=N`, read-only, treść w `<pre>` escapowana). Akcja „Wznów" tylko na `failed` → `admin-post` `evreg_requeue_mail` (nonce + cap + PRG) → `MailQueueRepository::requeueFailed` (`status→queued`, `attempts=0`, chronione `WHERE status='failed'`). Admin nie woła dispatchera — resetuje wiersz, cron `evreg_dispatch_mail` z 4A złapie w następnym przebiegu. Zapytania listujące (`paginate`/`countByFilter`/`distinctEventIds`) i wznowienie w `MailQueueRepository` (jedyne SQL kolejki).
```

W sekcji Gotchas dopisz:

```
- **`WP_List_Table` nie jest autoloadowane** — plik podklasy (`MailQueueListTable`) musi `require_once ABSPATH.'wp-admin/includes/class-wp-list-table.php'` przed deklaracją klasy. Testy: `set_current_screen(...)` przed instancjonowaniem; redirect handlera łapany filtrem `wp_redirect` rzucającym wyjątek, zły nonce/cap → `WPDieException`.
```

- [ ] **Step 3: Commit**

```bash
git add README.md CLAUDE.md
git commit -m "docs: document mail queue admin screen and Plan 4B completion"
```

---

## Definicja ukończenia Planu 4B-Kolejka

- `vendor/bin/phpunit -c phpunit-integration.xml.dist` — zielone, w tym testy kolejki z 4A (silnik nietknięty)
- `vendor/bin/phpcs src` i `vendor/bin/phpstan analyse --memory-limit=512M` — zero błędów
- Submenu „Kolejka maili" widoczne pod menu Wydarzenia dla `edit_evreg_events`
- Lista filtruje po statusie i evencie, paginacja działa
- „Podgląd" pokazuje pełną treść i `last_error`
- „Wznów" na `failed` resetuje wiersz do `queued` (attempts=0); `sent`/`queued`/`sending` bez akcji; podrobione `id` nie wskrzesi nie-failed (test)
- Weryfikacja w przeglądarce (kontroler): lista, filtry, podgląd, wznowienie end-to-end

## Czego Plan 4B-Kolejka świadomie nie robi

Brak bulk actions (masowe wznawianie) — pojedyncze wystarcza. Brak kasowania wierszy z UI — purge robi cron z 4A. Brak ponownej wysyłki `sent` — świadomie (dubel do uczestnika). Brak sortowania po kolumnach i wyszukiwania — `id DESC` + filtry wystarczą dla logu. Brak keyset pagination — `OFFSET` wystarcza przy tej skali.
