# Edycja odpowiedzi zgłoszenia (Plan 5B) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Organizator edytuje odpowiedzi istniejącego zgłoszenia w adminie z re-walidacją serwerową i przeliczeniem miejsc przy zmianie typu/noclegu.

**Architecture:** Walidacja/ekstrakcja wydzielona z `SubmitHandler` do wspólnego `SubmissionAssembler` (front+admin). Edycja przechodzi przez `ReservationService::editAnswers` — ten sam inwariant `lockEvent→occupancy→decide` co `reserve`, ale z `occupancyExcluding(self)` (anty-double-count), twardym blokiem na pełny typ/nocleg, waitlist bez bramki, bez maila. Bespoke `RegistrationEditForm` + reuse publicznego `form.js`.

**Tech Stack:** PHP 8.1, WordPress, PHPUnit (kontener wp-env), `$wpdb` transakcje, PSR-4 `EvReg\`.

**Spec:** [docs/superpowers/specs/2026-08-20-registration-edit-design.md](../specs/2026-08-20-registration-edit-design.md)

## Global Constraints

- `src/Domain/**` = zero WordPressa i zero `$wpdb`. `SubmissionAssembler` idzie do `src/Frontend/` (adapter kompozycji), nie do Domain. `DomainPurityTest` musi zostać zielony.
- Domena/serwis zwraca **kody** (`AdminActionResult`), nigdy komunikaty użytkownika. Tłumaczenie w warstwie admina.
- Inwariant lock→count z 3A: `lockEvent()` MUSI poprzedzać pierwszy COUNT. W `editAnswers` COUNT to `occupancyExcluding`. NIE zmieniać kolejności.
- Edycja **nie emituje** hooków cyklu życia (`editAnswers` bez `do_action`) → Subscriber (4A) nie kolejkuje maila. Wzór: `confirmManually`.
- Wszystkie stringi UI przez i18n, text domain `event-registration`. Wszystkie wartości w formularzu escapowane (`esc_attr`/`esc_textarea`/`esc_html`). Serwer arbitrem walidacji; `errors[].detail` renderowany escapowany.
- Każdy plik PHP poza `src/Domain/` zaczyna od `defined( 'ABSPATH' ) || exit;`.
- Handlery admin-post: nonce **potem** cap (wzór 5A `guard`). PRG `wp_safe_redirect`+`exit`.
- phpcs (pod `phpcs.xml.dist`) i phpstan (poziom 6) czysto. Katalogi testów wielką literą.
- Zewnętrzny kontrakt `SubmitHandler::process` niezmieniony — istniejące testy 3B zielone bez modyfikacji.

---

### Task 1: SubmissionAssembler + refaktor SubmitHandler

**Files:**
- Create: `src/Frontend/SubmissionAssembler.php`
- Create: `src/Frontend/AssembledSubmission.php`
- Modify: `src/Frontend/SubmitHandler.php` (delegacja `process` do assemblera; ekstrakcja prywatnych metod przenoszona)
- Test: `tests/Integration/Frontend/SubmissionAssemblerTest.php`
- Regresja: `tests/Integration/Frontend/SubmitHandlerTest.php` (istniejący — musi przejść bez zmian)

**Interfaces:**
- Produces: `SubmissionAssembler::assemble( FormSchema $schema, array $post ): AssembledSubmission`; `AssembledSubmission::isValid(): bool`, `errors(): array`, `values(): array`, `request(): ?ReservationRequest`.
- Consumes: `Validator`, `VisibilityResolver`, `ConditionEngine`, `FieldValidatorRegistry`, `FormSchema::TYPE_FIELD_KEY`, `AccommodationSelection`, `ReservationRequest`.

- [ ] **Step 1: Test — assemble zwraca ReservationRequest dla poprawnych odpowiedzi**

W `tests/Integration/Frontend/SubmissionAssemblerTest.php`: zbuduj `FormSchema` z polem email `email`, polem tekstowym `name`, ukrytym `__type` z jednym typem. Wywołaj `assemble( $schema, [ 'email' => 'a@b.pl', 'name' => 'Jan', '__type' => 'std' ] )`.

```php
$assembled = ( new SubmissionAssembler() )->assemble( $schema, $post );
$this->assertTrue( $assembled->isValid() );
$this->assertSame( array(), $assembled->errors() );
$request = $assembled->request();
$this->assertInstanceOf( ReservationRequest::class, $request );
$this->assertSame( 'a@b.pl', $request->email );
$this->assertSame( 'Jan', $request->name );
$this->assertSame( 'std', $request->typeKey );
```

- [ ] **Step 2: Uruchom test — ma paść**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter SubmissionAssembler`
Expected: FAIL (`SubmissionAssembler` nie istnieje). Fazę RED sprawdź jawnym try/catch drukującym `get_class($e)` (konsola PHPUnit zniekształca `Error`).

- [ ] **Step 3: AssembledSubmission**

`src/Frontend/AssembledSubmission.php`:

```php
<?php
declare( strict_types=1 );
namespace EvReg\Frontend;
use EvReg\Services\ReservationRequest;
defined( 'ABSPATH' ) || exit;

final class AssembledSubmission {
	/**
	 * @param array<int,mixed>    $errors  Błędy walidacji (puste gdy valid).
	 * @param array<string,mixed> $values  Odpowiedzi do re-renderu (surowe przy invalid).
	 */
	private function __construct(
		private readonly bool $valid,
		private readonly array $errors,
		private readonly array $values,
		private readonly ?ReservationRequest $request
	) {}

	/** @param array<int,mixed> $errors @param array<string,mixed> $values */
	public static function invalid( array $errors, array $values ): self {
		return new self( false, $errors, $values, null );
	}

	/** @param array<string,mixed> $values */
	public static function valid( array $values, ReservationRequest $request ): self {
		return new self( true, array(), $values, $request );
	}

	public function isValid(): bool { return $this->valid; }
	/** @return array<int,mixed> */
	public function errors(): array { return $this->errors; }
	/** @return array<string,mixed> */
	public function values(): array { return $this->values; }
	public function request(): ?ReservationRequest { return $this->request; }
}
```

- [ ] **Step 4: SubmissionAssembler (przenieś ekstrakcję z SubmitHandler)**

`src/Frontend/SubmissionAssembler.php` — przenieś `extractAnswers`/`extractEmail`/`extractName`/`extractSelection` z `SubmitHandler` (identyczne ciała) i złóż `assemble`:

```php
<?php
declare( strict_types=1 );
namespace EvReg\Frontend;
use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Domain\Conditions\ConditionEngine;
use EvReg\Domain\Schema\FieldType;
use EvReg\Domain\Schema\FormSchema;
use EvReg\Domain\Schema\VisibilityResolver;
use EvReg\Domain\Validation\FieldValidatorRegistry;
use EvReg\Domain\Validation\Validator;
use EvReg\Services\ReservationRequest;
defined( 'ABSPATH' ) || exit;

final class SubmissionAssembler {
	/**
	 * Waliduje surowe posty względem schematu i buduje ReservationRequest.
	 *
	 * @param array<string,mixed> $post Surowe $_POST.
	 */
	public function assemble( FormSchema $schema, array $post ): AssembledSubmission {
		$answers   = $this->extractAnswers( $schema, $post );
		$validator = new Validator( new VisibilityResolver( new ConditionEngine() ), new FieldValidatorRegistry() );
		$result    = $validator->validate( $schema, $answers );

		if ( ! $result->isValid() ) {
			return AssembledSubmission::invalid( $result->errors(), $answers );
		}

		$values  = $result->values();
		$email   = $this->extractEmail( $schema, $values );
		$request = new ReservationRequest(
			$email,
			$this->extractName( $schema, $values, $email ),
			(string) ( $values[ FormSchema::TYPE_FIELD_KEY ] ?? '' ),
			$values,
			$this->extractSelection( $schema, $values )
		);

		return AssembledSubmission::valid( $values, $request );
	}

	// --- extractAnswers / extractEmail / extractName / extractSelection: ciała 1:1 z SubmitHandler ---
}
```

Skopiuj cztery metody `private` dokładnie jak w `src/Frontend/SubmitHandler.php` (extractAnswers, extractEmail, extractName, extractSelection). Uwaga: **`assemble` NIE robi guardu pustego emaila** — ten guard (`'' === $email → configError`) zostaje w `SubmitHandler` (zachowanie 3B).

- [ ] **Step 5: Refaktor SubmitHandler::process do delegacji**

Zamień środek `process` (od `$answers = ...` do budowy `$request`) na delegację; zachowaj spam-guardy, `configError` przy braku schemy i przy pustym emailu, oraz `match` na wyniku rezerwacji:

```php
public function process( int $event_id, array $post ): SubmitResult {
	if ( '' !== (string) ( $post['evreg_hp'] ?? '' ) ) {
		return SubmitResult::spam();
	}
	if ( time() - (int) ( $post['evreg_ts'] ?? 0 ) < self::MIN_FILL_SECONDS ) {
		return SubmitResult::spam();
	}

	$schema = $this->loader->load( $event_id );
	if ( null === $schema ) {
		return SubmitResult::configError();
	}

	$assembled = ( new SubmissionAssembler() )->assemble( $schema, $post );
	if ( ! $assembled->isValid() ) {
		return SubmitResult::invalid( $assembled->errors(), $assembled->values() );
	}

	$request = $assembled->request();
	if ( '' === $request->email ) {
		return SubmitResult::configError();
	}

	$reservation = $this->reservations->reserve( $event_id, $request );

	return match ( $reservation->code ) {
		'reserved'   => SubmitResult::success( 'reserved' ),
		'waitlisted' => SubmitResult::success( 'waitlisted' ),
		'duplicate'  => SubmitResult::duplicate(),
		default      => SubmitResult::rejected(),
	};
}
```

Usuń z `SubmitHandler` cztery prywatne metody ekstrakcji (są teraz w assemblerze) oraz nieużywane `use` (`FieldType`, `VisibilityResolver`, `ConditionEngine`, `FieldValidatorRegistry`, `Validator`, `AccommodationSelection` — zostaw tylko realnie używane; phpstan/phpcs wskaże).

- [ ] **Step 6: Test regresji 3B + assembler invalid**

Dodaj do `SubmissionAssemblerTest` przypadek invalid (brak wymaganego pola → `isValid()===false`, `request()===null`, `values()` = surowe wejście). Uruchom całość:

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter "SubmissionAssembler|SubmitHandler"`
Expected: PASS — nowe testy zielone ORAZ istniejące `SubmitHandlerTest` zielone bez zmian (regresja).

- [ ] **Step 7: Commit**

```bash
git add src/Frontend/SubmissionAssembler.php src/Frontend/AssembledSubmission.php src/Frontend/SubmitHandler.php tests/Integration/Frontend/SubmissionAssemblerTest.php
git commit -m "refactor: extract SubmissionAssembler shared by front and admin"
```

---

### Task 2: AdminActionResult — nowe kody

**Files:**
- Modify: `src/Services/AdminActionResult.php`
- Test: `tests/Unit/Services/AdminActionResultTest.php` (dodaj przypadki; utwórz jeśli brak)

**Interfaces:**
- Produces: `AdminActionResult::edited(): self`, `capacityFull(): self`, `accommodationFull(): self`. Kody: `edited`, `capacity_full`, `accommodation_full`.

- [ ] **Step 1: Test — nowe fabryki**

```php
$this->assertSame( 'edited', AdminActionResult::edited()->code );
$this->assertSame( 'capacity_full', AdminActionResult::capacityFull()->code );
$this->assertSame( 'accommodation_full', AdminActionResult::accommodationFull()->code );
```

- [ ] **Step 2: Uruchom — ma paść**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit --filter AdminActionResult`
Expected: FAIL (metody nie istnieją).

- [ ] **Step 3: Dodaj fabryki**

W `AdminActionResult` dopisz (i uzupełnij komentarz kodów w docblocku konstruktora o `edited|capacity_full|accommodation_full`):

```php
/** Odpowiedzi zgłoszenia zaktualizowane. */
public static function edited(): self {
	return new self( 'edited' );
}

/** Edycja odrzucona: wybrany typ/globalny limit pełny. */
public static function capacityFull(): self {
	return new self( 'capacity_full' );
}

/** Edycja odrzucona: wybrany slot noclegu pełny. */
public static function accommodationFull(): self {
	return new self( 'accommodation_full' );
}
```

- [ ] **Step 4: Uruchom — ma przejść**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit --filter AdminActionResult`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Services/AdminActionResult.php tests/Unit/Services/AdminActionResultTest.php
git commit -m "feat: add edited/capacity_full/accommodation_full action results"
```

---

### Task 3: Repozytorium — occupancyExcluding + updateRegistration

**Files:**
- Modify: `src/Persistence/RegistrationRepository.php`
- Test: `tests/Integration/Persistence/RegistrationEditQueriesTest.php`

**Interfaces:**
- Produces: `occupancyExcluding( int $event_id, int $exclude_id ): OccupancySnapshot`; `updateRegistration( int $id, string $type_key, string $email, string $name, string $data_json, float $price ): void`.
- Consumes: `RegistrationStatus::occupyingValues()`, `OccupancySnapshot`.

- [ ] **Step 1: Test — occupancyExcluding pomija własny wiersz**

Wstaw event z dwoma pending w typie `std`. `occupancy($event)` → per-type `std` = 2. `occupancyExcluding($event, $firstId)` → per-type `std` = 1. Analogicznie global. Dodaj wariant ze slotem noclegu (dwa bookingi, wyklucz jeden → per-slot spada o 1).

```php
$snap = $repo->occupancyExcluding( $event_id, $id_a );
$this->assertSame( 1, $snap->forType( 'std' ) );
$this->assertSame( 1, $snap->global() );
```
(Użyj realnych akcesorów `OccupancySnapshot` — sprawdź w `src/Domain/Capacity/OccupancySnapshot.php`.)

- [ ] **Step 2: Test — updateRegistration nadpisuje pola**

Wstaw zgłoszenie, wywołaj `updateRegistration($id, 'vip', 'x@y.pl', 'Nowy', '{"__type":"vip"}', 199.0)`, odczytaj `findById` → `type_key='vip'`, `email='x@y.pl'`, `name='Nowy'`, `data='{"__type":"vip"}'`, `price_total=199.00`. `status`/`token`/`created_at` niezmienione.

- [ ] **Step 3: Uruchom — ma paść**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RegistrationEditQueries`
Expected: FAIL (metody nie istnieją).

- [ ] **Step 4: occupancyExcluding**

Kopia `occupancy` z dodatkowym `AND ... id != %d` w trzech zapytaniach (global, per_type, per_slot). `$exclude_id` doklej do `$args` w każdym prepare:

```php
/**
 * Zajętość eventu policzona tak, jakby wiersz $exclude_id nie istniał (self-exclusion przy edycji).
 */
public function occupancyExcluding( int $event_id, int $exclude_id ): OccupancySnapshot {
	global $wpdb;

	$statuses = RegistrationStatus::occupyingValues();
	$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
	$args     = array_merge( array( $event_id ), $statuses, array( $exclude_id ) );

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$global = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->registrations()} WHERE event_id = %d AND status IN ($in) AND id != %d", $args ) );

	$per_type = array();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT type_key, COUNT(*) AS c FROM {$this->registrations()} WHERE event_id = %d AND status IN ($in) AND id != %d GROUP BY type_key", $args ), ARRAY_A );
	foreach ( (array) $rows as $row ) {
		$per_type[ (string) $row['type_key'] ] = (int) $row['c'];
	}

	$per_slot = array();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$slot_rows = $wpdb->get_results( $wpdb->prepare( "SELECT CONCAT(b.package_key, '|', b.room_type_key) AS slot, COUNT(*) AS c FROM {$this->bookings()} b INNER JOIN {$this->registrations()} r ON b.registration_id = r.id WHERE r.event_id = %d AND r.status IN ($in) AND r.id != %d GROUP BY slot", $args ), ARRAY_A );
	foreach ( (array) $slot_rows as $row ) {
		$per_slot[ (string) $row['slot'] ] = (int) $row['c'];
	}

	return new OccupancySnapshot( $global, $per_type, $per_slot );
}
```

- [ ] **Step 5: updateRegistration**

Wzór `updateNote`:

```php
/**
 * Nadpisuje edytowalne pola zgłoszenia (bez zmiany statusu/tokenu/dat cyklu życia).
 */
public function updateRegistration( int $id, string $type_key, string $email, string $name, string $data_json, float $price ): void {
	global $wpdb;

	$wpdb->update(
		$this->registrations(),
		array(
			'type_key'    => $type_key,
			'email'       => $email,
			'name'        => $name,
			'data'        => $data_json,
			'price_total' => $price,
			'updated_at'  => current_time( 'mysql', true ),
		),
		array( 'id' => $id ),
		array( '%s', '%s', '%s', '%s', '%f', '%s' ),
		array( '%d' )
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
```

- [ ] **Step 6: Uruchom — ma przejść**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RegistrationEditQueries`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Persistence/RegistrationRepository.php tests/Integration/Persistence/RegistrationEditQueriesTest.php
git commit -m "feat: add occupancyExcluding and updateRegistration queries"
```

---

### Task 4: ReservationService::editAnswers

**Files:**
- Modify: `src/Services/ReservationService.php`
- Test: `tests/Integration/Services/EditAnswersTest.php`

**Interfaces:**
- Consumes: `RegistrationRepository::{findById, lockEvent, occupancyExcluding, updateRegistration, deleteAccommodationBooking, insertAccommodationBooking}`, `EventConfigRepository::get`, `RegistrationTypeCollection`, `AccommodationConfig`, `CapacityLimits`, `CapacityCalculator::decide`, `Outcome`, `PriceCalculator::total`, `RegistrationStatus`, `ReservationRequest`, `AdminActionResult`.
- Produces: `editAnswers( int $id, ReservationRequest $request ): AdminActionResult`.

- [ ] **Step 1: Testy — dziewięć przypadków z §10 spec**

W `tests/Integration/Services/EditAnswersTest.php` (wzór z `PromoteFromWaitlistTest`/`AdminActionsTest`). Zbuduj event z konfiguracją typów (`std` cap 10, `vip` cap 1) i noclegu (slot cap 1). Przypadki:

1. **Self-exclusion:** typ `std` wypełnij do 10 (w tym edytowany confirmed). `editAnswers` zmieniając tylko pole (typ zostaje `std`) → `AdminActionResult::edited()->code === 'edited'`.
2. **Twardy blok typ:** `vip` zajęty innym confirmed (1/1). Edytowany pending w `std` → request z `typeKey='vip'` → `code === 'capacity_full'`; `findById` pokazuje `type_key` nadal `std` (ROLLBACK).
3. **Twardy blok nocleg:** slot pełny innym; request z selekcją tego slotu → `code === 'accommodation_full'`; booking edytowanego niezmieniony.
4. **Happy typ+cena:** pending w `std`, request `typeKey='vip'` (vip wolny) → `edited`; `findById` `type_key='vip'`, `price_total` = cena vip.
5. **Wymiana noclegu:** zmiana na wolny slot → stary booking usunięty, `findAccommodationBooking` zwraca nowy `package_key/room_type_key`.
6. **Waitlist bez bramki:** waitlist w evencie z `std` pełnym; request `typeKey='std'` → `edited`; `findById` status nadal `waitlist`.
7. **cancelled:** → `code === 'invalid_status'`, wiersz niezmieniony.
8. **not_found:** id 999999 → `code === 'not_found'`.
9. **Brak maila:** po happy-path edycji `SELECT COUNT(*) FROM {prefix}evreg_mail_queue WHERE registration_id = $id` === 0 (wzór z testu `confirmManually` — brak wiersza kolejki).

- [ ] **Step 2: Uruchom — ma paść**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter EditAnswers`
Expected: FAIL (`editAnswers` nie istnieje). RED weryfikuj jawnym try/catch.

- [ ] **Step 3: Implementuj editAnswers**

Dodaj metodę (importy `CapacityLimits`, `Outcome`, `AccommodationConfig`, `RegistrationTypeCollection`, `RegistrationStatus`, `AdminActionResult` są już w pliku z innych metod — dołóż brakujące):

```php
/**
 * Edytuje odpowiedzi zgłoszenia z re-walidacją pojemności przy zmianie typu/noclegu.
 *
 * Statusy zajmujące miejsce (pending/confirmed): transakcja lock→occupancyExcluding→decide,
 * twardy blok na pełny typ/nocleg. Waitlist: bez bramki, status zostaje waitlist.
 * Nie emituje hooków cyklu życia → brak maila. Cancelled nieedytowalne.
 *
 * @throws \Throwable ROLLBACK i ponowne rzucenie przy błędzie w transakcji.
 */
public function editAnswers( int $id, ReservationRequest $request ): AdminActionResult {
	global $wpdb;

	$row = $this->repository->findById( $id );
	if ( null === $row ) {
		return AdminActionResult::notFound();
	}
	$status = RegistrationStatus::from( (string) $row['status'] );
	if ( RegistrationStatus::Cancelled === $status ) {
		return AdminActionResult::invalidStatus();
	}

	$event_id      = (int) $row['event_id'];
	$config        = $this->config->get( $event_id );
	$types         = RegistrationTypeCollection::fromArray( is_array( $config['types'] ) ? $config['types'] : array() );
	$accommodation = AccommodationConfig::fromArray( is_array( $config['accommodation'] ) ? $config['accommodation'] : array() );
	$settings      = is_array( $config['settings'] ) ? $config['settings'] : array();
	$type          = $types->get( $request->typeKey );

	$wpdb->query( 'START TRANSACTION' );

	try {
		$occupies = in_array( $status, array( RegistrationStatus::Pending, RegistrationStatus::Confirmed ), true );

		if ( $occupies ) {
			// KRYTYCZNA KOLEJNOŚĆ: lockEvent przed occupancyExcluding (inwariant lock→count 3A).
			$this->repository->lockEvent( $event_id );

			$limits = new CapacityLimits(
				isset( $settings['global_cap'] ) && null !== $settings['global_cap'] ? (int) $settings['global_cap'] : null,
				$types->capacities(),
				$accommodation->capacities(),
				(bool) ( $settings['waitlist_enabled'] ?? true )
			);

			$decision = $this->calculator->decide(
				$limits,
				$this->repository->occupancyExcluding( $event_id, $id ),
				$request->typeKey,
				$request->selection
			);

			if ( Outcome::Rejected === $decision->outcome ) {
				$wpdb->query( 'ROLLBACK' );
				return AdminActionResult::capacityFull();
			}
			if ( null !== $request->selection && ! $decision->accommodationGranted ) {
				$wpdb->query( 'ROLLBACK' );
				return AdminActionResult::accommodationFull();
			}
		}

		$price = null === $type ? 0.0 : $this->pricing->total( $type, $accommodation, $request->selection );

		$this->repository->updateRegistration(
			$id,
			$request->typeKey,
			$request->email,
			$request->name,
			(string) wp_json_encode( $request->data ),
			$price
		);

		$this->repository->deleteAccommodationBooking( $id );
		if ( null !== $request->selection ) {
			$item      = $accommodation->item( $request->selection->packageKey, $request->selection->roomKey );
			$acc_price = null === $item ? 0.0 : $item->price;
			$this->repository->insertAccommodationBooking( $id, $request->selection, $acc_price );
		}

		$wpdb->query( 'COMMIT' );
	} catch ( \Throwable $e ) {
		$wpdb->query( 'ROLLBACK' );
		throw $e;
	}

	// Świadomie BEZ do_action — edycja nie wysyła maila (Subscriber 4A nie kolejkuje).
	return AdminActionResult::edited();
}
```

Uwaga waitlist+nocleg: dla waitlisty `$occupies===false`, więc pomijamy bramkę; przy podanej selekcji wstawiamy booking bez sprawdzania `accommodationGranted` (waitlista nie zajmuje slotu). To zamierzone (spec §7).

- [ ] **Step 4: Uruchom — ma przejść**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter EditAnswers`
Expected: PASS (9/9).

- [ ] **Step 5: Commit**

```bash
git add src/Services/ReservationService.php tests/Integration/Services/EditAnswersTest.php
git commit -m "feat: add transactional editAnswers with self-excluding capacity recheck"
```

---

### Task 5: RegistrationEditForm — renderer

**Files:**
- Create: `src/Admin/RegistrationEditForm.php`
- Test: `tests/Integration/Admin/RegistrationEditFormTest.php`

**Interfaces:**
- Produces: `RegistrationEditForm::render( FormSchema $schema, array $answers, int $reg_id, ?array $errors = null, ?array $submitted = null ): string`.
- Consumes: `FormSchema::allFields()`, `Field`, `FieldType`, `AccommodationConfig` (z pola accommodation), `wp_nonce_field`, `admin_url`.

- [ ] **Step 1: Test — formularz prefillowany + POST do admin-post + nonce**

Zbuduj schemę (email `email`, text `name`, ukryty `__type`). `render( $schema, [ 'email' => 'a@b.pl', 'name' => 'Jan', '__type' => 'std' ], 42 )`. Asercje:

```php
$html = RegistrationEditForm::render( $schema, $answers, 42 );
$this->assertStringContainsString( 'action="' . admin_url( 'admin-post.php' ) . '"', $html );
$this->assertStringContainsString( 'name="action" value="evreg_edit_registration"', $html );
$this->assertStringContainsString( 'name="registration" value="42"', $html );
$this->assertStringContainsString( 'value="a@b.pl"', $html );          // prefill email
$this->assertStringContainsString( 'evreg_edit_42', $html );            // nonce action w polu
```

Drugi test: `$errors` niepuste → HTML zawiera **escapowany** `detail` (podaj `detail` z `<b>` i asertuj `&lt;b&gt;`), a prefill bierze z `$submitted` nie `$answers`.

- [ ] **Step 2: Uruchom — ma paść**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RegistrationEditForm`
Expected: FAIL (klasa nie istnieje).

- [ ] **Step 3: Implementuj renderer**

`src/Admin/RegistrationEditForm.php`. Wzór escapowania/i18n z `RegistrationsScreen`. Struktura: `<form>` + hidden `action`/`registration`/nonce, pętla po `allFields()` renderująca kontrolkę per typ, pomijając `Heading`/`Paragraph`. Wartość pola: `$source[$field->key]` gdzie `$source = $submitted ?? $answers`.

```php
<?php
declare( strict_types=1 );
namespace EvReg\Admin;
use EvReg\Domain\Schema\FieldType;
use EvReg\Domain\Schema\Field;
use EvReg\Domain\Schema\FormSchema;
defined( 'ABSPATH' ) || exit;

final class RegistrationEditForm {

	/**
	 * Renderuje formularz edycji odpowiedzi zgłoszenia (POST → admin-post.php).
	 *
	 * @param array<string,mixed>   $answers   Bieżące odpowiedzi zgłoszenia (prefill domyślny).
	 * @param array<int,mixed>|null $errors    Błędy walidacji do wyświetlenia (opcjonalne).
	 * @param array<string,mixed>|null $submitted Wartości z odrzuconego POST (prefill przy błędzie).
	 */
	public static function render( FormSchema $schema, array $answers, int $reg_id, ?array $errors = null, ?array $submitted = null ): string {
		$source = null !== $submitted ? $submitted : $answers;
		$nonce  = wp_nonce_field( 'evreg_edit_' . $reg_id, '_wpnonce', true, false );

		$out  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$out .= '<input type="hidden" name="action" value="evreg_edit_registration" />';
		$out .= '<input type="hidden" name="registration" value="' . esc_attr( (string) $reg_id ) . '" />';
		$out .= $nonce;
		$out .= self::renderErrors( $errors );

		$out .= '<table class="form-table"><tbody>';
		foreach ( $schema->allFields() as $field ) {
			if ( in_array( $field->type, array( FieldType::Heading, FieldType::Paragraph ), true ) ) {
				continue;
			}
			$out .= self::renderRow( $field, $source[ $field->key ] ?? '' );
		}
		$out .= '</tbody></table>';
		$out .= '<p><button type="submit" class="button button-primary">' . esc_html__( 'Zapisz zmiany', 'event-registration' ) . '</button></p>';
		$out .= '</form>';

		return $out;
	}

	/** @param array<int,mixed>|null $errors */
	private static function renderErrors( ?array $errors ): string {
		if ( empty( $errors ) ) {
			return '';
		}
		$items = '';
		foreach ( $errors as $error ) {
			$detail = is_array( $error ) ? (string) ( $error['detail'] ?? '' ) : (string) $error;
			$items .= '<li>' . esc_html( $detail ) . '</li>';
		}
		return '<div class="notice notice-error"><ul>' . $items . '</ul></div>';
	}

	/** @param mixed $value */
	private static function renderRow( Field $field, $value ): string {
		$label   = '<th scope="row"><label>' . esc_html( $field->label ) . '</label></th>';
		$control = '<td>' . self::renderControl( $field, $value ) . '</td>';
		return '<tr>' . $label . $control . '</tr>';
	}

	/** @param mixed $value */
	private static function renderControl( Field $field, $value ): string {
		$name = esc_attr( $field->key );
		switch ( $field->type ) {
			case FieldType::Textarea:
				return '<textarea name="' . $name . '" rows="4" class="large-text">' . esc_textarea( self::scalar( $value ) ) . '</textarea>';
			case FieldType::Select:
			case FieldType::Radio:
			case FieldType::Checkbox:
			case FieldType::CheckboxGroup:
				return self::renderChoices( $field, $value );
			case FieldType::Accommodation:
				return self::renderAccommodation( $field, $value );
			case FieldType::Hidden:
				return '<input type="hidden" name="' . $name . '" value="' . esc_attr( self::scalar( $value ) ) . '" />';
			default: // Text, Email, Tel, Number, Date
				$input_type = esc_attr( $field->type->value );
				return '<input type="' . $input_type . '" name="' . $name . '" value="' . esc_attr( self::scalar( $value ) ) . '" class="regular-text" />';
		}
	}

	/** @param mixed $value */
	private static function scalar( $value ): string {
		if ( is_array( $value ) ) {
			return implode( ', ', array_map( 'strval', $value ) );
		}
		return (string) $value;
	}

	// renderChoices / renderAccommodation: patrz Step 4.
}
```

- [ ] **Step 4: Kontrolki wyboru i noclegu**

Dodaj `renderChoices` (opcje z `$field->options`; zaznacz bieżącą wartość) i `renderAccommodation` (`<select>` slotów `package|room` z `AccommodationConfig` osadzonego w `$field->config`, zaznacz `"{package}|{room}"` z `$value` + input `roommate`). Odwzoruj konwencję z `src/Frontend/FormRenderer.php:192-247` (`renderChoices`/`renderAccommodation`) — ta sama struktura opcji, ale kontrolki admina (bez powłoki publicznej). Escapuj wszystko, i18n na etykietach „brak"/„współlokator". Wartość accommodation w `$answers` ma kształt `{package, room, roommate}` (jak `extractAnswers`) — zbuduj z niej zaznaczony slot.

- [ ] **Step 5: Uruchom — ma przejść**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RegistrationEditForm`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Admin/RegistrationEditForm.php tests/Integration/Admin/RegistrationEditFormTest.php
git commit -m "feat: add admin registration edit form renderer"
```

---

### Task 6: RegistrationsScreen — routing, handler, przycisk, enqueue

**Files:**
- Modify: `src/Admin/RegistrationsScreen.php`
- Test: `tests/Integration/Admin/RegistrationEditScreenTest.php`

**Interfaces:**
- Consumes: `SubmissionAssembler::assemble`, `EventFormLoader::load`, `ReservationService::editAnswers`, `RegistrationEditForm::render`, istniejące `guard`/`redirect`/`service`/`render_detail` z 5A.
- Produces: stała `ACTION_EDIT='evreg_edit_registration'`; `render_edit(int $id)`; `handle_edit()`.

- [ ] **Step 1: Testy — handler i routing**

W `tests/Integration/Admin/RegistrationEditScreenTest.php` (wzór `RegistrationsScreenTest` z 5A — `set_current_screen`, filtr `wp_redirect` rzucający wyjątek na PRG, zły nonce/cap → `WPDieException`):

1. `handle_edit` bez/zły nonce → `WPDieException`.
2. `handle_edit` bez capa → `WPDieException`.
3. Poprawny POST z ważnymi odpowiedziami → `editAnswers` zadziałał (status/dane zmienione) i PRG na `action=view` (redirect złapany).
4. POST z niepoprawnymi odpowiedziami (brak wymaganego pola) → **brak redirectu**, zwrócony/wyechowany HTML zawiera formularz z błędem (inline re-render).
5. `render_edit` na zgłoszeniu `cancelled` → nie renderuje formularza (notice/powrót).

- [ ] **Step 2: Uruchom — ma paść**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RegistrationEditScreen`
Expected: FAIL.

- [ ] **Step 3: Stała, rejestracja handlera, enqueue**

W `RegistrationsScreen`: dodaj `private const ACTION_EDIT = 'evreg_edit_registration';`. W `register()` dodaj `add_action( 'admin_post_' . self::ACTION_EDIT, array( $screen, 'handle_edit' ) );`. Enqueue `evreg-public` na ekranie edycji (w `render()`/`render_edit` gdy `action===edit`): `wp_enqueue_script( 'evreg-public' );` (handle zarejestrowany na `init` w 3B).

- [ ] **Step 4: render_edit**

Routing w `render()`: gdy `$_GET['action']==='edit'` → `render_edit( (int) $_GET['id'] )`. Metoda:

```php
private function render_edit( int $id ): void {
	$repository = new \EvReg\Persistence\RegistrationRepository();
	$row        = $repository->findById( $id );
	if ( null === $row ) {
		echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__( 'Nie znaleziono zgłoszenia.', 'event-registration' ) . '</p></div></div>';
		return;
	}
	if ( 'cancelled' === (string) $row['status'] ) {
		echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__( 'Nie można edytować anulowanego zgłoszenia.', 'event-registration' ) . '</p></div></div>';
		return;
	}
	$schema  = ( new \EvReg\Frontend\EventFormLoader( new \EvReg\Persistence\EventConfigRepository() ) )->load( (int) $row['event_id'] );
	$answers = is_array( json_decode( (string) $row['data'], true ) ) ? json_decode( (string) $row['data'], true ) : array();
	echo '<div class="wrap"><h1>' . esc_html__( 'Edytuj zgłoszenie', 'event-registration' ) . '</h1>';
	echo RegistrationEditForm::render( $schema, $answers, $id ); // już escapowane w rendererze
	echo '</div>';
}
```

- [ ] **Step 5: handle_edit**

```php
public function handle_edit(): void {
	$id = isset( $_POST['registration'] ) ? (int) $_POST['registration'] : 0;
	check_admin_referer( 'evreg_edit_' . $id );
	if ( ! current_user_can( 'edit_evreg_events' ) ) {
		wp_die( esc_html__( 'Brak uprawnień.', 'event-registration' ) );
	}

	$repository = new \EvReg\Persistence\RegistrationRepository();
	$row        = $repository->findById( $id );
	if ( null === $row ) {
		$this->redirect( array( 'evreg_msg' => 'not_found' ) );
		return;
	}

	$schema = ( new \EvReg\Frontend\EventFormLoader( new \EvReg\Persistence\EventConfigRepository() ) )->load( (int) $row['event_id'] );
	$posted = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$assembled = ( new \EvReg\Frontend\SubmissionAssembler() )->assemble( $schema, is_array( $posted ) ? $posted : array() );

	if ( ! $assembled->isValid() ) {
		// Inline re-render z zachowanymi wartościami i błędami (bez PRG).
		echo '<div class="wrap"><h1>' . esc_html__( 'Edytuj zgłoszenie', 'event-registration' ) . '</h1>';
		echo RegistrationEditForm::render( $schema, array(), $id, $assembled->errors(), $assembled->values() );
		echo '</div>';
		return;
	}

	$result = $this->service()->editAnswers( $id, $assembled->request() );

	switch ( $result->code ) {
		case 'edited':
			$this->redirect( array( 'action' => 'view', 'id' => $id, 'evreg_msg' => 'edited' ) );
			break;
		case 'capacity_full':
		case 'accommodation_full':
			$this->redirect( array( 'action' => 'edit', 'id' => $id, 'evreg_msg' => $result->code ) );
			break;
		default: // invalid_status / not_found
			$this->redirect( array( 'evreg_msg' => $result->code ) );
	}
}
```

Uwaga: `redirect()` z 5A przyjmuje `array $extra` (dodane w fix-wave 5A) — użyj go do `action`/`id`/`evreg_msg`. Jeśli sygnatura inna, dostosuj wywołanie do istniejącej. Przycisk „Edytuj": w `render_actions` (lub tam gdzie budowane są akcje detalu) dodaj link `?page=...&action=edit&id=$id` dla statusów pending/confirmed/waitlist. Dodaj obsługę notice `edited`/`capacity_full`/`accommodation_full` tam gdzie 5A renderuje `evreg_msg`.

- [ ] **Step 6: Uruchom — ma przejść**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RegistrationEditScreen`
Expected: PASS.

- [ ] **Step 7: Pełny zestaw + statyka**

Run:
```
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=1G
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
```
Expected: wszystko zielone. `DomainPurityTest` zielony (assembler w `src/Frontend`).

- [ ] **Step 8: Commit**

```bash
git add src/Admin/RegistrationsScreen.php tests/Integration/Admin/RegistrationEditScreenTest.php
git commit -m "feat: wire registration edit screen, handler and action button"
```

---

## Uwagi wykonawcze

- **RED faza:** konsola PHPUnit w kontenerze zniekształca `Error` — weryfikuj jawnym try/catch drukującym `get_class($e)`/`getMessage()`.
- **WP_List_Table / ekran:** `set_current_screen(...)` przed instancjonowaniem; redirect handlera łap filtrem `wp_redirect` rzucającym wyjątek; zły nonce/cap → `WPDieException`.
- **Kolejność:** Task 1 (assembler) jest fundamentem — Task 6 od niego zależy. Task 4 zależy od Task 2+3. Zachowaj kolejność 1→6.
- **Nie zmieniać** kolejności `lockEvent`→`occupancyExcluding` w Task 4.
- **Weryfikacja przeglądarkowa** (poza zasięgiem subagentów): render formularza edycji, conditional show/hide sekcji wg `__type` (form.js), round-trip zapisu, twardy blok pełnego typu/noclegu z notice, brak maila po edycji.
