# Liczba osób w pokoju — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Pokój dostaje liczbę osób (1 albo 2); z niej wynikają zajmowane miejsca, mnożnik ceny i to, czy pokój da się wybrać z osobą towarzyszącą — a checkbox towarzyszącej przenosi się nad listę noclegów i filtruje ją.

**Architecture:** Jedna reguła „ustawiona liczba osób, a gdy nieustawiona to `companion ? 2 : 1`" mieszka w `RoomOccupancy::seats()`; `RoomType`, `AccommodationConfig` i `CapacityLimits` z niej czytają, a `CapacityCalculator` i `PriceCalculator` przestają mnożyć przez flagę. Sąd o spójności pary pokój/towarzysząca jest czystą metodą `RoomType::companionMismatch()`, stosowaną przez `SubmitHandler` zawsze, a przez `RegistrationsScreen::handle_edit` tylko gdy edycja faktycznie zmienia nocleg albo flagę. Filtr listy jest serwerowy, `form.js` go lustrzy.

**Tech Stack:** PHP 8.1, WordPress 6.4+, PHPUnit (unit + integration w wp-env), jest, Playwright, phpcs/PHPStan L6. Bez nowych zależności, bez migracji bazy.

**Spec:** `docs/superpowers/specs/2026-11-16-room-occupancy-design.md`

**Baza:** ten plan stoi na gałęzi `feature/exhausted-accommodation-slots` (HEAD `593ceca`), nie na `master`. Cały kod cytowany niżej pochodzi z `593ceca`. Jeśli startujesz z innego commita, zatrzymaj się i zgłoś to.

## Global Constraints

- `src/Domain/**` — zero WordPressa i zero `$wpdb`; pilnuje `tests/Unit/Architecture/DomainPurityTest.php`. Pliki w `src/Domain/` NIE mają `defined( 'ABSPATH' ) || exit;`, każdy inny plik PHP poza `tests/` MA.
- Domena zwraca kody, nigdy komunikatów dla użytkownika.
- Cała arytmetyka miejsc i ceny w czystej domenie. **`RoomOccupancy::seats()` jest jedynym domem reguły fallbacku** — nie kopiuj jej nigdzie i nie ucz jej o `companion_enabled`.
- Inwariant 3A: w `reserve` / `editAnswers` / `promoteFromWaitlist` `lockEvent()` MUSI poprzedzać pierwszy COUNT zajętości. Ten plan nie zmienia tej kolejności w żadnym miejscu.
- Hooki cyklu życia emitowane wyłącznie po COMMIT.
- Wszystkie stringi UI przez i18n, text domain `event-registration`.
- Styl: `array()` (nie `[]`), Yoda conditions (`'' === $x`), taby, docblock z `@param` dla każdego parametru. Metody obiektów wartości camelCase.
- phpcs czysto pod `phpcs.xml.dist` (scope: `src` + `event-registration.php`; `tests/` NIE jest linttowane). PHPStan poziom 6, bez nowych `@phpstan-ignore`.
- Logika mutacji admina w czystych modułach `assets/admin/ops/*` testowanych `jest`; komponenty cienkie.
- `assets/public/form.js` — zwykły ES5 bez webpacka, bez zależności.
- Pola schematu zostają w namespace `evreg_field[<klucz>]`; `evreg_companion` / `evreg_companion_name` zostają poza nim.
- LF, nie CRLF. Na Windowsie: pisz pliki narzędziami edycyjnymi, nie przez PowerShell `Out-File`/`Set-Content` bez `-Encoding utf8`.
- Host NIE MA PHP. Komendy PHP przez wrapper, nigdy gołym `npx wp-env`:
  `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- <cmd>`
- **Uwaga z CLAUDE.md:** konsola PHPUnit w tym kontenerze zniekształca komunikat każdego niezłapanego `Error`. Fazę RED weryfikuj jawnym `try/catch` drukującym `get_class($e)` / `getMessage()`, gdy podsumowanie PHPUnit jest nieczytelne.

---

### Task 1: `RoomOccupancy` + liczba osób na `RoomType`

**Files:**
- Create: `src/Domain/Accommodation/RoomOccupancy.php`
- Modify: `src/Domain/Accommodation/RoomType.php`
- Test: `tests/Unit/Domain/Accommodation/RoomOccupancyTest.php`
- Test: `tests/Unit/Domain/Accommodation/RoomTypeOccupancyTest.php`

**Interfaces:**
- Consumes: nic.
- Produces: `RoomOccupancy::seats( int $occupancy, bool $companion ): int`; `RoomType::$occupancy`, `RoomType::seats( bool $companion ): int`, `RoomType::companionMismatch( bool $companion ): ?string`. Taski 2, 3, 7 i 8 na tym stoją.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Domain/Accommodation/RoomOccupancyTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Accommodation;

use EvReg\Domain\Accommodation\RoomOccupancy;
use PHPUnit\Framework\TestCase;

final class RoomOccupancyTest extends TestCase {

	/**
	 * @return array<string,array{0:int,1:bool,2:int}>
	 */
	public static function seatsProvider(): array {
		return array(
			'nieustawione, solo'       => array( 0, false, 1 ),
			'nieustawione, z osobą'    => array( 0, true, 2 ),
			'jednoosobowy, solo'       => array( 1, false, 1 ),
			'jednoosobowy, z osobą'    => array( 1, true, 1 ),
			'dwuosobowy, solo'         => array( 2, false, 2 ),
			'dwuosobowy, z osobą'      => array( 2, true, 2 ),
		);
	}

	/**
	 * @dataProvider seatsProvider
	 *
	 * @param int  $occupancy Liczba osób w pokoju.
	 * @param bool $companion Czy zgłoszenie ma osobę towarzyszącą.
	 * @param int  $expected  Oczekiwana liczba miejsc.
	 */
	public function test_seats( int $occupancy, bool $companion, int $expected ): void {
		$this->assertSame( $expected, RoomOccupancy::seats( $occupancy, $companion ) );
	}
}
```

`tests/Unit/Domain/Accommodation/RoomTypeOccupancyTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Accommodation;

use EvReg\Domain\Accommodation\RoomType;
use EvReg\Domain\Schema\SchemaException;
use PHPUnit\Framework\TestCase;

final class RoomTypeOccupancyTest extends TestCase {

	public function test_occupancy_defaults_to_zero(): void {
		$room = RoomType::fromArray( array( 'key' => 'single', 'label' => '1-os.' ) );

		$this->assertSame( 0, $room->occupancy );
	}

	public function test_occupancy_round_trips(): void {
		$room = RoomType::fromArray( array( 'key' => 'double', 'label' => '2-os.', 'occupancy' => 2 ) );

		$this->assertSame( 2, $room->occupancy );
		$this->assertSame( 2, $room->toArray()['occupancy'] );
	}

	public function test_rejects_occupancy_above_two(): void {
		$this->expectException( SchemaException::class );

		RoomType::fromArray( array( 'key' => 'triple', 'label' => '3-os.', 'occupancy' => 3 ) );
	}

	public function test_rejects_negative_occupancy(): void {
		$this->expectException( SchemaException::class );

		RoomType::fromArray( array( 'key' => 'weird', 'label' => 'x', 'occupancy' => -1 ) );
	}

	public function test_seats_delegates_to_room_occupancy(): void {
		$unset  = RoomType::fromArray( array( 'key' => 'a', 'label' => 'a' ) );
		$double = RoomType::fromArray( array( 'key' => 'b', 'label' => 'b', 'occupancy' => 2 ) );

		$this->assertSame( 1, $unset->seats( false ) );
		$this->assertSame( 2, $unset->seats( true ) );
		$this->assertSame( 2, $double->seats( false ) );
	}

	/**
	 * @return array<string,array{0:int,1:bool,2:string|null}>
	 */
	public static function mismatchProvider(): array {
		return array(
			'nieustawione nigdy nie protestuje, solo'    => array( 0, false, null ),
			'nieustawione nigdy nie protestuje, z osobą' => array( 0, true, null ),
			'jednoosobowy solo jest OK'                  => array( 1, false, null ),
			'jednoosobowy z osobą jest błędem'           => array( 1, true, 'companion_not_allowed' ),
			'dwuosobowy z osobą jest OK'                 => array( 2, true, null ),
			'dwuosobowy solo jest błędem'                => array( 2, false, 'companion_required' ),
		);
	}

	/**
	 * @dataProvider mismatchProvider
	 *
	 * @param int         $occupancy Liczba osób w pokoju.
	 * @param bool        $companion Czy zgłoszenie ma osobę towarzyszącą.
	 * @param string|null $expected  Oczekiwany kod błędu albo null.
	 */
	public function test_companion_mismatch( int $occupancy, bool $companion, ?string $expected ): void {
		$room = RoomType::fromArray( array( 'key' => 'r', 'label' => 'r', 'occupancy' => $occupancy ) );

		$this->assertSame( $expected, $room->companionMismatch( $companion ) );
	}
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit --filter "RoomOccupancyTest|RoomTypeOccupancyTest"
```

Oczekiwane: FAIL — brak klasy `RoomOccupancy`, brak `$occupancy` / `seats()` / `companionMismatch()`.

- [ ] **Step 3: Utwórz `RoomOccupancy`**

`src/Domain/Accommodation/RoomOccupancy.php` (bez `defined( 'ABSPATH' )` — to `src/Domain/`):

```php
<?php
/**
 * Reguła przeliczania liczby osób w pokoju na zajmowane miejsca.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Accommodation;

/**
 * Jedyny dom reguły: ustawiona liczba osób wygrywa, a gdy nieustawiona —
 * zachowanie sprzed wprowadzenia tego pojęcia (`companion ? 2 : 1`).
 *
 * Nie uczy się o globalnym przełączniku `companion_enabled` — sprzeczną
 * konfigurację zeruje AccommodationConfig, zanim tu dotrze.
 */
final class RoomOccupancy {

	/**
	 * Ile miejsc zajmuje zgłoszenie w pokoju o danej liczbie osób.
	 *
	 * @param int  $occupancy Liczba osób w pokoju; 0 = nieustawione.
	 * @param bool $companion Czy zgłoszenie zawiera osobę towarzyszącą.
	 */
	public static function seats( int $occupancy, bool $companion ): int {
		return 0 === $occupancy ? ( $companion ? 2 : 1 ) : $occupancy;
	}
}
```

- [ ] **Step 4: Rozszerz `RoomType`**

W `src/Domain/Accommodation/RoomType.php`: dołóż czwarty parametr konstruktora wraz z `@param`, rozszerz `fromArray`, `toArray`, i dodaj dwie metody.

Konstruktor:

```php
	 * @param int    $occupancy     Liczba osób w pokoju: 0 = nieustawione, 1, 2.
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly bool $roommateField = false,
		public readonly int $occupancy = 0
	) {
	}
```

`fromArray` — dołóż walidację i czwarty argument:

```php
		$occupancy = (int) ( $data['occupancy'] ?? 0 );

		if ( $occupancy < 0 || $occupancy > 2 ) {
			throw new SchemaException(
				sprintf( 'Pokój "%s" może mieć 1 albo 2 osoby (0 = nieustawione).', (string) $data['key'] )
			);
		}

		return new self(
			(string) $data['key'],
			(string) ( $data['label'] ?? $data['key'] ),
			(bool) ( $data['roommate_field'] ?? false ),
			$occupancy
		);
```

`toArray` — dołóż klucz:

```php
		return array(
			'key'            => $this->key,
			'label'          => $this->label,
			'roommate_field' => $this->roommateField,
			'occupancy'      => $this->occupancy,
		);
```

Dwie nowe metody:

```php
	/**
	 * Ile miejsc zajmuje w tym pokoju zgłoszenie z lub bez osoby towarzyszącej.
	 *
	 * @param bool $companion Czy zgłoszenie zawiera osobę towarzyszącą.
	 */
	public function seats( bool $companion ): int {
		return RoomOccupancy::seats( $this->occupancy, $companion );
	}

	/**
	 * Zwraca kod błędu, gdy para pokój/osoba towarzysząca jest niespójna, albo null.
	 * Pokój bez ustawionej liczby osób nigdy nie protestuje (zgodność w tył).
	 *
	 * @param bool $companion Czy zgłoszenie zawiera osobę towarzyszącą.
	 */
	public function companionMismatch( bool $companion ): ?string {
		if ( 0 === $this->occupancy ) {
			return null;
		}
		if ( $companion && 1 === $this->occupancy ) {
			return 'companion_not_allowed';
		}
		if ( ! $companion && 2 === $this->occupancy ) {
			return 'companion_required';
		}

		return null;
	}
```

- [ ] **Step 5: Run tests, lint**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
```

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
```

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=2G
```

Oczekiwane: PASS, w tym `DomainPurityTest` i istniejący `AccommodationConfigTest` (nowy klucz w `toArray` może wymagać poprawienia asercji na pełnej tablicy — jeśli tak, popraw ją i wymień w raporcie).

- [ ] **Step 6: Commit**

```bash
git add src/Domain/Accommodation/RoomOccupancy.php src/Domain/Accommodation/RoomType.php tests/Unit/Domain/Accommodation/RoomOccupancyTest.php tests/Unit/Domain/Accommodation/RoomTypeOccupancyTest.php && git commit -m "feat(domain): room occupancy drives seats and companion consistency"
```

---

### Task 2: `AccommodationConfig` — mapy liczby osób i zerowanie sprzeczności

**Files:**
- Modify: `src/Domain/Accommodation/AccommodationConfig.php`
- Test: `tests/Unit/Domain/Accommodation/AccommodationOccupancyTest.php`

**Interfaces:**
- Consumes: `RoomType::$occupancy`, `RoomType::seats()`, `RoomOccupancy::seats()` (Task 1).
- Produces: `AccommodationConfig::seatsFor( string $room_key, bool $companion ): int`, `AccommodationConfig::slotOccupancies(): array<string,int>`. Taski 3, 4, 5 i 7 na tym stoją.

**Decyzja zapisana w specu, tu konkretnie:** gdy `companion_enabled` jest wyłączone, `fromArray` buduje pokoje z `occupancy` wyzerowanym. Dzięki temu sprzeczna konfiguracja czyta się jak „nieustawione" **we wszystkich** warstwach naraz (pojemność, cena, walidacja, render), a `RoomOccupancy::seats` nie musi nic wiedzieć o przełączniku.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Domain/Accommodation/AccommodationOccupancyTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Accommodation;

use EvReg\Domain\Accommodation\AccommodationConfig;
use PHPUnit\Framework\TestCase;

final class AccommodationOccupancyTest extends TestCase {

	/**
	 * @param bool $companion_enabled Czy event dopuszcza osobę towarzyszącą.
	 * @return array<string,mixed>
	 */
	private function raw( bool $companion_enabled = true ): array {
		return array(
			'packages'          => array( array( 'key' => 'n18', 'label' => 'Noc 18/19' ) ),
			'rooms'             => array(
				array( 'key' => 'single', 'label' => '1-os.', 'occupancy' => 1 ),
				array( 'key' => 'double', 'label' => '2-os.', 'occupancy' => 2 ),
				array( 'key' => 'any', 'label' => 'dowolny' ),
			),
			'inventory'         => array(
				array( 'package' => 'n18', 'room' => 'single', 'capacity' => 10, 'price' => 100.0 ),
				array( 'package' => 'n18', 'room' => 'double', 'capacity' => 4, 'price' => 180.0 ),
				array( 'package' => 'n18', 'room' => 'any', 'capacity' => 2, 'price' => 150.0 ),
			),
			'companion_enabled' => $companion_enabled,
		);
	}

	public function test_slot_occupancies_maps_every_inventory_slot(): void {
		$config = AccommodationConfig::fromArray( $this->raw() );

		$this->assertSame(
			array( 'n18|single' => 1, 'n18|double' => 2, 'n18|any' => 0 ),
			$config->slotOccupancies()
		);
	}

	public function test_seats_for_uses_room_occupancy(): void {
		$config = AccommodationConfig::fromArray( $this->raw() );

		$this->assertSame( 1, $config->seatsFor( 'single', true ) );
		$this->assertSame( 2, $config->seatsFor( 'double', false ) );
	}

	public function test_seats_for_unset_room_falls_back_to_companion(): void {
		$config = AccommodationConfig::fromArray( $this->raw() );

		$this->assertSame( 1, $config->seatsFor( 'any', false ) );
		$this->assertSame( 2, $config->seatsFor( 'any', true ) );
	}

	public function test_seats_for_unknown_room_falls_back_to_companion(): void {
		$config = AccommodationConfig::fromArray( $this->raw() );

		$this->assertSame( 1, $config->seatsFor( 'ghost', false ) );
		$this->assertSame( 2, $config->seatsFor( 'ghost', true ) );
	}

	public function test_occupancy_is_zeroed_when_companion_disabled(): void {
		// Sprzeczna konfiguracja (pokój na 2 osoby przy wyłączonej towarzyszącej)
		// czyta się jak „nieustawione" — inaczej formularz nie oferowałby żadnego
		// pokoju dwuosobowego, a companion_required byłoby niespełnialne.
		$config = AccommodationConfig::fromArray( $this->raw( false ) );

		$this->assertSame(
			array( 'n18|single' => 0, 'n18|double' => 0, 'n18|any' => 0 ),
			$config->slotOccupancies()
		);
		$this->assertSame( 0, $config->room( 'double' )->occupancy );
		$this->assertNull( $config->room( 'double' )->companionMismatch( false ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit --filter AccommodationOccupancyTest
```

Oczekiwane: FAIL — brak `slotOccupancies()` i `seatsFor()`.

- [ ] **Step 3: Zeruj sprzeczność w `fromArray`**

W `AccommodationConfig::fromArray`, pętla budująca `$rooms` czyta dziś:

```php
		foreach ( (array) ( $config['rooms'] ?? array() ) as $room ) {
			$rooms[] = RoomType::fromArray( (array) $room );
		}
```

Zamień na:

```php
		// Liczba osób w pokoju ma sens tylko gdy event dopuszcza osobę towarzyszącą.
		// Przy wyłączonym przełączniku SubmissionAssembler twardo wymusza companion=false,
		// więc pokój „na 2 osoby" byłby trwale niewybieralny, a companion_required
		// niespełnialne. Czytamy taką sprzeczność jak „nieustawione" — w jednym miejscu,
		// żeby pojemność, cena, walidacja i render widziały to samo.
		$companion_enabled = (bool) ( $config['companion_enabled'] ?? false );

		foreach ( (array) ( $config['rooms'] ?? array() ) as $room ) {
			$room = (array) $room;
			if ( ! $companion_enabled ) {
				$room['occupancy'] = 0;
			}
			$rooms[] = RoomType::fromArray( $room );
		}
```

- [ ] **Step 4: Dodaj dwie metody**

Po `room()` w `src/Domain/Accommodation/AccommodationConfig.php`:

```php
	/**
	 * Ile miejsc zajmuje zgłoszenie w pokoju o danym kluczu.
	 * Nieznany pokój (config-drift) zachowuje się jak pokój bez ustawionej liczby osób.
	 *
	 * @param string $room_key  Klucz typu pokoju.
	 * @param bool   $companion Czy zgłoszenie zawiera osobę towarzyszącą.
	 */
	public function seatsFor( string $room_key, bool $companion ): int {
		$room = $this->room( $room_key );

		return null === $room
			? RoomOccupancy::seats( 0, $companion )
			: $room->seats( $companion );
	}

	/**
	 * Zwraca mapę slot "pakiet|pokój" => liczba osób w pokoju (0 = nieustawione).
	 *
	 * @return array<string,int>
	 */
	public function slotOccupancies(): array {
		$map = array();

		foreach ( $this->items as $item ) {
			$room                   = $this->room( $item->roomKey );
			$map[ $item->slotKey() ] = null === $room ? 0 : $room->occupancy;
		}

		return $map;
	}
```

- [ ] **Step 5: Run tests, lint, commit**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
```

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs && node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=2G
```

```bash
git add src/Domain/Accommodation/AccommodationConfig.php tests/Unit/Domain/Accommodation/AccommodationOccupancyTest.php && git commit -m "feat(domain): accommodation config exposes per-slot occupancy"
```

---

### Task 3: Pojemność liczy z liczby osób

**Files:**
- Modify: `src/Domain/Capacity/CapacityLimits.php`
- Modify: `src/Domain/Capacity/CapacityCalculator.php`
- Test: `tests/Unit/Domain/Capacity/CapacityOccupancyTest.php`

**Interfaces:**
- Consumes: `RoomOccupancy::seats()` (Task 1), `AccommodationConfig::slotOccupancies()` (Task 2).
- Produces: szósty parametr konstruktora `CapacityLimits` (`array<string,int> $perSlotSeats = array()`) + `CapacityLimits::seatsForSlot( string $slot, bool $companion ): int`. Task 5 przekazuje mapę z serwisu.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Domain/Capacity/CapacityOccupancyTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Capacity;

use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Domain\Capacity\CapacityCalculator;
use EvReg\Domain\Capacity\CapacityLimits;
use EvReg\Domain\Capacity\OccupancySnapshot;
use PHPUnit\Framework\TestCase;

final class CapacityOccupancyTest extends TestCase {

	public function test_seats_for_slot_uses_configured_occupancy(): void {
		$limits = new CapacityLimits( null, array(), array(), true, false, array( 'n18|double' => 2 ) );

		$this->assertSame( 2, $limits->seatsForSlot( 'n18|double', false ) );
		$this->assertSame( 2, $limits->seatsForSlot( 'n18|double', true ) );
	}

	public function test_seats_for_slot_without_occupancy_falls_back_to_companion(): void {
		$limits = new CapacityLimits( null, array(), array(), true, false, array( 'n18|any' => 0 ) );

		$this->assertSame( 1, $limits->seatsForSlot( 'n18|any', false ) );
		$this->assertSame( 2, $limits->seatsForSlot( 'n18|any', true ) );
	}

	public function test_seats_for_slot_absent_from_map_falls_back_to_companion(): void {
		$limits = new CapacityLimits( null, array(), array(), true, false, array() );

		$this->assertSame( 1, $limits->seatsForSlot( 'n18|ghost', false ) );
		$this->assertSame( 2, $limits->seatsForSlot( 'n18|ghost', true ) );
	}

	public function test_double_room_takes_two_seats_even_without_companion(): void {
		// Config-drift: pokój oznaczony jako dwuosobowy po tym, jak ktoś wybrał go solo.
		// Pokój zajmuje dwa łóżka niezależnie od flagi — slot ma tylko jedno wolne.
		$limits   = new CapacityLimits( null, array(), array( 'n18|double' => 1 ), true, false, array( 'n18|double' => 2 ) );
		$decision = ( new CapacityCalculator() )->decide(
			$limits,
			new OccupancySnapshot( 0 ),
			'uczestnik',
			new AccommodationSelection( 'n18', 'double' ),
			false
		);

		$this->assertTrue( $decision->isAccepted() );
		$this->assertFalse( $decision->accommodationGranted );
		$this->assertSame( 'accommodation_full', $decision->reason );
	}

	public function test_single_room_takes_one_seat_even_with_companion(): void {
		$limits   = new CapacityLimits( null, array(), array( 'n18|single' => 1 ), true, false, array( 'n18|single' => 1 ) );
		$decision = ( new CapacityCalculator() )->decide(
			$limits,
			new OccupancySnapshot( 0 ),
			'uczestnik',
			new AccommodationSelection( 'n18', 'single' ),
			true
		);

		$this->assertTrue( $decision->accommodationGranted );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit --filter CapacityOccupancyTest
```

Oczekiwane: FAIL — konstruktor nie przyjmuje szóstego argumentu, brak `seatsForSlot()`.

- [ ] **Step 3: Rozszerz `CapacityLimits`**

Dołóż szósty parametr i `@param`:

```php
	 * @param array<string,int>      $perSlotSeats Liczba osób w pokoju per slot "pakiet|pokój"; 0 = nieustawione.
	 */
	public function __construct(
		public readonly ?int $globalLimit,
		private readonly array $perType,
		private readonly array $perSlot,
		public readonly bool $waitlistEnabled = true,
		public readonly bool $companionCountsEvent = false,
		private readonly array $perSlotSeats = array()
	) {
	}
```

Dodaj metodę po `forSlot()`:

```php
	/**
	 * Ile miejsc zajmie zgłoszenie w tym slocie. Delegacja do RoomOccupancy —
	 * reguła fallbacku ma jeden dom i nie jest tu kopiowana.
	 *
	 * @param string $key       Klucz slotu "pakiet|pokój".
	 * @param bool   $companion Czy zgłoszenie zawiera osobę towarzyszącą.
	 */
	public function seatsForSlot( string $key, bool $companion ): int {
		return RoomOccupancy::seats( $this->perSlotSeats[ $key ] ?? 0, $companion );
	}
```

Dołóż import `use EvReg\Domain\Accommodation\RoomOccupancy;`.

- [ ] **Step 4: Niech `CapacityCalculator` pyta o miejsca**

W `src/Domain/Capacity/CapacityCalculator.php` zamień:

```php
		$acc_seats  = $companion ? 2 : 1;
```

na:

```php
		$acc_seats  = $limits->seatsForSlot( $slot, $companion );
```

(linia stoi tuż po `$slot_limit = $limits->forSlot( $slot );` — reszta metody bez zmian)

- [ ] **Step 5: Run tests, lint, commit**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
```

Oczekiwane: PASS, w tym `CapacityCalculatorTest` i `CapacityCalculatorCompanionTest` — ich wywołania `new CapacityLimits( ... )` nie podają szóstego argumentu, więc mapa jest pusta i fallback daje dzisiejsze zachowanie.

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs && node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=2G
```

```bash
git add src/Domain/Capacity/CapacityLimits.php src/Domain/Capacity/CapacityCalculator.php tests/Unit/Domain/Capacity/CapacityOccupancyTest.php && git commit -m "feat(domain): capacity takes seats from room occupancy"
```

---

### Task 4: Cena liczy z liczby osób

**Files:**
- Modify: `src/Domain/Pricing/PriceCalculator.php`
- Test: `tests/Unit/Domain/Pricing/PriceOccupancyTest.php`

**Interfaces:**
- Consumes: `AccommodationConfig::seatsFor()` (Task 2).
- Produces: nic nowego — sygnatura `total()` się NIE zmienia.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Domain/Pricing/PriceOccupancyTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Pricing;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Domain\Pricing\PriceCalculator;
use EvReg\Domain\Registration\RegistrationType;
use PHPUnit\Framework\TestCase;

final class PriceOccupancyTest extends TestCase {

	private function config(): AccommodationConfig {
		return AccommodationConfig::fromArray(
			array(
				'packages'          => array( array( 'key' => 'n18', 'label' => 'Noc 18/19' ) ),
				'rooms'             => array(
					array( 'key' => 'single', 'label' => '1-os.', 'occupancy' => 1 ),
					array( 'key' => 'double', 'label' => '2-os.', 'occupancy' => 2 ),
					array( 'key' => 'any', 'label' => 'dowolny' ),
				),
				'inventory'         => array(
					array( 'package' => 'n18', 'room' => 'single', 'capacity' => 10, 'price' => 100.0 ),
					array( 'package' => 'n18', 'room' => 'double', 'capacity' => 4, 'price' => 180.0 ),
					array( 'package' => 'n18', 'room' => 'any', 'capacity' => 2, 'price' => 150.0 ),
				),
				'companion_enabled' => true,
			)
		);
	}

	private function type(): RegistrationType {
		return RegistrationType::fromArray( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 450.0 ) );
	}

	public function test_double_room_is_charged_twice_without_companion(): void {
		$total = ( new PriceCalculator() )->total(
			$this->type(),
			$this->config(),
			new AccommodationSelection( 'n18', 'double' ),
			false
		);

		$this->assertSame( 810.0, $total ); // 450 + 2 × 180
	}

	public function test_single_room_is_charged_once_with_companion(): void {
		$total = ( new PriceCalculator() )->total(
			$this->type(),
			$this->config(),
			new AccommodationSelection( 'n18', 'single' ),
			true
		);

		$this->assertSame( 550.0, $total ); // 450 + 1 × 100
	}

	public function test_room_without_occupancy_keeps_companion_based_multiplier(): void {
		$calculator = new PriceCalculator();

		$this->assertSame( 600.0, $calculator->total( $this->type(), $this->config(), new AccommodationSelection( 'n18', 'any' ), false ) );
		$this->assertSame( 750.0, $calculator->total( $this->type(), $this->config(), new AccommodationSelection( 'n18', 'any' ), true ) );
	}
}
```

Sygnatury sprawdzone w kodzie: `RegistrationType::fromArray( array $data )` istnieje, a konstruktor to `( string $key, string $label, float $price = 0.0, ?int $capacity = null, bool $active = true )`.

- [ ] **Step 2: Run test to verify it fails**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit --filter PriceOccupancyTest
```

Oczekiwane: FAIL — `test_double_room_is_charged_twice_without_companion` dostaje 630.0 (450 + 1 × 180), bo mnożnik idzie z flagi.

- [ ] **Step 3: Niech cena pyta o miejsca**

W `src/Domain/Pricing/PriceCalculator.php` zamień:

```php
				$total += $item->price * ( $companion ? 2 : 1 );
```

na:

```php
				// Mnożnik idzie z liczby osób w pokoju, nie z flagi — inaczej cena
				// rozjechałaby się z pojemnością (pokój dwuosobowy zajmuje 2 łóżka
				// także wtedy, gdy flaga mówi inaczej).
				$total += $item->price * $config->seatsFor( $selection->roomKey, $companion );
```

Zaktualizuj `@param` dla `$companion` — nie „podwaja cenę noclegu", a „wpływa na liczbę miejsc w pokojach bez ustawionej liczby osób".

- [ ] **Step 4: Run tests, lint, commit**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
```

Oczekiwane: PASS, w tym istniejące testy `PriceCalculator` (ich pokoje nie mają `occupancy`, więc fallback daje dzisiejsze liczby).

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs && node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=2G
```

```bash
git add src/Domain/Pricing/PriceCalculator.php tests/Unit/Domain/Pricing/PriceOccupancyTest.php && git commit -m "feat(domain): price multiplier comes from room occupancy"
```

---

### Task 5: Ścieżki transakcyjne przekazują liczbę osób

**Files:**
- Modify: `src/Services/ReservationService.php`
- Test: `tests/Integration/Services/RoomOccupancyReservationTest.php`

**Interfaces:**
- Consumes: `AccommodationConfig::slotOccupancies()` i `seatsFor()` (Task 2), szósty argument `CapacityLimits` (Task 3).
- Produces: nic nowego publicznie — domyka arytmetykę w produkcji.

**Policz miejsca w kodzie, nie na pamięć.** Przed edycją:

```bash
grep -n "new CapacityLimits" src/Services/ReservationService.php
```

```bash
grep -n "insertAccommodationBooking" src/Services/ReservationService.php
```

Oczekiwane: **trzy** wystąpienia pierwszego (`reserve`, `promoteFromWaitlist`, `editAnswers`) i **trzy** drugiego. Jeśli liczby są inne, zatrzymaj się i zgłoś.

- [ ] **Step 1: Write the failing test**

`tests/Integration/Services/RoomOccupancyReservationTest.php`:

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

final class RoomOccupancyReservationTest extends WP_UnitTestCase {

	private ReservationService $service;

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
		$this->service    = new ReservationService( $this->repository, new EventConfigRepository() );
		$this->event_id   = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );

		( new EventConfigRepository() )->save(
			$this->event_id,
			array(
				'types'         => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 100.0 ) ),
				'accommodation' => array(
					'packages'          => array( array( 'key' => 'n18', 'label' => 'Noc 18/19' ) ),
					'rooms'             => array(
						array( 'key' => 'single', 'label' => '1-os.', 'occupancy' => 1 ),
						array( 'key' => 'double', 'label' => '2-os.', 'occupancy' => 2 ),
					),
					'inventory'         => array(
						array( 'package' => 'n18', 'room' => 'single', 'capacity' => 2, 'price' => 100.0 ),
						array( 'package' => 'n18', 'room' => 'double', 'capacity' => 2, 'price' => 180.0 ),
					),
					'allow_none'        => true,
					'companion_enabled' => true,
				),
				'settings'      => array( 'global_cap' => null, 'waitlist_enabled' => true ),
			)
		);
	}

	private function request( string $email, string $room, bool $companion ): ReservationRequest {
		return new ReservationRequest(
			$email,
			'Jan',
			'uczestnik',
			array( 'email' => $email ),
			new AccommodationSelection( 'n18', $room ),
			'',
			$companion,
			$companion ? 'Anna Kowalska' : ''
		);
	}

	public function test_double_room_books_two_seats_and_double_price(): void {
		$result = $this->service->reserve( $this->event_id, $this->request( 'a@example.com', 'double', true ) );

		$this->assertSame( 'reserved', $result->code );

		$booking = $this->repository->findAccommodationBooking( (int) $result->registrationId );
		$row     = $this->repository->findById( (int) $result->registrationId );

		$this->assertSame( 2, (int) $booking['seats'] );
		$this->assertSame( '360.00', $booking['price'] );
		$this->assertSame( '460.00', $row['price_total'] ); // 100 typ + 2 × 180
	}

	public function test_double_room_exhausts_its_slot_after_one_booking(): void {
		// Slot ma 2 miejsca, pokój dwuosobowy zdejmuje oba.
		$this->service->reserve( $this->event_id, $this->request( 'a@example.com', 'double', true ) );

		$result = $this->service->reserve( $this->event_id, $this->request( 'b@example.com', 'double', true ) );

		$this->assertSame( 'accommodation_full', $result->code );
		$this->assertNull( $result->registrationId );
	}

	public function test_single_room_books_one_seat_even_with_companion(): void {
		$result = $this->service->reserve( $this->event_id, $this->request( 'a@example.com', 'single', true ) );

		$booking = $this->repository->findAccommodationBooking( (int) $result->registrationId );

		$this->assertSame( 1, (int) $booking['seats'] );
		$this->assertSame( '100.00', $booking['price'] );
	}
}
```

Kolejność argumentów `ReservationRequest` sprawdzona w kodzie: `( string $email, string $name, string $typeKey, array $data, ?AccommodationSelection $selection = null, string $lang = '', bool $companion = false, string $companionName = '' )` — helper wyżej jej odpowiada.

- [ ] **Step 2: Run test to verify it fails**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RoomOccupancyReservationTest
```

Oczekiwany RED, konkretnie: **`test_single_room_books_one_seat_even_with_companion` pada** — dziś `seats` idzie z flagi, więc pokój jednoosobowy z towarzyszącą zapisuje `seats=2` i cenę `200.00` zamiast `1` i `100.00`. Dwa pozostałe testy przechodzą już przed zmianą (oba przypadki mają `companion=true`, więc flaga daje tę samą dwójkę co liczba osób) — są strażnikami regresji, nie fazą RED, i w raporcie trzeba to napisać wprost.

- [ ] **Step 3: Przekaż mapę do wszystkich trzech `CapacityLimits`**

W każdym z trzech miejsc dołóż szósty argument. Wzór (`reserve`):

```php
			$limits = new CapacityLimits(
				isset( $settings['global_cap'] ) && null !== $settings['global_cap'] ? (int) $settings['global_cap'] : null,
				$types->capacities(),
				$accommodation->capacities(),
				(bool) ( $settings['waitlist_enabled'] ?? true ),
				$accommodation->companionCountsEvent(),
				$accommodation->slotOccupancies()
			);
```

Powtórz identycznie w `promoteFromWaitlist` i `editAnswers`.

- [ ] **Step 4: Niech wszystkie trzy wstawienia bookingu liczyły miejsca z pokoju**

W każdym z trzech miejsc zamień `$seats = $companion ? 2 : 1;` (albo `$request->companion ? 2 : 1`) na odczyt z configu. Wzór (`reserve`):

```php
				$item      = $accommodation->item( $request->selection->packageKey, $request->selection->roomKey );
				$seats     = $accommodation->seatsFor( $request->selection->roomKey, $request->companion );
				$acc_price = ( null === $item ? 0.0 : $item->price ) * $seats;
```

W `promoteFromWaitlist` i `editAnswers` zmienna z flagą nazywa się `$companion` / `$request->companion` — użyj tej, która jest w danym zakresie.

- [ ] **Step 5: Run tests, lint, commit**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
```

Oczekiwane: PASS, w tym `ReserveCompanionTest`, `PromoteFromWaitlistTest`, `EditAnswersTest`, `ReservationServiceTest` (ich pokoje nie mają `occupancy`).

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs && node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=2G
```

```bash
git add src/Services/ReservationService.php tests/Integration/Services/RoomOccupancyReservationTest.php && git commit -m "feat(reservation): seats and price follow room occupancy on every path"
```

---

### Task 6: Promocja z listy rezerwowej odmawia przy sprzecznym pokoju

**Files:**
- Modify: `src/Services/AdminActionResult.php`
- Modify: `src/Services/ReservationService.php` (wyłącznie `promoteFromWaitlist`)
- Modify: `src/Admin/RegistrationsScreen.php` (wyłącznie mapa `$messages`)
- Test: `tests/Integration/Services/PromoteFromWaitlistTest.php` (dopisanie przypadku)

**Interfaces:**
- Consumes: `RoomType::companionMismatch()` (Task 1).
- Produces: `AdminActionResult::occupancyMismatch()` → `->code === 'occupancy_mismatch'`.

**UWAGA phpcs:** nowy klucz `occupancy_mismatch` wchodzi do tablicy `$messages` w `RegistrationsScreen::render_notice`, która została przewyrównana w poprzedniej zmianie. Jeśli nowy klucz zmieni szerokość kolumny, sniff zażąda ponownego przewyrównania całego bloku — przewyrównaj i puść `phpcbf`, nie dodawaj wyłączenia.

- [ ] **Step 1: Write the failing test**

Dopisz do `tests/Integration/Services/PromoteFromWaitlistTest.php` (import `use EvReg\Domain\Accommodation\AccommodationSelection;` jest już w pliku od poprzedniej zmiany — sprawdź i dodaj tylko jeśli brak):

```php
	public function test_promote_refused_when_room_now_requires_companion(): void {
		// Organizator oznaczył pokój jako dwuosobowy PO tym, jak ktoś zapisał się solo
		// na listę rezerwową. Bez odmowy ten człowiek dostałby seats=2 i cenę ×2 przy
		// companion=0 — czyli dwa łóżka i podwójny rachunek dla jednej osoby.
		( new EventConfigRepository() )->save(
			$this->event_id,
			array(
				'types'         => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 0.0 ) ),
				'accommodation' => array(
					'packages'          => array( array( 'key' => 'n18', 'label' => 'Noc 18/19' ) ),
					'rooms'             => array( array( 'key' => 'double', 'label' => '2-os.', 'occupancy' => 2 ) ),
					'inventory'         => array( array( 'package' => 'n18', 'room' => 'double', 'capacity' => 10, 'price' => 0.0 ) ),
					'companion_enabled' => true,
				),
				'settings'      => array( 'waitlist_enabled' => true ),
			)
		);

		$waitlisted = $this->seed( 'waitlist', str_repeat( 'f', 32 ) );
		$this->repository->insertAccommodationBooking(
			$waitlisted,
			new AccommodationSelection( 'n18', 'double' ),
			0.0,
			1,
			'Noc 18/19',
			'2-os.'
		);

		$result = $this->service->promoteFromWaitlist( $waitlisted );

		$this->assertSame( 'occupancy_mismatch', $result->code );
		$this->assertSame( 'waitlist', $this->repository->findById( $waitlisted )['status'] );
		$this->assertNotNull( $this->repository->findAccommodationBooking( $waitlisted ) );
	}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter PromoteFromWaitlistTest
```

Oczekiwane: FAIL — dostaje `promoted` i status `pending`.

- [ ] **Step 3: Dodaj kod wyniku**

W `src/Services/AdminActionResult.php` rozszerz listę kodów w docbloku konstruktora o `occupancy_mismatch` i dodaj fabrykę po `promotedWithoutAccommodation()`:

```php
	/**
	 * Promocja odrzucona: zapisana para pokój/osoba towarzysząca jest niespójna
	 * z liczbą osób, jaką pokój ma dziś. Promowanie „jak się da" rozjechałoby
	 * cenę z pojemnością — admin ma to rozstrzygnąć edycją odpowiedzi.
	 */
	public static function occupancyMismatch(): self {
		return new self( 'occupancy_mismatch' );
	}
```

- [ ] **Step 4: Dołóż guard w `promoteFromWaitlist`**

Guard idzie **przed** `START TRANSACTION`, obok istniejącego guardu na zniknięty typ zgłoszenia (ten z komentarzem „Typ usunięty z configu po zawaitlistowaniu"), i po zbudowaniu `$selection` oraz `$companion`:

```php
		// Pokój mógł zmienić liczbę osób po zawaitlistowaniu. Promowanie zgłoszenia
		// niespójnego z nową konfiguracją dałoby seats/cenę dla innej liczby osób,
		// niż zapisano w zgłoszeniu — admin musi to rozstrzygnąć edycją odpowiedzi.
		if ( null !== $selection ) {
			$room = $accommodation->room( $selection->roomKey );
			if ( null !== $room && null !== $room->companionMismatch( $companion ) ) {
				return AdminActionResult::occupancyMismatch();
			}
		}
```

- [ ] **Step 5: Komunikat w panelu**

W `src/Admin/RegistrationsScreen::render_notice`, w tablicy `$messages`, dodaj po wpisie `promoted_no_accommodation`:

```php
			'occupancy_mismatch'        => array( 'error', __( 'Nie można awansować: wybrany pokój ma dziś inną liczbę osób niż zapisano w zgłoszeniu. Popraw nocleg albo osobę towarzyszącą w edycji odpowiedzi.', 'event-registration' ) ),
```

Następnie przewyrównaj tablicę i uruchom `phpcbf`, jeśli phpcs tego zażąda.

- [ ] **Step 6: Run tests, lint, commit**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
```

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs && node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=2G
```

```bash
git add src/Services/AdminActionResult.php src/Services/ReservationService.php src/Admin/RegistrationsScreen.php tests/Integration/Services/PromoteFromWaitlistTest.php && git commit -m "feat(admin): refuse a promotion whose room changed occupancy"
```

---

### Task 7: Formularz publiczny — checkbox nad listą, filtr pokojów

**Files:**
- Modify: `src/Frontend/FormRenderer.php`
- Modify: `assets/public/form.css`
- Test: `tests/Integration/Frontend/RoomOccupancyRenderTest.php`

**Interfaces:**
- Consumes: `RoomOccupancy::seats()` (Task 1), `$room['occupancy']` z surowego configu pola.
- Produces: atrybut `data-evreg-occupancy="<n>"` na `<input>` slotu; `hidden` + `disabled` na wierszu niepasującym; klasa CSS `.evreg-choice[hidden]`. Task 9 (`form.js`) czyta ten atrybut.

**Co dokładnie się zmienia w `renderAccommodation` (stan z `593ceca`):**

- `seatsNeeded( ?SubmitResult ): int` **znika**, zastąpione przez `companionSubmitted( ?SubmitResult ): bool`. `renderControl()` i `renderAccommodation()` przyjmują `bool $companion = false` zamiast `int $seats = 1`.
- Zacieśnienie „event bez towarzyszącej ignoruje odtworzony POST" **zostaje**, tylko zeruje teraz flagę, nie liczbę miejsc.
- Liczba potrzebnych miejsc staje się **per wiersz**.

- [ ] **Step 1: Write the failing test**

`tests/Integration/Frontend/RoomOccupancyRenderTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Frontend;

use EvReg\Domain\Schema\FormSchema;
use EvReg\Frontend\FormRenderer;
use EvReg\Frontend\SubmitResult;
use WP_UnitTestCase;

final class RoomOccupancyRenderTest extends WP_UnitTestCase {

	/**
	 * @param bool $roommate Czy pokój dwuosobowy ma włączone pole współlokatora.
	 * @return array<string,mixed>
	 */
	private function accommodationConfig( bool $roommate = false ): array {
		return array(
			'packages'          => array( array( 'key' => 'n18', 'label' => 'Noc 18/19' ) ),
			'rooms'             => array(
				array( 'key' => 'single', 'label' => 'Jednoosobowy', 'occupancy' => 1 ),
				array( 'key' => 'double', 'label' => 'Dwuosobowy', 'occupancy' => 2, 'roommate_field' => $roommate ),
				array( 'key' => 'any', 'label' => 'Dowolny' ),
			),
			'inventory'         => array(
				array( 'package' => 'n18', 'room' => 'single', 'capacity' => 10, 'price' => 100.0 ),
				array( 'package' => 'n18', 'room' => 'double', 'capacity' => 10, 'price' => 180.0 ),
				array( 'package' => 'n18', 'room' => 'any', 'capacity' => 10, 'price' => 150.0 ),
			),
			'allow_none'        => true,
			'companion_enabled' => true,
		);
	}

	/**
	 * @param array<string,mixed>|null $config Nadpisany config noclegów.
	 */
	private function schema( ?array $config = null ): FormSchema {
		return FormSchema::fromArray(
			array(
				'version'  => 1,
				'sections' => array(
					array(
						'key'    => 'dane',
						'title'  => 'Dane',
						'fields' => array(
							array( 'key' => 'email', 'type' => 'email', 'label' => 'E-mail' ),
							array(
								'key'    => 'nocleg',
								'type'   => 'accommodation',
								'label'  => 'Nocleg',
								'config' => $config ?? $this->accommodationConfig(),
							),
						),
					),
				),
			)
		);
	}

	/**
	 * @param array<string,mixed> $values Odtwarzane odpowiedzi.
	 */
	private function result( array $values ): SubmitResult {
		return SubmitResult::invalid( array( 'email' => 'required' ), $values );
	}

	public function test_companion_checkbox_renders_before_the_accommodation_field(): void {
		$html = ( new FormRenderer() )->render( $this->schema(), 1 );

		$companion = strpos( $html, 'name="evreg_companion"' );
		$slots     = strpos( $html, 'class="evreg-accommodation"' );

		$this->assertNotFalse( $companion );
		$this->assertNotFalse( $slots );
		$this->assertLessThan( $slots, $companion, 'Checkbox towarzyszącej musi wyjść przed listą noclegów.' );
	}

	public function test_double_room_is_hidden_and_disabled_without_companion(): void {
		$html = ( new FormRenderer() )->render( $this->schema(), 1 );

		$this->assertMatchesRegularExpression( '/value="n18\|double"[^>]*hidden/', $html );
		$this->assertMatchesRegularExpression( '/value="n18\|double"[^>]*disabled/', $html );
		$this->assertDoesNotMatchRegularExpression( '/value="n18\|single"[^>]*hidden/', $html );
	}

	public function test_single_room_is_hidden_and_disabled_with_companion(): void {
		$html = ( new FormRenderer() )->render( $this->schema(), 1, $this->result( array( 'evreg_companion' => true ) ) );

		$this->assertMatchesRegularExpression( '/value="n18\|single"[^>]*hidden/', $html );
		$this->assertMatchesRegularExpression( '/value="n18\|single"[^>]*disabled/', $html );
		$this->assertDoesNotMatchRegularExpression( '/value="n18\|double"[^>]*hidden/', $html );
	}

	public function test_room_without_occupancy_shows_in_both_states(): void {
		$solo = ( new FormRenderer() )->render( $this->schema(), 1 );
		$duo  = ( new FormRenderer() )->render( $this->schema(), 1, $this->result( array( 'evreg_companion' => true ) ) );

		$this->assertDoesNotMatchRegularExpression( '/value="n18\|any"[^>]*hidden/', $solo );
		$this->assertDoesNotMatchRegularExpression( '/value="n18\|any"[^>]*hidden/', $duo );
	}

	public function test_occupancy_attribute_is_emitted(): void {
		$html = ( new FormRenderer() )->render( $this->schema(), 1 );

		$this->assertStringContainsString( 'data-evreg-occupancy="1"', $html );
		$this->assertStringContainsString( 'data-evreg-occupancy="2"', $html );
	}

	public function test_mismatched_current_selection_moves_to_none(): void {
		$html = ( new FormRenderer() )->render(
			$this->schema(),
			1,
			$this->result( array( 'nocleg' => array( 'package' => 'n18', 'room' => 'double', 'roommate' => '' ) ) )
		);

		$this->assertDoesNotMatchRegularExpression( '/value="n18\|double"[^>]*checked/', $html );
		$this->assertMatchesRegularExpression( '/name="evreg_field\[nocleg\]\[slot\]" value=""[^>]*checked/', $html );
		$this->assertStringContainsString( 'Twój wcześniejszy wybór noclegu jest już niedostępny.', $html );
	}

	public function test_roommate_field_is_suppressed_for_double_rooms(): void {
		$html = ( new FormRenderer() )->render( $this->schema( $this->accommodationConfig( true ) ), 1 );

		$this->assertStringNotContainsString( 'data-evreg-roommate-input', $html );
	}

	public function test_message_when_nothing_matches_the_filter(): void {
		$config          = $this->accommodationConfig();
		$config['rooms'] = array( array( 'key' => 'double', 'label' => 'Dwuosobowy', 'occupancy' => 2 ) );
		$config['inventory'] = array( array( 'package' => 'n18', 'room' => 'double', 'capacity' => 10, 'price' => 180.0 ) );

		$html = ( new FormRenderer() )->render( $this->schema( $config ), 1 );

		$this->assertStringContainsString( 'nie ma pasujących pokojów', $html );
	}

	public function test_css_hides_hidden_choices(): void {
		// `hidden` samo nie chowa wiersza: .evreg-choice { display: block; } bije
		// regułę przeglądarki dla [hidden]. Bez tej reguły atrybut jest ozdobą.
		$css = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/public/form.css' );

		$this->assertStringContainsString( '.evreg-choice[hidden]', $css );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RoomOccupancyRenderTest
```

Oczekiwane: FAIL na wszystkich oprócz `test_room_without_occupancy_shows_in_both_states` i `test_mismatched_current_selection_moves_to_none` w części o notce.

- [ ] **Step 3: Zamień `seatsNeeded` na `companionSubmitted`**

W `src/Frontend/FormRenderer.php` zamień metodę:

```php
	/**
	 * Czy odtwarzana submisja miała zaznaczoną osobę towarzyszącą.
	 *
	 * @param SubmitResult|null $result Wynik poprzedniej próby submisji.
	 */
	private function companionSubmitted( ?SubmitResult $result ): bool {
		return null !== $result && ! empty( $result->submittedValue( 'evreg_companion' ) );
	}
```

W `renderField` zamień `$seats = $this->seatsNeeded( $result );` na `$companion = $this->companionSubmitted( $result );` i oba wywołania `$this->renderControl( $field, $value, $invalid, $seats )` na `$this->renderControl( $field, $value, $invalid, $companion )`.

W `renderControl` zamień czwarty parametr `int $seats = 1` na `bool $companion = false` (z `@param`) i gałąź `case FieldType::Accommodation: return $this->renderAccommodation( $field, $value, $companion );`.

- [ ] **Step 4: Przenieś blok towarzyszącej nad pole**

W `renderField` **usuń** z końca metody:

```php
		if ( FieldType::Accommodation === $field->type ) {
			$out .= $this->renderCompanion( $field, $result );
		}
```

i wstaw **przed** pierwszym przypisaniem do `$out` (czyli przed `if ( FieldType::Checkbox === $field->type ) {`):

```php
		// Checkbox towarzyszącej idzie NAD listę noclegów: steruje tym, które pokoje
		// są w ogóle pokazane, więc musi być widoczny przed wyborem.
		$before = FieldType::Accommodation === $field->type ? $this->renderCompanion( $field, $result ) : '';
```

a każde `$out  = '<div class="' . $class . ...` zamień na `$out  = $before . '<div class="' . $class . ...` (dwa miejsca: gałąź `Checkbox` i `else`).

- [ ] **Step 5: Przebuduj `renderAccommodation`**

Sygnatura i zacieśnienie flagi:

```php
	 * @param bool  $companion Czy odtwarzana submisja ma osobę towarzyszącą.
	 */
	private function renderAccommodation( Field $field, mixed $value, bool $companion = false ): string {
```

Zacieśnienie (zamiast dzisiejszego `$seats = 1`):

```php
		// Klientowi się nie ufa: gdy event nie pozwala na osobę towarzyszącą,
		// odtworzony POST z evreg_companion nie może filtrować listy ani zamykać
		// slotów (ten sam wzór co SubmissionAssembler::extractCompanion).
		if ( empty( $config['companion_enabled'] ) ) {
			$companion = false;
		}
```

Mapa pokojów z współlokatorem — pomiń pokoje dwuosobowe:

```php
		// Pole „Preferowana osoba w pokoju" nie ma sensu w pokoju dwuosobowym:
		// drugie łóżko zajmuje imiennie wskazana osoba towarzysząca.
		$roommateRooms = array();
		foreach ( $rooms as $room ) {
			if ( ! empty( $room['roommate_field'] ) && 2 !== (int) ( $room['occupancy'] ?? 0 ) ) {
				$roommateRooms[ (string) ( $room['key'] ?? '' ) ] = true;
			}
		}
```

Pętla budująca `$rows` — dołóż `occupancy` i `mismatch`, a `closed` licz per wiersz:

```php
		$rows = array();
		foreach ( $packages as $pkg ) {
			foreach ( $rooms as $room ) {
				$slot = (string) ( $pkg['key'] ?? '' ) . '|' . (string) ( $room['key'] ?? '' );
				if ( ! isset( $available[ $slot ] ) ) {
					continue;
				}
				$occupancy = (int) ( $room['occupancy'] ?? 0 );
				$rowSeats  = RoomOccupancy::seats( $occupancy, $companion );
				$rows[]    = array(
					'slot'      => $slot,
					'label'     => (string) ( $pkg['label'] ?? '' ) . ' — ' . (string) ( $room['label'] ?? '' ),
					'roommate'  => isset( $roommateRooms[ (string) ( $room['key'] ?? '' ) ] ),
					'occupancy' => $occupancy,
					'left'      => null === $this->availability ? null : $this->availability->left( $slot ),
					'closed'    => null !== $this->availability && $this->availability->isFull( $slot, $rowSeats ),
					'mismatch'  => 0 !== $occupancy && $occupancy !== ( $companion ? 2 : 1 ),
				);
			}
		}
```

Dołóż import `use EvReg\Domain\Accommodation\RoomOccupancy;`.

Wykrywanie porzuconego wyboru — wiersz `mismatch` liczy się jak niedostępny:

```php
		$dropped   = false;
		$any_open  = false;
		$any_match = false;
		foreach ( $rows as $row ) {
			if ( ! $row['mismatch'] ) {
				$any_match = true;
			}
			if ( ! $row['closed'] && ! $row['mismatch'] ) {
				$any_open = true;
			}
		}
		if ( '' !== $current ) {
			$still_open = false;
			foreach ( $rows as $row ) {
				if ( $row['slot'] === $current && ! $row['closed'] && ! $row['mismatch'] ) {
					$still_open = true;
				}
			}
			if ( ! $still_open ) {
				$dropped = true;
				$current = '';
			}
		}
```

Pętla renderująca — dołóż atrybut i oba znaczniki ukrycia:

```php
		foreach ( $rows as $row ) {
			$slot_id = $base . '-slot-' . $index++;
			$note    = '';
			if ( $row['closed'] ) {
				// Osobny <span>, by form.js mógł ukryć notkę, gdy slot znów się mieści.
				$note = ' <span class="evreg-seats-note">' . ( null !== $row['left'] && $row['left'] < 1
					? esc_html__( '(brak miejsc)', 'event-registration' )
					: esc_html__( '(za mało miejsc)', 'event-registration' ) ) . '</span>';
			}
			$seats_attr = null === $row['left'] ? '' : ' data-evreg-seats-left="' . esc_attr( (string) $row['left'] ) . '"';
			$occ_attr   = ' data-evreg-occupancy="' . esc_attr( (string) $row['occupancy'] ) . '"';
			// Niedostępny ALBO niepasujący slot NIGDY nie renderuje się jako wybrany.
			// `hidden` chowa, ale NADAL wysyła wartość, więc wiersz niepasujący dostaje
			// też `disabled` — inaczej schowany-a-zaznaczony radio trafiłby do $_POST.
			$off      = $row['closed'] || $row['mismatch'];
			$checked  = ! $off && $row['slot'] === $current;
			$roommate = $row['roommate'] ? ' data-evreg-roommate="1"' : '';
			$out     .= '<div class="evreg-choice form-check' . ( $row['closed'] ? ' is-full' : '' ) . '"' . ( $row['mismatch'] ? ' hidden' : '' ) . '>'
				. '<input class="form-check-input" type="radio" id="' . $slot_id . '" name="' . $name . '[slot]" value="' . esc_attr( $row['slot'] ) . '"' . checked( $checked, true, false ) . ( $off ? ' disabled' : '' ) . ( $row['mismatch'] ? ' hidden' : '' ) . $seats_attr . $occ_attr . $roommate . '>'
				. ' <label class="form-check-label" for="' . $slot_id . '">' . esc_html( $row['label'] ) . $note . '</label>'
				. '</div>';
		}
```

Komunikat o braku pasujących pokojów — dołóż przed istniejącym komunikatem o zajętości:

```php
		if ( ! $any_match && array() !== $rows ) {
			$out .= '<p class="evreg-accommodation-mismatch">'
				. esc_html__( 'Dla tego wyboru nie ma pasujących pokojów — zmień zaznaczenie osoby towarzyszącej.', 'event-registration' )
				. '</p>';
		}
```

- [ ] **Step 6: Reguła CSS**

Na końcu `assets/public/form.css` dopisz:

```css
.evreg-choice[hidden] { display: none; }
```

- [ ] **Step 7: Run tests, lint, commit**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
```

Oczekiwane: PASS, w tym `FormRendererTest`, `AccommodationAvailabilityRenderTest`, `OptionDescriptionRenderTest`, `ShortcodeTest`. Jeśli `AccommodationAvailabilityRenderTest` oczekuje checkboxa towarzyszącej po liście — popraw asercję i wymień to w raporcie.

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs && node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=2G
```

```bash
git add src/Frontend/FormRenderer.php assets/public/form.css tests/Integration/Frontend/RoomOccupancyRenderTest.php && git commit -m "feat(form): companion checkbox filters the room list by occupancy"
```

---

### Task 8: Walidacja niespójnej pary

**Files:**
- Modify: `src/Frontend/SubmitHandler.php`
- Modify: `src/Admin/RegistrationsScreen.php` (wyłącznie `handle_edit`)
- Modify: `src/Frontend/FormRenderer.php` (wyłącznie mapa `errorMessage`)
- Modify: `src/Admin/RegistrationEditForm.php` (wyłącznie mapa `errorMessage`)
- Test: `tests/Integration/Frontend/CompanionRoomRuleTest.php`
- Test: `tests/Integration/Admin/EditCompanionRoomRuleTest.php`

**Interfaces:**
- Consumes: `RoomType::companionMismatch()` (Task 1), `FormSchema::accommodationFieldKey()` i `AssembledSubmission::rawAnswers()` (z poprzedniej zmiany).
- Produces: kody błędów `companion_required` / `companion_not_allowed` pod kluczem pola noclegu.

**Dlaczego nie w `SubmissionAssembler`:** asembler jest wspólny dla formularza publicznego i edycji w adminie oraz bezstanowy względem zapisanego wiersza. Wołany bezwarunkowo, zablokowałby każdy zapis starych zgłoszeń w pokoju, który dopiero teraz dostał liczbę osób. Reguła stosuje się więc u wywołujących: publicznie zawsze, w adminie tylko przy zmianie noclegu albo flagi.

- [ ] **Step 1: Write the failing tests**

`tests/Integration/Frontend/CompanionRoomRuleTest.php` — wzoruj się na `AccommodationFullSubmitTest` z poprzedniej zmiany (ten sam fixture, `SubmitHandler` budowany tak samo). Konfiguracja musi mieć **trzy** pokoje, bo testy sięgają po wszystkie trzy: `single` z `occupancy => 1`, `double` z `occupancy => 2` i `any` **bez** klucza `occupancy` — każdy z zapasem miejsc (`capacity => 10`), pakiet `n18`, `companion_enabled => true`, `allow_none => true`. Testy:

```php
	public function test_double_room_without_companion_is_refused(): void {
		$result = $this->handler->process( $this->event_id, $this->post( 'a@example.com', 'n18|double', false ) );

		$this->assertSame( 'invalid', $result->code() );
		$this->assertSame( 'companion_required', $result->errors()['nocleg'] ?? null );
		$this->assertSame(
			array( 'package' => 'n18', 'room' => 'double', 'roommate' => '' ),
			$result->submittedValue( 'nocleg' )
		);
		$this->assertSame( 0, $this->countRegistrations() );
	}

	public function test_single_room_with_companion_is_refused(): void {
		$result = $this->handler->process( $this->event_id, $this->post( 'a@example.com', 'n18|single', true, 'Anna Kowalska' ) );

		$this->assertSame( 'invalid', $result->code() );
		$this->assertSame( 'companion_not_allowed', $result->errors()['nocleg'] ?? null );
		$this->assertSame( 0, $this->countRegistrations() );
	}

	public function test_matching_pairs_pass(): void {
		$this->assertTrue( $this->handler->process( $this->event_id, $this->post( 'a@example.com', 'n18|single', false ) )->isSuccess() );
		$this->assertTrue( $this->handler->process( $this->event_id, $this->post( 'b@example.com', 'n18|double', true, 'Anna Kowalska' ) )->isSuccess() );
	}

	public function test_room_without_occupancy_passes_in_both_states(): void {
		$this->assertTrue( $this->handler->process( $this->event_id, $this->post( 'a@example.com', 'n18|any', false ) )->isSuccess() );
		$this->assertTrue( $this->handler->process( $this->event_id, $this->post( 'b@example.com', 'n18|any', true, 'Anna Kowalska' ) )->isSuccess() );
	}
```

z tymi helperami:

```php
	/**
	 * @return array<string,mixed>
	 */
	private function post( string $email, string $slot, bool $companion, string $companion_name = '' ): array {
		$post = array(
			'evreg_field' => array(
				'__type' => 'uczestnik',
				'email'  => $email,
				'nocleg' => array( 'slot' => $slot, 'roommate' => '' ),
			),
		);

		if ( $companion ) {
			$post['evreg_companion']      = '1';
			$post['evreg_companion_name'] = $companion_name;
		}

		return $post;
	}

	private function countRegistrations(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::table( 'registrations' ) . ' WHERE event_id = %d', $this->event_id )
		);
	}
```

`tests/Integration/Admin/EditCompanionRoomRuleTest.php` — ten test pilnuje decyzji partnera, więc musi być jednoznaczny. Wzorzec przechwytywania redirectu skopiowany z istniejącego `tests/Integration/Admin/RegistrationEditScreenTest.php`; klasa wyjątku MUSI mieć inną nazwę, bo oba pliki siedzą w tym samym namespace.

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Admin;

use EvReg\Admin\Capabilities;
use EvReg\Admin\RegistrationsScreen;
use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use WP_UnitTestCase;

/**
 * Wyjątek przenoszący adres redirectu, by przerwać przed exit handlera.
 */
final class OccEditRedirectException extends \Exception {

	public string $location;

	public function __construct( string $location ) {
		parent::__construct( 'redirect' );
		$this->location = $location;
	}
}

final class EditCompanionRoomRuleTest extends WP_UnitTestCase {

	private RegistrationRepository $repository;

	private int $admin_id;

	private int $event_id;

	private int $registration_id;

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
		$this->event_id = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );

		// Konfiguracja BEZ liczby osób — tak wyglądał event, gdy zgłoszenie powstało.
		$this->saveConfig( 0 );

		$this->registration_id = $this->repository->insertRegistration(
			array(
				'event_id'    => $this->event_id,
				'type_key'    => 'uczestnik',
				'status'      => 'pending',
				'email'       => 'jan@example.com',
				'name'        => 'Jan',
				'token'       => str_repeat( 'a', 32 ),
				'data'        => (string) wp_json_encode(
					array(
						'__type' => 'uczestnik',
						'email'  => 'jan@example.com',
						'nocleg' => array( 'package' => 'n18', 'room' => 'double', 'roommate' => '' ),
					)
				),
				'price_total' => 100.0,
				'expires_at'  => '2099-01-01 00:00:00',
				'companion'   => 0,
			)
		);

		$this->repository->insertAccommodationBooking(
			$this->registration_id,
			new AccommodationSelection( 'n18', 'double' ),
			180.0,
			1,
			'Noc 18/19',
			'Dwuosobowy'
		);

		// Organizator oznacza pokój jako dwuosobowy PO fakcie.
		$this->saveConfig( 2 );

		wp_set_current_user( $this->admin_id );
	}

	protected function tearDown(): void {
		remove_all_filters( 'wp_redirect' );
		unset( $_POST['registration'], $_REQUEST['_wpnonce'], $_POST['evreg_field'], $_POST['evreg_companion'], $_POST['evreg_companion_name'] );
		parent::tearDown();
	}

	/**
	 * @param int $occupancy Liczba osób pokoju "double".
	 */
	private function saveConfig( int $occupancy ): void {
		$room = array( 'key' => 'double', 'label' => 'Dwuosobowy' );
		if ( 0 !== $occupancy ) {
			$room['occupancy'] = $occupancy;
		}

		( new EventConfigRepository() )->save(
			$this->event_id,
			array(
				'schema'        => array(
					'version'  => 1,
					'sections' => array(
						array(
							'key'    => 'dane',
							'title'  => 'Dane',
							'fields' => array(
								array( 'key' => '__type', 'type' => 'radio', 'label' => 'Typ' ),
								array( 'key' => 'email', 'type' => 'email', 'label' => 'E-mail', 'required' => true ),
								array( 'key' => 'nocleg', 'type' => 'accommodation', 'label' => 'Nocleg' ),
							),
						),
					),
				),
				'types'         => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 100.0 ) ),
				'accommodation' => array(
					'packages'          => array( array( 'key' => 'n18', 'label' => 'Noc 18/19' ) ),
					'rooms'             => array( $room ),
					'inventory'         => array( array( 'package' => 'n18', 'room' => 'double', 'capacity' => 10, 'price' => 180.0 ) ),
					'allow_none'        => true,
					'companion_enabled' => true,
				),
				'settings'      => array( 'global_cap' => null, 'waitlist_enabled' => true ),
			)
		);
	}

	private function catchRedirect(): ?OccEditRedirectException {
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new OccEditRedirectException( (string) $location );
			}
		);

		try {
			RegistrationsScreen::handle_edit();
		} catch ( OccEditRedirectException $e ) {
			return $e;
		}

		return null;
	}

	public function test_edit_without_touching_accommodation_passes_for_legacy_row(): void {
		// DECYZJA PARTNERA: pokój dostał liczbę osób PO zapisaniu zgłoszenia, więc
		// poprawka innego pola MUSI przejść. Inaczej admin nie zmieniłby nawet adresu
		// e-mail bez rozstrzygania osoby towarzyszącej.
		$_POST['registration'] = $this->registration_id;
		$_REQUEST['_wpnonce']  = wp_create_nonce( 'evreg_edit_' . $this->registration_id );
		$_POST['evreg_field']  = array(
			'__type' => 'uczestnik',
			'email'  => 'nowy@example.com',
			'nocleg' => array( 'slot' => 'n18|double', 'roommate' => '' ),
		);

		$redirect = $this->catchRedirect();

		$this->assertNotNull( $redirect, 'Zapis bez zmiany noclegu i flagi powinien przejść (PRG).' );
		$this->assertStringContainsString( 'evreg_msg=edited', $redirect->location );
		$this->assertSame( 'nowy@example.com', $this->repository->findById( $this->registration_id )['email'] );
	}

	public function test_edit_changing_the_flag_into_a_consistent_pair_passes(): void {
		// Zmiana jest (flaga 0 → 1), więc reguła się odpala — ale para pokój dwuosobowy
		// + osoba towarzysząca jest spójna, więc zapis przechodzi. To dowód, że reguła
		// nie blokuje na samą obecność zmiany.
		$_POST['registration']        = $this->registration_id;
		$_REQUEST['_wpnonce']         = wp_create_nonce( 'evreg_edit_' . $this->registration_id );
		$_POST['evreg_field']         = array(
			'__type' => 'uczestnik',
			'email'  => 'jan@example.com',
			'nocleg' => array( 'slot' => 'n18|double', 'roommate' => '' ),
		);
		$_POST['evreg_companion']     = '1';
		$_POST['evreg_companion_name'] = 'Anna Kowalska';

		$redirect = $this->catchRedirect();

		$this->assertNotNull( $redirect, 'Zmiana flagi na spójną z pokojem powinna przejść.' );
		$this->assertStringContainsString( 'evreg_msg=edited', $redirect->location );
		$this->assertSame( 1, (int) $this->repository->findById( $this->registration_id )['companion'] );
	}

	public function test_edit_changing_the_slot_into_a_mismatch_is_refused(): void {
		// Niespójna zmiana: pokój jednoosobowy z zaznaczoną osobą towarzyszącą.
		$this->addSingleRoom();

		$_POST['registration']        = $this->registration_id;
		$_REQUEST['_wpnonce']         = wp_create_nonce( 'evreg_edit_' . $this->registration_id );
		$_POST['evreg_field']         = array(
			'__type' => 'uczestnik',
			'email'  => 'jan@example.com',
			'nocleg' => array( 'slot' => 'n18|single', 'roommate' => '' ),
		);
		$_POST['evreg_companion']     = '1';
		$_POST['evreg_companion_name'] = 'Anna Kowalska';

		$redirect = null;
		ob_start();
		try {
			$redirect = $this->catchRedirect();
		} finally {
			$html = (string) ob_get_clean();
		}

		$this->assertNull( $redirect, 'Niespójna zmiana nie powinna przekierowywać (inline re-render).' );
		$this->assertStringContainsString( 'Ten pokój jest jednoosobowy', $html );

		// Zgłoszenie w bazie nietknięte.
		$row = $this->repository->findById( $this->registration_id );
		$this->assertSame( 0, (int) $row['companion'] );
		$this->assertSame( 'double', (string) $this->repository->findAccommodationBooking( $this->registration_id )['room_type_key'] );
	}

	/**
	 * Dokłada do eventu pokój jednoosobowy obok istniejącego dwuosobowego.
	 */
	private function addSingleRoom(): void {
		( new EventConfigRepository() )->save(
			$this->event_id,
			array(
				'accommodation' => array(
					'packages'          => array( array( 'key' => 'n18', 'label' => 'Noc 18/19' ) ),
					'rooms'             => array(
						array( 'key' => 'double', 'label' => 'Dwuosobowy', 'occupancy' => 2 ),
						array( 'key' => 'single', 'label' => 'Jednoosobowy', 'occupancy' => 1 ),
					),
					'inventory'         => array(
						array( 'package' => 'n18', 'room' => 'double', 'capacity' => 10, 'price' => 180.0 ),
						array( 'package' => 'n18', 'room' => 'single', 'capacity' => 10, 'price' => 100.0 ),
					),
					'allow_none'        => true,
					'companion_enabled' => true,
				),
			)
		);
	}
}
```

Trzy przypadki pokrywają trzy różne gałęzie: brak zmiany (reguła pominięta), zmiana ze spójną parą (reguła odpalona, przepuszcza), zmiana z niespójną parą (reguła odpalona, blokuje). Jeśli `addSingleRoom()` okaże się potrzebne też w teście drugim, przenieś je do `setUp()` — wtedy event ma od początku oba pokoje, a fixture dalej startuje z `occupancy = 0`.

- [ ] **Step 2: Run tests to verify they fail**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter "CompanionRoomRuleTest|EditCompanionRoomRuleTest"
```

Oczekiwane: FAIL — niespójne pary przechodzą (zapisują zgłoszenie), bo reguły jeszcze nie ma.

- [ ] **Step 3: Reguła na formularzu publicznym**

W `src/Frontend/SubmitHandler::process`, **po** guardzie pustego e-maila i **przed** `$reservation = $this->reservations->reserve( ... );`:

```php
		// Niespójna para pokój/osoba towarzysząca: pokój dwuosobowy bez towarzyszącej
		// albo jednoosobowy z nią. Na formularzu publicznym reguła obowiązuje zawsze —
		// każda submisja to nowy wybór, nie ma „stanu przed".
		$mismatch = $this->companionRoomMismatch( $schema, $request );
		if ( null !== $mismatch ) {
			$accommodation_key = $schema->accommodationFieldKey();

			return null === $accommodation_key
				? SubmitResult::rejected()
				: SubmitResult::invalid( array( $accommodation_key => $mismatch ), $assembled->rawAnswers() );
		}
```

Nowa prywatna metoda w tej samej klasie:

```php
	/**
	 * Zwraca kod błędu, gdy wybrany pokój nie zgadza się z flagą osoby towarzyszącej.
	 *
	 * @param FormSchema         $schema  Złożony schemat formularza.
	 * @param ReservationRequest $request Zbudowane żądanie rezerwacji.
	 */
	private function companionRoomMismatch( FormSchema $schema, ReservationRequest $request ): ?string {
		if ( null === $request->selection ) {
			return null;
		}

		$key = $schema->accommodationFieldKey();
		if ( null === $key ) {
			return null;
		}

		$field = $schema->findField( $key );
		if ( null === $field ) {
			return null;
		}

		$room = AccommodationConfig::fromArray( $field->config )->room( $request->selection->roomKey );

		return null === $room ? null : $room->companionMismatch( $request->companion );
	}
```

Dołóż importy `use EvReg\Domain\Accommodation\AccommodationConfig;` i `use EvReg\Domain\Schema\FormSchema;` (sprawdź, czego brakuje).

- [ ] **Step 4: Reguła w adminie, tylko przy zmianie**

W `src/Admin/RegistrationsScreen::handle_edit`, po `$request = $assembled->request();` i guardzie `null === $request`, przed `$result = self::service()->editAnswers( $id, $request );`:

```php
		// Reguła spójności pary obowiązuje w adminie TYLKO gdy edycja faktycznie zmienia
		// nocleg albo flagę towarzyszącej. Bez tego oznaczenie pokoju jako dwuosobowego
		// zablokowałoby każdy zapis starych zgłoszeń w tym pokoju — admin nie poprawiłby
		// w nich numeru telefonu bez rozstrzygania osoby towarzyszącej.
		$booking       = $repository->findAccommodationBooking( $id );
		$previous_slot = null === $booking
			? ''
			: (string) $booking['package_key'] . '|' . (string) $booking['room_type_key'];
		$new_slot      = null === $request->selection ? '' : $request->selection->slotKey();
		$changed       = $previous_slot !== $new_slot || (bool) ( $row['companion'] ?? false ) !== $request->companion;

		if ( $changed ) {
			$mismatch = self::companionRoomMismatch( $schema, $request );

			if ( null !== $mismatch ) {
				$key    = $schema->accommodationFieldKey();
				$errors = null === $key ? array() : array( $key => $mismatch );

				echo '<div class="wrap">';
				echo '<h1>' . esc_html__( 'Edytuj zgłoszenie', 'event-registration' ) . '</h1>';
				echo RegistrationEditForm::render( $schema, array(), $id, $errors, $assembled->values() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- już escapowane w rendererze.
				echo '</div>';
				return;
			}
		}
```

Plus prywatny statyczny bliźniak helpera (ta sama treść co w `SubmitHandler`, ale `private static`). **To jedyna dopuszczalna duplikacja w tym planie** — dwie powierzchnie, dwa wyzwalacze, a sam sąd siedzi w jednym miejscu (`RoomType::companionMismatch`). Jeśli wolisz, wyciągnij helper do małej czystej klasy w `src/Domain/Accommodation/` przyjmującej `AccommodationConfig`, `AccommodationSelection|null` i `bool` — wtedy obaj wywołujący mają po jednej linii. Wybór zostawiam wykonawcy; warunek: `RoomType::companionMismatch` pozostaje jedynym sądem.

- [ ] **Step 5: Komunikaty**

W `FormRenderer::errorMessage` i `RegistrationEditForm::errorMessage` dodaj do map:

```php
			'companion_required'      => __( 'Ten pokój jest dwuosobowy. Zaznacz osobę towarzyszącą albo wybierz pokój jednoosobowy.', 'event-registration' ),
			'companion_not_allowed'   => __( 'Ten pokój jest jednoosobowy. Odznacz osobę towarzyszącą albo wybierz pokój dwuosobowy.', 'event-registration' ),
```

Wyrównaj obie tablice zgodnie z phpcs.

- [ ] **Step 6: Run tests, lint, commit**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
```

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs && node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=2G
```

```bash
git add src/Frontend/SubmitHandler.php src/Frontend/FormRenderer.php src/Admin/RegistrationsScreen.php src/Admin/RegistrationEditForm.php tests/Integration/Frontend/CompanionRoomRuleTest.php tests/Integration/Admin/EditCompanionRoomRuleTest.php && git commit -m "feat(form): refuse an inconsistent room/companion pair"
```

---

### Task 9: `form.js` lustrzy filtr

**Files:**
- Modify: `assets/public/form.js`
- Create: `tests/e2e/setup-room-occupancy.php`
- Create: `tests/e2e/room-occupancy.spec.js`

**Interfaces:**
- Consumes: `data-evreg-occupancy` i `data-evreg-seats-left` (Task 7).
- Produces: nic dla PHP.

**Nośny wymóg:** slot bywa zamknięty z **dwóch niezależnych powodów** (brak miejsc albo niepasująca liczba osób). `disabled` musi być **sumą** powodów, nie wynikiem ostatniej funkcji — inaczej jedna odblokuje slot, który druga zamknęła.

- [ ] **Step 1: Napisz fixture i spec**

`tests/e2e/setup-room-occupancy.php`:

```php
<?php
/**
 * Fixture setup for the room-occupancy Playwright E2E spec.
 *
 * Run via `wp eval-file` (WP-CLI) inside the wp-env dev container. Creates an
 * `evreg_event` with one single-occupancy and one double-occupancy room, both
 * with plenty of seats, plus a page embedding the form shortcode. Makes no
 * reservations: this spec exercises the companion filter, not availability.
 *
 * Prints a single line "<eventId> <pageId>" to stdout for the spec to parse.
 *
 * @package EvReg
 */

\EvReg\Persistence\Migrations::install();

$event_id = wp_insert_post(
	array(
		'post_type'   => 'evreg_event',
		'post_status' => 'publish',
		'post_title'  => 'E2E Room Occupancy',
	),
	true
);

if ( is_wp_error( $event_id ) ) {
	fwrite( STDERR, 'Failed to create evreg_event: ' . $event_id->get_error_message() . "\n" );
	exit( 1 );
}

( new \EvReg\Persistence\EventConfigRepository() )->save(
	(int) $event_id,
	array(
		'schema'        => array(
			'version'  => 1,
			'sections' => array(
				array(
					'key'    => 'dane',
					'title'  => 'Dane',
					'fields' => array(
						array( 'key' => '__type', 'type' => 'radio', 'label' => 'Typ' ),
						array( 'key' => 'email', 'type' => 'email', 'label' => 'E-mail', 'required' => true ),
						array( 'key' => 'nocleg', 'type' => 'accommodation', 'label' => 'Nocleg' ),
					),
				),
			),
		),
		'types'         => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 0 ) ),
		'accommodation' => array(
			'packages'          => array( array( 'key' => 'n18', 'label' => 'Noc 18/19' ) ),
			'rooms'             => array(
				array( 'key' => 'single', 'label' => 'Jednoosobowy', 'occupancy' => 1 ),
				array( 'key' => 'double', 'label' => 'Dwuosobowy', 'occupancy' => 2 ),
			),
			'inventory'         => array(
				array( 'package' => 'n18', 'room' => 'single', 'capacity' => 10, 'price' => 100.0 ),
				array( 'package' => 'n18', 'room' => 'double', 'capacity' => 10, 'price' => 180.0 ),
			),
			'allow_none'        => true,
			'companion_enabled' => true,
		),
		'settings'      => array( 'global_cap' => null, 'waitlist_enabled' => true ),
	)
);

$page_id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Zapisy z liczbą osób',
		'post_content' => '[evreg_form event="' . (int) $event_id . '"]',
	),
	true
);

if ( is_wp_error( $page_id ) ) {
	fwrite( STDERR, 'Failed to create page: ' . $page_id->get_error_message() . "\n" );
	exit( 1 );
}

echo (int) $event_id . ' ' . (int) $page_id . "\n";
```

`tests/e2e/room-occupancy.spec.js`:

```js
const { test, expect } = require( '@playwright/test' );
const { execSync } = require( 'child_process' );

test( 'companion checkbox filters the room list by occupancy', async ( { page } ) => {
	const out = execSync(
		'node scripts/wp-env.cjs run cli --env-cwd=wp-content/plugins/event-registration -- wp eval-file tests/e2e/setup-room-occupancy.php',
		{ encoding: 'utf8' }
	);
	const line = out.trim().split( '\n' ).pop().trim();
	const [ , pageId ] = line.split( /\s+/ );

	await page.goto( `http://localhost:8891/?page_id=${ pageId }` );

	const single = page.locator( 'input[name="evreg_field[nocleg][slot]"][value="n18|single"]' );
	const double = page.locator( 'input[name="evreg_field[nocleg][slot]"][value="n18|double"]' );
	const none = page.locator( 'input[name="evreg_field[nocleg][slot]"][value=""]' );
	const companion = page.locator( 'input[name="evreg_companion"]' );

	// Checkbox towarzyszącej musi być nad listą.
	await expect( companion ).toBeVisible();

	// Bez towarzyszącej: tylko jednoosobowy.
	await expect( single ).toBeVisible();
	await expect( double ).toBeHidden();
	await expect( double ).toBeDisabled();

	await single.check();
	await expect( single ).toBeChecked();

	// Zaznaczenie towarzyszącej: lista przeskakuje na dwuosobowy, a zaznaczenie
	// jednoosobowego przenosi się na „Bez noclegu" (pusta grupa radio wysyłałaby pusty slot).
	await companion.check();
	await expect( double ).toBeVisible();
	await expect( single ).toBeHidden();
	await expect( single ).not.toBeChecked();
	await expect( none ).toBeChecked();

	// Odznaczenie wraca do stanu wyjściowego.
	await companion.uncheck();
	await expect( single ).toBeVisible();
	await expect( double ).toBeHidden();
} );
```

- [ ] **Step 2: Run spec to verify it fails**

```bash
node scripts/wp-env.cjs start
```

```bash
npm run test:e2e -- room-occupancy
```

Oczekiwane: FAIL po `companion.check()` — bez JS lista nie przeskakuje.

- [ ] **Step 3: Przebuduj `applySlotSeats` na per-wiersz i dołóż filtr**

W `assets/public/form.js` zamień `applySlotSeats` na jedną funkcję liczącą oba powody razem (prostsze i z definicji odporne na „ostatnia wygrywa"):

```js
	// Lustro EvReg\Domain\Accommodation\RoomOccupancy::seats.
	function seatsFor( occupancy, companion ) {
		return occupancy === 0 ? ( companion ? 2 : 1 ) : occupancy;
	}

	// Slot bywa zamknięty z DWÓCH niezależnych powodów: brak miejsc (serwer liczy to
	// świeżo i oznacza wyczerpane) oraz niepasująca liczba osób w pokoju. `disabled`
	// jest SUMĄ powodów — gdyby każdy powód miał własną pętlę, druga odblokowałaby
	// slot zamknięty przez pierwszą.
	function applySlotState( form ) {
		var checkbox = form.querySelector( 'input[name="evreg_companion"]' );
		var companion = !! ( checkbox && checkbox.checked );

		form.querySelectorAll( '[data-evreg-occupancy], [data-evreg-seats-left]' ).forEach( function ( el ) {
			var occupancy = parseInt( el.getAttribute( 'data-evreg-occupancy' ), 10 );
			if ( isNaN( occupancy ) ) { occupancy = 0; }

			var mismatch = occupancy !== 0 && occupancy !== ( companion ? 2 : 1 );

			var left = parseInt( el.getAttribute( 'data-evreg-seats-left' ), 10 );
			var full = ! isNaN( left ) && left < seatsFor( occupancy, companion );

			el.hidden = mismatch;
			el.disabled = mismatch || full;

			if ( el.disabled && el.checked ) {
				el.checked = false;
				// Pusta grupa radio nie wysyła klucza [slot], co serwer odczytałby jako
				// „Bez noclegu" bez wiedzy zgłaszającego się — przenosimy zaznaczenie jawnie.
				var none = form.querySelector( 'input[name="' + el.name + '"][value=""]' );
				if ( none ) { none.checked = true; }
			}

			var wrap = el.closest( '.evreg-choice' );
			if ( wrap ) {
				wrap.hidden = mismatch;
				wrap.classList.toggle( 'is-full', full );
				var note = wrap.querySelector( '.evreg-seats-note' );
				if ( note ) { note.hidden = ! full; }
			}
		} );
	}
```

W obu pętlach inicjalizujących zamień wywołania `applySlotSeats( form )` na `applySlotState( form )`. `refreshAccommodation( form )` zostaje tam, gdzie jest — programowe `checked = false` nadal nie emituje `change`.

- [ ] **Step 4: Run specs**

```bash
npm run test:e2e -- room-occupancy
```

```bash
npm run test:e2e
```

Oczekiwane: cała suita zielona, w tym `accommodation-availability` (ta sama funkcja obsługuje teraz oba powody).

- [ ] **Step 5: Commit**

```bash
git add assets/public/form.js tests/e2e/setup-room-occupancy.php tests/e2e/room-occupancy.spec.js && git commit -m "feat(form): mirror the occupancy filter in the public form script"
```

---

### Task 10: Admin — liczba osób przy pokoju

**Files:**
- Modify: `assets/admin/tabs/AccommodationTab.jsx`
- Modify: `assets/admin/ops/accommodationOps.js`
- Test: `assets/admin/ops/accommodationOps.test.js` (dopisanie przypadków)

**Interfaces:**
- Consumes: nic z PHP — `EventConfigController::sanitize` przepuszcza int gałęzią `else` bez zmian.
- Produces: klucz `occupancy` w `_evreg_accommodation.rooms[]`.

- [ ] **Step 1: Write the failing jest tests**

Dopisz do `assets/admin/ops/accommodationOps.test.js`:

```js
	it( 'addRoom daje pokój bez ustawionej liczby osób', () => {
		const acc = addRoom( { packages: [], rooms: [], inventory: [] } );

		expect( acc.rooms[ 0 ].occupancy ).toBe( 0 );
	} );

	it( 'updateRoom ustawia liczbę osób', () => {
		const acc = updateRoom(
			{ packages: [], rooms: [ { key: 'double', label: '2-os.', occupancy: 0 } ], inventory: [] },
			'double',
			{ occupancy: 2 }
		);

		expect( acc.rooms[ 0 ].occupancy ).toBe( 2 );
	} );
```

(dopasuj importy i kształt `describe` do tego, co plik już ma)

- [ ] **Step 2: Run tests to verify they fail**

```bash
npm run test:js
```

Oczekiwane: FAIL — `addRoom` nie ustawia `occupancy`.

- [ ] **Step 3: `accommodationOps.js`**

W `addRoom` zamień tworzony obiekt na:

```js
	next.rooms.push( { key: nextKey( 'room' ), label: '', roommate_field: false, occupancy: 0 } );
```

- [ ] **Step 4: `AccommodationTab.jsx`**

W wierszu pokoju, po `ToggleControl` z polem współlokatora, dołóż `SelectControl` (dołóż go do importu z `@wordpress/components`, jeśli go tam nie ma):

```jsx
					<SelectControl
						label={ __( 'Liczba osób', 'event-registration' ) }
						help={ __( 'Nieustawione = jak dotąd (osoba towarzysząca opcjonalna). 1 osoba = bez towarzyszącej. 2 osoby = wymaga towarzyszącej, a pole współlokatora się nie pokazuje. Opcja „2 osoby" wymaga włączonej osoby towarzyszącej w ustawieniach noclegów poniżej.', 'event-registration' ) }
						value={ String( room.occupancy ?? 0 ) }
						options={ [
							{ value: '0', label: __( '— nieustawione —', 'event-registration' ) },
							{ value: '1', label: __( '1 osoba', 'event-registration' ) },
							{ value: '2', label: __( '2 osoby', 'event-registration' ), disabled: ! acc.companion_enabled },
						] }
						onChange={ ( occupancy ) =>
							setAcc( updateRoom( acc, room.key, { occupancy: parseInt( occupancy, 10 ) } ) )
						}
					/>
					{ 2 === ( room.occupancy ?? 0 ) && ! acc.companion_enabled && (
						<p className="evreg-room-row__warning">
							{ __( 'Ten pokój jest ustawiony na 2 osoby, ale osoba towarzysząca jest wyłączona dla tego wydarzenia — ustawienie jest ignorowane. Włącz osobę towarzyszącą albo zmień liczbę osób.', 'event-registration' ) }
						</p>
					) }
```

Styl ostrzeżenia dopisz do `assets/admin/style.css` w duchu `.evreg-field-row__note` (mały, szary tekst; ostrzeżenie może być w kolorze `#996800`).

- [ ] **Step 5: Run tests, build, commit**

```bash
npm run test:js
```

```bash
npm run build
```

```bash
git add assets/admin/tabs/AccommodationTab.jsx assets/admin/ops/accommodationOps.js assets/admin/ops/accommodationOps.test.js assets/admin/style.css && git commit -m "feat(admin): set the number of people per room"
```

---

### Task 11: i18n, pełny przebieg, changelog, paczka

**Files:**
- Modify: `languages/event-registration.pot`
- Modify: `CHANGELOG.md`
- Build: `event-registration.zip` (gitignored)

- [ ] **Step 1: Zregeneruj katalog stringów**

```bash
npm run i18n:pot
```

Sprawdź obecność **wszystkich** nowych stringów i wypisz w raporcie, który znalazłeś pod jaką linią: dwa komunikaty walidacji (`companion_required`, `companion_not_allowed`), komunikat „Dla tego wyboru nie ma pasujących pokojów…", komunikat admina `occupancy_mismatch`, etykieta „Liczba osób", trzy etykiety opcji, help text pokoju, ostrzeżenie o sprzeczności.

- [ ] **Step 2: Pełny przebieg**

```bash
npm run test:js
```

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
```

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
```

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
```

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=2G
```

```bash
npm run test:e2e
```

Jeśli cokolwiek nie przejdzie — NIE commituj i NIE buduj paczki; zaraportuj BLOCKED z wyjściem komendy.

- [ ] **Step 3: Changelog**

Dopisz pod `## [Unreleased]`, do istniejących sekcji `### Added` / `### Fixed` (nie twórz wersji, nie podbijaj `Version:`), osobne punkty po angielsku:

- liczba osób per pokój w konfiguracji noclegów,
- checkbox osoby towarzyszącej nad listą noclegów, filtrujący pokoje,
- odrzucanie niespójnej pary pokój/osoba towarzysząca,
- cena i miejsca liczone z liczby osób w pokoju,
- odmowa promocji z listy rezerwowej przy sprzecznej konfiguracji.

Po edycji sprawdź, że `## [Unreleased]` oraz **wszystkie** nagłówki wersji poniżej nadal istnieją z własną treścią — w tym repo zdarzyło się już, że edycja wchłonęła sąsiedni nagłówek. Napisz w raporcie, że to sprawdziłeś.

- [ ] **Step 4: Paczka**

```bash
bash scripts/build-zip.sh
```

Podaj rozmiar powstałego `event-registration.zip`.

- [ ] **Step 5: Commit**

```bash
git add languages/event-registration.pot CHANGELOG.md && git commit -m "chore: refresh translation catalog and changelog for room occupancy"
```

- [ ] **Step 6: Domknięcie gałęzi**

Użyj skilla `superpowers:finishing-a-development-branch`: whole-branch review, potem decyzja partnera o merge'u i wersji. **Nie merguj, nie taguj i nie pushuj bez jego zgody.**
