# Wyczerpane sloty noclegowe — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wyczerpane sloty noclegowe wyłączają się na formularzu publicznym, a zgłoszenie, którego nocleg przepadł między renderem a submitem, nie zapisuje się po cichu bez łóżka.

**Architecture:** Nowy czysty obiekt `SlotAvailability` (domena) liczy wolne miejsca per slot z `AccommodationConfig` + `OccupancySnapshot`. `Shortcode` wstrzykuje go do `FormRenderer`, który wyłącza niedostępne sloty serwerowo; `form.js` dokłada tylko reakcję na przełącznik osoby towarzyszącej (2 miejsca). Po stronie transakcyjnej `ReservationService::reserve` robi ROLLBACK i zwraca nowy kod `accommodation_full`, który `SubmitHandler` zamienia na błąd walidacji przy polu noclegu — ten sam wzór, co istniejące `editAnswers`.

**Tech Stack:** PHP 8.1, WordPress 6.4+, PHPUnit (unit + integration w wp-env), Playwright (e2e), phpcs/PHPStan L6. Bez nowych zależności.

**Spec:** `docs/superpowers/specs/2026-11-16-exhausted-accommodation-slots-design.md`

## Global Constraints

- `src/Domain/**` — zero WordPressa i zero `$wpdb`; pilnuje `tests/Unit/Architecture/DomainPurityTest.php`.
- Domena zwraca kody, nigdy komunikaty dla użytkownika.
- Każdy plik PHP poza `src/Domain/` i `tests/` zaczyna się od `defined( 'ABSPATH' ) || exit;`.
- Wszystkie stringi UI przez `__()` / `esc_html__()`, text domain `event-registration`.
- Inwariant 3A: w `reserve` / `editAnswers` / `promoteFromWaitlist` `lockEvent()` MUSI poprzedzać pierwszy COUNT zajętości. Ten plan nie zmienia tej kolejności w żadnym miejscu.
- Styl kodu: `array()` (nie `[]`), Yoda conditions (`'' === $x`), taby, docblock z `@param` dla każdego parametru. phpcs musi przejść czysto pod `phpcs.xml.dist`, PHPStan poziom 6 bez nowych `@phpstan-ignore`.
- Pola schematu renderują się pod `name="evreg_field[<klucz>]"`; kontrolki `evreg_companion` / `evreg_companion_name` zostają poza tym namespace.
- Komendy PHP lecą przez wrapper, nigdy gołym `npx wp-env`:
  `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- <cmd>`
- `.gitattributes` wymusza LF — nie wprowadzaj CRLF.

---

### Task 1: `SlotAvailability` — czysta arytmetyka wolnych miejsc

**Files:**
- Create: `src/Domain/Accommodation/SlotAvailability.php`
- Test: `tests/Unit/Domain/Accommodation/SlotAvailabilityTest.php`

**Interfaces:**
- Consumes: `EvReg\Domain\Accommodation\AccommodationConfig::capacities(): array<string,int>`, `EvReg\Domain\Capacity\OccupancySnapshot::forSlot( string ): int`.
- Produces: `SlotAvailability::fromSnapshot( AccommodationConfig, OccupancySnapshot ): self`, `->left( string $slot ): int`, `->isFull( string $slot, int $seats = 1 ): bool`. Zadania 2 i 4 używają `fromSnapshot` i `left`.

- [ ] **Step 1: Write the failing test**

Utwórz `tests/Unit/Domain/Accommodation/SlotAvailabilityTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Accommodation;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Accommodation\SlotAvailability;
use EvReg\Domain\Capacity\OccupancySnapshot;
use PHPUnit\Framework\TestCase;

final class SlotAvailabilityTest extends TestCase {

	private function config(): AccommodationConfig {
		return AccommodationConfig::fromArray(
			array(
				'packages'  => array( array( 'key' => 'n18', 'label' => 'Noc 18/19' ) ),
				'rooms'     => array(
					array( 'key' => 'single', 'label' => '1-os.' ),
					array( 'key' => 'double', 'label' => '2-os.' ),
				),
				'inventory' => array(
					array( 'package' => 'n18', 'room' => 'single', 'capacity' => 10, 'price' => 100.0 ),
					array( 'package' => 'n18', 'room' => 'double', 'capacity' => 1, 'price' => 180.0 ),
				),
			)
		);
	}

	public function test_left_subtracts_taken_seats(): void {
		$availability = SlotAvailability::fromSnapshot(
			$this->config(),
			new OccupancySnapshot( 4, array(), array( 'n18|single' => 4 ) )
		);

		$this->assertSame( 6, $availability->left( 'n18|single' ) );
	}

	public function test_left_never_goes_below_zero(): void {
		$availability = SlotAvailability::fromSnapshot(
			$this->config(),
			new OccupancySnapshot( 5, array(), array( 'n18|double' => 5 ) )
		);

		$this->assertSame( 0, $availability->left( 'n18|double' ) );
	}

	public function test_empty_snapshot_leaves_full_capacity(): void {
		$availability = SlotAvailability::fromSnapshot( $this->config(), new OccupancySnapshot( 0 ) );

		$this->assertSame( 10, $availability->left( 'n18|single' ) );
		$this->assertSame( 1, $availability->left( 'n18|double' ) );
	}

	public function test_unknown_slot_has_no_seats(): void {
		$availability = SlotAvailability::fromSnapshot( $this->config(), new OccupancySnapshot( 0 ) );

		$this->assertSame( 0, $availability->left( 'n18|suite' ) );
		$this->assertTrue( $availability->isFull( 'n18|suite' ) );
	}

	public function test_single_free_seat_is_full_for_companion_only(): void {
		$availability = SlotAvailability::fromSnapshot( $this->config(), new OccupancySnapshot( 0 ) );

		$this->assertFalse( $availability->isFull( 'n18|double', 1 ) );
		$this->assertTrue( $availability->isFull( 'n18|double', 2 ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit --filter SlotAvailabilityTest
```

Oczekiwane: FAIL, `Class "EvReg\Domain\Accommodation\SlotAvailability" not found`. Uwaga z CLAUDE.md: konsola PHPUnit w tym kontenerze zniekształca komunikat niezłapanego `Error`. Jeśli komunikat jest nieczytelny, nie zgaduj — potwierdź fazę RED jawnym `try/catch` drukującym `get_class($e)` i `getMessage()`.

- [ ] **Step 3: Write minimal implementation**

Utwórz `src/Domain/Accommodation/SlotAvailability.php` (bez `defined( 'ABSPATH' )` — to `src/Domain/`):

```php
<?php
/**
 * Dostępność miejsc w slotach zakwaterowania.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Accommodation;

use EvReg\Domain\Capacity\OccupancySnapshot;

/**
 * Liczba wolnych miejsc per slot "pakiet|pokój". Czysta arytmetyka — bez WordPressa.
 */
final class SlotAvailability {

	/**
	 * Tworzy dostępność z gotowej mapy wolnych miejsc.
	 *
	 * @param array<string,int> $left Mapa slot "pakiet|pokój" => liczba wolnych miejsc.
	 */
	private function __construct( private readonly array $left ) {
	}

	/**
	 * Składa dostępność z konfiguracji zakwaterowania i migawki obłożenia.
	 *
	 * @param AccommodationConfig $config Konfiguracja zakwaterowania eventu.
	 * @param OccupancySnapshot   $taken  Migawka aktualnego obłożenia.
	 */
	public static function fromSnapshot( AccommodationConfig $config, OccupancySnapshot $taken ): self {
		$left = array();

		foreach ( $config->capacities() as $slot => $capacity ) {
			$left[ $slot ] = max( 0, $capacity - $taken->forSlot( $slot ) );
		}

		return new self( $left );
	}

	/**
	 * Zwraca liczbę wolnych miejsc w slocie. Slot nieobecny w inwentarzu => 0
	 * (lustro CapacityCalculator, gdzie brak limitu slotu też znaczy "nieprzyznany").
	 *
	 * @param string $slot Klucz slotu "pakiet|pokój".
	 */
	public function left( string $slot ): int {
		return $this->left[ $slot ] ?? 0;
	}

	/**
	 * Czy slot nie pomieści żądanej liczby miejsc.
	 *
	 * @param string $slot  Klucz slotu "pakiet|pokój".
	 * @param int    $seats Liczba potrzebnych miejsc (2 przy osobie towarzyszącej).
	 */
	public function isFull( string $slot, int $seats = 1 ): bool {
		return $this->left( $slot ) < $seats;
	}
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
```

Oczekiwane: PASS, w tym `DomainPurityTest` (nowy plik nie zawiera żadnego ciągu z denylisty).

- [ ] **Step 5: Lint**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
```

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=2G
```

- [ ] **Step 6: Commit**

```bash
git add src/Domain/Accommodation/SlotAvailability.php tests/Unit/Domain/Accommodation/SlotAvailabilityTest.php && git commit -m "feat(domain): SlotAvailability counts free seats per accommodation slot"
```

---

### Task 2: Renderer wyłącza niedostępne sloty

**Files:**
- Modify: `src/Frontend/FormRenderer.php` (konstruktor; `renderField`; `renderControl`; `renderAccommodation`)
- Modify: `assets/public/form.css` (styl `.evreg-choice.is-full`)
- Test: `tests/Integration/Frontend/AccommodationAvailabilityRenderTest.php` (nowy)

**Interfaces:**
- Consumes: `SlotAvailability::left()` z Taska 1; `SubmitResult::submittedValue( 'evreg_companion' )`.
- Produces: `new FormRenderer( ?SlotAvailability $availability = null )` — Task 4 woła z argumentem. HTML: `disabled` + klasa `is-full` + `data-evreg-seats-left="<n>"` na `<input>` niedostępnego slotu — Task 7 (`form.js`) czyta ten atrybut.

- [ ] **Step 1: Write the failing test**

Utwórz `tests/Integration/Frontend/AccommodationAvailabilityRenderTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Frontend;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Accommodation\SlotAvailability;
use EvReg\Domain\Capacity\OccupancySnapshot;
use EvReg\Domain\Schema\FormSchema;
use EvReg\Frontend\FormRenderer;
use EvReg\Frontend\SubmitResult;
use WP_UnitTestCase;

final class AccommodationAvailabilityRenderTest extends WP_UnitTestCase {

	/**
	 * @return array<string,mixed>
	 */
	private function accommodationConfig(): array {
		return array(
			'packages'          => array( array( 'key' => 'n18', 'label' => 'Noc 18/19' ) ),
			'rooms'             => array(
				array( 'key' => 'single', 'label' => 'Jednoosobowy' ),
				array( 'key' => 'double', 'label' => 'Dwuosobowy' ),
			),
			'inventory'         => array(
				array( 'package' => 'n18', 'room' => 'single', 'capacity' => 1, 'price' => 100.0 ),
				array( 'package' => 'n18', 'room' => 'double', 'capacity' => 5, 'price' => 180.0 ),
			),
			'allow_none'        => true,
			'companion_enabled' => true,
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
								'key'    => 'nocleg',
								'type'   => 'accommodation',
								'label'  => 'Nocleg',
								'config' => $this->accommodationConfig(),
							),
						),
					),
				),
			)
		);
	}

	/**
	 * @param array<string,int> $perSlot
	 */
	private function availability( array $perSlot ): SlotAvailability {
		return SlotAvailability::fromSnapshot(
			AccommodationConfig::fromArray( $this->accommodationConfig() ),
			new OccupancySnapshot( 0, array(), $perSlot )
		);
	}

	public function test_exhausted_slot_is_disabled_and_annotated(): void {
		$html = ( new FormRenderer( $this->availability( array( 'n18|single' => 1 ) ) ) )
			->render( $this->schema(), 1 );

		$this->assertMatchesRegularExpression( '/value="n18\|single"[^>]*disabled/', $html );
		$this->assertStringContainsString( '(brak miejsc)', $html );
		$this->assertStringContainsString( 'evreg-choice form-check is-full', $html );
	}

	public function test_slot_with_room_left_stays_enabled(): void {
		$html = ( new FormRenderer( $this->availability( array( 'n18|single' => 1 ) ) ) )
			->render( $this->schema(), 1 );

		$this->assertDoesNotMatchRegularExpression( '/value="n18\|double"[^>]*disabled/', $html );
		$this->assertStringContainsString( 'value="n18|double" data-evreg-seats-left="5"', $html );
	}

	public function test_single_free_seat_is_too_small_for_companion(): void {
		$result = SubmitResult::invalid(
			array( 'nocleg' => 'invalid_accommodation' ),
			array( 'evreg_companion' => true )
		);

		$html = ( new FormRenderer( $this->availability( array( 'n18|double' => 4 ) ) ) )
			->render( $this->schema(), 1, $result );

		$this->assertMatchesRegularExpression( '/value="n18\|double"[^>]*disabled/', $html );
		$this->assertStringContainsString( '(za mało miejsc)', $html );
	}

	public function test_exhausted_slot_is_never_rendered_as_checked(): void {
		$result = SubmitResult::invalid(
			array( 'email' => 'required' ),
			array( 'nocleg' => array( 'package' => 'n18', 'room' => 'single', 'roommate' => '' ) )
		);

		$html = ( new FormRenderer( $this->availability( array( 'n18|single' => 1 ) ) ) )
			->render( $this->schema(), 1, $result );

		$this->assertDoesNotMatchRegularExpression( '/value="n18\|single"[^>]*checked/', $html );
	}

	public function test_renderer_without_availability_renders_everything_enabled(): void {
		$html = ( new FormRenderer() )->render( $this->schema(), 1 );

		$this->assertStringNotContainsString( 'disabled', $html );
		$this->assertStringNotContainsString( 'data-evreg-seats-left', $html );
		$this->assertStringNotContainsString( '(brak miejsc)', $html );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter AccommodationAvailabilityRenderTest
```

Oczekiwane: FAIL — `FormRenderer::__construct()` nie przyjmuje argumentu.

- [ ] **Step 3: Dodaj konstruktor i import do `FormRenderer`**

W bloku `use` dołóż:

```php
use EvReg\Domain\Accommodation\SlotAvailability;
```

Zaraz po `final class FormRenderer {` wstaw:

```php
	/**
	 * Tworzy renderer. Bez dostępności (null) wszystkie sloty noclegowe renderują się aktywne.
	 *
	 * @param SlotAvailability|null $availability Dostępność miejsc w slotach albo null.
	 */
	public function __construct( private readonly ?SlotAvailability $availability = null ) {
	}

	/**
	 * Ile miejsc zajmie to zgłoszenie w slocie noclegowym: 2 przy osobie towarzyszącej.
	 *
	 * @param SubmitResult|null $result Wynik poprzedniej próby submisji.
	 */
	private function seatsNeeded( ?SubmitResult $result ): int {
		return null !== $result && ! empty( $result->submittedValue( 'evreg_companion' ) ) ? 2 : 1;
	}
```

- [ ] **Step 4: Przewiąż liczbę miejsc do kontrolki**

W `renderField`, zaraz po linii `$invalid = null !== $error;`, dodaj:

```php
		$seats   = $this->seatsNeeded( $result );
```

Następnie w obu gałęziach `renderField` zamień wywołania:

- `$out .= $this->renderControl( $field, $value, $invalid );` → `$out .= $this->renderControl( $field, $value, $invalid, $seats );` (dwa miejsca: gałąź `FieldType::Checkbox` i gałąź `else`).

W `renderControl` zmień sygnaturę i docblock:

```php
	/**
	 * Renderuje kontrolkę pola odpowiednią dla jego typu.
	 *
	 * @param Field $field   Pole formularza.
	 * @param mixed $value   Wpisana wartość do odtworzenia (lub null).
	 * @param bool  $invalid Czy pole ma błąd walidacji.
	 * @param int   $seats   Ile miejsc zajmie zgłoszenie w slocie noclegu (2 z osobą towarzyszącą).
	 */
	private function renderControl( Field $field, mixed $value, bool $invalid, int $seats = 1 ): string {
```

(zachowaj istniejący docblock w zakresie pozostałych `@param`, dołóż tylko `$seats`)

oraz gałąź:

```php
			case FieldType::Accommodation:
				return $this->renderAccommodation( $field, $value, $seats );
```

- [ ] **Step 5: Przebuduj `renderAccommodation`**

Zamień całe ciało `renderAccommodation` na poniższe. Zmiana polega na rozdzieleniu „zbierz wiersze" od „wyrenderuj" — stan dostępności musi być znany, zanim cokolwiek pójdzie na wyjście (Task 3 skorzysta z tego samego `$rows`).

```php
	/**
	 * Renderuje kontrolkę wyboru noclegu (pakiet × pokój + współlokator).
	 *
	 * @param Field $field Pole typu accommodation.
	 * @param mixed $value Wpisana wartość do odtworzenia (lub null).
	 * @param int   $seats Ile miejsc zajmie zgłoszenie w slocie (2 z osobą towarzyszącą).
	 */
	private function renderAccommodation( Field $field, mixed $value, int $seats = 1 ): string {
		$config    = $field->config;
		$packages  = is_array( $config['packages'] ?? null ) ? $config['packages'] : array();
		$rooms     = is_array( $config['rooms'] ?? null ) ? $config['rooms'] : array();
		$inventory = is_array( $config['inventory'] ?? null ) ? $config['inventory'] : array();
		$available = array();
		foreach ( $inventory as $item ) {
			$available[ (string) ( $item['package'] ?? '' ) . '|' . (string) ( $item['room'] ?? '' ) ] = $item;
		}

		$current = is_array( $value ) ? ( (string) ( $value['package'] ?? '' ) . '|' . (string) ( $value['room'] ?? '' ) ) : '';

		// Pokoje z włączonym polem współlokatora — tylko dla nich renderujemy
		// (i pokazujemy) pole „Preferowana osoba w pokoju". Flaga jest per pokój.
		$roommateRooms = array();
		foreach ( $rooms as $room ) {
			if ( ! empty( $room['roommate_field'] ) ) {
				$roommateRooms[ (string) ( $room['key'] ?? '' ) ] = true;
			}
		}

		// Najpierw zbieramy wiersze z dostępnością, dopiero potem renderujemy: stan
		// „wybrany slot jest niedostępny" musi być znany, zanim wypiszemy pierwsze radio.
		$rows = array();
		foreach ( $packages as $pkg ) {
			foreach ( $rooms as $room ) {
				$slot = (string) ( $pkg['key'] ?? '' ) . '|' . (string) ( $room['key'] ?? '' );
				if ( ! isset( $available[ $slot ] ) ) {
					continue;
				}
				$rows[] = array(
					'slot'     => $slot,
					'label'    => (string) ( $pkg['label'] ?? '' ) . ' — ' . (string) ( $room['label'] ?? '' ),
					'roommate' => isset( $roommateRooms[ (string) ( $room['key'] ?? '' ) ] ),
					'left'     => null === $this->availability ? null : $this->availability->left( $slot ),
					'closed'   => null !== $this->availability && $this->availability->isFull( $slot, $seats ),
				);
			}
		}

		// Namespace pól pod evreg_field[...], by nazwy pól NIE kolidowały z publicznymi
		// query-vars WordPressa (name/page/p/s/cat/author/...). WP czyta te vary także
		// z $_POST przy POST-to-self → surowe name="name" korumpuje główne zapytanie (404).
		$name  = 'evreg_field[' . esc_attr( $field->key ) . ']';
		$base  = 'evreg-' . esc_attr( $field->key );
		$index = 0;
		$out   = '<div class="evreg-accommodation">';

		$none_id = $base . '-slot-' . $index++;
		$out    .= '<div class="evreg-choice form-check">'
			. '<input class="form-check-input" type="radio" id="' . $none_id . '" name="' . $name . '[slot]" value=""' . checked( '', $current, false ) . '>'
			. ' <label class="form-check-label" for="' . $none_id . '">' . esc_html__( 'Bez noclegu', 'event-registration' ) . '</label>'
			. '</div>';

		foreach ( $rows as $row ) {
			$slot_id = $base . '-slot-' . $index++;
			$note    = '';
			if ( $row['closed'] ) {
				$note = ' ' . ( null !== $row['left'] && $row['left'] < 1
					? esc_html__( '(brak miejsc)', 'event-registration' )
					: esc_html__( '(za mało miejsc)', 'event-registration' ) );
			}
			$seats_attr = null === $row['left'] ? '' : ' data-evreg-seats-left="' . esc_attr( (string) $row['left'] ) . '"';
			// Niedostępny slot NIGDY nie renderuje się jako wybrany: `disabled` nie wysyła
			// wartości, więc zaznaczony-i-wyłączony radio dałby po submicie pusty [slot]
			// = „Bez noclegu" bez wiedzy zgłaszającego się (cichy downgrade).
			$checked  = ! $row['closed'] && $row['slot'] === $current;
			$roommate = $row['roommate'] ? ' data-evreg-roommate="1"' : '';
			$out     .= '<div class="evreg-choice form-check' . ( $row['closed'] ? ' is-full' : '' ) . '">'
				. '<input class="form-check-input" type="radio" id="' . $slot_id . '" name="' . $name . '[slot]" value="' . esc_attr( $row['slot'] ) . '"' . checked( $checked, true, false ) . ( $row['closed'] ? ' disabled' : '' ) . $seats_attr . $roommate . '>'
				. ' <label class="form-check-label" for="' . $slot_id . '">' . esc_html( $row['label'] ) . $note . '</label>'
				. '</div>';
		}

		// Pole współlokatora renderujemy tylko gdy JAKIKOLWIEK pokój je włącza.
		// Widoczność startową wyznacza wybrany pokój (poprawne bez JS przy
		// re-renderze błędu); form.js przełącza ją reaktywnie przy zmianie pokoju.
		if ( array() !== $roommateRooms ) {
			$separator   = strpos( $current, '|' );
			$currentRoom = ( '' !== $current && false !== $separator ) ? substr( $current, $separator + 1 ) : '';
			$hidden      = isset( $roommateRooms[ $currentRoom ] ) ? '' : ' hidden';
			$roommateVal = is_array( $value ) ? (string) ( $value['roommate'] ?? '' ) : '';
			$out        .= '<input class="form-control mt-2" type="text" data-evreg-roommate-input name="' . $name . '[roommate]" placeholder="' . esc_attr__( 'Preferowana osoba w pokoju', 'event-registration' ) . '" value="' . esc_attr( $roommateVal ) . '"' . $hidden . '>';
		}

		$out .= '</div>';

		return $out;
	}
```

- [ ] **Step 6: Dołóż styl wyszarzenia**

Na końcu `assets/public/form.css` dopisz:

```css
.evreg-choice.is-full > label { opacity: .55; }
```

- [ ] **Step 7: Run tests to verify they pass**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
```

Oczekiwane: PASS, w tym istniejące `FormRendererTest`, `OptionDescriptionRenderTest`, `ShortcodeTest` (konstruktor bez argumentu nadal działa).

- [ ] **Step 8: Lint**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
```

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=2G
```

- [ ] **Step 9: Commit**

```bash
git add src/Frontend/FormRenderer.php assets/public/form.css tests/Integration/Frontend/AccommodationAvailabilityRenderTest.php && git commit -m "feat(form): disable exhausted accommodation slots on the public form"
```

---

### Task 3: Porzucony wybór przenosi się na „Bez noclegu" i jest ogłoszony

**Files:**
- Modify: `src/Frontend/FormRenderer.php` (`renderAccommodation`)
- Test: `tests/Integration/Frontend/AccommodationAvailabilityRenderTest.php` (dopisanie przypadków)

**Interfaces:**
- Consumes: tablica `$rows` zbudowana w Tasku 2.
- Produces: `<p class="evreg-accommodation-dropped">` oraz `<p class="evreg-accommodation-empty">` w HTML — nic poza testami tego nie konsumuje.

- [ ] **Step 1: Write the failing test**

Dopisz do `AccommodationAvailabilityRenderTest`:

```php
	public function test_dropped_selection_moves_to_none_and_is_announced(): void {
		$result = SubmitResult::invalid(
			array( 'email' => 'required' ),
			array( 'nocleg' => array( 'package' => 'n18', 'room' => 'single', 'roommate' => '' ) )
		);

		$html = ( new FormRenderer( $this->availability( array( 'n18|single' => 1 ) ) ) )
			->render( $this->schema(), 1, $result );

		$this->assertMatchesRegularExpression( '/name="evreg_field\[nocleg\]\[slot\]" value=""[^>]*checked/', $html );
		$this->assertStringContainsString( 'Twój wcześniejszy wybór noclegu jest już niedostępny.', $html );
	}

	public function test_kept_selection_is_not_announced_as_dropped(): void {
		$result = SubmitResult::invalid(
			array( 'email' => 'required' ),
			array( 'nocleg' => array( 'package' => 'n18', 'room' => 'double', 'roommate' => '' ) )
		);

		$html = ( new FormRenderer( $this->availability( array( 'n18|single' => 1 ) ) ) )
			->render( $this->schema(), 1, $result );

		$this->assertMatchesRegularExpression( '/value="n18\|double"[^>]*checked/', $html );
		$this->assertStringNotContainsString( 'jest już niedostępny', $html );
	}

	public function test_empty_submitted_selection_checks_none(): void {
		$result = SubmitResult::invalid(
			array( 'email' => 'required' ),
			array( 'nocleg' => array( 'package' => '', 'room' => '', 'roommate' => '' ) )
		);

		$html = ( new FormRenderer( $this->availability( array() ) ) )->render( $this->schema(), 1, $result );

		$this->assertMatchesRegularExpression( '/name="evreg_field\[nocleg\]\[slot\]" value=""[^>]*checked/', $html );
		$this->assertStringNotContainsString( 'jest już niedostępny', $html );
	}

	public function test_all_slots_closed_without_allow_none_shows_message(): void {
		$config               = $this->accommodationConfig();
		$config['allow_none'] = false;

		$schema = FormSchema::fromArray(
			array(
				'version'  => 1,
				'sections' => array(
					array(
						'key'    => 'dane',
						'title'  => 'Dane',
						'fields' => array(
							array( 'key' => 'nocleg', 'type' => 'accommodation', 'label' => 'Nocleg', 'config' => $config ),
						),
					),
				),
			)
		);

		$availability = SlotAvailability::fromSnapshot(
			AccommodationConfig::fromArray( $config ),
			new OccupancySnapshot( 0, array(), array( 'n18|single' => 1, 'n18|double' => 5 ) )
		);

		$html = ( new FormRenderer( $availability ) )->render( $schema, 1 );

		$this->assertStringContainsString( 'Brak wolnych miejsc noclegowych.', $html );
	}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter AccommodationAvailabilityRenderTest
```

Oczekiwane: FAIL na czterech nowych testach (brak `checked` na „Bez noclegu", brak notki, brak komunikatu).

- [ ] **Step 3: Normalizuj puste `$current`**

W `renderAccommodation`, zaraz po linii ustawiającej `$current`, dodaj:

```php
		// Pusta tablica odpowiedzi daje „|" (puste package i room połączone separatorem) —
		// to NIE jest wybrany slot, lecz brak wyboru. Bez tej normalizacji radio
		// „Bez noclegu" nie zaznaczyłoby się przy re-renderze pustej selekcji.
		if ( '|' === $current ) {
			$current = '';
		}
```

- [ ] **Step 4: Wykryj porzucony wybór i brak otwartych slotów**

W `renderAccommodation`, między pętlą budującą `$rows` a blokiem `$name = ...`, wstaw:

```php
		// Wybór z poprzedniej próby, który stał się niedostępny (albo zniknął z inwentarza),
		// PRZENOSIMY na „Bez noclegu" i ogłaszamy. Pusta grupa radio nie wysyła klucza
		// [slot], a AccommodationValidator przy allow_none tego nie kwestionuje — czyli
		// dokładnie ten cichy downgrade, który ta zmiana likwiduje.
		$dropped  = false;
		$any_open = false;
		foreach ( $rows as $row ) {
			if ( ! $row['closed'] ) {
				$any_open = true;
			}
		}
		if ( '' !== $current ) {
			$still_open = false;
			foreach ( $rows as $row ) {
				if ( $row['slot'] === $current && ! $row['closed'] ) {
					$still_open = true;
				}
			}
			if ( ! $still_open ) {
				$dropped = true;
				$current = '';
			}
		}
```

- [ ] **Step 5: Wypisz notkę i komunikat**

W `renderAccommodation`, bezpośrednio przed końcowym `$out .= '</div>';`, wstaw:

```php
		if ( $dropped ) {
			$out .= '<p class="evreg-accommodation-dropped">'
				. esc_html__( 'Twój wcześniejszy wybór noclegu jest już niedostępny.', 'event-registration' )
				. '</p>';
		}

		// Bez „Bez noclegu" i bez ani jednego otwartego slotu nie ma czego wybrać —
		// powiedz to wprost, zamiast pokazywać listę samych wyszarzonych pozycji.
		if ( ! $any_open && array() !== $rows && false === ( $config['allow_none'] ?? true ) ) {
			$out .= '<p class="evreg-accommodation-empty">'
				. esc_html__( 'Brak wolnych miejsc noclegowych.', 'event-registration' )
				. '</p>';
		}
```

- [ ] **Step 6: Run tests to verify they pass**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
```

- [ ] **Step 7: Lint**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
```

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=2G
```

- [ ] **Step 8: Commit**

```bash
git add src/Frontend/FormRenderer.php tests/Integration/Frontend/AccommodationAvailabilityRenderTest.php && git commit -m "fix(form): move a dropped accommodation choice to none and say so"
```

---

### Task 4: `Shortcode` wstrzykuje dostępność

**Files:**
- Modify: `src/Frontend/Shortcode.php` (`renderForm`, nowa prywatna `availability()`)
- Test: `tests/Integration/Frontend/ShortcodeTest.php` (dopisanie przypadku)

**Interfaces:**
- Consumes: `SlotAvailability::fromSnapshot()` (Task 1), `new FormRenderer( ?SlotAvailability )` (Task 2), `RegistrationRepository::occupancy( int ): OccupancySnapshot`, `EventConfigRepository::get( int )`.
- Produces: nic nowego publicznie — domyka ścieżkę renderowania formularza na froncie.

- [ ] **Step 1: Write the failing test**

`ShortcodeTest::setUp` konfiguruje event z pustym noclegiem (`'accommodation' => array( 'packages' => array(), 'rooms' => array(), 'inventory' => array() )`) i nie instaluje tabel, więc ten test nadpisuje konfigurację i robi to sam.

Dołóż importy do `tests/Integration/Frontend/ShortcodeTest.php`:

```php
use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Services\ReservationRequest;
use EvReg\Services\ReservationService;
```

i dopisz test:

```php
	public function test_shortcode_renders_exhausted_slot_as_disabled(): void {
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'locks' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
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
				'types'         => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 0.0 ) ),
				'accommodation' => array(
					'packages'  => array( array( 'key' => 'n18', 'label' => 'Noc 18/19' ) ),
					'rooms'     => array( array( 'key' => 'single', 'label' => 'Jednoosobowy' ) ),
					'inventory' => array( array( 'package' => 'n18', 'room' => 'single', 'capacity' => 1, 'price' => 0.0 ) ),
				),
			)
		);

		// Zajmij jedyne miejsce przez serwis — obłożenie ma się liczyć jak w produkcji.
		( new ReservationService( new RegistrationRepository(), new EventConfigRepository() ) )->reserve(
			$this->event_id,
			new ReservationRequest(
				'zajete@example.com',
				'Jan',
				'uczestnik',
				array( 'email' => 'zajete@example.com' ),
				new AccommodationSelection( 'n18', 'single' )
			)
		);

		$html = Shortcode::render( array( 'event' => (string) $this->event_id ) );

		$this->assertMatchesRegularExpression( '/value="n18\|single"[^>]*disabled/', $html );
		$this->assertStringContainsString( '(brak miejsc)', $html );
	}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter ShortcodeTest
```

Oczekiwane: FAIL — slot renderuje się aktywny, bo `Shortcode` tworzy `new FormRenderer()` bez dostępności.

- [ ] **Step 3: Dołóż importy i helper**

W bloku `use` w `src/Frontend/Shortcode.php` dodaj:

```php
use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Accommodation\SlotAvailability;
use EvReg\Domain\Schema\SchemaException;
use EvReg\Persistence\RegistrationRepository;
```

Dodaj prywatną metodę:

```php
	/**
	 * Składa dostępność miejsc w slotach noclegowych eventu. Zwraca null, gdy event nie
	 * ma inwentarza noclegów albo konfiguracja jest niepoprawna — renderer bez dostępności
	 * zachowuje się jak przed tą zmianą.
	 *
	 * @param int $event_id ID posta eventu.
	 */
	private static function availability( int $event_id ): ?SlotAvailability {
		$config = ( new EventConfigRepository() )->get( $event_id );
		$raw    = is_array( $config['accommodation'] ) ? $config['accommodation'] : array();

		if ( ! is_array( $raw['inventory'] ?? null ) || array() === $raw['inventory'] ) {
			return null;
		}

		try {
			$accommodation = AccommodationConfig::fromArray( $raw );
		} catch ( SchemaException $e ) {
			return null;
		}

		return SlotAvailability::fromSnapshot(
			$accommodation,
			( new RegistrationRepository() )->occupancy( $event_id )
		);
	}
```

- [ ] **Step 4: Wstrzyknij ją do renderera**

W `renderForm` zamień ostatnią linię:

```php
		return $notice . ( new FormRenderer() )->render( $schema, $event_id, $result );
```

na:

```php
		return $notice . ( new FormRenderer( self::availability( $event_id ) ) )->render( $schema, $event_id, $result );
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
```

- [ ] **Step 6: Lint**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
```

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=2G
```

- [ ] **Step 7: Commit**

```bash
git add src/Frontend/Shortcode.php tests/Integration/Frontend/ShortcodeTest.php && git commit -m "feat(form): feed live slot availability into the public form renderer"
```

---

### Task 5: `reserve` przestaje cicho przyjmować zgłoszenia bez noclegu

**Files:**
- Modify: `src/Services/ReservationResult.php` (nowa fabryka `accommodationFull()`)
- Modify: `src/Services/ReservationService.php` (`reserve`)
- Test: `tests/Integration/Services/ReservationServiceTest.php` (przepisanie `test_accommodation_full_accepts_without_room`, nowy test regresji listy rezerwowej)

**Interfaces:**
- Consumes: `CapacityDecision { outcome, accommodationGranted, reason }`.
- Produces: `ReservationResult::accommodationFull()` → `->code === 'accommodation_full'`, `registrationId === null`. Task 6 konsumuje ten kod w `SubmitHandler`.

- [ ] **Step 1: Przepisz test, który asertuje usuwane zachowanie**

W `tests/Integration/Services/ReservationServiceTest.php` zamień cały `test_accommodation_full_accepts_without_room` na:

```php
	public function test_accommodation_full_is_refused_without_storing(): void {
		$this->configure(); // slot n12|double capacity=1
		$this->service->reserve( $this->event_id, $this->request( 'a@example.com', 'uczestnik', new AccommodationSelection( 'n12', 'double' ) ) );

		$result = $this->service->reserve( $this->event_id, $this->request( 'b@example.com', 'uczestnik', new AccommodationSelection( 'n12', 'double' ) ) );

		$this->assertSame( 'accommodation_full', $result->code );
		$this->assertNull( $result->registrationId );
		$this->assertFalse( $result->accommodationGranted );

		global $wpdb;
		$registrations = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::table( 'registrations' ) . ' WHERE event_id = %d', $this->event_id )
		);
		$bookings = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Migrations::table( 'accommodation_bookings' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$this->assertSame( 1, $registrations, 'Odrzucone zgłoszenie nie może zostawić wiersza.' );
		$this->assertSame( 1, $bookings );
	}

	public function test_event_full_with_accommodation_still_waitlists(): void {
		$this->configure( array( 'settings' => array( 'global_cap' => 1, 'waitlist_enabled' => true ) ) );
		$this->service->reserve( $this->event_id, $this->request( 'a@example.com' ) );

		$result = $this->service->reserve(
			$this->event_id,
			$this->request( 'b@example.com', 'uczestnik', new AccommodationSelection( 'n12', 'double' ) )
		);

		$this->assertSame( 'waitlisted', $result->code );
		$this->assertSame( 'event_full', $result->reason );
	}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter ReservationServiceTest
```

Oczekiwane: FAIL na `test_accommodation_full_is_refused_without_storing` (dostaje `'reserved'`). `test_event_full_with_accommodation_still_waitlists` powinien przejść już teraz — to test regresji na warunek dodawany w Step 4.

- [ ] **Step 3: Dodaj fabrykę wyniku**

W `src/Services/ReservationResult.php` rozszerz listę kodów w docbloku konstruktora:

```php
	 * @param string      $code                 Kod wyniku: reserved|waitlisted|rejected|duplicate|accommodation_full.
```

i dodaj metodę po `rejected()`:

```php
	/**
	 * Buduje wynik dla zgłoszenia NIEzapisanego, bo wybrany slot noclegu nie ma już miejsca.
	 * Celowo osobny od rejected(): zgłaszający się ma poprawić wybór, nie odbić się od limitu
	 * całego wydarzenia.
	 */
	public static function accommodationFull(): self {
		return new self( 'accommodation_full', null, null, false, 'accommodation_full' );
	}
```

- [ ] **Step 4: Dołóż politykę w `reserve`**

W `src/Services/ReservationService::reserve`, bezpośrednio po bloku:

```php
			if ( Outcome::Rejected === $decision->outcome ) {
				$wpdb->query( 'ROLLBACK' );
				return ReservationResult::rejected( (string) $decision->reason );
			}
```

wstaw:

```php
			// Wybrany nocleg przepadł między renderem a submitem. NIE zapisujemy zgłoszenia
			// bez bookingu — to byłby cichy downgrade (człowiek dostaje potwierdzenie bez
			// łóżka). Wywołujący zamienia ten kod na błąd przy polu noclegu i re-render.
			// Warunek Accepted jest konieczny: przy pełnym limicie wydarzenia/typu decide()
			// wraca wcześniej z full(), gdzie accommodationGranted też jest false — bez
			// tego warunku zapis na listę rezerwową zamieniłby się w błąd noclegu.
			if ( Outcome::Accepted === $decision->outcome
				&& null !== $request->selection
				&& ! $decision->accommodationGranted ) {
				$wpdb->query( 'ROLLBACK' );
				return ReservationResult::accommodationFull();
			}
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
```

Oczekiwane: PASS. Jeśli inny test integracyjny opierał się na starym zachowaniu (szukaj `accommodationGranted` i `accommodation_full` w `tests/`), popraw go zgodnie z nową polityką — zapisu bez noclegu już nie ma.

- [ ] **Step 6: Lint**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
```

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=2G
```

- [ ] **Step 7: Commit**

```bash
git add src/Services/ReservationResult.php src/Services/ReservationService.php tests/Integration/Services/ReservationServiceTest.php && git commit -m "fix(reservation): refuse a registration whose chosen accommodation just filled up"
```

---

### Task 6: Błąd wraca na formularz z zachowanymi danymi

**Files:**
- Modify: `src/Frontend/AssembledSubmission.php` (nowe pole + `rawAnswers()`)
- Modify: `src/Frontend/SubmissionAssembler.php` (przekazanie surowych odpowiedzi do `valid()`)
- Modify: `src/Domain/Schema/FormSchema.php` (`accommodationFieldKey()`)
- Modify: `src/Frontend/SubmitHandler.php` (`process`)
- Modify: `src/Frontend/FormRenderer.php` (`errorMessage`)
- Test: `tests/Unit/Domain/Schema/AccommodationFieldKeyTest.php` (nowy)
- Test: `tests/Integration/Frontend/AccommodationFullSubmitTest.php` (nowy, samowystarczalny fixture)

**Interfaces:**
- Consumes: `ReservationResult::accommodationFull()` z Taska 5.
- Produces: `AssembledSubmission::rawAnswers(): array<string,mixed>`, `FormSchema::accommodationFieldKey(): ?string`, `SubmitResult` o kodzie `invalid` z błędem `accommodation_full` pod kluczem pola noclegu.

- [ ] **Step 1: Write the failing unit test**

Utwórz `tests/Unit/Domain/Schema/AccommodationFieldKeyTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Schema;

use EvReg\Domain\Schema\FormSchema;
use PHPUnit\Framework\TestCase;

final class AccommodationFieldKeyTest extends TestCase {

	/**
	 * @param array<int,array<string,mixed>> $fields
	 */
	private function schema( array $fields ): FormSchema {
		return FormSchema::fromArray(
			array(
				'version'  => 1,
				'sections' => array(
					array( 'key' => 'dane', 'title' => 'Dane', 'fields' => $fields ),
				),
			)
		);
	}

	public function test_returns_key_of_accommodation_field(): void {
		$schema = $this->schema(
			array(
				array( 'key' => 'email', 'type' => 'email', 'label' => 'E-mail' ),
				array( 'key' => 'nocleg', 'type' => 'accommodation', 'label' => 'Nocleg' ),
			)
		);

		$this->assertSame( 'nocleg', $schema->accommodationFieldKey() );
	}

	public function test_returns_null_without_accommodation_field(): void {
		$schema = $this->schema( array( array( 'key' => 'email', 'type' => 'email', 'label' => 'E-mail' ) ) );

		$this->assertNull( $schema->accommodationFieldKey() );
	}
}
```

- [ ] **Step 2: Write the failing integration test**

Utwórz `tests/Integration/Frontend/AccommodationFullSubmitTest.php` (własny fixture — `SubmitHandlerTest` nie ma pola noclegu, a `process()` jest publiczne):

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Frontend;

use EvReg\Frontend\EventFormLoader;
use EvReg\Frontend\SubmitHandler;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Services\ReservationService;
use WP_UnitTestCase;

final class AccommodationFullSubmitTest extends WP_UnitTestCase {

	private SubmitHandler $handler;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'locks', 'mail_queue' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$this->event_id = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
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
				'types'         => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 0.0 ) ),
				'accommodation' => array(
					'packages'          => array( array( 'key' => 'n18', 'label' => 'Noc 18/19' ) ),
					'rooms'             => array( array( 'key' => 'double', 'label' => 'Dwuosobowy' ) ),
					'inventory'         => array( array( 'package' => 'n18', 'room' => 'double', 'capacity' => 1, 'price' => 0.0 ) ),
					'allow_none'        => true,
					'companion_enabled' => true,
				),
				'settings'      => array( 'global_cap' => null, 'waitlist_enabled' => true ),
			)
		);

		$this->handler = new SubmitHandler(
			new EventFormLoader( new EventConfigRepository() ),
			new ReservationService( new RegistrationRepository(), new EventConfigRepository() )
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function post( string $email, bool $companion = false, string $companion_name = '' ): array {
		$post = array(
			'evreg_field' => array(
				'__type' => 'uczestnik',
				'email'  => $email,
				'nocleg' => array( 'slot' => 'n18|double', 'roommate' => '' ),
			),
		);

		if ( $companion ) {
			$post['evreg_companion']      = '1';
			$post['evreg_companion_name'] = $companion_name;
		}

		return $post;
	}

	public function test_full_slot_returns_invalid_with_preserved_answers(): void {
		$first = $this->handler->process( $this->event_id, $this->post( 'a@example.com' ) );
		$this->assertTrue( $first->isSuccess() );

		$result = $this->handler->process( $this->event_id, $this->post( 'b@example.com', true, 'Anna Kowalska' ) );

		$this->assertSame( 'invalid', $result->code() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( 'accommodation_full', $result->errors()['nocleg'] ?? null );

		// Wpisane dane muszą przeżyć re-render: slot jako TABLICA (nie obiekt
		// AccommodationSelection) oraz stan osoby towarzyszącej.
		$this->assertSame(
			array( 'package' => 'n18', 'room' => 'double', 'roommate' => '' ),
			$result->submittedValue( 'nocleg' )
		);
		$this->assertTrue( (bool) $result->submittedValue( 'evreg_companion' ) );
		$this->assertSame( 'Anna Kowalska', $result->submittedValue( 'evreg_companion_name' ) );
	}

	public function test_nothing_is_stored_for_the_refused_submission(): void {
		$this->handler->process( $this->event_id, $this->post( 'a@example.com' ) );
		$this->handler->process( $this->event_id, $this->post( 'b@example.com' ) );

		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::table( 'registrations' ) . ' WHERE event_id = %d', $this->event_id )
		);

		$this->assertSame( 1, $count );
	}
}
```

- [ ] **Step 3: Run tests to verify they fail**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit --filter AccommodationFieldKeyTest
```

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter AccommodationFullSubmitTest
```

Oczekiwane: FAIL — brak metody `accommodationFieldKey`, a `process` zwraca `rejected` (domyślne ramię `match`).

- [ ] **Step 4: `FormSchema::accommodationFieldKey()`**

W `src/Domain/Schema/FormSchema.php` dodaj po `findField()`:

```php
	/**
	 * Zwraca klucz pierwszego pola zakwaterowania albo null, gdy schemat go nie ma.
	 */
	public function accommodationFieldKey(): ?string {
		foreach ( $this->allFields() as $field ) {
			if ( FieldType::Accommodation === $field->type ) {
				return $field->key;
			}
		}

		return null;
	}
```

- [ ] **Step 5: `AssembledSubmission` niesie surowe odpowiedzi**

W `src/Frontend/AssembledSubmission.php`:

- dołóż piąty parametr konstruktora i jego `@param`:

```php
	 * @param array<string,mixed>     $rawAnswers Surowe odpowiedzi z $_POST (do re-renderu).
	 */
	private function __construct(
		private readonly bool $valid,
		private readonly array $errors,
		private readonly array $values,
		private readonly ?ReservationRequest $request,
		private readonly array $rawAnswers
	) {
	}
```

- `invalid()` przekazuje te same `$values` jako surowe (na tej gałęzi `$values` to już surowe `$answers`):

```php
	public static function invalid( array $errors, array $values ): self {
		return new self( false, $errors, $values, null, $values );
	}
```

- `valid()` dostaje trzeci, wymagany argument:

```php
	/**
	 * Tworzy wynik udanej walidacji z gotowym żądaniem rezerwacji.
	 *
	 * @param array<string,mixed> $values     Znormalizowane wartości z Validatora.
	 * @param ReservationRequest  $request    Zbudowane żądanie rezerwacji.
	 * @param array<string,mixed> $rawAnswers Surowe odpowiedzi z $_POST (do re-renderu).
	 */
	public static function valid( array $values, ReservationRequest $request, array $rawAnswers ): self {
		return new self( true, array(), $values, $request, $rawAnswers );
	}
```

- nowy akcesor:

```php
	/**
	 * Surowe odpowiedzi z $_POST — kształt, który FormRenderer umie odtworzyć.
	 *
	 * Różnią się od values() na gałęzi poprawnej walidacji: tam pole noclegu jest
	 * OBIEKTEM AccommodationSelection, a kluczy evreg_companion* nie ma wcale. Podanie
	 * values() do re-renderu wyrenderowałoby „Bez noclegu" jako zaznaczone i skasowało
	 * stan osoby towarzyszącej.
	 *
	 * @return array<string,mixed>
	 */
	public function rawAnswers(): array {
		return $this->rawAnswers;
	}
```

W `src/Frontend/SubmissionAssembler.php` zamień ostatnią linię `assemble()`:

```php
		return AssembledSubmission::valid( $values, $request, $answers );
```

- [ ] **Step 6: `SubmitHandler` mapuje nowy kod**

W `src/Frontend/SubmitHandler::process`, zamień końcówkę:

```php
		$reservation = $this->reservations->reserve( $event_id, $request );

		return match ( $reservation->code ) {
			'reserved'   => SubmitResult::success( 'reserved' ),
			'waitlisted' => SubmitResult::success( 'waitlisted' ),
			'duplicate'  => SubmitResult::duplicate(),
			default      => SubmitResult::rejected(),
		};
```

na:

```php
		$reservation = $this->reservations->reserve( $event_id, $request );

		// Wybrany nocleg zajęty w wyścigu: zgłoszenie nie zostało zapisane, więc wracamy
		// na formularz z błędem przy polu noclegu i ODTWORZONYMI surowymi odpowiedziami.
		if ( 'accommodation_full' === $reservation->code ) {
			$accommodation_key = $schema->accommodationFieldKey();

			return null === $accommodation_key
				? SubmitResult::rejected()
				: SubmitResult::invalid(
					array( $accommodation_key => 'accommodation_full' ),
					$assembled->rawAnswers()
				);
		}

		return match ( $reservation->code ) {
			'reserved'   => SubmitResult::success( 'reserved' ),
			'waitlisted' => SubmitResult::success( 'waitlisted' ),
			'duplicate'  => SubmitResult::duplicate(),
			default      => SubmitResult::rejected(),
		};
```

- [ ] **Step 7: Komunikat dla człowieka**

W `src/Frontend/FormRenderer::errorMessage`, w mapie `$map`, dodaj:

```php
			'accommodation_full'      => __( 'Wybrany nocleg nie ma już wolnych miejsc dla Twojego zgłoszenia. Wybierz inny albo „Bez noclegu".', 'event-registration' ),
```

- [ ] **Step 8: Run tests to verify they pass**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
```

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
```

Oczekiwane: PASS. `AssembledSubmission::valid()` ma teraz trzeci wymagany argument — jeśli jakiś test woła ją bezpośrednio, dopisz tam surowe odpowiedzi.

- [ ] **Step 9: Lint**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
```

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=2G
```

- [ ] **Step 10: Commit**

```bash
git add src/Frontend/AssembledSubmission.php src/Frontend/SubmissionAssembler.php src/Frontend/SubmitHandler.php src/Frontend/FormRenderer.php src/Domain/Schema/FormSchema.php tests/Unit/Domain/Schema/AccommodationFieldKeyTest.php tests/Integration/Frontend/AccommodationFullSubmitTest.php && git commit -m "fix(form): re-render with a field error when the chosen accommodation is gone"
```

---

### Task 7: `form.js` reaguje na osobę towarzyszącą

**Files:**
- Modify: `assets/public/form.js`
- Create: `tests/e2e/setup-accommodation-availability.php`
- Create: `tests/e2e/accommodation-availability.spec.js`

**Interfaces:**
- Consumes: `data-evreg-seats-left` na `<input>` slotu (Task 2), `input[name="evreg_companion"]` (istniejące), `.evreg-choice` jako wrapper.
- Produces: nic dla PHP — wyłącznie zachowanie przeglądarki.

- [ ] **Step 1: Napisz fixture e2e**

Utwórz `tests/e2e/setup-accommodation-availability.php`:

```php
<?php
/**
 * Fixture setup for the accommodation-availability Playwright E2E spec.
 *
 * Run via `wp eval-file` (WP-CLI) inside the wp-env dev container. Creates an
 * `evreg_event` whose accommodation inventory has two slots of one seat each,
 * reserves one of them through ReservationService (so the slot is genuinely
 * exhausted in the DB), and publishes a page embedding the form shortcode.
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
		'post_title'  => 'E2E Accommodation Availability',
	),
	true
);

if ( is_wp_error( $event_id ) ) {
	fwrite( STDERR, 'Failed to create evreg_event: ' . $event_id->get_error_message() . "\n" );
	exit( 1 );
}

$accommodation = array(
	'packages'          => array( array( 'key' => 'n18', 'label' => 'Noc 18/19' ) ),
	'rooms'             => array(
		array( 'key' => 'single', 'label' => 'Jednoosobowy' ),
		array( 'key' => 'double', 'label' => 'Dwuosobowy' ),
	),
	'inventory'         => array(
		array( 'package' => 'n18', 'room' => 'single', 'capacity' => 1, 'price' => 100.0 ),
		array( 'package' => 'n18', 'room' => 'double', 'capacity' => 1, 'price' => 180.0 ),
	),
	'allow_none'        => true,
	'companion_enabled' => true,
);

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
		'accommodation' => $accommodation,
		'settings'      => array( 'global_cap' => null, 'waitlist_enabled' => true ),
	)
);

// Zajmij jedyne miejsce w slocie n18|single — przez serwis, żeby obłożenie liczyło się
// tak samo jak w produkcji (wiersz zgłoszenia + booking z seats).
( new \EvReg\Services\ReservationService(
	new \EvReg\Persistence\RegistrationRepository(),
	new \EvReg\Persistence\EventConfigRepository()
) )->reserve(
	(int) $event_id,
	new \EvReg\Services\ReservationRequest(
		'e2e-zajete@example.com',
		'Jan',
		'uczestnik',
		array( 'email' => 'e2e-zajete@example.com' ),
		new \EvReg\Domain\Accommodation\AccommodationSelection( 'n18', 'single' )
	)
);

$page_id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Zapisy z noclegiem',
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

- [ ] **Step 2: Napisz failing spec**

Utwórz `tests/e2e/accommodation-availability.spec.js`:

```js
const { test, expect } = require( '@playwright/test' );
const { execSync } = require( 'child_process' );

test( 'exhausted slots are disabled and the companion toggle closes one-seat slots', async ( { page } ) => {
	// Fixture: event z dwoma slotami po 1 miejscu; n18|single już zajęty.
	// Zobacz tests/e2e/setup-accommodation-availability.php.
	const out = execSync(
		'node scripts/wp-env.cjs run cli --env-cwd=wp-content/plugins/event-registration -- wp eval-file tests/e2e/setup-accommodation-availability.php',
		{ encoding: 'utf8' }
	);
	const line = out.trim().split( '\n' ).pop().trim();
	const [ , pageId ] = line.split( /\s+/ );

	await page.goto( `http://localhost:8891/?page_id=${ pageId }` );

	const single = page.locator( 'input[name="evreg_field[nocleg][slot]"][value="n18|single"]' );
	const double = page.locator( 'input[name="evreg_field[nocleg][slot]"][value="n18|double"]' );

	// Zajęty slot: wyłączony serwerowo, z dopiskiem.
	await expect( single ).toBeDisabled();
	await expect( page.locator( '.evreg-choice.is-full' ) ).toContainText( '(brak miejsc)' );

	// Slot z jednym wolnym miejscem: dostępny dla jednej osoby.
	await expect( double ).toBeEnabled();
	await double.check();
	await expect( double ).toBeChecked();

	// Osoba towarzysząca potrzebuje dwóch miejsc → slot się wyłącza, a zaznaczenie
	// przechodzi na „Bez noclegu" (pusta grupa radio wysyłałaby pusty [slot]).
	await page.locator( 'input[name="evreg_companion"]' ).check();
	await expect( double ).toBeDisabled();
	await expect( double ).not.toBeChecked();
	await expect( page.locator( 'input[name="evreg_field[nocleg][slot]"][value=""]' ) ).toBeChecked();
} );
```

- [ ] **Step 3: Run spec to verify it fails**

```bash
node scripts/wp-env.cjs start
```

```bash
npm run test:e2e -- accommodation-availability
```

Oczekiwane: FAIL na asercjach po zaznaczeniu osoby towarzyszącej (slot zostaje aktywny i zaznaczony).

- [ ] **Step 4: Dodaj `applySlotSeats` do `form.js`**

W `assets/public/form.js` dodaj funkcję bezpośrednio po `applyCompanion`:

```js
	// Ile miejsc zajmie zgłoszenie w slocie: 2 z osobą towarzyszącą, inaczej 1.
	// Serwer wyłącza już sloty bez ani jednego miejsca; tutaj domykamy przypadek
	// „jedno wolne miejsce, a potrzebne dwa", który zależy od stanu checkboxa.
	function applySlotSeats( form ) {
		var checkbox = form.querySelector( 'input[name="evreg_companion"]' );
		var needed = checkbox && checkbox.checked ? 2 : 1;
		form.querySelectorAll( '[data-evreg-seats-left]' ).forEach( function ( el ) {
			var left = parseInt( el.getAttribute( 'data-evreg-seats-left' ), 10 );
			var full = ! isNaN( left ) && left < needed;
			el.disabled = full;
			if ( full && el.checked ) {
				el.checked = false;
				// Pusta grupa radio nie wysyła klucza [slot], co serwer odczytałby jako
				// „Bez noclegu" bez wiedzy zgłaszającego się — przenosimy zaznaczenie jawnie.
				var none = form.querySelector( 'input[name="' + el.name + '"][value=""]' );
				if ( none ) { none.checked = true; }
			}
			var wrap = el.closest( '.evreg-choice' );
			if ( wrap ) { wrap.classList.toggle( 'is-full', full ); }
		} );
	}
```

- [ ] **Step 5: Przewiąż pętle inicjalizujące**

Zamień dwa dolne bloki `document.querySelectorAll(...)` w `assets/public/form.js` na:

```js
	// Programowe el.checked = false w applySlotSeats NIE emituje zdarzenia `change`,
	// więc widoczność pola współlokatora trzeba przeliczyć ręcznie — inaczej zostaje
	// widoczna po odebraniu zaznaczenia. Kontenery .evreg-accommodation poza
	// formularzem (ekran edycji w adminie) obsługuje druga pętla.
	function refreshAccommodation( form ) {
		form.querySelectorAll( '.evreg-accommodation' ).forEach( applyRoommate );
	}

	document.querySelectorAll( '.evreg-form' ).forEach( function ( form ) {
		apply( form );
		applyCompanion( form );
		applySlotSeats( form );
		refreshAccommodation( form );
		form.addEventListener( 'change', function () {
			apply( form );
			applyCompanion( form );
			applySlotSeats( form );
			refreshAccommodation( form );
		} );
		form.addEventListener( 'input', function () { apply( form ); } );
	} );

	document.querySelectorAll( '.evreg-accommodation' ).forEach( function ( container ) {
		applyRoommate( container );
		container.addEventListener( 'change', function ( e ) {
			if ( e.target && /\[slot\]$/.test( e.target.name || '' ) ) {
				applyRoommate( container );
			}
		} );
	} );
```

- [ ] **Step 6: Run spec to verify it passes**

```bash
npm run test:e2e -- accommodation-availability
```

- [ ] **Step 7: Run the whole e2e suite (regresja na warunkach i formularzu)**

```bash
npm run test:e2e
```

- [ ] **Step 8: Commit**

```bash
git add assets/public/form.js tests/e2e/setup-accommodation-availability.php tests/e2e/accommodation-availability.spec.js && git commit -m "feat(form): companion toggle closes accommodation slots with one seat left"
```

---

### Task 8: Promocja z listy rezerwowej mówi o utraconym noclegu

**Files:**
- Modify: `src/Services/AdminActionResult.php` (nowa fabryka)
- Modify: `src/Services/ReservationService.php` (`promoteFromWaitlist`)
- Modify: `src/Admin/RegistrationsScreen.php` (mapa komunikatów)
- Test: `tests/Integration/Services/PromoteFromWaitlistTest.php` (dopisanie przypadku)

**Interfaces:**
- Consumes: `CapacityDecision::$accommodationGranted`.
- Produces: `AdminActionResult::promotedWithoutAccommodation()` → `->code === 'promoted_no_accommodation'`; `RegistrationsScreen::handle_promote` przekazuje `->code` do PRG bez zmian, więc wystarczy wpis w mapie komunikatów.

- [ ] **Step 1: Write the failing test**

`PromoteFromWaitlistTest::setUp` daje typ `uczestnik` o `capacity => 1` i nie konfiguruje noclegów, a jego `seed( string $status, string $token ): int` wstawia zgłoszenie wprost przez repozytorium. Ten test nadpisuje konfigurację (typ bez limitu, żeby promocja nie odbiła się o `type_full`, zanim dojdzie do noclegu).

Dołóż import do `tests/Integration/Services/PromoteFromWaitlistTest.php`:

```php
use EvReg\Domain\Accommodation\AccommodationSelection;
```

i dopisz test:

```php
	public function test_promote_without_accommodation_reports_the_loss(): void {
		// Typ bez limitu + slot noclegowy na 1 miejsce: miejsce na wydarzeniu jest,
		// ale łóżko zajął ktoś inny, gdy zgłoszenie czekało na liście rezerwowej.
		( new EventConfigRepository() )->save(
			$this->event_id,
			array(
				'types'         => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 0.0 ) ),
				'accommodation' => array(
					'packages'  => array( array( 'key' => 'n18', 'label' => 'Noc 18/19' ) ),
					'rooms'     => array( array( 'key' => 'single', 'label' => 'Jednoosobowy' ) ),
					'inventory' => array( array( 'package' => 'n18', 'room' => 'single', 'capacity' => 1, 'price' => 0.0 ) ),
				),
				'settings'      => array( 'waitlist_enabled' => true ),
			)
		);

		$selection = new AccommodationSelection( 'n18', 'single' );

		// Potwierdzone zgłoszenie trzyma jedyne łóżko (status zajmujący miejsce).
		$taken = $this->seed( 'confirmed', str_repeat( 'd', 32 ) );
		$this->repository->insertAccommodationBooking( $taken, $selection, 0.0, 1, 'Noc 18/19', 'Jednoosobowy' );

		// Zgłoszenie na liście rezerwowej ma booking na ten sam slot (waitlist nie zajmuje miejsca).
		$waitlisted = $this->seed( 'waitlist', str_repeat( 'e', 32 ) );
		$this->repository->insertAccommodationBooking( $waitlisted, $selection, 0.0, 1, 'Noc 18/19', 'Jednoosobowy' );

		$result = $this->service->promoteFromWaitlist( $waitlisted );

		$this->assertSame( 'promoted_no_accommodation', $result->code );
		$this->assertSame( 'pending', $this->repository->findById( $waitlisted )['status'] );

		global $wpdb;
		$bookings = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::table( 'accommodation_bookings' ) . ' WHERE registration_id = %d', $waitlisted )
		);

		$this->assertSame( 0, $bookings, 'Nieaktualny booking musi zniknąć, inaczej slot zostanie przesprzedany.' );
	}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter PromoteFromWaitlistTest
```

Oczekiwane: FAIL — kod wyniku to `promoted`.

- [ ] **Step 3: Dodaj fabrykę wyniku**

W `src/Services/AdminActionResult.php` rozszerz listę kodów w docbloku konstruktora o `promoted_no_accommodation` i dodaj po `promoted()`:

```php
	/**
	 * Zgłoszenie awansowane, ale BEZ noclegu — wybrany slot nie miał już miejsca.
	 * Celowo nie blokujemy promocji: odmowa zostawiłaby człowieka na liście rezerwowej
	 * mimo wolnego miejsca na wydarzeniu. Admin dowiaduje się i może poprawić nocleg
	 * przez edycję odpowiedzi.
	 */
	public static function promotedWithoutAccommodation(): self {
		return new self( 'promoted_no_accommodation' );
	}
```

- [ ] **Step 4: Zwróć nowy kod z `promoteFromWaitlist`**

W `src/Services/ReservationService::promoteFromWaitlist`:

- przed `$wpdb->query( 'START TRANSACTION' );` dodaj:

```php
		$accommodation_lost = false;
```

- w gałęzi `else` kasującej nieaktualny booking (komentarz „Brak grantu na promocji…") dołóż na końcu:

```php
					$accommodation_lost = true;
```

- zamień końcowe `return AdminActionResult::promoted();` na:

```php
		return $accommodation_lost
			? AdminActionResult::promotedWithoutAccommodation()
			: AdminActionResult::promoted();
```

(`do_action( 'evreg_registration_reserved', ... )` zostaje nad tym bez zmian — mail nadal wychodzi.)

- [ ] **Step 5: Komunikat w panelu**

W `src/Admin/RegistrationsScreen::render_notice`, w tablicy `$messages`, dodaj po wpisie `'promoted'`:

```php
			'promoted_no_accommodation' => array( 'warning', __( 'Awansowano z listy rezerwowej, ale wybrany nocleg był już pełny — zgłoszenie nie ma przyznanego noclegu.', 'event-registration' ) ),
```

- [ ] **Step 6: Run tests to verify they pass**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
```

- [ ] **Step 7: Lint**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
```

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=2G
```

- [ ] **Step 8: Commit**

```bash
git add src/Services/AdminActionResult.php src/Services/ReservationService.php src/Admin/RegistrationsScreen.php tests/Integration/Services/PromoteFromWaitlistTest.php && git commit -m "feat(admin): say when a waitlist promotion lost its accommodation"
```

---

### Task 9: i18n, pełny przebieg testów, paczka, changelog

**Files:**
- Modify: `languages/event-registration.pot` (regeneracja)
- Modify: `CHANGELOG.md` (sekcja `## [Unreleased]`)
- Build: `event-registration.zip` (gitignored)

**Interfaces:**
- Consumes: wszystkie stringi dodane w taskach 2, 3, 6, 8.
- Produces: zielony pełny zestaw testów + paczka instalacyjna do ręcznego sprawdzenia.

- [ ] **Step 1: Zregeneruj katalog stringów**

```bash
npm run i18n:pot
```

Sprawdź, że w `languages/event-registration.pot` znalazły się: `(brak miejsc)`, `(za mało miejsc)`, `Twój wcześniejszy wybór noclegu jest już niedostępny.`, `Brak wolnych miejsc noclegowych.`, komunikat błędu noclegu i komunikat promocji bez noclegu.

```bash
grep -c "brak miejsc" languages/event-registration.pot
```

- [ ] **Step 2: Pełny przebieg testów**

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

- [ ] **Step 3: Dopisz wpis do changeloga**

W `CHANGELOG.md`, pod `## [Unreleased]`, dodaj (angielski, user-facing — `release.yml` wycina tę sekcję jako treść release'u):

```markdown
### Fixed
- An accommodation option that has run out of seats is now shown as disabled with a "no seats" note instead of looking available, and a registration whose chosen accommodation filled up in the meantime is no longer accepted without it: the form comes back with the entered data and an explanation, so nobody is confirmed for a bed that does not exist. Ticking "companion" closes the options that have only one seat left. Promoting a waitlisted registration that lost its accommodation now says so in the admin notice.
```

- [ ] **Step 4: Zbuduj paczkę**

```bash
bash scripts/build-zip.sh
```

- [ ] **Step 5: Commit**

```bash
git add languages/event-registration.pot CHANGELOG.md && git commit -m "chore: refresh translation catalog and changelog for accommodation availability"
```

- [ ] **Step 6: Domknięcie gałęzi**

Użyj skilla `superpowers:finishing-a-development-branch`: whole-branch review, potem decyzja o merge'u i wydaniu wersji.
