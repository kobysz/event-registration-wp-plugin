# Pule noclegowe per doba — plan implementacji

> **PLAN NIEAKTUALNY — NIE WYKONUJ.** Opisuje odrzucony model (pule per
> `doba × typ pokoju`, pakowanie łóżek). Aktualny plan powstaje z
> `docs/superpowers/specs/2026-11-17-night-seat-pools-design.md`.


> **Dla pracujących agentowo:** WYMAGANY SUB-SKILL: użyj superpowers:subagent-driven-development
> (rekomendowane) albo superpowers:executing-plans, żeby wykonać ten plan task po tasku.
> Kroki mają składnię checkboxów (`- [ ]`) do odhaczania.

**Cel:** limit noclegów przenieść z pary `(pakiet × pokój)` na parę `(doba × typ pokoju)`,
wpisywany w pokojach, tak żeby rezerwacja na dwie doby zdejmowała pokój z każdej z nich,
a jeden zapas 31 dwójek obsługiwał jednocześnie pary (cały pokój) i singli (łóżko).

**Architektura:** doba to nowy byt konfiguracji; pakiet deklaruje listę dób. Popyt dobowy
zapisywany jest w nowej tabeli potomnej bookingu (`evreg_booking_nights`) i liczony pod
lockiem eventu tym samym wzorcem co dziś. Arytmetyka pokoi (pakowanie luźnych łóżek)
mieszka w nowej czystej klasie `NightOccupancy`; arytmetyka łóżek zostaje w
`RoomOccupancy`, której sygnatura rośnie o parę `beds`/`whole_only` zastępującą
`occupancy`. Klucz slotu, selekcja, JSON odpowiedzi i tabela bookingów **nie zmieniają
kształtu** — to warunek zgodności z zapisanymi rezerwacjami.

**Stack:** PHP 8.1, WordPress 6.4+, PHPUnit 9 (unit + integration w kontenerze wp-env),
React (@wordpress/components) + jest dla `assets/admin/ops/*`, Playwright e2e.

**Spec:** `docs/superpowers/specs/2026-11-17-per-night-accommodation-pools-design.md`
(czytaj razem z planem — plan argumentuje ze speca, konflikty rozstrzyga spec)

## Global Constraints

- `src/Domain/**` nie zawiera ani jednego odwołania do WordPressa i `$wpdb`; pilnuje
  `tests/Unit/Architecture/DomainPurityTest.php`.
- Domena rzuca wyjątki albo zwraca kody; komunikaty dla człowieka tylko w prezentacji.
- Hooki cyklu życia zgłoszenia emitowane **wyłącznie po COMMIT**.
- `lockEvent()` **przed** pierwszym odczytem zajętości w każdej ścieżce transakcyjnej.
  Kolejności nie zmieniać.
- Każdy plik PHP poza `src/Domain/` i `tests/` zaczyna się od `defined( 'ABSPATH' ) || exit;`.
- Wszystkie stringi UI przez i18n, text domain `event-registration`; w JS `__()`
  z `@wordpress/i18n`.
- PHPStan poziom 6, bez obniżania i bez rozproszonych `@phpstan-ignore`.
- `phpcs` czysto pod `phpcs.xml.dist`; bez dodawania wykluczeń sniffów.
- Logika mutacji konfiguracji w adminie w `assets/admin/ops/*`, komponenty cienkie.
- Klucz slotu zostaje `"pakiet|pokój"`. `AccommodationSelection`, JSON odpowiedzi
  (`{package, room, roommate}`) i tabela `evreg_accommodation_bookings` bez zmian.
- `RoomOccupancy` to jedyny dom reguły łóżek; `NightOccupancy` jedyny dom reguły pokoi.
  Lustro w `assets/public/form.js` musi dawać ten sam wynik.
- Tryb zgodności (pakiet bez dób) zachowuje dzisiejsze zachowanie **bit w bit**.

### Komendy

```bash
# JS (host)
npm run test:js
npm run build

# PHP (kontener, przez wrapper — NIE gołe npx wp-env)
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- composer analyse
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs

# e2e (host, wymaga działającej wp-env)
node scripts/wp-env.cjs start && npm run test:e2e
```

Filtrowanie jednego testu: `-- vendor/bin/phpunit --testsuite unit --filter NightOccupancyTest`.

---

## Struktura plików

**Nowe:**

| plik | odpowiedzialność |
|---|---|
| `src/Domain/Accommodation/Night.php` | obiekt wartości doby (klucz + etykieta) |
| `src/Domain/Capacity/NightOccupancy.php` | arytmetyka pokoi: pakowanie luźnych łóżek, wolne łóżka, pokoje zajęte |
| `tests/Unit/Domain/Accommodation/NightTest.php` | — |
| `tests/Unit/Domain/Capacity/NightOccupancyTest.php` | — |
| `tests/Unit/Domain/Accommodation/NightConfigTest.php` | doby i `night_inventory` w `AccommodationConfig` |
| `tests/Integration/Services/NightPoolReservationTest.php` | `reserve` pod pulami dobowymi |
| `tests/Integration/Services/NightPoolEditTest.php` | `editAnswers` + `promoteFromWaitlist` |
| `tests/Integration/Admin/NightBackfillTest.php` | dosypanie dób |
| `tests/e2e/night-pools.spec.js` + `setup-night-pools.php` | para i singiel z jednej puli |

**Modyfikowane (główna odpowiedzialność po zmianie):**

| plik | zmiana |
|---|---|
| `src/Domain/Accommodation/RoomOccupancy.php` | `seats( int $beds, bool $whole_only, bool $companion )` |
| `src/Domain/Accommodation/RoomType.php` | `occupancy` → `beds` + `wholeOnly`, mapowanie legacy |
| `src/Domain/Accommodation/Package.php` | `nights` + `usesNightPools()` |
| `src/Domain/Accommodation/AccommodationConfig.php` | doby, `night_inventory`, walidacja, normalizacje, mapy dla limitów |
| `src/Domain/Accommodation/SlotAvailability.php` | `left()` świadome dób |
| `src/Domain/Capacity/OccupancySnapshot.php` | histogram `(doba\|pokój) => seats => liczba` |
| `src/Domain/Capacity/CapacityLimits.php` | `nightRoomCapacity`, `packageNights`, `roomBeds` |
| `src/Domain/Capacity/CapacityCalculator.php` | bramka dobowa |
| `src/Domain/Capacity/OccupancyReport.php` | obłożenie per doba |
| `src/Domain/Export/RegistrationExportMapper.php` | kolumna „Doby" |
| `src/Domain/Schema/ContentTranslator.php` | bucket `nights` |
| `src/Persistence/Migrations.php` | db v7, tabela `booking_nights` |
| `src/Persistence/Uninstaller.php` | nowa tabela na liście |
| `src/Persistence/RegistrationRepository.php` | zapytanie dobowe, insert/delete dób, bulk read, zapytanie dosypania |
| `src/Services/ReservationService.php` | mapy dobowe w trzech ścieżkach, wiersze dób w transakcji |
| `src/Frontend/FormRenderer.php` | `data-evreg-beds` + `data-evreg-whole-only` |
| `src/Frontend/Shortcode.php` | `availability()` z pulami dobowymi |
| `src/Admin/RegistrationEditForm.php` | te same atrybuty w edycji |
| `src/Admin/RegistrationsScreen.php` | detal: doby; akcja dosypania |
| `src/Admin/RegistrationsOverview.php` | tabela „Noclegi per doba" |
| `assets/public/form.js` | dwa lustra przepisane |
| `assets/admin/ops/accommodationOps.js` | doby, matryca dobowa, kaskady, pola pokoju |
| `assets/admin/tabs/AccommodationTab.jsx` | UI dób, matryca, ostrzeżenia |
| `assets/admin/ops/i18nOps.js` + `tabs/TranslationsTab.jsx` | tłumaczenia etykiet dób |

---

## Task 1: Doba jako obiekt wartości + doby na pakiecie

**Files:**
- Create: `src/Domain/Accommodation/Night.php`
- Create: `tests/Unit/Domain/Accommodation/NightTest.php`
- Modify: `src/Domain/Accommodation/Package.php`
- Modify: `tests/Unit/Domain/Accommodation/PackageTest.php` (jeśli nie istnieje — utwórz)

**Interfaces:**
- Produces: `Night` z `public readonly string $key`, `$label`, `fromArray(array): self`,
  `toArray(): array`. `Package` z `public readonly array $nights` (lista stringów) oraz
  `usesNightPools(): bool`.
- Consumes: nic (pierwszy task).

- [ ] **Krok 1: Test doby**

```php
<?php
declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Accommodation;

use EvReg\Domain\Accommodation\Night;
use EvReg\Domain\Schema\SchemaException;
use PHPUnit\Framework\TestCase;

final class NightTest extends TestCase {

	public function test_from_array_reads_key_and_label(): void {
		$night = Night::fromArray( array( 'key' => 'n1819', 'label' => '18/19 grudnia' ) );

		$this->assertSame( 'n1819', $night->key );
		$this->assertSame( '18/19 grudnia', $night->label );
	}

	public function test_label_falls_back_to_key(): void {
		$this->assertSame( 'n1819', Night::fromArray( array( 'key' => 'n1819' ) )->label );
	}

	public function test_empty_key_is_rejected(): void {
		$this->expectException( SchemaException::class );
		Night::fromArray( array( 'key' => '' ) );
	}

	public function test_to_array_round_trips(): void {
		$data = array( 'key' => 'n1819', 'label' => '18/19 grudnia' );

		$this->assertSame( $data, Night::fromArray( $data )->toArray() );
	}
}
```

- [ ] **Krok 2: Uruchom — ma FAIL**

`--filter NightTest`, oczekiwane: `Error: Class "EvReg\Domain\Accommodation\Night" not found`.

> Konsola PHPUnit w tym kontenerze **zniekształca komunikat niezłapanego `Error`**.
> Fazę RED weryfikuj jawnym try/catch drukującym `get_class($e)` i `getMessage()`,
> nie podsumowaniem PHPUnit.

- [ ] **Krok 3: `Night`** — bliźniak `Package` (ten sam kształt, ten sam styl docbloków)

```php
<?php
/**
 * Doba noclegowa dostępna w ramach wydarzenia.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Accommodation;

use EvReg\Domain\Schema\SchemaException;

/**
 * Pojedyncza doba (noc), do której przypisany jest limit pokoi.
 */
final class Night {

	/**
	 * @param string $key   Unikalny klucz doby.
	 * @param string $label Etykieta prezentowana użytkownikowi.
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $label
	) {
	}

	/**
	 * @param array<string,mixed> $data Surowe dane doby.
	 *
	 * @throws SchemaException Gdy brakuje klucza doby.
	 */
	public static function fromArray( array $data ): self {
		if ( ! isset( $data['key'] ) || '' === (string) $data['key'] ) {
			throw new SchemaException( 'Doba noclegowa wymaga niepustego klucza.' );
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

- [ ] **Krok 4: Test dób na pakiecie**

Dopisz do `PackageTest` (utwórz plik w tym samym stylu co `NightTest`, jeśli brak):

```php
	public function test_package_without_nights_does_not_use_night_pools(): void {
		$package = Package::fromArray( array( 'key' => 'p1', 'label' => 'Doba 18/19' ) );

		$this->assertSame( array(), $package->nights );
		$this->assertFalse( $package->usesNightPools() );
	}

	public function test_package_with_nights_uses_night_pools(): void {
		$package = Package::fromArray(
			array( 'key' => 'p2', 'label' => 'Dwie doby', 'nights' => array( 'n1819', 'n1920' ) )
		);

		$this->assertSame( array( 'n1819', 'n1920' ), $package->nights );
		$this->assertTrue( $package->usesNightPools() );
	}

	public function test_nights_are_normalised_to_a_list_of_strings(): void {
		$package = Package::fromArray(
			array( 'key' => 'p2', 'nights' => array( 2 => 'n1819', 5 => 'n1920' ) )
		);

		// Lista, nie mapa — klucze z configu nie mogą przeciekać do domeny.
		$this->assertSame( array( 'n1819', 'n1920' ), $package->nights );
	}

	public function test_to_array_keeps_nights(): void {
		$data = array( 'key' => 'p2', 'label' => 'Dwie doby', 'nights' => array( 'n1819' ) );

		$this->assertSame( $data, Package::fromArray( $data )->toArray() );
	}
```

- [ ] **Krok 5: Uruchom — ma FAIL** (`nights` nie istnieje)

- [ ] **Krok 6: `Package`**

Konstruktor dostaje czwarty parametr, `fromArray` normalizuje listę, `toArray` ją zwraca:

```php
	public function __construct(
		public readonly string $key,
		public readonly string $label,
		/** @var string[] */
		public readonly array $nights = array()
	) {
	}

	public static function fromArray( array $data ): self {
		if ( ! isset( $data['key'] ) || '' === (string) $data['key'] ) {
			throw new SchemaException( 'Pakiet noclegowy wymaga niepustego klucza.' );
		}

		$nights = array_values(
			array_map( 'strval', array_filter( (array) ( $data['nights'] ?? array() ), static fn ( $n ): bool => '' !== (string) $n ) )
		);

		return new self( (string) $data['key'], (string) ( $data['label'] ?? $data['key'] ), $nights );
	}

	/**
	 * Czy pakiet jest rozliczany z pul dobowych. Pakiet bez zadeklarowanych dób
	 * zostaje w trybie zgodności: limit z pozycji inwentarza, pula w miejscach.
	 */
	public function usesNightPools(): bool {
		return array() !== $this->nights;
	}
```

`toArray()` dokłada `'nights' => $this->nights` **zawsze** (także pustą listę) — testy
asertują równość całej tablicy, a stabilny kształt upraszcza round-trip w adminie.

- [ ] **Krok 7: Uruchom unit — PASS**

- [ ] **Krok 8: `phpcs` + `composer analyse` na czysto**

- [ ] **Krok 9: Commit**

```bash
git add src/Domain/Accommodation/Night.php src/Domain/Accommodation/Package.php tests/Unit/Domain/Accommodation/
git commit -m "feat(accommodation): add the night value object and let a package declare its nights"
```

---

## Task 2: `beds` + `whole_only` zamiast `occupancy`

Najbardziej nośny task domeny: zmienia sygnaturę reguły, której dziś używa pięć miejsc,
i musi zachować dzisiejsze zachowanie dla wszystkich trzech wartości legacy.

**Files:**
- Modify: `src/Domain/Accommodation/RoomOccupancy.php`
- Modify: `src/Domain/Accommodation/RoomType.php`
- Modify: `src/Domain/Accommodation/AccommodationConfig.php` (`seatsFor`, `slotOccupancies`
  → `slotRooms`, reguła sprzeczności z wyłączoną towarzyszącą)
- Modify: `src/Domain/Capacity/CapacityLimits.php` (`seatsForSlot` czyta parę)
- Modify: `tests/Unit/Domain/Accommodation/RoomOccupancyTest.php`,
  `RoomTypeTest.php`, `AccommodationConfigTest.php`
- Modify: `tests/Unit/Domain/Capacity/CapacityLimitsTest.php` (jeśli istnieje)

**Interfaces:**
- Consumes: nic z Taska 1.
- Produces:
  - `RoomOccupancy::seats( int $beds, bool $whole_only, bool $companion ): int`
  - `RoomType` z `public readonly int $beds`, `public readonly bool $wholeOnly`;
    `seats( bool $companion ): int`, `companionMismatch( bool $companion ): ?string`
    (sygnatury publiczne bez zmian)
  - `AccommodationConfig::slotRooms(): array<string, array{beds:int, whole:bool}>`
    (zastępuje `slotOccupancies()`)
  - `CapacityLimits` szósty parametr `$perSlotRooms` (ta sama pozycja co dzisiejszy
    `$perSlotSeats`, zmienia się tylko typ wartości)

- [ ] **Krok 1: Test reguły łóżek**

```php
	public function test_room_sold_whole_takes_every_bed_regardless_of_companion(): void {
		$this->assertSame( 2, RoomOccupancy::seats( 2, true, true ) );
		$this->assertSame( 2, RoomOccupancy::seats( 2, true, false ) );
	}

	public function test_room_sold_per_bed_takes_one_bed_alone_and_two_with_a_companion(): void {
		// To jest przypadek prowadzący 2: dwójka sprzedawana na łóżka.
		$this->assertSame( 1, RoomOccupancy::seats( 2, false, false ) );
		$this->assertSame( 2, RoomOccupancy::seats( 2, false, true ) );
	}

	public function test_single_room_takes_one_bed(): void {
		$this->assertSame( 1, RoomOccupancy::seats( 1, false, false ) );
	}

	public function test_room_without_declared_beds_keeps_the_legacy_fallback(): void {
		$this->assertSame( 1, RoomOccupancy::seats( 0, false, false ) );
		$this->assertSame( 2, RoomOccupancy::seats( 0, false, true ) );
		// `whole_only` bez liczby łóżek nie ma czego pomnożyć — fallback wygrywa.
		$this->assertSame( 1, RoomOccupancy::seats( 0, true, false ) );
	}
```

- [ ] **Krok 2: Uruchom — FAIL** (`ArgumentCountError`/zły wynik)

- [ ] **Krok 3: `RoomOccupancy`**

```php
	/**
	 * Ile łóżek zajmuje jedna rezerwacja w pokoju o danej konfiguracji.
	 * JEDYNY dom tej reguły — delegują tu `RoomType::seats`,
	 * `AccommodationConfig::seatsFor`, `CapacityLimits::seatsForSlot`
	 * oraz lustro `seatsFor` w assets/public/form.js.
	 *
	 * @param int  $beds       Liczba łóżek w pokoju (0 = nieustawione).
	 * @param bool $whole_only Czy pokój sprzedawany jest tylko w całości.
	 * @param bool $companion  Czy zgłoszenie zawiera osobę towarzyszącą.
	 */
	public static function seats( int $beds, bool $whole_only, bool $companion ): int {
		return $whole_only && $beds > 0 ? $beds : ( $companion ? 2 : 1 );
	}
```

- [ ] **Krok 4: Test typu pokoju — mapowanie legacy i niespójności**

```php
	public function test_legacy_two_person_room_becomes_two_beds_sold_whole(): void {
		$room = RoomType::fromArray( array( 'key' => 'd', 'occupancy' => 2 ) );

		$this->assertSame( 2, $room->beds );
		$this->assertTrue( $room->wholeOnly );
		// Zachowanie bit w bit jak dziś: wymaga towarzyszącej, zjada 2 łóżka.
		$this->assertSame( 'companion_required', $room->companionMismatch( false ) );
		$this->assertSame( 2, $room->seats( true ) );
	}

	public function test_legacy_single_room_becomes_one_bed(): void {
		$room = RoomType::fromArray( array( 'key' => 's', 'occupancy' => 1 ) );

		$this->assertSame( 1, $room->beds );
		$this->assertFalse( $room->wholeOnly );
		$this->assertSame( 'companion_not_allowed', $room->companionMismatch( true ) );
		$this->assertNull( $room->companionMismatch( false ) );
	}

	public function test_legacy_unset_room_protests_about_nothing(): void {
		$room = RoomType::fromArray( array( 'key' => 'u', 'occupancy' => 0 ) );

		$this->assertSame( 0, $room->beds );
		$this->assertNull( $room->companionMismatch( false ) );
		$this->assertNull( $room->companionMismatch( true ) );
	}

	public function test_two_bed_room_sold_per_bed_accepts_a_lone_registrant(): void {
		$room = RoomType::fromArray( array( 'key' => 'd', 'beds' => 2, 'whole_only' => false ) );

		// Przypadek prowadzący 2 — dziś odrzucany jako companion_required.
		$this->assertNull( $room->companionMismatch( false ) );
		$this->assertSame( 1, $room->seats( false ) );
		$this->assertSame( 2, $room->seats( true ) );
	}

	public function test_whole_only_is_normalised_away_for_a_one_bed_room(): void {
		$room = RoomType::fromArray( array( 'key' => 's', 'beds' => 1, 'whole_only' => true ) );

		// Inaczej pokój żądałby towarzyszącej I jednocześnie jej zabraniał,
		// czyli byłby niewybieralny na każdy stan checkboxa.
		$this->assertFalse( $room->wholeOnly );
		$this->assertNull( $room->companionMismatch( false ) );
		$this->assertSame( 'companion_not_allowed', $room->companionMismatch( true ) );
	}

	public function test_new_keys_win_over_the_legacy_one(): void {
		$room = RoomType::fromArray(
			array( 'key' => 'd', 'occupancy' => 2, 'beds' => 2, 'whole_only' => false )
		);

		$this->assertFalse( $room->wholeOnly );
	}

	public function test_beds_out_of_range_is_rejected(): void {
		$this->expectException( SchemaException::class );
		RoomType::fromArray( array( 'key' => 'd', 'beds' => 3 ) );
	}

	public function test_to_array_writes_the_new_keys(): void {
		$room = RoomType::fromArray( array( 'key' => 'd', 'occupancy' => 2 ) );

		$this->assertSame(
			array( 'key' => 'd', 'label' => 'd', 'roommate_field' => false, 'beds' => 2, 'whole_only' => true ),
			$room->toArray()
		);
	}
```

- [ ] **Krok 5: Uruchom — FAIL**

- [ ] **Krok 6: `RoomType`**

```php
	/**
	 * @param string $key           Unikalny klucz typu pokoju.
	 * @param string $label         Etykieta prezentowana użytkownikowi.
	 * @param bool   $roommateField Czy formularz ma pytać o preferencje współlokatora.
	 * @param int    $beds          Liczba łóżek: 0 = nieustawione, 1, 2.
	 * @param bool   $wholeOnly     Czy pokój sprzedawany jest tylko w całości.
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly bool $roommateField = false,
		public readonly int $beds = 0,
		public readonly bool $wholeOnly = false
	) {
	}

	public static function fromArray( array $data ): self {
		if ( ! isset( $data['key'] ) || '' === (string) $data['key'] ) {
			throw new SchemaException( 'Typ pokoju wymaga niepustego klucza.' );
		}

		// Legacy `occupancy` czytamy na stałe: konfiguracje na istniejących stronach
		// nie wymagają migracji ani wejścia do edytora. occupancy=2 znaczyło
		// jednocześnie „dwa łóżka" i „tylko w całości" — stąd rozbicie na dwa pola.
		$legacy = (int) ( $data['occupancy'] ?? 0 );
		$beds   = (int) ( $data['beds'] ?? $legacy );

		if ( $beds < 0 || $beds > 2 ) {
			throw new SchemaException(
				sprintf( 'Pokój "%s" może mieć 1 albo 2 łóżka (0 = nieustawione).', (string) $data['key'] )
			);
		}

		$whole = array_key_exists( 'whole_only', $data )
			? (bool) $data['whole_only']
			: 2 === $legacy;

		// Cały pokój jednoosobowy to jego jedno łóżko; bez tej normalizacji taki pokój
		// żądałby towarzyszącej i zabraniał jej naraz.
		if ( 1 === $beds ) {
			$whole = false;
		}

		return new self(
			(string) $data['key'],
			(string) ( $data['label'] ?? $data['key'] ),
			(bool) ( $data['roommate_field'] ?? false ),
			$beds,
			$whole
		);
	}

	public function toArray(): array {
		return array(
			'key'            => $this->key,
			'label'          => $this->label,
			'roommate_field' => $this->roommateField,
			'beds'           => $this->beds,
			'whole_only'     => $this->wholeOnly,
		);
	}

	public function seats( bool $companion ): int {
		return RoomOccupancy::seats( $this->beds, $this->wholeOnly, $companion );
	}

	/**
	 * Kod błędu, gdy para pokój/osoba towarzysząca jest niespójna, albo null.
	 * Pokój bez zadeklarowanych łóżek nie protestuje nigdy (zgodność w tył).
	 */
	public function companionMismatch( bool $companion ): ?string {
		if ( 0 === $this->beds ) {
			return null;
		}
		if ( $companion && 1 === $this->beds ) {
			return 'companion_not_allowed';
		}
		if ( ! $companion && $this->wholeOnly ) {
			return 'companion_required';
		}

		return null;
	}
```

- [ ] **Krok 7: Przenieś wołających**

`AccommodationConfig`:

```php
	public function seatsFor( string $room_key, bool $companion ): int {
		$room = $this->room( $room_key );

		return null === $room
			? RoomOccupancy::seats( 0, false, $companion )
			: $room->seats( $companion );
	}

	/**
	 * Mapa slot "pakiet|pokój" => konfiguracja pokoju potrzebna do liczenia łóżek.
	 *
	 * @return array<string,array{beds:int,whole:bool}>
	 */
	public function slotRooms(): array {
		$map = array();

		foreach ( $this->items as $item ) {
			$room                    = $this->room( $item->roomKey );
			$map[ $item->slotKey() ] = array(
				'beds'  => null === $room ? 0 : $room->beds,
				'whole' => null !== $room && $room->wholeOnly,
			);
		}

		return $map;
	}
```

`fromArray` — reguła sprzeczności (zastępuje dzisiejsze zerowanie `occupancy`):

```php
		// Pokój „tylko w całości" wymaga osoby towarzyszącej, więc przy wyłączonym
		// przełączniku byłby trwale niewybieralny. Rozstrzygamy sprzeczność w jednym
		// miejscu, żeby pojemność, cena, walidacja i render widziały to samo.
		// RÓŻNICA wobec poprzedniej reguły: zerujemy TRYB, nie liczbę łóżek —
		// liczba łóżek jest potrzebna pulom dobowym do pakowania.
		foreach ( (array) ( $config['rooms'] ?? array() ) as $room ) {
			$room = (array) $room;
			if ( ! $companion_enabled ) {
				$room['whole_only'] = false;
				unset( $room['occupancy'] );
				$room['beds'] = (int) ( $room['beds'] ?? 0 );
			}
			$rooms[] = RoomType::fromArray( $room );
		}
```

> `unset( $room['occupancy'] )` jest obowiązkowe: bez tego legacy `occupancy = 2`
> odtworzyłoby `whole_only = true` w `fromArray` typu pokoju i zignorowało wymuszenie.

`CapacityLimits`:

```php
	public function __construct(
		public readonly ?int $globalLimit,
		private readonly array $perType,
		private readonly array $perSlot,
		public readonly bool $waitlistEnabled = true,
		public readonly bool $companionCountsEvent = false,
		/** @var array<string,array{beds:int,whole:bool}> */
		private readonly array $perSlotRooms = array()
	) {
	}

	public function seatsForSlot( string $key, bool $companion ): int {
		$room = $this->perSlotRooms[ $key ] ?? array( 'beds' => 0, 'whole' => false );

		return RoomOccupancy::seats( (int) $room['beds'], (bool) $room['whole'], $companion );
	}
```

- [ ] **Krok 8: Test reguły sprzeczności w konfiguracji**

```php
	public function test_disabled_companion_clears_whole_only_but_keeps_the_beds(): void {
		$config = AccommodationConfig::fromArray(
			array(
				'companion_enabled' => false,
				'rooms'             => array( array( 'key' => 'd', 'beds' => 2, 'whole_only' => true ) ),
			)
		);

		$room = $config->room( 'd' );
		$this->assertNotNull( $room );
		$this->assertFalse( $room->wholeOnly );
		$this->assertSame( 2, $room->beds );
		// Tryb zgodności liczy miejsca, więc samotna rezerwacja zjada jedno — jak dziś.
		$this->assertSame( 1, $config->seatsFor( 'd', false ) );
	}

	public function test_disabled_companion_also_clears_the_legacy_two_person_room(): void {
		$config = AccommodationConfig::fromArray(
			array(
				'companion_enabled' => false,
				'rooms'             => array( array( 'key' => 'd', 'occupancy' => 2 ) ),
			)
		);

		$room = $config->room( 'd' );
		$this->assertNotNull( $room );
		$this->assertFalse( $room->wholeOnly );
		$this->assertSame( 1, $config->seatsFor( 'd', false ) );
	}
```

- [ ] **Krok 9: Uruchom CAŁY unit — PASS**

Testy `occupancy` z B-occupancy przestaną się kompilować tam, gdzie wołają
`RoomOccupancy::seats` z dwoma argumentami albo czytają `$room->occupancy` — przepisz je
na nową parę, **nie usuwaj**: każdy z nich pilnuje zachowania, które ma zostać.

- [ ] **Krok 10: Uruchom integration — PASS** (`FormRenderer` jeszcze czyta `occupancy`;
      jeśli pada na brakującej właściwości, popraw odczyt na `beds`/`wholeOnly`
      minimalnie — pełny render idzie w Tasku 10)

- [ ] **Krok 11: `phpcs` + `composer analyse`**

- [ ] **Krok 12: Commit**

```bash
git commit -am "refactor(accommodation): split a room's occupancy into beds and whole-room-only"
```

---

## Task 3: Doby i limity dobowe w konfiguracji

**Files:**
- Modify: `src/Domain/Accommodation/AccommodationConfig.php`
- Create: `tests/Unit/Domain/Accommodation/NightConfigTest.php`

**Interfaces:**
- Consumes: `Night` i `Package::usesNightPools()` (Task 1); `RoomType::$beds` (Task 2).
- Produces:
  - `nights(): Night[]`, `nightLabel( string $key ): string`,
  - `nightsForPackage( string $package_key ): string[]`,
  - `usesNightPools( string $package_key ): bool`,
  - `nightRoomCapacity(): array<string,int>` kluczowane `"doba|pokój"`, **w pokojach**,
  - `roomBeds(): array<string,int>` kluczowane kluczem pokoju,
  - `packageNights(): array<string,string[]>`.

- [ ] **Krok 1: Testy**

```php
	private function config(): AccommodationConfig {
		return AccommodationConfig::fromArray(
			array(
				'companion_enabled' => true,
				'nights'            => array(
					array( 'key' => 'n1819', 'label' => '18/19 grudnia' ),
					array( 'key' => 'n1920', 'label' => '19/20 grudnia' ),
				),
				'rooms'             => array(
					array( 'key' => 'single', 'beds' => 1 ),
					array( 'key' => 'double', 'beds' => 2 ),
				),
				'packages'          => array(
					array( 'key' => 'p18', 'nights' => array( 'n1819' ) ),
					array( 'key' => 'p2', 'nights' => array( 'n1819', 'n1920' ) ),
					array( 'key' => 'legacy' ),
				),
				'inventory'         => array(
					array( 'package' => 'p2', 'room' => 'double', 'capacity' => 20, 'price' => 300.0 ),
					array( 'package' => 'legacy', 'room' => 'double', 'capacity' => 7, 'price' => 300.0 ),
				),
				'night_inventory'   => array(
					array( 'night' => 'n1819', 'room' => 'single', 'capacity' => 10 ),
					array( 'night' => 'n1819', 'room' => 'double', 'capacity' => 31 ),
					array( 'night' => 'n1920', 'room' => 'double', 'capacity' => 43 ),
				),
			)
		);
	}

	public function test_nights_are_exposed_with_labels(): void {
		$this->assertSame( '18/19 grudnia', $this->config()->nightLabel( 'n1819' ) );
	}

	public function test_unknown_night_label_falls_back_to_the_raw_key(): void {
		$this->assertSame( 'n9', $this->config()->nightLabel( 'n9' ) );
	}

	public function test_night_pools_are_per_package(): void {
		$config = $this->config();

		$this->assertTrue( $config->usesNightPools( 'p2' ) );
		$this->assertFalse( $config->usesNightPools( 'legacy' ) );
		$this->assertFalse( $config->usesNightPools( 'nieznany' ) );
		$this->assertSame( array( 'n1819', 'n1920' ), $config->nightsForPackage( 'p2' ) );
	}

	public function test_night_capacity_is_keyed_by_night_and_room(): void {
		$this->assertSame(
			array( 'n1819|single' => 10, 'n1819|double' => 31, 'n1920|double' => 43 ),
			$this->config()->nightRoomCapacity()
		);
	}

	public function test_missing_night_room_line_is_simply_absent(): void {
		// Brak wiersza = brak pojemności = zamknięte. Bez wyjątku: ta konfiguracja
		// jest osiągalna z admina (dodanie doby przed wypełnieniem macierzy).
		$this->assertArrayNotHasKey( 'n1920|single', $this->config()->nightRoomCapacity() );
	}

	public function test_room_beds_map_feeds_the_packing_arithmetic(): void {
		$this->assertSame( array( 'single' => 1, 'double' => 2 ), $this->config()->roomBeds() );
	}

	public function test_night_inventory_pointing_at_an_unknown_night_is_rejected(): void {
		$this->expectException( SchemaException::class );
		AccommodationConfig::fromArray(
			array(
				'rooms'           => array( array( 'key' => 'd', 'beds' => 2 ) ),
				'night_inventory' => array( array( 'night' => 'brak', 'room' => 'd', 'capacity' => 1 ) ),
			)
		);
	}

	public function test_package_pointing_at_an_unknown_night_is_rejected(): void {
		$this->expectException( SchemaException::class );
		AccommodationConfig::fromArray(
			array( 'packages' => array( array( 'key' => 'p', 'nights' => array( 'brak' ) ) ) )
		);
	}

	public function test_duplicate_night_room_line_is_rejected(): void {
		$this->expectException( SchemaException::class );
		AccommodationConfig::fromArray(
			array(
				'nights'          => array( array( 'key' => 'n1' ) ),
				'rooms'           => array( array( 'key' => 'd', 'beds' => 2 ) ),
				'night_inventory' => array(
					array( 'night' => 'n1', 'room' => 'd', 'capacity' => 1 ),
					array( 'night' => 'n1', 'room' => 'd', 'capacity' => 2 ),
				),
			)
		);
	}

	public function test_duplicate_night_key_is_rejected(): void {
		$this->expectException( SchemaException::class );
		AccommodationConfig::fromArray(
			array( 'nights' => array( array( 'key' => 'n1' ), array( 'key' => 'n1' ) ) )
		);
	}

	public function test_negative_night_capacity_is_rejected(): void {
		$this->expectException( SchemaException::class );
		AccommodationConfig::fromArray(
			array(
				'nights'          => array( array( 'key' => 'n1' ) ),
				'rooms'           => array( array( 'key' => 'd', 'beds' => 2 ) ),
				'night_inventory' => array( array( 'night' => 'n1', 'room' => 'd', 'capacity' => -1 ) ),
			)
		);
	}
```

- [ ] **Krok 2: Uruchom — FAIL**

- [ ] **Krok 3: Implementacja**

`fromArray`: po zbudowaniu `$rooms` i `$packages` zbuduj `$nights` (z kontrolą
duplikatów), potem zwaliduj `packages[].nights` względem kluczy dób, potem zbuduj
`$night_capacity` z kontrolą nieznanej doby/pokoju, duplikatu pary i ujemnej pojemności.
Konstruktor dostaje dwa nowe parametry (`$nights`, `$nightCapacity`) — oba z domyślną
pustą tablicą, żeby nie łamać istniejących wywołań `new self(...)` w testach.

Akcesory:

```php
	/** @return Night[] */
	public function nights(): array {
		return $this->nights;
	}

	public function nightLabel( string $key ): string {
		foreach ( $this->nights as $night ) {
			if ( $night->key === $key ) {
				return $night->label;
			}
		}

		return $key;
	}

	/** @return string[] */
	public function nightsForPackage( string $package_key ): array {
		foreach ( $this->packages as $package ) {
			if ( $package->key === $package_key ) {
				return $package->nights;
			}
		}

		return array();
	}

	public function usesNightPools( string $package_key ): bool {
		return array() !== $this->nightsForPackage( $package_key );
	}

	/** @return array<string,int> */
	public function nightRoomCapacity(): array {
		return $this->nightCapacity;
	}

	/** @return array<string,int> */
	public function roomBeds(): array {
		$map = array();

		foreach ( $this->rooms as $room ) {
			$map[ $room->key ] = $room->beds;
		}

		return $map;
	}

	/** @return array<string,string[]> */
	public function packageNights(): array {
		$map = array();

		foreach ( $this->packages as $package ) {
			$map[ $package->key ] = $package->nights;
		}

		return $map;
	}
```

`toArray()` dokłada `'nights'` i `'night_inventory'`.

- [ ] **Krok 4: Uruchom unit — PASS.** Krok 5: `phpcs` + `analyse`. Krok 6: Commit

```bash
git commit -am "feat(accommodation): read nights and per-night room limits from the configuration"
```

---

## Task 4: Arytmetyka pokoi (`NightOccupancy`) + histogram w snapshotcie

**Files:**
- Create: `src/Domain/Capacity/NightOccupancy.php`
- Create: `tests/Unit/Domain/Capacity/NightOccupancyTest.php`
- Modify: `src/Domain/Capacity/OccupancySnapshot.php`
- Modify: `tests/Unit/Domain/Capacity/OccupancySnapshotTest.php` (jeśli istnieje)

**Interfaces:**
- Produces:
  - `NightOccupancy::freeBeds( array $seat_counts, int $beds, int $limit ): int`
  - `NightOccupancy::roomsUsed( array $seat_counts, int $beds ): int`
  - gdzie `$seat_counts` to mapa `seats => liczba rezerwacji`
  - `OccupancySnapshot::__construct( ..., array $nightHistogram = array() )` oraz
    `seatCountsFor( string $night_room_key ): array<int,int>`; histogram ma kształt
    `array<string, array<int,int>>` kluczowany `"doba|pokój"`.

- [ ] **Krok 1: Testy arytmetyki** — to serce zmiany, testy piszemy na przykładach
      prowadzących i na obu błędach poprzednich wersji projektu

```php
<?php
declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Capacity;

use EvReg\Domain\Capacity\NightOccupancy;
use PHPUnit\Framework\TestCase;

final class NightOccupancyTest extends TestCase {

	public function test_empty_night_offers_every_bed(): void {
		$this->assertSame( 62, NightOccupancy::freeBeds( array(), 2, 31 ) );
		$this->assertSame( 0, NightOccupancy::roomsUsed( array(), 2 ) );
	}

	public function test_a_pair_takes_a_whole_room(): void {
		// Jedna rezerwacja na 2 łóżka w dwójce = jeden pokój zajęty na wyłączność.
		$taken = array( 2 => 1 );

		$this->assertSame( 1, NightOccupancy::roomsUsed( $taken, 2 ) );
		$this->assertSame( 60, NightOccupancy::freeBeds( $taken, 2, 31 ) );
	}

	public function test_a_lone_bed_leaves_the_second_one_sellable(): void {
		// Błąd v1: samotny uczestnik zjadał cały pokój.
		$taken = array( 1 => 1 );

		$this->assertSame( 1, NightOccupancy::roomsUsed( $taken, 2 ) );
		$this->assertSame( 61, NightOccupancy::freeBeds( $taken, 2, 31 ) );
	}

	public function test_lone_beds_are_packed_two_to_a_room(): void {
		$this->assertSame( 1, NightOccupancy::roomsUsed( array( 1 => 2 ), 2 ) );
		// Trzy samotne łóżka nie mieszczą się w jednym pokoju.
		$this->assertSame( 2, NightOccupancy::roomsUsed( array( 1 => 3 ), 2 ) );
	}

	public function test_a_pair_never_gets_two_loose_beds_from_two_rooms(): void {
		// Błąd v2: pula w łóżkach pokazywała 2 wolne łóżka i wpuszczała parę,
		// choć każde z nich leżało w innym, już zajętym pokoju.
		// Limit 2 pokoje, w każdym po jednym samotnym łóżku:
		$taken = array( 1 => 2 );

		// Dwa luźne łóżka pakują się do JEDNEGO pokoju, więc drugi jest wolny
		// i para ma gdzie spać — dokładnie dwa wolne łóżka, w jednym pokoju.
		$this->assertSame( 2, NightOccupancy::freeBeds( $taken, 2, 2 ) );
		$this->assertSame( 1, NightOccupancy::roomsUsed( $taken, 2 ) );

		// Trzy samotne łóżka zajmują dwa pokoje, więc dla pary nie ma już pokoju,
		// a jedno wolne łóżko to za mało na jej dwa.
		$this->assertSame( 1, NightOccupancy::freeBeds( array( 1 => 3 ), 2, 2 ) );
	}

	public function test_leading_example_two_mixes_pairs_and_singles_in_one_pool(): void {
		// 31 dwójek: 10 par (10 pokoi) + 7 singli (4 pokoje) = 14 pokoi zajętych.
		$taken = array( 2 => 10, 1 => 7 );

		$this->assertSame( 14, NightOccupancy::roomsUsed( $taken, 2 ) );
		// (31 - 10) * 2 - 7 = 35 wolnych łóżek.
		$this->assertSame( 35, NightOccupancy::freeBeds( $taken, 2, 31 ) );
	}

	public function test_single_rooms_degrade_to_counting_reservations(): void {
		$this->assertSame( 3, NightOccupancy::roomsUsed( array( 1 => 3 ), 1 ) );
		$this->assertSame( 7, NightOccupancy::freeBeds( array( 1 => 3 ), 1, 10 ) );
	}

	public function test_full_night_offers_nothing(): void {
		$this->assertSame( 0, NightOccupancy::freeBeds( array( 2 => 31 ), 2, 31 ) );
	}

	public function test_stored_seats_above_the_current_beds_count_as_one_room(): void {
		// Ktoś zmienił dwójkę na jedynkę pod istniejącymi parami: niedoszacowuje
		// łóżek, ale nie pokoi — pula zostaje uczciwa, nie ujemna.
		$this->assertSame( 2, NightOccupancy::roomsUsed( array( 2 => 2 ), 1 ) );
		$this->assertSame( 0, NightOccupancy::freeBeds( array( 2 => 2 ), 1, 2 ) );
	}

	public function test_room_without_declared_beds_is_closed(): void {
		// Bez liczby łóżek nie ma czym pakować — zero wolnych, zamiast zgadywania.
		$this->assertSame( 0, NightOccupancy::freeBeds( array(), 0, 31 ) );
	}

	public function test_free_beds_never_goes_negative(): void {
		$this->assertSame( 0, NightOccupancy::freeBeds( array( 2 => 40 ), 2, 31 ) );
	}
}
```

- [ ] **Krok 2: Uruchom — FAIL**

- [ ] **Krok 3: `NightOccupancy`**

```php
<?php
/**
 * Arytmetyka pul dobowych: ile pokoi zajmuje popyt i ile łóżek zostało.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Capacity;

/**
 * JEDYNY dom reguły pakowania. Limit doby jest w POKOJACH; rezerwacja potrzebująca
 * wszystkich łóżek pokoju zajmuje pokój na wyłączność, a rezerwacje potrzebujące mniej
 * są pakowane po `beds` do pokoju. Lustro tej reguły siedzi w assets/public/form.js.
 */
final class NightOccupancy {

	/**
	 * Rozbija histogram na rezerwacje „pełne" (zajmujące pokój na wyłączność)
	 * i „luźne" (pakowalne łóżka).
	 *
	 * @param array<int,int> $seat_counts Mapa: liczba łóżek => liczba rezerwacji.
	 *
	 * @return array{0:int,1:int} Pełne rezerwacje i suma luźnych łóżek.
	 */
	private static function split( array $seat_counts, int $beds ): array {
		$full  = 0;
		$loose = 0;

		foreach ( $seat_counts as $seats => $count ) {
			$seats = (int) $seats;
			$count = (int) $count;

			if ( $seats >= $beds ) {
				$full += $count;
				continue;
			}

			$loose += $seats * $count;
		}

		return array( $full, $loose );
	}

	/**
	 * Ile pokoi zajmuje zadany popyt (do panelu obłożenia).
	 *
	 * @param array<int,int> $seat_counts Mapa: liczba łóżek => liczba rezerwacji.
	 */
	public static function roomsUsed( array $seat_counts, int $beds ): int {
		if ( $beds < 1 ) {
			return 0;
		}

		[ $full, $loose ] = self::split( $seat_counts, $beds );

		return $full + (int) ceil( $loose / $beds );
	}

	/**
	 * Ile łóżek zostało w dobie. Ta liczba jest JEDNOCZEŚNIE odpowiedzią na pytanie
	 * „czy zmieści się rezerwacja potrzebująca całego pokoju": bramka
	 * `seats <= freeBeds` dla `seats = beds` wymaga całego wolnego pokoju, bo każdy
	 * częściowo zajęty pokój jest już wliczony w `loose`.
	 *
	 * @param array<int,int> $seat_counts Mapa: liczba łóżek => liczba rezerwacji.
	 * @param int            $beds        Liczba łóżek w pokoju (0 = nieustawione).
	 * @param int            $limit       Limit POKOI tej doby.
	 */
	public static function freeBeds( array $seat_counts, int $beds, int $limit ): int {
		if ( $beds < 1 ) {
			return 0;
		}

		[ $full, $loose ] = self::split( $seat_counts, $beds );

		return max( 0, ( $limit - $full ) * $beds - $loose );
	}
}
```

- [ ] **Krok 4: Test histogramu w snapshotcie**

```php
	public function test_snapshot_exposes_the_night_histogram(): void {
		$snapshot = new OccupancySnapshot( 5, array(), array(), 0, array( 'n1|d' => array( 1 => 3, 2 => 1 ) ) );

		$this->assertSame( array( 1 => 3, 2 => 1 ), $snapshot->seatCountsFor( 'n1|d' ) );
		$this->assertSame( array(), $snapshot->seatCountsFor( 'brak' ) );
	}

	public function test_snapshot_without_a_histogram_stays_backwards_compatible(): void {
		$snapshot = new OccupancySnapshot( 5 );

		$this->assertSame( array(), $snapshot->seatCountsFor( 'n1|d' ) );
	}
```

- [ ] **Krok 5: Uruchom — FAIL. Krok 6: dopisz piąty parametr i akcesor**

```php
	/**
	 * @param array<string,array<int,int>> $nightHistogram Mapa "doba|pokój" => (łóżka => liczba rezerwacji).
	 */
	public function __construct(
		private readonly int $globalCount,
		private readonly array $perType = array(),
		private readonly array $perSlot = array(),
		private readonly int $companions = 0,
		private readonly array $nightHistogram = array()
	) {
	}

	/**
	 * Surowy histogram doby. Rozbicie na pełne/luźne wymaga liczby łóżek z
	 * konfiguracji, więc robi je {@see NightOccupancy} — snapshot zostaje głupi,
	 * a repozytorium bez wiedzy o konfiguracji.
	 *
	 * @return array<int,int>
	 */
	public function seatCountsFor( string $night_room_key ): array {
		return $this->nightHistogram[ $night_room_key ] ?? array();
	}
```

- [ ] **Krok 7: unit PASS. Krok 8: `phpcs` + `analyse`. Krok 9: Commit**

```bash
git commit -am "feat(capacity): add the night packing arithmetic and the night histogram"
```

---

## Task 5: Bramka dobowa w kalkulatorze

**Files:**
- Modify: `src/Domain/Capacity/CapacityLimits.php`
- Modify: `src/Domain/Capacity/CapacityCalculator.php`
- Modify: `tests/Unit/Domain/Capacity/CapacityCalculatorTest.php`

**Interfaces:**
- Consumes: `NightOccupancy` i `OccupancySnapshot::seatCountsFor` (Task 4);
  mapy z `AccommodationConfig` (Task 3).
- Produces: `CapacityLimits` z trzema nowymi parametrami (pozycje 7–9):
  `array $nightRoomCapacity = array()`, `array $packageNights = array()`,
  `array $roomBeds = array()`, plus `nightsForPackage( string $key ): array`,
  `nightCapacity( string $night_room_key ): ?int`, `bedsForRoom( string $key ): int`.

- [ ] **Krok 1: Testy bramki**

```php
	private function nightLimits( int $capacity = 31 ): CapacityLimits {
		return new CapacityLimits(
			null,
			array(),
			array(),
			false,
			false,
			array( 'p2|double' => array( 'beds' => 2, 'whole' => false ) ),
			array( 'n1|double' => $capacity, 'n2|double' => $capacity ),
			array( 'p2' => array( 'n1', 'n2' ) ),
			array( 'double' => 2 )
		);
	}

	public function test_night_package_is_accepted_when_every_night_has_room(): void {
		$decision = ( new CapacityCalculator() )->decide(
			$this->nightLimits(),
			new OccupancySnapshot( 0 ),
			'std',
			new AccommodationSelection( 'p2', 'double' ),
			false
		);

		$this->assertSame( Outcome::Accepted, $decision->outcome );
		$this->assertTrue( $decision->accommodationGranted );
	}

	public function test_a_single_exhausted_night_closes_the_offer(): void {
		// Pierwsza doba ma miejsce, DRUGA jest pełna — bramka musi sprawdzić obie.
		$limits = new CapacityLimits(
			null, array(), array(), false, false,
			array( 'p2|double' => array( 'beds' => 2, 'whole' => false ) ),
			array( 'n1|double' => 31, 'n2|double' => 1 ),
			array( 'p2' => array( 'n1', 'n2' ) ),
			array( 'double' => 2 )
		);
		$taken = new OccupancySnapshot( 0, array(), array(), 0, array( 'n2|double' => array( 2 => 1 ) ) );

		$decision = ( new CapacityCalculator() )->decide(
			$limits, $taken, 'std', new AccommodationSelection( 'p2', 'double' ), false
		);

		$this->assertSame( Outcome::Accepted, $decision->outcome );
		$this->assertFalse( $decision->accommodationGranted );
		$this->assertSame( 'accommodation_full', $decision->reason );
	}

	public function test_a_lone_registrant_fits_next_to_another_lone_registrant(): void {
		$limits = $this->nightLimits( 1 );
		$taken  = new OccupancySnapshot( 0, array(), array(), 0, array( 'n1|double' => array( 1 => 1 ), 'n2|double' => array( 1 => 1 ) ) );

		$decision = ( new CapacityCalculator() )->decide(
			$limits, $taken, 'std', new AccommodationSelection( 'p2', 'double' ), false
		);

		$this->assertTrue( $decision->accommodationGranted );
	}

	public function test_a_pair_does_not_fit_into_a_half_occupied_last_room(): void {
		$limits = $this->nightLimits( 1 );
		$taken  = new OccupancySnapshot( 0, array(), array(), 0, array( 'n1|double' => array( 1 => 1 ), 'n2|double' => array( 1 => 1 ) ) );

		$decision = ( new CapacityCalculator() )->decide(
			$limits, $taken, 'std', new AccommodationSelection( 'p2', 'double' ), true
		);

		$this->assertFalse( $decision->accommodationGranted );
	}

	public function test_missing_night_capacity_closes_the_offer(): void {
		$limits = new CapacityLimits(
			null, array(), array(), false, false,
			array( 'p2|double' => array( 'beds' => 2, 'whole' => false ) ),
			array(),
			array( 'p2' => array( 'n1' ) ),
			array( 'double' => 2 )
		);

		$decision = ( new CapacityCalculator() )->decide(
			$limits, new OccupancySnapshot( 0 ), 'std', new AccommodationSelection( 'p2', 'double' ), false
		);

		$this->assertFalse( $decision->accommodationGranted );
	}

	public function test_package_without_nights_keeps_using_the_slot_pool(): void {
		// Tryb zgodności: istniejące testy slotowe muszą dalej przechodzić bez zmian.
		$limits = new CapacityLimits( null, array(), array( 'legacy|double' => 1 ), false, false, array( 'legacy|double' => array( 'beds' => 2, 'whole' => false ) ) );

		$decision = ( new CapacityCalculator() )->decide(
			$limits, new OccupancySnapshot( 0 ), 'std', new AccommodationSelection( 'legacy', 'double' ), false
		);

		$this->assertTrue( $decision->accommodationGranted );
	}
```

- [ ] **Krok 2: Uruchom — FAIL**

- [ ] **Krok 3: `CapacityLimits`** — trzy parametry + trzy akcesory

```php
		/** @var array<string,int> */
		private readonly array $nightRoomCapacity = array(),
		/** @var array<string,string[]> */
		private readonly array $packageNights = array(),
		/** @var array<string,int> */
		private readonly array $roomBeds = array()
```

```php
	/** @return string[] */
	public function nightsForPackage( string $key ): array {
		return $this->packageNights[ $key ] ?? array();
	}

	public function nightCapacity( string $night_room_key ): ?int {
		return $this->nightRoomCapacity[ $night_room_key ] ?? null;
	}

	public function bedsForRoom( string $key ): int {
		return $this->roomBeds[ $key ] ?? 0;
	}
```

- [ ] **Krok 4: `CapacityCalculator::decide`** — bramka noclegowa rozwidla się na dwie,
      reszta metody bez zmian

```php
		$nights = $limits->nightsForPackage( $selection->packageKey );

		if ( array() !== $nights ) {
			return $this->decideNights( $limits, $taken, $selection, $companion, $nights );
		}

		$slot       = $selection->slotKey();
		$slot_limit = $limits->forSlot( $slot );
		$acc_seats  = $limits->seatsForSlot( $slot, $companion );

		if ( null === $slot_limit || $taken->forSlot( $slot ) + $acc_seats > $slot_limit ) {
			return new CapacityDecision( Outcome::Accepted, false, 'accommodation_full' );
		}

		return new CapacityDecision( Outcome::Accepted, true );
	}

	/**
	 * Bramka dla pakietu rozliczanego z pul dobowych: nocleg jest przyznany tylko
	 * wtedy, gdy mieści się w KAŻDEJ dobie pakietu. Pierwsza wyczerpana kończy
	 * sprawdzanie.
	 *
	 * @param string[] $nights Doby pakietu.
	 */
	private function decideNights(
		CapacityLimits $limits,
		OccupancySnapshot $taken,
		AccommodationSelection $selection,
		bool $companion,
		array $nights
	): CapacityDecision {
		$beds  = $limits->bedsForRoom( $selection->roomKey );
		$seats = $limits->seatsForSlot( $selection->slotKey(), $companion );

		foreach ( $nights as $night ) {
			$key   = $night . '|' . $selection->roomKey;
			$limit = $limits->nightCapacity( $key );

			if ( null === $limit ) {
				return new CapacityDecision( Outcome::Accepted, false, 'accommodation_full' );
			}

			if ( $seats > NightOccupancy::freeBeds( $taken->seatCountsFor( $key ), $beds, $limit ) ) {
				return new CapacityDecision( Outcome::Accepted, false, 'accommodation_full' );
			}
		}

		return new CapacityDecision( Outcome::Accepted, true );
	}
```

- [ ] **Krok 5: unit PASS (cały suite). Krok 6: `phpcs` + `analyse`. Krok 7: Commit**

```bash
git commit -am "feat(capacity): gate a night package on every one of its nights"
```

---

## Task 6: Dostępność slotu świadoma dób

**Files:**
- Modify: `src/Domain/Accommodation/SlotAvailability.php`
- Modify: `tests/Unit/Domain/Accommodation/SlotAvailabilityTest.php`

**Interfaces:**
- Consumes: `AccommodationConfig::nightRoomCapacity/packageNights/roomBeds` (Task 3),
  `NightOccupancy` + histogram (Task 4).
- Produces: `left()`/`isFull()` bez zmian w sygnaturach; dla oferty z dobami `left()`
  zwraca **minimum wolnych łóżek po dobach**.

- [ ] **Krok 1: Testy**

```php
	public function test_night_offer_reports_the_tightest_night(): void {
		$config = AccommodationConfig::fromArray(
			array(
				'nights'          => array( array( 'key' => 'n1' ), array( 'key' => 'n2' ) ),
				'rooms'           => array( array( 'key' => 'd', 'beds' => 2 ) ),
				'packages'        => array( array( 'key' => 'p2', 'nights' => array( 'n1', 'n2' ) ) ),
				'inventory'       => array( array( 'package' => 'p2', 'room' => 'd', 'capacity' => 99, 'price' => 1.0 ) ),
				'night_inventory' => array(
					array( 'night' => 'n1', 'room' => 'd', 'capacity' => 10 ),
					array( 'night' => 'n2', 'room' => 'd', 'capacity' => 2 ),
				),
			)
		);
		$taken = new OccupancySnapshot( 0, array(), array(), 0, array( 'n2|d' => array( 1 => 3 ) ) );

		$availability = SlotAvailability::fromSnapshot( $config, $taken );

		// n1: 20 wolnych łóżek, n2: (2-0)*2-3 = 1. Minimum rządzi.
		$this->assertSame( 1, $availability->left( 'p2|d' ) );
		$this->assertFalse( $availability->isFull( 'p2|d' ) );
		// Towarzysząca potrzebuje dwóch łóżek — „za mało miejsc".
		$this->assertTrue( $availability->isFull( 'p2|d', 2 ) );
	}

	public function test_night_offer_over_a_room_without_beds_is_full(): void {
		$config = AccommodationConfig::fromArray(
			array(
				'nights'          => array( array( 'key' => 'n1' ) ),
				'rooms'           => array( array( 'key' => 'x' ) ),
				'packages'        => array( array( 'key' => 'p', 'nights' => array( 'n1' ) ) ),
				'inventory'       => array( array( 'package' => 'p', 'room' => 'x', 'capacity' => 5, 'price' => 1.0 ) ),
				'night_inventory' => array( array( 'night' => 'n1', 'room' => 'x', 'capacity' => 5 ) ),
			)
		);

		$availability = SlotAvailability::fromSnapshot( $config, new OccupancySnapshot( 0 ) );

		$this->assertSame( 0, $availability->left( 'p|x' ) );
		$this->assertTrue( $availability->isFull( 'p|x' ) );
	}
```

Dopisz też test, że **oferta bez dób zachowuje dzisiejsze liczby** (skopiuj istniejący
przypadek slotowy, jeśli jest; inaczej napisz go teraz).

- [ ] **Krok 2: FAIL. Krok 3: `fromSnapshot`**

```php
	public static function fromSnapshot( AccommodationConfig $config, OccupancySnapshot $taken ): self {
		$left     = array();
		$capacity = $config->capacities();
		$beds     = $config->roomBeds();

		foreach ( $config->items() as $item ) {
			$slot   = $item->slotKey();
			$nights = $config->nightsForPackage( $item->packageKey );

			if ( array() === $nights ) {
				$left[ $slot ] = max( 0, ( $capacity[ $slot ] ?? 0 ) - $taken->forSlot( $slot ) );
				continue;
			}

			$room_beds = $beds[ $item->roomKey ] ?? 0;
			$min       = null;

			foreach ( $nights as $night ) {
				$key   = $night . '|' . $item->roomKey;
				$limit = $config->nightRoomCapacity()[ $key ] ?? 0;
				$free  = NightOccupancy::freeBeds( $taken->seatCountsFor( $key ), $room_beds, $limit );
				$min   = null === $min ? $free : min( $min, $free );
			}

			$left[ $slot ] = (int) $min;
		}

		return new self( $left );
	}
```

- [ ] **Krok 4: unit PASS. Krok 5: `phpcs` + `analyse`. Krok 6: Commit**

```bash
git commit -am "feat(accommodation): report availability of a night offer as its tightest night"
```

---

## Task 7: Migracja v7 + SQL dób

**Files:**
- Modify: `src/Persistence/Migrations.php`
- Modify: `src/Persistence/Uninstaller.php`
- Modify: `src/Persistence/RegistrationRepository.php`
- Modify: `tests/Integration/Persistence/MigrationsTest.php`
- Create: `tests/Integration/Persistence/BookingNightsRepositoryTest.php`

**Interfaces:**
- Produces:
  - tabela `evreg_booking_nights` (kształt w specu), `DB_VERSION = 7`
  - `insertAccommodationBooking( int $registration_id, AccommodationSelection $selection, int $seats, float $price, string $package_label, string $room_label, array $nights ): void`
    gdzie `$nights` to lista `array{key:string,label:string}`
  - `deleteAccommodationBooking( int $registration_id ): void` kasuje też wiersze dób
  - `occupancy()/occupancyExcluding()` zwracają snapshot z histogramem
  - `bookingNightsFor( array $registration_ids ): array<int,array<int,array{key:string,label:string}>>`
  - `registrationsMissingNights( int $event_id, string $package_key, array $nights ): array`

- [ ] **Krok 1: Test migracji** — dopisz do `MigrationsTest`

```php
	public function test_booking_nights_table_exists_after_install(): void {
		global $wpdb;
		$table = Migrations::table( 'booking_nights' );

		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
	}
```

- [ ] **Krok 2: FAIL. Krok 3: `Migrations`** — `DB_VERSION = 7`, nowy `CREATE TABLE`
      dokładnie jak w specu, dopisany do listy przekazywanej `dbDelta`.
      `Uninstaller::TABLES` dostaje `'booking_nights'`.

- [ ] **Krok 4: Test histogramu z bazy**

```php
	public function test_occupancy_builds_the_night_histogram(): void {
		// Dwie rezerwacje w tej samej dobie: para (2 łóżka) i singiel (1).
		$pair   = $this->insertRegistration( 'confirmed' );
		$single = $this->insertRegistration( 'pending' );
		$this->insertBookingWithNights( $pair, 'p2', 'double', 2, array( 'n1', 'n2' ) );
		$this->insertBookingWithNights( $single, 'p2', 'double', 1, array( 'n1' ) );

		$snapshot = $this->repository->occupancy( $this->event_id, RegistrationStatus::occupying() );

		$this->assertSame( array( 2 => 1, 1 => 1 ), $snapshot->seatCountsFor( 'n1|double' ) );
		$this->assertSame( array( 2 => 1 ), $snapshot->seatCountsFor( 'n2|double' ) );
	}

	public function test_cancelled_registration_drops_out_of_the_histogram(): void {
		$id = $this->insertRegistration( 'cancelled' );
		$this->insertBookingWithNights( $id, 'p2', 'double', 2, array( 'n1' ) );

		$snapshot = $this->repository->occupancy( $this->event_id, RegistrationStatus::occupying() );

		$this->assertSame( array(), $snapshot->seatCountsFor( 'n1|double' ) );
	}

	public function test_occupancy_excluding_drops_the_edited_registration(): void {
		$id = $this->insertRegistration( 'confirmed' );
		$this->insertBookingWithNights( $id, 'p2', 'double', 2, array( 'n1' ) );

		$snapshot = $this->repository->occupancyExcluding( $this->event_id, RegistrationStatus::occupying(), $id );

		$this->assertSame( array(), $snapshot->seatCountsFor( 'n1|double' ) );
	}

	public function test_deleting_a_booking_takes_its_night_rows(): void {
		$id = $this->insertRegistration( 'confirmed' );
		$this->insertBookingWithNights( $id, 'p2', 'double', 2, array( 'n1', 'n2' ) );

		$this->repository->deleteAccommodationBooking( $id );

		$snapshot = $this->repository->occupancy( $this->event_id, RegistrationStatus::occupying() );
		$this->assertSame( array(), $snapshot->seatCountsFor( 'n1|double' ) );
		$this->assertSame( array(), $snapshot->seatCountsFor( 'n2|double' ) );
	}
```

- [ ] **Krok 5: FAIL. Krok 6: zapytania**

W `occupancy()` i `occupancyExcluding()` dopisz trzecie zapytanie i zbuduj histogram:

```php
		$night_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT n.night_key, n.room_type_key, n.seats, COUNT(*) AS c
				 FROM {$this->bookingNights()} n
				 INNER JOIN {$this->registrations()} r ON n.registration_id = r.id
				 WHERE r.event_id = %d AND r.status IN ($in)
				 GROUP BY n.night_key, n.room_type_key, n.seats",
				$args
			),
			ARRAY_A
		);

		$nights = array();

		foreach ( (array) $night_rows as $row ) {
			$key = (string) $row['night_key'] . '|' . (string) $row['room_type_key'];
			$nights[ $key ][ (int) $row['seats'] ] = (int) $row['c'];
		}
```

`insertAccommodationBooking` po wstawieniu bookingu bierze `$wpdb->insert_id` i wstawia
wiersze dób (`seats` to ta sama liczba, co na bookingu; `night_label` to snapshot).
`deleteAccommodationBooking` kasuje **najpierw** wiersze dób (`WHERE registration_id`),
potem booking — w jednej metodzie, żeby nie dało się wywołać jednego bez drugiego.

Dopisz `bookingNightsFor( array $ids )` (bulk `IN`, short-circuit na pustej liście,
zwraca mapę `registration_id => lista dób`) oraz
`registrationsMissingNights( int $event_id, string $package_key, array $nights )`
(aktywne rezerwacje tego pakietu, którym brakuje choć jednej doby z listy —
`LEFT JOIN` po `(booking_id, night_key)`, `HAVING COUNT(...) < liczba dób`).

- [ ] **Krok 7: integration PASS. Krok 8: `phpcs` + `analyse`. Krok 9: Commit**

```bash
git commit -am "feat(persistence): store the nights a booking occupies and count them per night"
```

---

## Task 8: `reserve` pod pulami dobowymi

**Files:**
- Modify: `src/Services/ReservationService.php`
- Create: `tests/Integration/Services/NightPoolReservationTest.php`

**Interfaces:**
- Consumes: wszystko z Tasków 3–7.
- Produces: `reserve` wstawia wiersze dób w tej samej transakcji; przy pełnej dobie
  ROLLBACK + `ReservationResult::accommodationFull()`.

- [ ] **Krok 1: Testy** (oba przykłady prowadzące, na pełnej ścieżce)

```php
	public function test_two_night_package_takes_a_room_from_both_nights(): void {
		$event = $this->eventWithNightPools( array( 'n1' => 1, 'n2' => 5 ) );

		$first = $this->service->reserve( $this->request( $event, 'p2', 'double', true ) );
		$this->assertTrue( $first->accommodationGranted );

		// Doba n1 ma już zajęty swój jedyny pokój — druga para nie wchodzi,
		// mimo że n2 ma miejsce.
		$second = $this->service->reserve( $this->request( $event, 'p2', 'double', true ) );
		$this->assertFalse( $second->accommodationGranted );
		$this->assertSame( 'accommodation_full', $second->reason );
	}

	public function test_a_pair_and_a_lone_registrant_share_one_pool(): void {
		// Przykład prowadzący 2: jeden zapas 2 pokoi obsługuje parę i dwóch singli.
		$event = $this->eventWithNightPools( array( 'n1' => 2 ) );

		$this->assertTrue( $this->service->reserve( $this->request( $event, 'p1', 'double', true ) )->accommodationGranted );
		$this->assertTrue( $this->service->reserve( $this->request( $event, 'p1', 'double', false ) )->accommodationGranted );
		$this->assertTrue( $this->service->reserve( $this->request( $event, 'p1', 'double', false ) )->accommodationGranted );
		// Pokoje: para (1) + dwa luźne łóżka (1) = 2 z 2. Kolejny singiel odpada.
		$this->assertFalse( $this->service->reserve( $this->request( $event, 'p1', 'double', false ) )->accommodationGranted );
	}

	public function test_full_night_rolls_back_without_leaving_a_booking(): void {
		$event = $this->eventWithNightPools( array( 'n1' => 0 ) );

		$result = $this->service->reserve( $this->request( $event, 'p1', 'double', false ) );

		$this->assertFalse( $result->accommodationGranted );
		$this->assertNull( $this->repository->findAccommodationBooking( $result->registrationId ) );
	}

	public function test_night_rows_carry_the_stored_seats(): void {
		$event  = $this->eventWithNightPools( array( 'n1' => 5 ) );
		$result = $this->service->reserve( $this->request( $event, 'p1', 'double', true ) );

		$snapshot = $this->repository->occupancy( $event, RegistrationStatus::occupying() );
		$this->assertSame( array( 2 => 1 ), $snapshot->seatCountsFor( 'n1|double' ) );
	}
```

- [ ] **Krok 2: FAIL. Krok 3: `reserve`**

Buduj `CapacityLimits` z nowymi mapami (tu i w pozostałych dwóch ścieżkach — **żadnej
nie pomijaj**):

```php
		$limits = new CapacityLimits(
			$settings->globalCap(),
			$types->capacities(),
			$accommodation->capacities(),
			$settings->waitlistEnabled(),
			$accommodation->companionCountsEvent(),
			$accommodation->slotRooms(),
			$accommodation->nightRoomCapacity(),
			$accommodation->packageNights(),
			$accommodation->roomBeds()
		);
```

Przy przyznanym noclegu przekaż doby do insertu:

```php
			$nights = array_map(
				static fn ( string $key ): array => array(
					'key'   => $key,
					'label' => $accommodation->nightLabel( $key ),
				),
				$accommodation->nightsForPackage( $selection->packageKey )
			);
```

- [ ] **Krok 4: integration PASS (cały suite — stare testy rezerwacji muszą przejść
      bez zmian). Krok 5: `phpcs` + `analyse`. Krok 6: Commit**

```bash
git commit -am "feat(reservation): reserve against the night pools of a package"
```

---

## Task 9: Edycja i awans z waitlisty

**Files:**
- Modify: `src/Services/ReservationService.php`
- Create: `tests/Integration/Services/NightPoolEditTest.php`

**Interfaces:**
- Consumes: Task 8.
- Produces: `editAnswers` i `promoteFromWaitlist` świadome dób; `cancel` kasujący wiersze
  dób (przez `deleteAccommodationBooking` z Taska 7).

- [ ] **Krok 1: Testy**

```php
	public function test_edit_that_moves_the_slot_rewrites_the_night_rows(): void {
	public function test_edit_that_changes_nothing_keeps_the_stored_nights_seats_and_price(): void {
	public function test_edit_into_a_full_night_is_refused(): void {
	public function test_cancel_frees_the_night_rows(): void {
	public function test_promotion_into_a_full_night_reports_promoted_no_accommodation(): void {
	public function test_promotion_takes_rooms_from_every_night(): void {
```

Ciała: wzoruj się na istniejących `editAnswers`/`promoteFromWaitlist` testach, dokładając
asercje na `seatCountsFor()`. Test „edycja niezmieniająca" jest najważniejszy — musi
asertować, że po edycji **histogram jest identyczny** i że cena się nie zmieniła.

- [ ] **Krok 2: FAIL. Krok 3: implementacja**

`editAnswers`: te same mapy w `CapacityLimits`; na gałęzi `$unchanged` **nie dotykaj**
wierszy dób (nie kasuj i nie wstawiaj); na gałęzi zmiany slotu przejdź przez
`deleteAccommodationBooking` + `insertAccommodationBooking` z nowymi dobami.
Porównanie „czy slot się zmienił" zostaje na `package_key|room_type_key` — klucz slotu
nie zmienia kształtu.

`promoteFromWaitlist`: te same mapy; przy niepowodzeniu bramki dobowej awansuj i zwróć
`promoted_no_accommodation`.

- [ ] **Krok 4: integration PASS. Krok 5: `phpcs` + `analyse`. Krok 6: Commit**

```bash
git commit -am "feat(reservation): keep night rows consistent across edits, cancels and promotions"
```

---

## Task 10: Render publiczny i dwa lustra w JS

**Files:**
- Modify: `src/Frontend/FormRenderer.php`
- Modify: `src/Frontend/Shortcode.php`
- Modify: `src/Admin/RegistrationEditForm.php`
- Modify: `assets/public/form.js`
- Modify: `tests/Integration/Frontend/FormRendererTest.php`,
  `tests/Integration/Frontend/CompanionRoomRuleTest.php`

**Interfaces:**
- Consumes: `SlotAvailability` (Task 6), `RoomType::$beds/$wholeOnly` (Task 2).
- Produces: kontrakt DOM — wiersz oferty emituje `data-evreg-beds` i
  `data-evreg-whole-only` (zamiast `data-evreg-occupancy`), plus `data-evreg-seats-left`.

- [ ] **Krok 1: Testy renderu**

```php
	public function test_row_emits_beds_and_whole_only_instead_of_occupancy(): void {
		$html = $this->renderer->render( $this->nightSchema(), 1 );

		$this->assertStringContainsString( 'data-evreg-beds="2"', $html );
		$this->assertStringContainsString( 'data-evreg-whole-only="0"', $html );
		$this->assertStringNotContainsString( 'data-evreg-occupancy', $html );
	}

	public function test_two_bed_room_sold_per_bed_is_offered_without_a_companion(): void {
		// Dziś ten wiersz dostaje hidden+disabled jako companion_required.
		$html = $this->renderer->render( $this->nightSchema(), 1 );

		$this->assertMatchesRegularExpression( '/value="p1\|double"(?![^>]*disabled)/', $html );
	}

	public function test_exhausted_night_disables_the_row_and_never_leaves_it_checked(): void {
```

- [ ] **Krok 2: FAIL. Krok 3: renderer**

W `renderAccommodation` zamień odczyty `occupancy` na parę (czytaj je **przez
`AccommodationConfig`**, nie z surowego `$field->config` — zerowanie `whole_only` przy
wyłączonej towarzyszącej musi być widziane tak samo jak w pojemności i cenie):

```php
				$beds       = null === $room_type ? 0 : $room_type->beds;
				$whole      = null !== $room_type && $room_type->wholeOnly;
				$rowSeats   = RoomOccupancy::seats( $beds, $whole, $companion );
```

```php
			$beds_attr  = ' data-evreg-beds="' . esc_attr( (string) $row['beds'] ) . '"';
			$whole_attr = ' data-evreg-whole-only="' . ( $row['whole'] ? '1' : '0' ) . '"';
```

Filtr pola współlokatora: `! empty( $room['roommate_field'] ) && ! $whole && $beds > 1`.

`Shortcode::availability()` buduje `SlotAvailability` z pełnego snapshotu (z histogramem).

- [ ] **Krok 4: `form.js` — dwa lustra**

```js
	// Lustro EvReg\Domain\Accommodation\RoomOccupancy::seats.
	function seatsFor( beds, wholeOnly, companion ) {
		return wholeOnly && beds > 0 ? beds : ( companion ? 2 : 1 );
	}

	// Lustro EvReg\Domain\Accommodation\RoomType::companionMismatch.
	// Jednego inta tu nie wystarczy: dwójka sprzedawana na łóżka BEZ towarzyszącej
	// jest poprawna, a dwójka „tylko w całości" bez towarzyszącej nie jest.
	function mismatchFor( beds, wholeOnly, companion ) {
		if ( beds === 0 ) { return false; }
		if ( companion && beds === 1 ) { return true; }
		return ! companion && wholeOnly;
	}
```

W `applySlotState` zmień selektor na
`'[data-evreg-beds], [data-evreg-seats-left]'` i odczyt:

```js
			var beds = parseInt( el.getAttribute( 'data-evreg-beds' ), 10 );
			if ( isNaN( beds ) ) { beds = 0; }
			var wholeOnly = el.getAttribute( 'data-evreg-whole-only' ) === '1';

			var mismatch = mismatchFor( beds, wholeOnly, companion );
			var left = parseInt( el.getAttribute( 'data-evreg-seats-left' ), 10 );
			var full = ! isNaN( left ) && left < seatsFor( beds, wholeOnly, companion );
```

Reszta funkcji (suma powodów w `disabled`, przeniesienie zaznaczenia na „Bez noclegu",
notki) **bez zmian** — to inwarianty B-slots.

- [ ] **Krok 5: ten sam kontrakt w `RegistrationEditForm`** (admin reuseuje `form.js`).

- [ ] **Krok 6: integration PASS, `npm run test:js` PASS, `phpcs` + `analyse`. Commit**

```bash
git commit -am "feat(form): drive availability and the companion filter from beds and whole-room-only"
```

---

## Task 11: Pola pokoju w adminie

**Files:**
- Modify: `assets/admin/ops/accommodationOps.js` + `accommodationOps.test.js`
- Modify: `assets/admin/tabs/AccommodationTab.jsx`

**Interfaces:**
- Produces: `setRoomBeds( acc, index, beds )`, `setRoomWholeOnly( acc, index, flag )`,
  `roomWarnings( acc ): Array<{room:string, code:string}>` — czyste, bez mutacji wejścia.

- [ ] **Krok 1: Testy jest** — `beds` 1/2/0, `whole_only` normalizowane przy `beds = 1`,
      ostrzeżenie przy `whole_only` + wyłączonej towarzyszącej, brak mutacji wejścia.
- [ ] **Krok 2: FAIL. Krok 3: ops. Krok 4: UI** — `SelectControl` „Łóżka"
      (nieustawione / 1 / 2) + `CheckboxControl` „Tylko cały pokój" (disabled przy
      `beds !== 2` albo wyłączonej towarzyszącej, z `help` mówiącym dlaczego),
      `roommate_field` disabled przy `whole_only` albo `beds < 2`.
- [ ] **Krok 5: `npm run test:js` + `npm run build`. Commit**

```bash
git commit -am "feat(admin): configure a room by its beds and whether it sells whole only"
```

---

## Task 12: Doby, macierz dobowa i kaskady w adminie

**Files:**
- Modify: `assets/admin/ops/accommodationOps.js` + test
- Modify: `assets/admin/tabs/AccommodationTab.jsx`
- Modify: `tests/e2e/config-admin.spec.js`

**Interfaces:**
- Produces: `addNight`, `removeNight` (**kaskada**: zdejmuje odniesienia z pakietów
  i osierocone wiersze `night_inventory`), `setNightLabel`, `setNightKey`,
  `setPackageNights`, `setNightCapacity`, `nightWarnings( acc )`.

- [ ] **Krok 1: Testy jest**

```js
	it( 'removing a night strips it from packages and drops its capacity rows', () => {
	it( 'adding a night leaves every room closed until its row is filled', () => {
	it( 'setting package nights does not touch the dormant slot capacity', () => {
	it( 'warns when a package with nights uses a room without beds', () => {
	it( 'warns when packages with and without nights share one room', () => {
```

Kaskada jest obowiązkowa: bez niej admin zapisze config, który **rzuca** z
`AccommodationConfig::fromArray` (nieznana doba w pakiecie) i kładzie cały formularz
publiczny. Wzór: dzisiejsze `removePackage`/`removeRoom`, które czyszczą inwentarz.

- [ ] **Krok 2: FAIL. Krok 3: ops. Krok 4: UI** — lista dób (bliźniak listy pakietów),
      `FormTokenField`/checkboxy dób na pakiecie, macierz `doba × pokój` z limitem
      w pokojach i podpisem „= N łóżek", wyszarzona komórka pojemności w macierzy
      `pakiet × pokój` z notką „limit idzie z dób", notka „cena za łóżko", `Notice`
      dla każdego ostrzeżenia z `nightWarnings`.
- [ ] **Krok 5: `npm run test:js` + `build` + e2e config-admin. Commit**

```bash
git commit -am "feat(admin): manage nights and the per-night room matrix"
```

---

## Task 13: Dosypanie dób istniejącym rezerwacjom

**Files:**
- Modify: `src/Admin/RegistrationsScreen.php` (albo `src/Admin/SettingsScreen.php` —
  wybór buildera; akcja musi siedzieć przy ekranie eventu)
- Modify: `src/Services/ReservationService.php` (metoda pod lockiem)
- Create: `tests/Integration/Admin/NightBackfillTest.php`

**Interfaces:**
- Consumes: `registrationsMissingNights` (Task 7).
- Produces: `ReservationService::backfillNights( int $event_id, string $package_key ): AdminActionResult`
  — pod `lockEvent`, idempotentna na `(booking, doba)`, `seats` z zapisanego bookingu.

- [ ] **Krok 1: Testy**

```php
	public function test_backfill_writes_the_missing_nights_from_the_stored_booking(): void {
	public function test_backfill_run_twice_changes_nothing_the_second_time(): void {
	public function test_backfill_fills_a_night_added_after_an_earlier_backfill(): void {
	public function test_backfill_skips_cancelled_registrations(): void {
	public function test_backfill_takes_the_event_lock_before_reading(): void {
```

Trzeci test jest sednem: idempotencja musi być na parze `(booking, doba)`, nie
„pomiń rezerwacje, które mają jakiekolwiek wiersze".

- [ ] **Krok 2: FAIL. Krok 3: implementacja** — handler `admin-post` (nonce → cap
      `edit_evreg_events` → akcja → PRG), przycisk z liczbą dotkniętych rezerwacji
      i jawnym potwierdzeniem.
- [ ] **Krok 4: integration PASS. `phpcs` + `analyse`. Commit**

```bash
git commit -am "feat(admin): assign nights to reservations made before the package had them"
```

---

## Task 14: Panel obłożenia per doba

**Files:**
- Modify: `src/Domain/Capacity/OccupancyReport.php`
- Modify: `src/Admin/RegistrationsOverview.php`
- Modify: `tests/Unit/Domain/Capacity/OccupancyReportTest.php`
- Modify: `tests/Integration/Admin/RegistrationsOverviewTest.php`

**Interfaces:**
- Produces: `OccupancyReport::nights(): array<int,array{night:string,nightLabel:string,room:string,roomLabel:string,limitRooms:int,roomsUsed:int,freeRooms:int,freeBeds:int}>`
  oraz `incompleteNightCoverage(): int` (wstrzykiwane, nie odpytywane z domeny).

- [ ] **Krok 1: Testy** — pakiet z dobami pokazuje realne wolne pokoje (nie „0");
      wiersz slotowy pakietu z dobami oznaczony jako nieobowiązujący; licznik
      niepełnego pokrycia widoczny.
- [ ] **Krok 2: FAIL. Krok 3: implementacja** (`report()` buduje `CapacityLimits`
      z mapami dobowymi — dziś buduje je bez nich).
- [ ] **Krok 4: testy PASS. `phpcs` + `analyse`. Commit**

```bash
git commit -am "feat(admin): show per-night room occupancy in the overview"
```

---

## Task 15: Eksport, detal i tłumaczenia dób

**Files:**
- Modify: `src/Domain/Export/RegistrationExportMapper.php` + test
- Modify: `src/Admin/RegistrationsExporter.php`, `src/Admin/RegistrationsScreen.php`
- Modify: `src/Domain/Schema/ContentTranslator.php` + test
- Modify: `assets/admin/ops/i18nOps.js` + test, `assets/admin/tabs/TranslationsTab.jsx`

**Interfaces:**
- Produces: kolumna „Doby" w CSV (z bulk odczytu, bez N+1); doby na detalu zgłoszenia;
  bucket `nights` w overlayu `_evreg_i18n[lang].accommodation`.

- [ ] **Krok 1: Testy** — `accommodationCells` z dobami i bez; `langBucket`
      whitelistuje `accommodation` (już whitelistuje — test na regresję);
      `translatableItems` zawiera doby; `ContentTranslator` nakłada etykiety dób
      i **nie tyka** kluczy ani pojemności.
- [ ] **Krok 2: FAIL. Krok 3: implementacja. Krok 4: testy PASS + `npm run test:js`.
      `phpcs` + `analyse`. Commit**

```bash
git commit -am "feat(export): add a nights column and translate night labels"
```

---

## Task 16: e2e, dokumentacja, wydanie

**Files:**
- Create: `tests/e2e/night-pools.spec.js`, `tests/e2e/setup-night-pools.php`
- Modify: `CLAUDE.md`, `CHANGELOG.md`, `languages/event-registration.pot`

- [ ] **Krok 1: e2e** — jeden scenariusz pokrywający przykład prowadzący 2: pokój
      dwuosobowy z jedną dobą i limitem 1; singiel bez towarzyszącej widzi wiersz
      aktywny, po zaznaczeniu towarzyszącej ten sam wiersz dostaje „(za mało miejsc)"
      i `disabled`, a zaznaczenie przenosi się na „Bez noclegu".
- [ ] **Krok 2: `npm run test:e2e`** (po `node scripts/wp-env.cjs start`)
- [ ] **Krok 3: `CLAUDE.md`** — akapit o pulach dobowych: limit w pokojach, popyt
      z pakowaniem, `NightOccupancy` jako jedyny dom, `beds`+`whole_only` zamiast
      `occupancy` (**przeredaguj akapit B-occupancy**, który opisuje dzisiejsze
      zerowanie), inwariant podaży/popytu, nowy kontrakt DOM lustra, tabela
      `booking_nights` i db v7.
- [ ] **Krok 4: `CHANGELOG.md`** — wpis w `## [Unreleased]`, po angielsku,
      user-facing: limity per doba, jeden zapas obsługujący pary i singli, nowa tabela,
      kolumna eksportu, panel per doba.
- [ ] **Krok 5: `npm run i18n:pot`** (nowe stringi admina i renderu)
- [ ] **Krok 6: CAŁE bramki** — unit, integration, `composer analyse`, `phpcs`,
      `npm run test:js`, `npm run test:e2e`
- [ ] **Krok 7: `bash scripts/build-zip.sh`**
- [ ] **Krok 8: Commit**

```bash
git commit -am "docs: record the per-night pool rules and refresh the catalog"
```

---

## Samoprzegląd planu

**Pokrycie speca.** Model pokoju → Task 2. Doby i limity → Task 3. Jednostka puli
i pakowanie → Task 4. Bramka → Task 5. Dostępność i komunikaty → Task 6. Baza
i repozytorium → Task 7. Ścieżki transakcyjne → Taski 8–9. Render i lustro → Task 10.
Admin (pokój / doby / macierz / kaskady / ostrzeżenia) → Taski 11–12. Dosypanie dób →
Task 13. Panel obłożenia → Task 14. Eksport, detal, i18n → Task 15. Cena: bez zmian
mechaniki, notka w UI → Task 12. Zgodność w tył: Task 2 (mapowanie legacy), Task 5
(gałąź slotowa), Task 6 (gałąź slotowa), Task 7 (zapytanie slotowe zostaje).

**Spójność typów.** `RoomOccupancy::seats( int, bool, bool )` wołane z `RoomType::seats`,
`AccommodationConfig::seatsFor`, `CapacityLimits::seatsForSlot` i lustra JS — wszystkie
w Tasku 2 i 10. `slotOccupancies()` → `slotRooms()` zmienia typ wartości na parę
`{beds, whole}` i jest konsumowane wyłącznie przez `CapacityLimits` (Task 2).
`NightOccupancy` przyjmuje mapę `seats => count`, którą produkuje
`OccupancySnapshot::seatCountsFor` (Task 4) i konsumują `CapacityCalculator` (Task 5),
`SlotAvailability` (Task 6) i `OccupancyReport` (Task 14).

**Ryzyka.** (1) Task 2 rusza sygnaturę używaną w pięciu miejscach — stare testy
`occupancy` trzeba przepisać, nie usunąć. (2) Task 7 zmienia `insertAccommodationBooking`
i `deleteAccommodationBooking`, więc wszystkie trzy ścieżki transakcyjne muszą przejść
przez Taski 8–9 w tym samym przebiegu. (3) Task 10 zmienia kontrakt DOM, który reuseuje
admin — `RegistrationEditForm` nie może zostać z `data-evreg-occupancy`.
