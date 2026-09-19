# Osoba towarzysząca + podwójne zajęcie noclegu (B5) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dodać opcję osoby towarzyszącej, która przy wybranym noclegu zajmuje 2 miejsca w puli (i opcjonalnie 1 w limicie wydarzenia) oraz płaci za drugie miejsce noclegowe.

**Architecture:** Flaga `companion` + `companion_name` na zgłoszeniu, kolumna `seats` (1/2) na bookingu noclegu. Cała arytmetyka miejsc/ceny w czystej domenie (`CapacityCalculator`, `PriceCalculator`, `OccupancySnapshot`); repo dostarcza surowe liczby (`SUM(seats)`, `SUM(companion)`). Konfiguracja per event w `_evreg_accommodation` (`companion_enabled`, `companion_counts_event`). Formularz publiczny renderuje checkbox + pole imienia (toggle JS wzorem `applyRoommate`).

**Tech Stack:** PHP 8 (namespace `EvReg\`, PSR-4), WordPress, MariaDB (`dbDelta`), React (`@wordpress/components`/`element`), jest, PHPUnit (unit + integration przez wp-env), phpstan L6, phpcs.

**Spec:** `docs/superpowers/specs/2026-09-19-companion-person-design.md`

## Global Constraints

- `src/Domain/**` = zero WordPressa i zero `$wpdb` (pilnuje `DomainPurityTest`). Cała arytmetyka miejsc/ceny w domenie.
- Domena zwraca kody błędów, nigdy komunikaty użytkownika. Tłumaczenie w prezentacji.
- Inwariant lock→count w `ReservationService::reserve`: `lockEvent()` przed pierwszym COUNT. NIE zmieniać kolejności.
- Wszystkie stringi UI (PHP + JS) przez i18n, text domain `event-registration` (`__()` z `@wordpress/i18n` w JS).
- React: logika mutacji w czystych `assets/admin/ops/*` (jest), komponenty cienkie, ops immutable.
- Defaulty zero-regresji: `seats=1`, `companion=0`, `companion_counts_event=false`, nowe parametry domeny domyślnie `false`/`1`.
- Każdy plik PHP poza `src/Domain/` i `tests/` zaczyna od `defined( 'ABSPATH' ) || exit;`.
- Nowy kod przechodzi `phpcs` czysto i `phpstan` L6 (`--memory-limit=1G`).
- Komendy PHP przez wrapper: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- <cmd>`. JS/jest na hoście (`npm run test:js`).
- Po zmianie wymagającej testów: `bash scripts/build-zip.sh`.

---

### Task 1: Migracja — kolumny companion/companion_name/seats + bump DB_VERSION

**Files:**
- Modify: `src/Persistence/Migrations.php` (CREATE TABLE registrations + accommodation_bookings; `DB_VERSION`)
- Test: `tests/Integration/Persistence/MigrationsCompanionColumnTest.php` (create)
- Modify (jeśli asertują wersję): `tests/Integration/Persistence/MailQueueMigrationTest.php` i inne testy migracji zakładające `DB_VERSION`

**Interfaces:**
- Produces: kolumny `registrations.companion` (tinyint), `registrations.companion_name` (varchar 191), `accommodation_bookings.seats` (tinyint unsigned default 1); `Migrations::DB_VERSION === 5`.

- [ ] **Step 1: Test — kolumny istnieją po instalacji**

```php
<?php
declare( strict_types=1 );
namespace EvReg\Tests\Integration\Persistence;
use EvReg\Persistence\Migrations;
use WP_UnitTestCase;

final class MigrationsCompanionColumnTest extends WP_UnitTestCase {
	public function test_registrations_have_companion_columns(): void {
		Migrations::install();
		global $wpdb;
		$cols = $wpdb->get_col( 'DESC ' . Migrations::table( 'registrations' ), 0 ); // phpcs:ignore WordPress.DB
		$this->assertContains( 'companion', $cols );
		$this->assertContains( 'companion_name', $cols );
	}

	public function test_bookings_have_seats_column(): void {
		Migrations::install();
		global $wpdb;
		$cols = $wpdb->get_col( 'DESC ' . Migrations::table( 'accommodation_bookings' ), 0 ); // phpcs:ignore WordPress.DB
		$this->assertContains( 'seats', $cols );
	}

	public function test_db_version_is_five(): void {
		$this->assertSame( 5, Migrations::DB_VERSION );
	}
}
```

- [ ] **Step 2: Uruchom — RED**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter MigrationsCompanionColumnTest`
Expected: FAIL (brak kolumn / DB_VERSION=4).

- [ ] **Step 3: Dopisz kolumny + bump wersji**

W `Migrations.php`: `const DB_VERSION = 5;`. W CREATE TABLE registrations dopisz po `name` (lub po `lang`):
```sql
companion tinyint(1) NOT NULL DEFAULT 0,
companion_name varchar(191) NOT NULL DEFAULT '',
```
W CREATE TABLE accommodation_bookings dopisz po `roommate_pref`:
```sql
seats tinyint unsigned NOT NULL DEFAULT 1,
```

- [ ] **Step 4: Uruchom — GREEN**

Run: ten sam filtr. Expected: PASS.

- [ ] **Step 5: Napraw testy migracji zakładające DB_VERSION**

Znajdź: `grep -rn "DB_VERSION\|=== 4\|=> 4\|VERSION_OPTION" tests/`. Zaktualizuj asercje wersji 4→5 tam gdzie dotyczą schematu (jak przy B3c-1). Uruchom cały pakiet migracji.

- [ ] **Step 6: phpcs + commit**

Run: `... vendor/bin/phpcs src/Persistence/Migrations.php`
```bash
git add src/Persistence/Migrations.php tests/Integration/Persistence/
git commit -m "feat(db): companion + seats columns, DB_VERSION 5"
```

---

### Task 2: Domena — konfiguracja, occupancy, limity, decyzja, cena

**Files:**
- Modify: `src/Domain/Accommodation/AccommodationConfig.php` (gettery companion)
- Modify: `src/Domain/Capacity/OccupancySnapshot.php` (wymiar companions)
- Modify: `src/Domain/Capacity/CapacityLimits.php` (flaga companionCountsEvent)
- Modify: `src/Domain/Capacity/CapacityCalculator.php` (param `$companion`)
- Modify: `src/Domain/Pricing/PriceCalculator.php` (param `$companion`)
- Test: `tests/Unit/Capacity/CapacityCalculatorCompanionTest.php` (create), `tests/Unit/Pricing/PriceCalculatorCompanionTest.php` (create), `tests/Unit/Capacity/OccupancySnapshotTest.php` (modify/create)

**Interfaces:**
- Consumes: `OccupancySnapshot(int $global, array $perType, array $perSlot, int $companions = 0)`; `AccommodationConfig::item($pkg,$room): ?InventoryItem` (istnieje).
- Produces:
  - `AccommodationConfig::companionEnabled(): bool`, `::companionCountsEvent(): bool`
  - `OccupancySnapshot::companions(): int`
  - `CapacityLimits::__construct(?int $globalLimit, array $perType, array $perSlot, bool $waitlistEnabled = true, bool $companionCountsEvent = false)` (nowy param NA KOŃCU) + `public readonly bool $companionCountsEvent`
  - `CapacityCalculator::decide(CapacityLimits $limits, OccupancySnapshot $taken, string $type_key, ?AccommodationSelection $selection = null, bool $companion = false): CapacityDecision`
  - `PriceCalculator::total(RegistrationType $type, ?AccommodationConfig $config = null, ?AccommodationSelection $selection = null, bool $companion = false): float`

- [ ] **Step 1: Test — OccupancySnapshot.companions + AccommodationConfig gettery**

```php
// tests/Unit/Capacity/OccupancySnapshotTest.php (dopisz lub utwórz)
public function test_companions_defaults_to_zero(): void {
	$snap = new \EvReg\Domain\Capacity\OccupancySnapshot( 5, array(), array() );
	$this->assertSame( 0, $snap->companions() );
}
public function test_companions_returned(): void {
	$snap = new \EvReg\Domain\Capacity\OccupancySnapshot( 5, array(), array(), 3 );
	$this->assertSame( 3, $snap->companions() );
}
```
```php
// AccommodationConfig test (dopisz do istniejącego tests/Unit/Accommodation/...)
public function test_companion_flags(): void {
	$c = \EvReg\Domain\Accommodation\AccommodationConfig::fromArray( array( 'companion_enabled' => true, 'companion_counts_event' => true ) );
	$this->assertTrue( $c->companionEnabled() );
	$this->assertTrue( $c->companionCountsEvent() );
	$d = \EvReg\Domain\Accommodation\AccommodationConfig::fromArray( array() );
	$this->assertFalse( $d->companionEnabled() );
	$this->assertFalse( $d->companionCountsEvent() );
}
```

- [ ] **Step 2: RED**

Run: `... vendor/bin/phpunit --testsuite unit --filter 'OccupancySnapshot|AccommodationConfig'`
Expected: FAIL.

- [ ] **Step 3: Implementuj snapshot + config**

`OccupancySnapshot`: dodaj `private readonly int $companions = 0` do konstruktora (ostatni param, domyślnie 0) i `public function companions(): int { return $this->companions; }`.
`AccommodationConfig`: w `fromArray` czytaj `companion_enabled`/`companion_counts_event` (bool cast); dodaj prywatne pola + gettery `companionEnabled()`/`companionCountsEvent()`.

- [ ] **Step 4: GREEN** (ten sam filtr).

- [ ] **Step 5: Test — CapacityCalculator companion**

```php
<?php
declare( strict_types=1 );
namespace EvReg\Tests\Unit\Capacity;
use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Domain\Capacity\CapacityCalculator;
use EvReg\Domain\Capacity\CapacityLimits;
use EvReg\Domain\Capacity\OccupancySnapshot;
use EvReg\Domain\Capacity\Outcome;
use PHPUnit\Framework\TestCase;

final class CapacityCalculatorCompanionTest extends TestCase {
	private CapacityCalculator $calc;
	protected function setUp(): void { $this->calc = new CapacityCalculator(); }

	public function test_companion_counts_to_event_when_enabled(): void {
		// global limit 2, zajęte 1 zgłoszenie (0 companionów). Companion counts → 1+2=3 > 2 → waitlist.
		$limits = new CapacityLimits( 2, array(), array(), true, true );
		$taken  = new OccupancySnapshot( 1, array(), array(), 0 );
		$d = $this->calc->decide( $limits, $taken, 'uczestnik', null, true );
		$this->assertSame( Outcome::Waitlisted, $d->outcome );
	}

	public function test_companion_ignored_for_event_when_flag_off(): void {
		$limits = new CapacityLimits( 2, array(), array(), true, false );
		$taken  = new OccupancySnapshot( 1, array(), array(), 0 );
		$d = $this->calc->decide( $limits, $taken, 'uczestnik', null, true );
		$this->assertSame( Outcome::Accepted, $d->outcome );
	}

	public function test_companion_needs_two_accommodation_seats(): void {
		// slot limit 3, zajęte 2 miejsca → companion żąda 2 → 2+2=4 > 3 → nocleg nieprzyznany.
		$sel    = new AccommodationSelection( 'n12', 'double' );
		$limits = new CapacityLimits( null, array(), array( 'n12|double' => 3 ), true, false );
		$taken  = new OccupancySnapshot( 5, array(), array( 'n12|double' => 2 ) );
		$d = $this->calc->decide( $limits, $taken, 'uczestnik', $sel, true );
		$this->assertSame( Outcome::Accepted, $d->outcome );
		$this->assertFalse( $d->accommodationGranted );
		$this->assertSame( 'accommodation_full', $d->reason );
	}

	public function test_companion_granted_when_two_seats_free(): void {
		$sel    = new AccommodationSelection( 'n12', 'double' );
		$limits = new CapacityLimits( null, array(), array( 'n12|double' => 4 ), true, false );
		$taken  = new OccupancySnapshot( 5, array(), array( 'n12|double' => 2 ) );
		$d = $this->calc->decide( $limits, $taken, 'uczestnik', $sel, true );
		$this->assertTrue( $d->accommodationGranted );
	}

	public function test_no_companion_matches_legacy_behavior(): void {
		// slot limit 1, zajęte 0 → bez companiona grant; zajęte 1 → deny.
		$sel    = new AccommodationSelection( 'n12', 'double' );
		$limits = new CapacityLimits( null, array(), array( 'n12|double' => 1 ), true, false );
		$grant  = $this->calc->decide( $limits, new OccupancySnapshot( 0, array(), array() ), 'x', $sel, false );
		$this->assertTrue( $grant->accommodationGranted );
		$deny   = $this->calc->decide( $limits, new OccupancySnapshot( 0, array(), array( 'n12|double' => 1 ) ), 'x', $sel, false );
		$this->assertFalse( $deny->accommodationGranted );
	}
}
```

- [ ] **Step 6: RED** (`--filter CapacityCalculatorCompanionTest`).

- [ ] **Step 7: Implementuj decide**

W `CapacityCalculator::decide` dodaj param `bool $companion = false`. Zmień bramki wg spec:
```php
$eventSeats = 1 + ( $companion && $limits->companionCountsEvent ? 1 : 0 );
$globalTaken = $taken->global() + ( $limits->companionCountsEvent ? $taken->companions() : 0 );
if ( null !== $limits->globalLimit && $globalTaken + $eventSeats > $limits->globalLimit ) {
	return $this->full( $limits, 'event_full' );
}
// typ — bez zmian
// nocleg:
$accSeats = $companion ? 2 : 1;
if ( null === $slot_limit || $taken->forSlot( $slot ) + $accSeats > $slot_limit ) {
	return new CapacityDecision( Outcome::Accepted, false, 'accommodation_full' );
}
return new CapacityDecision( Outcome::Accepted, true );
```

- [ ] **Step 8: GREEN** + uruchom PEŁNY `--testsuite unit` (regresja istniejących decyzji).

- [ ] **Step 9: Test + impl PriceCalculator**

```php
// tests/Unit/Pricing/PriceCalculatorCompanionTest.php
public function test_companion_doubles_accommodation_price(): void {
	$type = new \EvReg\Domain\Registration\RegistrationType( 'u', 'Uczestnik', 100.0, null );
	$cfg  = \EvReg\Domain\Accommodation\AccommodationConfig::fromArray( array(
		'packages' => array( array( 'key' => 'n12', 'label' => 'N' ) ),
		'rooms'    => array( array( 'key' => 'double', 'label' => 'D' ) ),
		'inventory'=> array( array( 'package' => 'n12', 'room' => 'double', 'capacity' => 5, 'price' => 180.0 ) ),
	) );
	$sel = new \EvReg\Domain\Accommodation\AccommodationSelection( 'n12', 'double' );
	$calc = new \EvReg\Domain\Pricing\PriceCalculator();
	$this->assertSame( 460.0, $calc->total( $type, $cfg, $sel, true ) );  // 100 + 2×180
	$this->assertSame( 280.0, $calc->total( $type, $cfg, $sel, false ) ); // 100 + 180
}
```
(Sprawdź dokładny konstruktor `RegistrationType` w kodzie — dostosuj argumenty.)
Impl: `total(..., bool $companion = false)`, `$total += $item->price * ( $companion ? 2 : 1 );`.

- [ ] **Step 10: GREEN + phpstan + phpcs + commit**

Run: `... vendor/bin/phpstan analyse --memory-limit=1G`; `... vendor/bin/phpcs src/Domain/`
```bash
git add src/Domain/ tests/Unit/
git commit -m "feat(domain): companion seats in capacity + price"
```

---

### Task 3: Repo — occupancy SUM(seats)+companions, insert/update companion, booking seats

**Files:**
- Modify: `src/Persistence/RegistrationRepository.php` (`occupancy`, `occupancyExcluding`, `insertRegistration`, `updateRegistration`, `insertAccommodationBooking`)
- Test: `tests/Integration/Persistence/OccupancyCompanionTest.php` (create)

**Interfaces:**
- Consumes: `OccupancySnapshot(...companions)`, kolumny z Task 1.
- Produces: `insertAccommodationBooking( int $registration_id, AccommodationSelection $selection, float $price, int $seats = 1 )`; `insertRegistration`/`updateRegistration` obsługują klucze `companion`, `companion_name`; `occupancy`/`occupancyExcluding` liczą slot jako `SUM(seats)` i wypełniają `companions`.

- [ ] **Step 1: Test — occupancy liczy seats i companions**

```php
<?php
declare( strict_types=1 );
namespace EvReg\Tests\Integration\Persistence;
use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use WP_UnitTestCase;

final class OccupancyCompanionTest extends WP_UnitTestCase {
	private RegistrationRepository $repo;
	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB
		}
		$this->repo = new RegistrationRepository();
		$this->event_id = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
	}

	public function test_slot_occupancy_sums_seats_and_companions_counted(): void {
		$id = $this->repo->insertRegistration( array(
			'event_id' => $this->event_id, 'type_key' => 'u', 'status' => 'pending',
			'email' => 'a@e.com', 'name' => 'A', 'token' => str_repeat( 'a', 32 ),
			'data' => '{}', 'price_total' => 0.0, 'expires_at' => '2099-01-01 00:00:00',
			'companion' => 1, 'companion_name' => 'Towarzysz T.',
		) );
		$this->repo->insertAccommodationBooking( $id, new AccommodationSelection( 'n12', 'double' ), 360.0, 2 );

		$snap = $this->repo->occupancy( $this->event_id );
		$this->assertSame( 2, $snap->forSlot( 'n12|double' ) );
		$this->assertSame( 1, $snap->companions() );

		$excl = $this->repo->occupancyExcluding( $this->event_id, $id );
		$this->assertSame( 0, $excl->forSlot( 'n12|double' ) );
		$this->assertSame( 0, $excl->companions() );
	}
}
```

- [ ] **Step 2: RED** (`--filter OccupancyCompanionTest`) — insertRegistration nie zna `companion` / slot liczy 1.

- [ ] **Step 3: Implementuj repo**

- `insertRegistration`: dopisz `companion` (`%d`, cast int) i `companion_name` (`%s`) do mapy kolumn/formatów (jak `lang` w B3c-1).
- `updateRegistration`: dopisz `companion` + `companion_name` do zestawu kolumn i formatów.
- `insertAccommodationBooking`: param `int $seats = 1`, dopisz `'seats' => $seats` (`%d`).
- `occupancy` + `occupancyExcluding`: slot SQL `COUNT(*)` → `SUM(b.seats)`; dodaj zapytanie companions `SELECT COALESCE(SUM(companion),0) FROM registrations WHERE event_id=%d AND status IN (...)` (+ `AND id != %d` w excluding); przekaż 4. arg do `new OccupancySnapshot(...)`.

- [ ] **Step 4: GREEN** + `... vendor/bin/phpunit -c phpunit-integration.xml.dist` (regresja PlaceholderFactory/reserve).

- [ ] **Step 5: phpstan + phpcs + commit**
```bash
git add src/Persistence/RegistrationRepository.php tests/Integration/Persistence/OccupancyCompanionTest.php
git commit -m "feat(repo): occupancy sums seats + companions, persist companion"
```

---

### Task 4: ReservationRequest + reserve/editAnswers threadują companion

**Files:**
- Modify: `src/Services/ReservationRequest.php` (pola companion/companionName)
- Modify: `src/Services/ReservationService.php` (`reserve`, `editAnswers`)
- Test: `tests/Integration/Services/ReserveCompanionTest.php` (create)

**Interfaces:**
- Consumes: Task 2 (`decide`/`total` z `$companion`), Task 3 (repo).
- Produces: `ReservationRequest` z `public readonly bool $companion = false`, `public readonly string $companionName = ''` (po `lang`); `reserve`/`editAnswers` zapisują companion, seats i cenę ×2.

- [ ] **Step 1: Test — reserve zapisuje companion + booking seats=2 + cena**

```php
public function test_reserve_with_companion_stores_flag_seats_and_price(): void {
	// event z typem 100 zł, nocleg n12|double capacity 5 price 180, companion_enabled.
	// ReservationRequest z companion=true, companionName='Jan T.', selection n12/double.
	// Asercje: wiersz companion=1, companion_name='Jan T.'; booking seats=2, price=360;
	// price_total = 100 + 360; occupancy slotu = 2.
}
public function test_reserve_companion_without_room_free_for_two_skips_booking(): void {
	// slot capacity 1, companion → accommodation_full: brak bookingu, companion=1, zgłoszenie reserved bez noclegu.
}
```
(Wzoruj setup na istniejącym `tests/Integration/Services/*ReserveTest*` — użyj `EventConfigRepository::save` z `companion_enabled`.)

- [ ] **Step 2: RED**.

- [ ] **Step 3: Implementuj**

- `ReservationRequest`: dodaj pola (ostatnie, po `lang`, z defaultami).
- `reserve`: `decide( ..., $request->companion )`; `price = $this->pricing->total( $type, $accommodation, $request->selection, $request->companion )`; insertRegistration z `companion`/`companion_name`; przy grant noclegu `$seats = $request->companion ? 2 : 1; $acc_price = ($item?->price ?? 0.0) * $seats; insertAccommodationBooking( $id, $selection, $acc_price, $seats )`.
- `editAnswers`: analogicznie (decide z companion, total z companion, updateRegistration companion+name, rebuild booking z seats). Zachowaj istniejące bramki (`Accepted !== outcome`, `accommodationFull`).
- **Nie zmieniać kolejności lockEvent→occupancy→decide.**

- [ ] **Step 4: GREEN** + pełny integration.

- [ ] **Step 5: phpstan + phpcs + commit**
```bash
git commit -am "feat(reserve): thread companion through reserve + editAnswers"
```

---

### Task 5: Formularz publiczny — render, form.js toggle, ekstrakcja + walidacja

**Files:**
- Modify: `src/Frontend/FormRenderer.php` (companion controls w sekcji noclegu)
- Modify: `assets/public/form.js` (`applyCompanion`)
- Modify: `src/Frontend/SubmissionAssembler.php` (ekstrakcja + walidacja)
- Test: `tests/Integration/Frontend/CompanionSubmissionTest.php` (create); render — asercja w istniejącym `FormRenderer` teście lub nowym

**Interfaces:**
- Consumes: Task 4 (`ReservationRequest`).
- Produces: render checkboxa `evreg_companion` + pola `evreg_companion_name` (gdy `companionEnabled`); `SubmissionAssembler` czyta je i waliduje; nowy kod błędu `companion_name_required`.

- [ ] **Step 1: Test — assembler ekstrahuje companion i waliduje puste imię**

```php
// Integration: zbuduj $_POST z evreg_companion=1 bez evreg_companion_name → isValid=false, błąd companion_name_required.
// Z evreg_companion=1 + imię → request->companion=true, companionName ustawione.
// Bez evreg_companion → companion=false.
// Gdy event NIE ma companion_enabled → POST evreg_companion ignorowany (companion=false).
```

- [ ] **Step 2: RED**.

- [ ] **Step 3: Implementuj assembler**

W `SubmissionAssembler` (pipeline ekstrakcji): odczyt `companion`/`companionName` z `$request` (POST) tylko gdy `$accommodation->companionEnabled()`. Walidacja: companion && '' === trim(name) → dodaj błąd `companion_name_required`. Przekaż do `ReservationRequest`. Komunikat mapowany w prezentacji (nie w domenie).

- [ ] **Step 4: GREEN**.

- [ ] **Step 5: Render (FormRenderer)**

W `renderAccommodation` (albo tuż za nim, gdy `$accommodation->companionEnabled()`):
```php
$companion_on = /* z re-render wartości */;
$out .= '<div class="evreg-companion form-check mt-2">'
	. '<input class="form-check-input" type="checkbox" id="evreg_companion" name="evreg_companion" value="1"' . checked( $companion_on, true, false ) . ' data-evreg-companion>'
	. ' <label class="form-check-label" for="evreg_companion">' . esc_html__( 'Osoba towarzysząca', 'event-registration' ) . '</label>'
	. '</div>';
$hidden = $companion_on ? '' : ' hidden';
$out .= '<input class="form-control mt-2" type="text" data-evreg-companion-input name="evreg_companion_name" placeholder="' . esc_attr__( 'Imię i nazwisko osoby towarzyszącej', 'event-registration' ) . '" value="' . esc_attr( $companion_name ) . '"' . $hidden . '>';
```
Mapę kodów błędów FormRenderera (`errorMessage`) rozszerz o `companion_name_required` → „Podaj imię i nazwisko osoby towarzyszącej.".

- [ ] **Step 6: form.js applyCompanion**

Wzorem `applyRoommate`: nasłuch `change` na `input[name="evreg_companion"]`, toggluj `hidden` na `[data-evreg-companion-input]`; wywołaj w init.

- [ ] **Step 7: Test render + build assetów**

Asercja render: HTML zawiera `name="evreg_companion"` gdy companionEnabled, brak gdy wyłączone. (form.js bez jest — statyczny asset; ewentualny e2e opcjonalny.)
Run: `npm run build` nie dotyczy public (statyczne) — brak buildu. Uruchom integration.

- [ ] **Step 8: phpcs + commit**
```bash
git commit -am "feat(form): companion checkbox + name field, JS toggle, validation"
```

---

### Task 6: Admin — konfiguracja companion w AccommodationTab

**Files:**
- Modify: `assets/admin/tabs/AccommodationTab.jsx` (dwa ToggleControl)
- Modify: ops konfiguracji noclegu (`assets/admin/ops/*` — ustal właściwy moduł, np. `accommodationOps.js`) + test jest
- Modify: `src/Rest/EventConfigController.php` (sanitize dopuszcza nowe bool)
- Test: `assets/admin/ops/__tests__/*` (jest), integration sanitize (istniejący test controllera lub nowy)

**Interfaces:**
- Consumes: `config.accommodation` z `companion_enabled`/`companion_counts_event`.
- Produces: UI toggli; ops immutable ustawiające flagi; controller zapisuje flagi.

- [ ] **Step 1: Test jest — ops set companion flags (immutable)**

```js
// setCompanionEnabled(accommodation, bool) / setCompanionCountsEvent(...) zwracają nowy obiekt, nie mutują wejścia.
```

- [ ] **Step 2: RED** (`npm run test:js`).

- [ ] **Step 3: Impl ops** (czyste, immutable) + **Step 4: GREEN**.

- [ ] **Step 5: UI AccommodationTab**

Dodaj `ToggleControl` „Opcja osoby towarzyszącej" (`companion_enabled`) i — gdy włączone — „Osoba towarzysząca zajmuje miejsce w limicie wydarzenia" (`companion_counts_event`). Komponent cienki, woła ops. Stringi przez `__()`.

- [ ] **Step 6: Controller sanitize**

W `EventConfigController::sanitize` (gałąź accommodation) przepuść `companion_enabled`/`companion_counts_event` jako `(bool)`. Test integration: POST configu z flagami → odczyt zachowuje bool.

- [ ] **Step 7: build + GREEN + commit**
```bash
npm run build
git add assets/admin/ src/Rest/EventConfigController.php tests/
git commit -m "feat(admin): companion config toggles in accommodation tab"
```

---

### Task 7: Panel / edycja / eksport / mail / privacy

**Files:**
- Modify: `src/Admin/RegistrationsScreen.php` (detal: wiersz companion)
- Modify: `src/Admin/RegistrationEditForm.php` (kontrolki companion + walidacja)
- Modify: `src/Admin/RegistrationsExporter.php` (kolumna CSV) — nagłówek i18n + komórka
- Modify: `src/Mail/PlaceholderFactory.php` (placeholder `{osoba_towarzyszaca}` + linia w podsumowaniu)
- Modify: `src/Privacy/PrivacyProvider.php` + `src/Persistence/RegistrationRepository.php` (anonimizacja companion_name)
- Test: rozszerz istniejące — `tests/Integration/Admin/*Export*`, `tests/Integration/Mail/PlaceholderFactoryTest.php`, `tests/Integration/Privacy/*`

**Interfaces:**
- Consumes: kolumny companion, Task 4 (edit).
- Produces: CSV kolumna „Osoba towarzysząca"; placeholder `{osoba_towarzyszaca}`; eraser czyści companion_name.

- [ ] **Step 1: Test — CSV kolumna companion**

Rozszerz test eksportu: rejestracja z `companion_name='Jan T.'` → `buildCsv` zawiera nagłówek „Osoba towarzysząca" i wartość w wierszu; neutralizacja CSV injection dla tej komórki.

- [ ] **Step 2: RED → impl Exporter** (dodaj nagłówek i18n + komórkę companion_name; przez `neutralize` jak inne dane) **→ GREEN**.

- [ ] **Step 3: Test — placeholder + podsumowanie**

`PlaceholderFactoryTest`: rejestracja companion → `build()->get('osoba_towarzyszaca')` == imię; `podsumowanie` zawiera „Osoba towarzysząca: Jan T."; bez companiona placeholder pusty, brak linii.

- [ ] **Step 4: RED → impl PlaceholderFactory** (dodaj do `Placeholders` klucz `osoba_towarzyszaca`; linia w `summary` gdy companion, wzorem linii noclegu) **→ GREEN**.

- [ ] **Step 5: Test — privacy erase czyści companion_name**

Rozszerz test eraser: po `anonymize*` companion_name == '', companion/status/seats zostają.

- [ ] **Step 6: RED → impl anonimizacji** (dopisz `companion_name` do SET '' w `anonymize*`) **→ GREEN**.

- [ ] **Step 7: Detal + edycja admina**

`RegistrationsScreen` detal: wiersz „Osoba towarzysząca: {imię}" gdy companion. `RegistrationEditForm`: checkbox + pole imienia (prefill), reuse `applyCompanion` (`evreg-public`), walidacja `companion_name_required` (mapa PL). Test: render zawiera kontrolki; edycja zapisuje companion.

- [ ] **Step 8: phpstan + phpcs + GREEN (pełne) + commit**
```bash
git commit -am "feat(admin+mail+privacy): surface companion in detail, edit, CSV, mail, eraser"
```

---

### Task 8: Weryfikacja końcowa — pełne testy, build, paczka, backlog

**Files:**
- Modify: `docs/superpowers/backlog.md` (B5 → done)
- Modify: `CLAUDE.md` (dopisek companion, jeśli nośny)

- [ ] **Step 1: Pełne pakiety**

Run kolejno:
- `... vendor/bin/phpunit --testsuite unit`
- `... vendor/bin/phpunit -c phpunit-integration.xml.dist`
- `... vendor/bin/phpstan analyse --memory-limit=1G`
- `... vendor/bin/phpcs`
- `npm run test:js`
Wszystko zielone.

- [ ] **Step 2: Build + paczka**
```bash
npm run build
bash scripts/build-zip.sh
```

- [ ] **Step 3: Backlog + CLAUDE.md**

Zaznacz B5 done w `docs/superpowers/backlog.md`. Dopisz w `CLAUDE.md` (sekcja iteracji lub noclegu) nośny fakt: companion (`companion`/`companion_name` na zgłoszeniu, `seats` na bookingu, `companion_enabled`/`companion_counts_event` w `_evreg_accommodation`; slot occupancy = SUM(seats); cena ×2; kontrolki `evreg_companion`/`evreg_companion_name`). Uwaga o B6 (labele companion nietłumaczone).

- [ ] **Step 4: Commit**
```bash
git commit -am "docs: mark B5 done; note companion in CLAUDE.md"
```

---

## Self-Review (wykonane przy pisaniu planu)

- **Pokrycie specu:** migracja (T1), domena occupancy/limits/decide/price (T2), repo (T3), reserve/edit (T4), formularz+walidacja (T5), config admin (T6), panel/eksport/mail/privacy (T7), weryfikacja (T8). Wszystkie sekcje specu mają task.
- **Typy spójne:** `decide(...,bool $companion=false)`, `total(...,bool $companion=false)`, `OccupancySnapshot(...,int $companions=0)`, `CapacityLimits(...,bool $companionCountsEvent=false)`, `insertAccommodationBooking(...,int $seats=1)` — używane spójnie w T2–T4.
- **Placeholdery:** brak TODO; testy mają konkretny kod lub jednoznaczny opis asercji (setupy wzorowane na istniejących testach — wskazane pliki).
- **Ryzyko:** dokładny konstruktor `RegistrationType` i nazwa modułu ops noclegu do potwierdzenia w kodzie przy implementacji (oznaczone w krokach).
