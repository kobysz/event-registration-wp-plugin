# Noclegi liczone miejscami na dobę — plan implementacji

> **Dla pracujących agentowo:** WYMAGANY SUB-SKILL: użyj superpowers:subagent-driven-development
> (rekomendowane) albo superpowers:executing-plans, żeby wykonać ten plan task po tasku.
> Kroki mają składnię checkboxów (`- [ ]`) do odhaczania.

**Cel:** dodać drugi tryb noclegu, w którym pojemność to liczba **miejsc na dobę**
(plus opcjonalny limit zgłoszeń z osobą towarzyszącą), a uczestnik **zaznacza doby**
zamiast wybierać jedną pozycję `pakiet × pokój`.

**Architektura:** tryb jest przełącznikiem na poziomie eventu (`classic` / `nights`).
Ścieżka `classic` to dzisiejszy kod i **nie jest zmieniana** — każde wejście dostaje
jedno rozgałęzienie po `mode`. Tryb `nights` ma własne obiekty czystej domeny
(`NightsConfig`, `NightSelection`, `NightOccupancy`, `NightCapacity`), własną tabelę
wierszy dób i własną gałąź w trzech ścieżkach transakcyjnych. Zajętość liczona jest pod
lockiem eventu tym samym wzorcem co dziś.

**Stack:** PHP 8.1, WordPress 6.4+, PHPUnit 9 (unit + integration w kontenerze wp-env),
React (@wordpress/components) + jest dla `assets/admin/ops/*`, Playwright e2e.

**Spec:** `docs/superpowers/specs/2026-11-17-night-seat-pools-design.md`
(czytaj razem z planem; konflikty rozstrzyga spec)

## Global Constraints

- **Ścieżka `classic` jest zamrożona.** Zero zmian w `AccommodationConfig`,
  `AccommodationSelection`, `CapacityCalculator`, `CapacityLimits`, `OccupancySnapshot`,
  `SlotAvailability`, `RoomOccupancy`, `RoomType`, `Package`, `InventoryItem`,
  `PriceCalculator::total()` i w ich testach. Task, który to robi, jest źle rozcięty.
- **W trybie `nights` nic nie konstruuje `AccommodationConfig`.** Jego `fromArray`
  rzuca `SchemaException` na niespójnym inwentarzu, a wołający na ścieżce renderu tego
  nie łapią — nieaktualne dane klasyczne wysadziłyby publiczny formularz.
- `lockEvent()` **przed** pierwszym odczytem zajętości w każdej ścieżce transakcyjnej.
  Kolejności nie zmieniać.
- Hooki cyklu życia zgłoszenia emitowane **wyłącznie po COMMIT**.
- `src/Domain/**` bez WordPressa i bez `$wpdb` (`DomainPurityTest`). Domena zwraca kody
  (klucz blokującej doby), prezentacja robi z nich zdania.
- **Dwie konwencje pustki:** `seats = 0` znaczy brak miejsc (doba zamknięta);
  `companion_cap` pusty/nieobecny znaczy bez limitu, a `0` zamyka dobę dla par.
- **Zbiór dób w JSON-ie odpowiedzi to zwykła lista kluczy**, nigdy obiekt (patrz B15).
- Pola formularza zostają pod namespace'em `evreg_field[...]` (inwariant 3B).
- Każdy plik PHP poza `src/Domain/` i `tests/` zaczyna się od `defined( 'ABSPATH' ) || exit;`.
- Wszystkie stringi UI przez i18n, text domain `event-registration`; w JS `__()`.
- PHPStan 6 bez obniżania, `phpcs` czysto, logika mutacji configu w `assets/admin/ops/*`.

### Komendy

```bash
npm run test:js
npm run build
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- composer analyse
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
node scripts/wp-env.cjs start && npm run test:e2e
```

> Konsola PHPUnit w tym kontenerze **zniekształca komunikat niezłapanego `Error`**.
> Fazę RED weryfikuj jawnym try/catch drukującym `get_class($e)` i `getMessage()`.

---

## Struktura plików

**Nowe:**

| plik | odpowiedzialność |
|---|---|
| `src/Domain/Accommodation/Night.php` | doba: klucz, etykieta, cena, miejsca, limit par |
| `src/Domain/Accommodation/NightsConfig.php` | konfiguracja trybu `nights` + wspólne flagi |
| `src/Domain/Accommodation/NightSelection.php` | znormalizowany zbiór zaznaczonych dób |
| `src/Domain/Capacity/NightOccupancy.php` | zajętość per doba: miejsca i pary |
| `src/Domain/Capacity/NightCapacity.php` | bramka: pierwsza doba bez miejsc |
| `tests/Unit/Domain/Accommodation/NightTest.php` | — |
| `tests/Unit/Domain/Accommodation/NightsConfigTest.php` | — |
| `tests/Unit/Domain/Accommodation/NightSelectionTest.php` | — |
| `tests/Unit/Domain/Capacity/NightCapacityTest.php` | — |
| `tests/Integration/Services/NightReservationTest.php` | `reserve` w trybie `nights` |
| `tests/Integration/Services/NightEditTest.php` | edycja, anulowanie, awans |
| `tests/Integration/Frontend/NightFormTest.php` | render i submit checkboxów |
| `tests/Integration/Rest/AccommodationModeTest.php` | blokada przełączenia trybu |
| `tests/e2e/night-seats.spec.js` + `setup-night-seats.php` | para i singiel na dwóch dobach |

**Modyfikowane:**

| plik | zmiana |
|---|---|
| `src/Domain/Pricing/PriceCalculator.php` | druga metoda `totalForNights` |
| `src/Domain/Export/RegistrationExportMapper.php` | kolumna „Doby" |
| `src/Domain/Schema/ContentTranslator.php` | bucket `nights` w overlayu |
| `src/Persistence/Migrations.php` | db v7 + tabela `booking_nights` |
| `src/Persistence/Uninstaller.php` | nowa tabela na liście |
| `src/Persistence/RegistrationRepository.php` | wiersze dób, zajętość dobowa, predykat blokady |
| `src/Services/ReservationService.php` | gałąź `nights` w trzech ścieżkach |
| `src/Services/ReservationRequest.php` | `?NightSelection $nights` |
| `src/Frontend/FormRenderer.php` | checkboxy dób |
| `src/Frontend/SubmissionAssembler.php` | ekstrakcja zbioru dób |
| `src/Domain/Validation/Validators/AccommodationValidator.php` | gałąź `nights` |
| `src/Rest/EventConfigController.php` | 409 na zmianę trybu + blok `stats` |
| `src/Admin/RegistrationEditForm.php`, `RegistrationsScreen.php`, `RegistrationsOverview.php` | checkboxy, detal, panel |
| `src/Mail/PlaceholderFactory.php` | `{noclegi}` |
| `assets/public/form.js` | lustro dób |
| `assets/admin/ops/accommodationOps.js`, `tabs/AccommodationTab.jsx` | tryb + lista dób |
| `assets/admin/ops/i18nOps.js`, `tabs/TranslationsTab.jsx` | tłumaczenia etykiet dób |

---

## Task 1: Doba i konfiguracja trybu

**Files:**
- Create: `src/Domain/Accommodation/Night.php`, `NightsConfig.php`
- Create: `tests/Unit/Domain/Accommodation/NightTest.php`, `NightsConfigTest.php`

**Interfaces:**
- Produces: `Night` z `$key`, `$label`, `$price` (float), `$seats` (int),
  `?int $companionCap`; `NightsConfig` z `isNights(): bool`, `nights(): Night[]`,
  `night( string $key ): ?Night`, `nightLabel( string $key ): string`,
  `allowsNone(): bool`, `companionEnabled(): bool`, `companionCountsEvent(): bool`,
  `fromArray( array $config ): self`.

- [ ] **Krok 1: Testy doby**

```php
	public function test_from_array_reads_every_field(): void {
		$night = Night::fromArray(
			array( 'key' => 'n1819', 'label' => '18/19 grudnia', 'price' => 150.0, 'seats' => 72, 'companion_cap' => 31 )
		);

		$this->assertSame( 'n1819', $night->key );
		$this->assertSame( '18/19 grudnia', $night->label );
		$this->assertSame( 150.0, $night->price );
		$this->assertSame( 72, $night->seats );
		$this->assertSame( 31, $night->companionCap );
	}

	public function test_empty_companion_cap_means_no_limit(): void {
		$this->assertNull( Night::fromArray( array( 'key' => 'n1', 'companion_cap' => '' ) )->companionCap );
		$this->assertNull( Night::fromArray( array( 'key' => 'n1' ) )->companionCap );
	}

	public function test_zero_companion_cap_is_a_limit_of_zero(): void {
		// Dwie różne konwencje pustki: tu 0 zamyka dobę dla par, ale zostawia ją singlom.
		$this->assertSame( 0, Night::fromArray( array( 'key' => 'n1', 'companion_cap' => 0 ) )->companionCap );
	}

	public function test_zero_seats_closes_the_night(): void {
		$this->assertSame( 0, Night::fromArray( array( 'key' => 'n1', 'seats' => 0 ) )->seats );
	}

	public function test_empty_key_is_rejected(): void {
		$this->expectException( SchemaException::class );
		Night::fromArray( array( 'key' => '' ) );
	}

	public function test_negative_numbers_are_rejected(): void {
		$this->expectException( SchemaException::class );
		Night::fromArray( array( 'key' => 'n1', 'seats' => -1 ) );
	}

	public function test_to_array_round_trips(): void {
		$data = array( 'key' => 'n1', 'label' => 'Noc', 'price' => 1.5, 'seats' => 2, 'companion_cap' => null );

		$this->assertSame( $data, Night::fromArray( $data )->toArray() );
	}
```

Dopisz też osobne `expectException` na ujemną `price` i ujemny `companion_cap`.

- [ ] **Krok 2: Uruchom — FAIL. Krok 3: `Night`**

Wzoruj kształt na `Package` (ten sam styl docbloków, `fromArray`/`toArray`).
`companion_cap` czytaj tak:

```php
		$cap = $data['companion_cap'] ?? null;
		// Pusty string i brak klucza znaczą „bez limitu"; liczba (także 0) znaczy limit.
		$cap = ( null === $cap || '' === $cap ) ? null : (int) $cap;
```

- [ ] **Krok 4: Testy konfiguracji**

```php
	public function test_absent_mode_is_classic(): void {
		$this->assertFalse( NightsConfig::fromArray( array() )->isNights() );
		$this->assertFalse( NightsConfig::fromArray( array( 'mode' => 'classic' ) )->isNights() );
	}

	public function test_nights_mode_exposes_its_nights(): void {
		$config = NightsConfig::fromArray(
			array(
				'mode'   => 'nights',
				'nights' => array(
					array( 'key' => 'n1819', 'label' => '18/19', 'seats' => 72 ),
					array( 'key' => 'n1920', 'label' => '19/20', 'seats' => 65 ),
				),
			)
		);

		$this->assertTrue( $config->isNights() );
		$this->assertCount( 2, $config->nights() );
		$this->assertSame( '18/19', $config->nightLabel( 'n1819' ) );
		$this->assertSame( 72, $config->night( 'n1819' )?->seats );
	}

	public function test_unknown_night_label_falls_back_to_the_raw_key(): void {
		$this->assertSame( 'n9', NightsConfig::fromArray( array( 'mode' => 'nights' ) )->nightLabel( 'n9' ) );
	}

	public function test_shared_flags_are_read_without_touching_the_classic_inventory(): void {
		// NOŚNY: inwentarz jest tu NIESPÓJNY (pozycja wskazuje nieznany pakiet),
		// co wysadziłoby AccommodationConfig::fromArray. NightsConfig go nie czyta.
		$config = NightsConfig::fromArray(
			array(
				'mode'              => 'nights',
				'companion_enabled' => true,
				'allow_none'        => false,
				'inventory'         => array( array( 'package' => 'widmo', 'room' => 'widmo', 'capacity' => 1 ) ),
			)
		);

		$this->assertTrue( $config->companionEnabled() );
		$this->assertFalse( $config->allowsNone() );
	}

	public function test_duplicate_night_key_is_rejected(): void {
		$this->expectException( SchemaException::class );
		NightsConfig::fromArray(
			array( 'mode' => 'nights', 'nights' => array( array( 'key' => 'n1' ), array( 'key' => 'n1' ) ) )
		);
	}

	public function test_classic_mode_ignores_the_nights_list(): void {
		$config = NightsConfig::fromArray( array( 'nights' => array( array( 'key' => 'n1' ) ) ) );

		$this->assertFalse( $config->isNights() );
		$this->assertSame( array(), $config->nights() );
	}
```

- [ ] **Krok 5: FAIL. Krok 6: `NightsConfig`** — `allow_none` domyślnie `true`,
      `companion_enabled`/`companion_counts_event` domyślnie `false` (jak dziś
      w `AccommodationConfig`).
- [ ] **Krok 7: unit PASS, `phpcs`, `composer analyse`. Krok 8: Commit**

```bash
git commit -am "feat(accommodation): read a nights-mode configuration with seats and a companion cap"
```

---

## Task 2: Zbiór dób i zajętość dobowa

**Files:**
- Create: `src/Domain/Accommodation/NightSelection.php`, `src/Domain/Capacity/NightOccupancy.php`
- Create: `tests/Unit/Domain/Accommodation/NightSelectionTest.php`

**Interfaces:**
- Produces: `NightSelection::fromKeys( array $keys, NightsConfig $config ): self`
  (unikalne, w kolejności konfiguracji, nieznane odfiltrowane), `keys(): string[]`,
  `isEmpty(): bool`; `NightOccupancy` z `__construct( array $seats, array $pairs )`,
  `seatsFor( string $night ): int`, `pairsFor( string $night ): int`.

- [ ] **Krok 1: Testy**

```php
	public function test_selection_keeps_configuration_order_and_drops_duplicates(): void {
		$config = $this->config( array( 'n1', 'n2', 'n3' ) );

		$selection = NightSelection::fromKeys( array( 'n3', 'n1', 'n3' ), $config );

		$this->assertSame( array( 'n1', 'n3' ), $selection->keys() );
	}

	public function test_unknown_keys_are_dropped(): void {
		$selection = NightSelection::fromKeys( array( 'n1', 'widmo' ), $this->config( array( 'n1' ) ) );

		$this->assertSame( array( 'n1' ), $selection->keys() );
	}

	public function test_empty_selection_is_empty(): void {
		$this->assertTrue( NightSelection::fromKeys( array(), $this->config( array( 'n1' ) ) )->isEmpty() );
		$this->assertTrue( NightSelection::fromKeys( array( 'widmo' ), $this->config( array( 'n1' ) ) )->isEmpty() );
	}
```

`NightOccupancy` dostaje dwa testy: zwraca zera dla nieznanej doby i podane liczby dla
znanej.

- [ ] **Krok 2: FAIL. Krok 3: implementacja. Krok 4: unit PASS, `phpcs`, `analyse`.
      Krok 5: Commit**

```bash
git commit -am "feat(accommodation): normalise a set of selected nights and the occupancy of each"
```

---

## Task 3: Bramka dobowa

**Files:**
- Create: `src/Domain/Capacity/NightCapacity.php`
- Create: `tests/Unit/Domain/Capacity/NightCapacityTest.php`

**Interfaces:**
- Consumes: Taski 1–2.
- Produces: `NightCapacity::blockingNight( NightsConfig $config, NightOccupancy $taken, NightSelection $selection, bool $companion ): ?string`
  — klucz **pierwszej** doby, która nie przechodzi, albo `null`.

- [ ] **Krok 1: Testy** — to serce zmiany

```php
	public function test_empty_nights_fit_anywhere(): void {
		$this->assertNull( $this->decide( array( 'n1' => 72 ), array(), array( 'n1' ), false ) );
	}

	public function test_a_lone_registrant_takes_one_seat(): void {
		// 1 miejsce, jedno zgłoszenie bez towarzyszącej — wchodzi.
		$this->assertNull( $this->decide( array( 'n1' => 1 ), array( 'n1' => 0 ), array( 'n1' ), false ) );
	}

	public function test_a_companion_takes_two_seats(): void {
		$this->assertSame( 'n1', $this->decide( array( 'n1' => 1 ), array( 'n1' => 0 ), array( 'n1' ), true ) );
	}

	public function test_zero_seats_closes_the_night(): void {
		$this->assertSame( 'n1', $this->decide( array( 'n1' => 0 ), array(), array( 'n1' ), false ) );
	}

	public function test_the_second_night_can_be_the_blocking_one(): void {
		// Pierwsza doba ma miejsce, druga nie — bramka MUSI sprawdzić obie i wskazać n2.
		$blocking = $this->decide(
			array( 'n1' => 10, 'n2' => 1 ),
			array( 'n1' => 0, 'n2' => 1 ),
			array( 'n1', 'n2' ),
			false
		);

		$this->assertSame( 'n2', $blocking );
	}

	public function test_companion_cap_blocks_a_pair_while_seats_remain(): void {
		// 72 miejsca wolne, ale limit par wyczerpany: singiel wchodzi, para nie.
		$config = $this->configWith( array( 'n1' => array( 'seats' => 72, 'companion_cap' => 2 ) ) );
		$taken  = new NightOccupancy( array( 'n1' => 4 ), array( 'n1' => 2 ) );

		$this->assertNull( NightCapacity::blockingNight( $config, $taken, $this->sel( $config, array( 'n1' ) ), false ) );
		$this->assertSame( 'n1', NightCapacity::blockingNight( $config, $taken, $this->sel( $config, array( 'n1' ) ), true ) );
	}

	public function test_empty_companion_cap_means_no_limit_on_pairs(): void {
		$config = $this->configWith( array( 'n1' => array( 'seats' => 72, 'companion_cap' => '' ) ) );
		$taken  = new NightOccupancy( array( 'n1' => 40 ), array( 'n1' => 20 ) );

		$this->assertNull( NightCapacity::blockingNight( $config, $taken, $this->sel( $config, array( 'n1' ) ), true ) );
	}

	public function test_companion_cap_of_zero_closes_the_night_for_pairs_only(): void {
		$config = $this->configWith( array( 'n1' => array( 'seats' => 72, 'companion_cap' => 0 ) ) );
		$taken  = new NightOccupancy( array(), array() );

		$this->assertNull( NightCapacity::blockingNight( $config, $taken, $this->sel( $config, array( 'n1' ) ), false ) );
		$this->assertSame( 'n1', NightCapacity::blockingNight( $config, $taken, $this->sel( $config, array( 'n1' ) ), true ) );
	}

	public function test_leading_example(): void {
		// 72 i 65 miejsc, para na dwie doby: 2 z każdej.
		$config = $this->configWith(
			array( 'n1819' => array( 'seats' => 72, 'companion_cap' => 31 ), 'n1920' => array( 'seats' => 65, 'companion_cap' => 28 ) )
		);
		$taken  = new NightOccupancy( array( 'n1819' => 70, 'n1920' => 10 ), array( 'n1819' => 5, 'n1920' => 5 ) );

		// n1819 ma 2 wolne miejsca — para wchodzi dokładnie.
		$this->assertNull( NightCapacity::blockingNight( $config, $taken, $this->sel( $config, array( 'n1819', 'n1920' ) ), true ) );

		$fuller = new NightOccupancy( array( 'n1819' => 71, 'n1920' => 10 ), array( 'n1819' => 5, 'n1920' => 5 ) );
		$this->assertSame( 'n1819', NightCapacity::blockingNight( $config, $fuller, $this->sel( $config, array( 'n1819', 'n1920' ) ), true ) );
	}

	public function test_unknown_night_in_the_configuration_blocks(): void {
		// Doba usunięta z konfiguracji pod zapisanym życzeniem — nie wpuszczamy.
		$config = $this->configWith( array( 'n1' => array( 'seats' => 10 ) ) );

		$this->assertNull( NightCapacity::blockingNight( $config, new NightOccupancy( array(), array() ), NightSelection::fromKeys( array( 'widmo' ), $config ), false ) );
	}
```

> Ostatni test jest świadomie asercją na `null`: `NightSelection` odfiltrowuje nieznane
> doby, więc do bramki nie dochodzą. Pusty zbiór to „bez noclegu", a nie blokada —
> walidacja wymagalności pola jest osobną sprawą (Task 8).

- [ ] **Krok 2: FAIL. Krok 3: `NightCapacity`**

```php
	public static function blockingNight(
		NightsConfig $config,
		NightOccupancy $taken,
		NightSelection $selection,
		bool $companion
	): ?string {
		$seats = $companion ? 2 : 1;

		foreach ( $selection->keys() as $key ) {
			$night = $config->night( $key );

			if ( null === $night ) {
				continue;
			}

			if ( $taken->seatsFor( $key ) + $seats > $night->seats ) {
				return $key;
			}

			if ( $companion && null !== $night->companionCap
				&& $taken->pairsFor( $key ) + 1 > $night->companionCap ) {
				return $key;
			}
		}

		return null;
	}
```

- [ ] **Krok 4: unit PASS, `phpcs`, `analyse`. Krok 5: Commit**

```bash
git commit -am "feat(capacity): refuse a night selection on the first night without room"
```

---

## Task 4: Cena noclegu dobowego

**Files:**
- Modify: `src/Domain/Pricing/PriceCalculator.php`
- Modify: `tests/Unit/Domain/Pricing/PriceCalculatorTest.php`

**Interfaces:**
- Produces: `totalForNights( RegistrationType $type, NightsConfig $config, NightSelection $selection, int $seats ): float`.
  Istniejące `total()` **nietknięte**.

- [ ] **Krok 1: Testy**

```php
	public function test_nights_total_adds_the_type_price_once(): void {
		// Cena typu liczy się RAZ, także dla pary — jak w total() dla trybu classic.
		$total = ( new PriceCalculator() )->totalForNights( $this->type( 100.0 ), $config, $selection, 2 );

		$this->assertSame( 100.0 + ( 150.0 + 150.0 ) * 2, $total );
	}

	public function test_lone_registrant_pays_for_one_seat_per_night(): void {
	public function test_empty_selection_prices_the_type_only(): void {
	public function test_unknown_night_contributes_nothing(): void {
	public function test_total_is_rounded_like_the_classic_path(): void {
```

Ciała dopisz wzorując się na istniejących testach `total()`; zaokrąglanie zrób **tą samą
drogą**, jaką robi `total()` (sprawdź jego ostatnią linię, nie zgaduj).

- [ ] **Krok 2: FAIL. Krok 3: metoda. Krok 4: unit PASS, `phpcs`, `analyse`. Commit**

```bash
git commit -am "feat(pricing): price a night selection, charging the type once"
```

---

## Task 5: Migracja v7 i SQL dób

**Files:**
- Modify: `src/Persistence/Migrations.php`, `Uninstaller.php`, `RegistrationRepository.php`
- Modify: `tests/Integration/Persistence/MigrationsTest.php`
- Create: `tests/Integration/Persistence/BookingNightsTest.php`

**Interfaces:**
- Produces:
  - tabela `evreg_booking_nights` (kształt w specu), `DB_VERSION = 7`
  - `replaceBookingNights( int $registration_id, array $nights ): void` — kasuje
    i wstawia w jednym wywołaniu (`$nights` = lista
    `array{key:string,label:string,seats:int,price:float}`)
  - `deleteBookingNights( int $registration_id ): void`
  - `nightOccupancy( int $event_id, array $statuses, int $exclude_id = 0 ): NightOccupancy`
  - `bookingNightsFor( array $registration_ids ): array<int,array<int,array{key:string,label:string,price:float,seats:int}>>`
  - `eventHasAccommodation( int $event_id ): bool` — predykat blokady trybu: istnieje
    wiersz bookingu klasycznego **albo** wiersz doby

- [ ] **Krok 1: Testy**

```php
	public function test_booking_nights_table_exists_after_install(): void {
	public function test_night_occupancy_counts_seats_and_pairs(): void {
		// Para (seats=2) i dwa single (seats=1) w tej samej dobie: 4 miejsca, 1 para.
	}
	public function test_cancelled_registration_drops_out_of_the_night_occupancy(): void {
	public function test_exclude_id_drops_the_edited_registration(): void {
	public function test_replace_is_idempotent_on_the_same_set(): void {
	public function test_replace_removes_nights_that_left_the_set(): void {
	public function test_event_has_accommodation_sees_both_kinds_of_rows(): void {
	public function test_event_with_only_cancelled_registrations_is_not_locked(): void {
```

Ostatni test jest nośny: anulowanie kasuje wiersze, więc event z samymi anulowanymi
zgłoszeniami **musi** dać się przełączyć między trybami.

- [ ] **Krok 2: FAIL. Krok 3: implementacja**

`DB_VERSION = 7`, `CREATE TABLE` dokładnie jak w specu, `Uninstaller::TABLES` +
`'booking_nights'`. Zapytanie zajętości:

```php
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT n.night_key, SUM(n.seats) AS seats, SUM(CASE WHEN n.seats >= 2 THEN 1 ELSE 0 END) AS pairs
				 FROM {$this->bookingNights()} n
				 INNER JOIN {$this->registrations()} r ON n.registration_id = r.id
				 WHERE r.event_id = %d AND r.status IN ($in)" . $exclude_sql . "
				 GROUP BY n.night_key",
				$args
			),
			ARRAY_A
		);
```

`INNER JOIN` i filtr statusu są **obowiązkowe**, nie optymalizacją: kasowanie wierszy przy
anulowaniu to drugi zamek, nie jedyny.

- [ ] **Krok 4: integration PASS, `phpcs`, `analyse`. Krok 5: Commit**

```bash
git commit -am "feat(persistence): store and count the nights a registration occupies"
```

---

## Task 6: `reserve` w trybie dobowym

**Files:**
- Modify: `src/Services/ReservationService.php`, `src/Services/ReservationRequest.php`
- Create: `tests/Integration/Services/NightReservationTest.php`

**Interfaces:**
- Consumes: Taski 1–5.
- Produces: `ReservationRequest` z `?NightSelection $nights = null` (dziewiąty parametr,
  domyślny — istniejące wywołania bez zmian); `reserve` z gałęzią `nights`;
  `ReservationResult::accommodationFull( string $night_key = '' )` niesie klucz
  blokującej doby.

- [ ] **Krok 1: Testy**

```php
	public function test_pair_on_two_nights_takes_two_seats_from_each(): void {
	public function test_second_night_full_refuses_the_whole_selection(): void {
		// Cel 5: nie przyjmujemy części po cichu — ani wiersza dla wolnej doby.
	}
	public function test_refusal_names_the_blocking_night(): void {
	public function test_companion_cap_refuses_a_pair_while_singles_still_fit(): void {
	public function test_price_is_the_type_plus_the_nights(): void {
	public function test_answers_json_stores_the_night_keys_as_a_plain_list(): void {
		// B15: lista kluczy, nigdy obiekt.
	}
	public function test_waitlisted_registration_holds_no_night_rows_but_keeps_its_wish(): void {
	public function test_event_cap_and_type_cap_still_decide_first(): void {
	public function test_lock_is_taken_before_the_first_occupancy_read(): void {
```

- [ ] **Krok 2: FAIL. Krok 3: implementacja**

Na wejściu `reserve` jedno rozgałęzienie:

```php
		$nights_config = NightsConfig::fromArray( $accommodation_raw );

		if ( $nights_config->isNights() ) {
			return $this->reserveNights( $request, $nights_config, $settings, $types );
		}
```

`reserveNights` powtarza szkielet transakcji ścieżki klasycznej (lock → zajętość →
limit globalny i typu → bramka → INSERT → COMMIT → hooki), podmieniając bramkę na
`NightCapacity::blockingNight` i zapis na `replaceBookingNights`. **Nie** woła
`AccommodationConfig::fromArray`.

- [ ] **Krok 4: integration PASS (cały suite — testy trybu `classic` bez zmian).
      Krok 5: `phpcs`, `analyse`. Krok 6: Commit**

```bash
git commit -am "feat(reservation): reserve seats on each selected night"
```

---

## Task 7: Edycja, anulowanie, awans

**Files:**
- Modify: `src/Services/ReservationService.php`
- Create: `tests/Integration/Services/NightEditTest.php`

- [ ] **Krok 1: Testy**

```php
	public function test_edit_that_moves_the_set_rewrites_the_rows_and_the_price(): void {
	public function test_edit_that_changes_nothing_keeps_the_rows_and_the_price(): void {
		// Jak B-occupancy dla slotu: niezwiązana edycja nie może przeliczyć ceny
		// według bieżącego cennika ani skasować wierszy.
	}
	public function test_edit_into_a_full_night_is_refused_naming_it(): void {
	public function test_cancel_frees_the_seats(): void {
	public function test_promotion_reserves_the_nights_the_waitlisted_wish_named(): void {
		// Lepiej niż classic (B12): życzenie jest w JSON-ie, więc awans ma co rozstrzygnąć.
	}
	public function test_promotion_into_a_full_night_promotes_without_accommodation(): void {
```

- [ ] **Krok 2: FAIL. Krok 3: implementacja** — `editAnswersNights`,
      `promoteFromWaitlistNights`; `cancel` woła `deleteBookingNights` obok dzisiejszego
      kasowania bookingu (jedna metoda repo, żeby nie dało się wywołać połowy).
      Wykrywanie „nic się nie zmieniło" porównuje **posortowane listy kluczy** i flagę
      towarzyszącej.
- [ ] **Krok 4: integration PASS. Krok 5: `phpcs`, `analyse`. Krok 6: Commit**

```bash
git commit -am "feat(reservation): keep night rows consistent across edits, cancels and promotions"
```

---

## Task 8: Formularz publiczny — checkboxy, submit, lustro

**Files:**
- Modify: `src/Frontend/FormRenderer.php`, `src/Frontend/SubmissionAssembler.php`
- Modify: `src/Domain/Validation/Validators/AccommodationValidator.php`
- Modify: `assets/public/form.js`
- Create: `tests/Integration/Frontend/NightFormTest.php`

**Interfaces:**
- Kontrakt DOM: `name="evreg_field[<klucz>][nights][]"`, wartość = klucz doby;
  atrybuty `data-evreg-night-seats-left` i `data-evreg-night-pairs-left`
  (pusty = bez limitu).
- `AssembledSubmission` niesie `?NightSelection`; `$values[<klucz>]` dla pola noclegu
  w trybie `nights` to **lista kluczy dób**.

- [ ] **Krok 1: Testy**

```php
	public function test_nights_render_as_checkboxes_under_the_field_namespace(): void {
	public function test_full_night_is_disabled_with_a_note(): void {
	public function test_night_with_seats_but_no_pair_room_is_disabled_only_for_a_pair(): void {
	public function test_submit_extracts_the_selected_night_keys(): void {
	public function test_required_accommodation_rejects_an_empty_selection(): void {
	public function test_allow_none_accepts_an_empty_selection(): void {
	public function test_blocking_night_comes_back_as_a_field_error_naming_it(): void {
		// Re-render MUSI iść z $assembled->rawAnswers(), nie values() — patrz B-slots.
	}
```

- [ ] **Krok 2: FAIL. Krok 3: renderer** — w `renderAccommodation` rozgałęzienie po
      `mode`; gałąź `nights` renderuje checkbox per doba z etykietą, dopiskiem
      `(brak miejsc)` / `(brak miejsc dla dwóch osób)` i atrybutami. Zaznaczenie
      z wyłączonej doby jest jawnie zdejmowane z notką.
- [ ] **Krok 4: `SubmissionAssembler`** — `extractAnswers` dla pola noclegu w trybie
      `nights` czyta `$raw['nights']` jako listę stringów; `extractSelection` zwraca
      `NightSelection`. **Nie woła `AccommodationConfig::fromArray`** (dotyczy też
      `extractCompanion`, które w tym trybie czyta flagi z `NightsConfig`).
- [ ] **Krok 5: `form.js`** — nowa funkcja obok istniejących, nie zamiast:

```js
	// Lustro EvReg\Domain\Capacity\NightCapacity: doba zamknięta, gdy brakuje miejsc
	// dla potrzebnej liczby osób albo gdy para nie mieści się w limicie par.
	function applyNightState( form ) {
		var checkbox = form.querySelector( 'input[name="evreg_companion"]' );
		var companion = !! ( checkbox && checkbox.checked );
		var needed = companion ? 2 : 1;

		form.querySelectorAll( '[data-evreg-night-seats-left]' ).forEach( function ( el ) {
			var left = parseInt( el.getAttribute( 'data-evreg-night-seats-left' ), 10 );
			var pairs = el.getAttribute( 'data-evreg-night-pairs-left' );
			var full = ( ! isNaN( left ) && left < needed )
				|| ( companion && '' !== pairs && parseInt( pairs, 10 ) < 1 );

			el.disabled = full;
			if ( full && el.checked ) { el.checked = false; }
			...
		} );
	}
```

Lustro trybu `classic` (`seatsFor`, mismatch po `occupancy`) **zostaje bez zmian**.

- [ ] **Krok 6: integration PASS, `npm run test:js`, `phpcs`, `analyse`. Commit**

```bash
git commit -am "feat(form): pick nights with checkboxes and mirror their availability"
```

---

## Task 9: Admin — przełącznik trybu i lista dób

**Files:**
- Modify: `src/Rest/EventConfigController.php`
- Modify: `assets/admin/ops/accommodationOps.js` + test, `tabs/AccommodationTab.jsx`
- Create: `tests/Integration/Rest/AccommodationModeTest.php`

**Interfaces:**
- Produces: 409 z kodem `evreg_mode_locked` na zapis zmieniający `mode`, gdy
  `eventHasAccommodation()`; blok `stats` w payloadzie GET (`mode_locked` + liczba
  rezerwacji per doba); ops: `setMode`, `addNight`, `removeNight`, `setNightField`.

- [ ] **Krok 1: Testy REST**

```php
	public function test_changing_the_mode_is_refused_when_the_event_has_accommodation(): void {
	public function test_changing_the_mode_is_allowed_when_only_cancelled_registrations_exist(): void {
	public function test_saving_the_same_mode_is_not_refused(): void {
	public function test_payload_reports_the_lock_and_the_per_night_counts(): void {
	public function test_sanitize_passes_the_new_keys_through(): void {
```

- [ ] **Krok 2: FAIL. Krok 3: kontroler** — blokada **przed** zapisem meta; serwer jest
      arbitrem, React tylko pokazuje powód.
- [ ] **Krok 4: ops + jest** — `removeNight` kasuje dobę z listy; **nie** tyka
      pakietów ani inwentarza (w tym trybie nieużywane). Ostrzeżenie, gdy usuwana doba
      ma rezerwacje (z `stats`).
- [ ] **Krok 5: UI** — przełącznik trybu na górze zakładki (disabled + `Notice`
      z powodem, gdy `mode_locked`); w trybie `nights` sekcje pakietów/pokoi/inwentarza
      **ukryte**; lista dób z polami klucz / etykieta / cena / miejsca / limit par
      i podpisem „puste = bez limitu".
- [ ] **Krok 6: `npm run test:js`, `npm run build`, integration PASS. Commit**

```bash
git commit -am "feat(admin): switch an event between classic and nights accommodation"
```

---

## Task 10: Admin — edycja, detal, panel obłożenia

**Files:**
- Modify: `src/Admin/RegistrationEditForm.php`, `RegistrationsScreen.php`, `RegistrationsOverview.php`
- Modify: `src/Domain/Capacity/OccupancyReport.php`
- Modify odpowiednie testy integracyjne

- [ ] **Krok 1: Testy** — edycja renderuje checkboxy **z zaznaczonymi** dobami
      z JSON-a (nośne: to jest dokładnie błąd B15 na ścieżce klasycznej, którego tu nie
      dziedziczymy); detal pokazuje etykiety i ceny z wierszy; panel pokazuje per dobę
      miejsca zajęte/limit/wolne oraz pary/limit par.
- [ ] **Krok 2: FAIL. Krok 3: implementacja** (`OccupancyReport` dostaje metodę dla dób;
      panel w trybie `classic` zostaje dzisiejszy). **Krok 4: testy PASS, `phpcs`,
      `analyse`. Commit**

```bash
git commit -am "feat(admin): edit, show and total up a nights reservation"
```

---

## Task 11: Eksport, mail, tłumaczenia

**Files:**
- Modify: `src/Domain/Export/RegistrationExportMapper.php` + test
- Modify: `src/Admin/RegistrationsExporter.php`, `src/Mail/PlaceholderFactory.php`
- Modify: `src/Domain/Schema/ContentTranslator.php` + test
- Modify: `assets/admin/ops/i18nOps.js` + test, `tabs/TranslationsTab.jsx`

- [ ] **Krok 1: Testy** — kolumna „Doby" z etykiet wierszy (bulk odczyt, bez N+1);
      placeholder `{noclegi}`; bucket `nights` w overlayu nakładający **tylko etykiety**;
      `langBucket` whitelistuje `accommodation` (test na regresję).
- [ ] **Krok 2: FAIL. Krok 3: implementacja. Krok 4: testy PASS + `npm run test:js`,
      `phpcs`, `analyse`. Commit**

```bash
git commit -am "feat(export): put the nights in the CSV, the mail and the translations"
```

---

## Task 12: e2e, dokumentacja, wydanie

**Files:**
- Create: `tests/e2e/night-seats.spec.js`, `tests/e2e/setup-night-seats.php`
- Modify: `CLAUDE.md`, `CHANGELOG.md`, `languages/event-registration.pot`

- [ ] **Krok 1: e2e** — event w trybie `nights`, dwie doby, druga z jednym miejscem:
      singiel zaznacza obie i przechodzi; druga osoba z towarzyszącą widzi drugą dobę
      wyłączoną z dopiskiem, a odznaczenie towarzyszącej ją otwiera.
- [ ] **Krok 2: `npm run test:e2e`**
- [ ] **Krok 3: `CLAUDE.md`** — dwa tryby noclegu i przełącznik na poziomie eventu,
      inwariant „w trybie `nights` nic nie konstruuje `AccommodationConfig`", dwie
      konwencje pustki, snapshot etykiety i ceny na wierszu doby, lista kluczy w JSON-ie
      odpowiedzi (z odsyłaczem do B15), tabela `booking_nights`, db v7.
- [ ] **Krok 4: `CHANGELOG.md`** w `## [Unreleased]`, po angielsku, user-facing.
- [ ] **Krok 5: `npm run i18n:pot`**
- [ ] **Krok 6: CAŁE bramki** — unit, integration, `composer analyse`, `phpcs`,
      `npm run test:js`, `npm run test:e2e`
- [ ] **Krok 7: `bash scripts/build-zip.sh`. Krok 8: Commit**

```bash
git commit -am "docs: record the nights accommodation mode and refresh the catalog"
```

---

## Samoprzegląd planu

**Pokrycie speca.** Konfiguracja i doby → Task 1. Zbiór dób i zajętość → Task 2. Bramka
(oba limity, odrzucenie całości) → Task 3. Cena (typ raz) → Task 4. Baza i SQL, predykat
blokady → Task 5. Ścieżki transakcyjne → Taski 6–7. Formularz, submit, lustro → Task 8.
Przełącznik trybu, lista dób, `stats` → Task 9. Edycja, detal, panel → Task 10. CSV, mail,
i18n → Task 11. e2e i dokumentacja → Task 12.

**Spójność typów.** `NightsConfig` (T1) jest wejściem `NightSelection::fromKeys` (T2),
`NightCapacity::blockingNight` (T3) i `PriceCalculator::totalForNights` (T4).
`NightOccupancy` (T2) produkuje repozytorium (T5) i konsumują T3 oraz `OccupancyReport`
(T10). `ReservationRequest->nights` (T6) wypełnia `SubmissionAssembler` (T8) — **Task 6
dostaje go przed Taskiem 8**, więc do czasu T8 gałąź `nights` jest wołana tylko z testów
integracyjnych, co jest zamierzone: pozwala zamknąć transakcję przed formularzem.

**Ryzyka.** (1) Ośmiu wejść czytających dziś `AccommodationConfig` dotyczy inwariant
z Global Constraints — gałąź `nights` nie może go konstruować; `NightsConfigTest`
pilnuje tego testem z **niespójnym inwentarzem**. (2) Task 8 zmienia `values()` dla pola
noclegu, więc re-render błędu musi iść z `rawAnswers()` (B-slots). (3) Task 5 i 7 muszą
iść razem: kasowanie wierszy dób i bookingu siedzi w jednej metodzie repo.
