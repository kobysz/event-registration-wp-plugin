# Eksport zgłoszeń do CSV (Plan 5C) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Organizator pobiera zgłoszenia wybranego eventu jako plik CSV z kolumnami wg schematu, respektujący bieżące filtry listy.

**Architecture:** Czysty `RegistrationExportMapper` (domena) mapuje schema→kolumny i `data`→komórki. `RegistrationsExporter::buildCsv` (admin) składa pełny tekst CSV (BOM, i18n nagłówki, status/typ label, neutralizacja CSV injection) — zwraca string (testowalne osobno od wysyłki HTTP). Repo dokłada `exportRegistrations` (bez paginacji) + `accommodationBookingsFor` (bulk). `RegistrationsScreen` dokłada przycisk + `handle_export` (nonce→cap→wymóg eventu→streaming+exit).

**Tech Stack:** PHP 8.1, WordPress, `fputcsv`, PHPUnit (kontener wp-env), PSR-4 `EvReg\`.

**Spec:** [docs/superpowers/specs/2026-08-20-registration-export-design.md](../specs/2026-08-20-registration-export-design.md)

## Global Constraints

- `src/Domain/**` = zero WordPressa, zero `$wpdb`, zero `__()`. `RegistrationExportMapper` idzie do `src/Domain/Export/` — labele z schematu/configu to treść autora eventu, NIE stringi UI. `DomainPurityTest` musi zostać zielony.
- Składanie CSV, i18n nagłówki, `status_label`, neutralizacja injection = warstwa admina (`RegistrationsExporter`), nie domena.
- SQL zgłoszeń wyłącznie w `RegistrationRepository`. Reuse prywatnego `registrationWhere`.
- Każdy plik PHP poza `src/Domain/` zaczyna od `defined( 'ABSPATH' ) || exit;`.
- Wszystkie stringi UI (nagłówki tożsamości, notice) przez i18n, text domain `event-registration`.
- Handler eksportu: `check_admin_referer('evreg_export')` **potem** `current_user_can(Capabilities::CAP)`; oba przed dostępem do danych. Eksport = odczyt danych osobowych.
- **CSV injection:** komórki DANYCH zaczynające się od `= + - @ \t \r` poprzedzone `'`. Nagłówki nie.
- **UTF-8 BOM** `\xEF\xBB\xBF` na początku pliku.
- phpcs (pod `phpcs.xml.dist`) i phpstan (poziom 6) czysto. Katalogi testów wielką literą.
- Serwer arbitrem: filtry re-czytane i sanityzowane w handlerze, link tylko podpowiada.

---

### Task 1: RegistrationExportMapper (domena)

**Files:**
- Create: `src/Domain/Export/RegistrationExportMapper.php`
- Test: `tests/Unit/Domain/Export/RegistrationExportMapperTest.php`

**Interfaces:**
- Produces: `answerColumns(FormSchema $schema): array<int,array{key:string,label:string}>`; `answerCells(array $data, FormSchema $schema): array<int,string>`; `accommodationCells(?array $booking, AccommodationConfig $config): array{package:string,room:string,roommate:string}`.
- Consumes: `FormSchema::allFields()`, `Field` (`key`,`type`,`label`), `FieldType::isInput()`, `AccommodationConfig::packages()`/`rooms()` (`Package`/`RoomType` z `->key`,`->label`).

- [ ] **Step 1: Test — answerColumns pomija non-input, zachowuje kolejność**

W `tests/Unit/Domain/Export/RegistrationExportMapperTest.php`. Zbuduj `FormSchema` (użyj `SchemaAssembler`/konstrukcji jak w istniejących unit testach domeny — sprawdź `tests/Unit/` dla wzorca budowy schematu) z polami: text `imie` (label „Imię"), heading (label „Sekcja"), email `mail` (label „E-mail"). 

```php
$cols = ( new RegistrationExportMapper() )->answerColumns( $schema );
$this->assertSame(
    array(
        array( 'key' => 'imie', 'label' => 'Imię' ),
        array( 'key' => 'mail', 'label' => 'E-mail' ),
    ),
    $cols
); // heading pominięty (isInput()===false), kolejność zachowana
```

- [ ] **Step 2: Uruchom — ma paść**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit --filter RegistrationExportMapper`
Expected: FAIL (klasa nie istnieje). Fazę RED sprawdź jawnym try/catch drukującym `get_class($e)` gdy podsumowanie niejasne.

- [ ] **Step 3: answerColumns + answerCells**

`src/Domain/Export/RegistrationExportMapper.php`:

```php
<?php
declare( strict_types=1 );
namespace EvReg\Domain\Export;
use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Schema\FormSchema;

final class RegistrationExportMapper {
	/**
	 * Uporządkowane kolumny odpowiedzi: pola input (pomija Heading/Paragraph), nagłówek = label pola.
	 *
	 * @return array<int,array{key:string,label:string}>
	 */
	public function answerColumns( FormSchema $schema ): array {
		$cols = array();
		foreach ( $schema->allFields() as $field ) {
			if ( ! $field->type->isInput() ) {
				continue;
			}
			$cols[] = array( 'key' => $field->key, 'label' => $field->label );
		}
		return $cols;
	}

	/**
	 * Wartości komórek odpowiedzi w kolejności answerColumns; multi-value → implode(', '); brak/null → ''.
	 *
	 * @param array<string,mixed> $data Odebrane odpowiedzi (data JSON zdekodowane).
	 * @return array<int,string>
	 */
	public function answerCells( array $data, FormSchema $schema ): array {
		$cells = array();
		foreach ( $this->answerColumns( $schema ) as $col ) {
			$value = $data[ $col['key'] ] ?? null;
			if ( is_array( $value ) ) {
				$cells[] = implode( ', ', array_map( 'strval', $value ) );
			} elseif ( null === $value ) {
				$cells[] = '';
			} else {
				$cells[] = (string) $value;
			}
		}
		return $cells;
	}

	/**
	 * Labele noclegu z wiersza bookingu (fallback surowy klucz gdy nieznany).
	 *
	 * @param array<string,mixed>|null $booking Wiersz evreg_accommodation_bookings albo null.
	 * @return array{package:string,room:string,roommate:string}
	 */
	public function accommodationCells( ?array $booking, AccommodationConfig $config ): array {
		if ( null === $booking ) {
			return array( 'package' => '', 'room' => '', 'roommate' => '' );
		}
		$package_key = (string) ( $booking['package_key'] ?? '' );
		$room_key    = (string) ( $booking['room_type_key'] ?? '' );
		return array(
			'package'  => $this->packageLabel( $config, $package_key ),
			'room'     => $this->roomLabel( $config, $room_key ),
			'roommate' => (string) ( $booking['roommate_pref'] ?? '' ),
		);
	}

	private function packageLabel( AccommodationConfig $config, string $key ): string {
		foreach ( $config->packages() as $package ) {
			if ( $package->key === $key ) {
				return $package->label;
			}
		}
		return $key;
	}

	private function roomLabel( AccommodationConfig $config, string $key ): string {
		$room = $config->room( $key );
		return null === $room ? $key : $room->label;
	}
}
```

- [ ] **Step 4: Testy answerCells + accommodationCells**

Dodaj: `answerCells` — brak pola → `''`, wartość skalarna → `(string)`, tablica (checkbox-group) → `implode(', ')`. `accommodationCells` — booking z known package/room → labele; nieznany klucz → surowy klucz; `null` → trzy puste; `roommate_pref` przepisany. Zbuduj `AccommodationConfig::fromArray(...)` z pakietem/pokojem (wzór z testów 3A/domeny noclegu).

- [ ] **Step 5: Uruchom — ma przejść**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit --filter RegistrationExportMapper`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Domain/Export/RegistrationExportMapper.php tests/Unit/Domain/Export/RegistrationExportMapperTest.php
git commit -m "feat: add RegistrationExportMapper for schema-driven export columns"
```

---

### Task 2: Repozytorium — exportRegistrations + accommodationBookingsFor

**Files:**
- Modify: `src/Persistence/RegistrationRepository.php`
- Test: `tests/Integration/Persistence/RegistrationExportQueriesTest.php`

**Interfaces:**
- Produces: `exportRegistrations( array $filters ): array<int,array<string,mixed>>`; `accommodationBookingsFor( array $ids ): array<int,array<string,mixed>>` (mapa `registration_id => wiersz`).
- Consumes: prywatne `registrationWhere`, `registrations()`, `bookings()`.

- [ ] **Step 1: Test — exportRegistrations bez LIMIT, ASC, filtry**

W `tests/Integration/Persistence/RegistrationExportQueriesTest.php` (wzór seedowania z `RegistrationListingTest`/`RegistrationEditQueriesTest`). Wstaw >20 zgłoszeń (żeby udowodnić brak paginacji) w jednym evencie, część innego typu. Asercje:

```php
$rows = $repo->exportRegistrations( array( 'event_id' => $event_id ) );
$this->assertCount( 25, $rows );                       // wszystkie, nie 20
$this->assertSame( $first_id, (int) $rows[0]['id'] );  // ORDER BY id ASC
$typed = $repo->exportRegistrations( array( 'event_id' => $event_id, 'type_key' => 'vip' ) );
$this->assertCount( 3, $typed );                       // filtr typu zawęża
```

- [ ] **Step 2: Test — accommodationBookingsFor mapa/pusty**

Wstaw 2 zgłoszenia z bookingiem, 1 bez. `accommodationBookingsFor([$idA,$idB,$idC])` → mapa ma klucze `$idA`,`$idB` (wiersze), brak `$idC`. `accommodationBookingsFor([])` → `array()` (bez zapytania). Klucze mapy to `registration_id` (int).

- [ ] **Step 3: Uruchom — ma paść**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RegistrationExportQueries`
Expected: FAIL.

- [ ] **Step 4: exportRegistrations**

Wstaw obok `paginateRegistrations`:

```php
/**
 * Zwraca WSZYSTKIE zgłoszenia spełniające filtry (bez paginacji), chronologicznie — do eksportu.
 *
 * @param array{status?: string, type_key?: string, event_id?: int} $filters Filtry.
 * @return array<int,array<string,mixed>>
 */
public function exportRegistrations( array $filters ): array {
	global $wpdb;

	list( $where, $args ) = $this->registrationWhere( $filters );

	if ( array() === $args ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( "SELECT * FROM {$this->registrations()} ORDER BY id ASC", ARRAY_A );
	} else {
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $args ma zmienną, ale dopasowaną liczbę elementów.
			$wpdb->prepare( "SELECT * FROM {$this->registrations()}{$where} ORDER BY id ASC", $args ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	return is_array( $rows ) ? $rows : array();
}
```

- [ ] **Step 5: accommodationBookingsFor**

Wstaw obok `findAccommodationBooking`:

```php
/**
 * Bulk-pobiera bookingi noclegu dla wielu zgłoszeń (unika N+1 przy eksporcie).
 *
 * @param array<int,int> $ids ID zgłoszeń.
 * @return array<int,array<string,mixed>> Mapa registration_id => wiersz bookingu.
 */
public function accommodationBookingsFor( array $ids ): array {
	global $wpdb;

	$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
	if ( array() === $ids ) {
		return array();
	}

	$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$rows = $wpdb->get_results(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in ma zmienną, ale dopasowaną liczbę placeholderów.
		$wpdb->prepare( "SELECT * FROM {$this->bookings()} WHERE registration_id IN ($in) ORDER BY id ASC", $ids ),
		ARRAY_A
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	$map = array();
	foreach ( (array) $rows as $row ) {
		$rid = (int) $row['registration_id'];
		if ( ! isset( $map[ $rid ] ) ) {
			$map[ $rid ] = $row; // pierwszy wygrywa gdy >1 booking
		}
	}
	return $map;
}
```

- [ ] **Step 6: Uruchom — ma przejść**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RegistrationExportQueries`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Persistence/RegistrationRepository.php tests/Integration/Persistence/RegistrationExportQueriesTest.php
git commit -m "feat: add exportRegistrations and bulk accommodationBookingsFor queries"
```

---

### Task 3: RegistrationsExporter::buildCsv

**Files:**
- Create: `src/Admin/RegistrationsExporter.php`
- Test: `tests/Integration/Admin/RegistrationsExporterTest.php`

**Interfaces:**
- Produces: `buildCsv( FormSchema $schema, AccommodationConfig $accommodation, RegistrationTypeCollection $types, array $rows, array $bookingsById ): string`.
- Consumes: `RegistrationExportMapper` (Task 1), `RegistrationsListTable::status_label` (static, i18n), `RegistrationTypeCollection::get`, `FormSchema`, `AccommodationConfig`.

- [ ] **Step 1: Test — pełny wiersz, nagłówek, BOM, injection**

W `tests/Integration/Admin/RegistrationsExporterTest.php`. Zbuduj event (schema email `mail` label „E-mail" + checkbox-group `dni` label „Dni" + accommodation), typy (`std` label „Standard”), config noclegu (pakiet+pokój z labelami). Jeden wiersz `evreg_registrations` (ARRAY_A) z `data` = `{"mail":"a@b.pl","dni":["sob","ndz"]}`, `type_key='std'`, status `confirmed`, `price_total='150.00'`. Booking w mapie.

```php
$csv = ( new RegistrationsExporter() )->buildCsv( $schema, $accommodation, $types, array( $row ), array( $row['id'] => $booking ) );

$this->assertStringStartsWith( "\xEF\xBB\xBF", $csv );                 // BOM
$lines = explode( "\n", trim( substr( $csv, 3 ) ) );
$this->assertStringContainsString( 'E-mail', $lines[0] );             // nagłówek pola
$this->assertStringContainsString( 'Nocleg', $lines[0] );             // nagłówki noclegu
$this->assertStringContainsString( 'Potwierdzone', $lines[1] );       // status i18n
$this->assertStringContainsString( 'Standard', $lines[1] );           // typ label
$this->assertStringContainsString( 'sob, ndz', $lines[1] );           // multi-value złączone
```

Drugi test — **CSV injection**: `data` = `{"mail":"=CMD()"}` → wiersz danych zawiera `'=CMD()` (poprzedzone apostrofem); nagłówek niezmieniony.

- [ ] **Step 2: Uruchom — ma paść**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RegistrationsExporter`
Expected: FAIL.

- [ ] **Step 3: Implementuj buildCsv**

`src/Admin/RegistrationsExporter.php`:

```php
<?php
declare( strict_types=1 );
namespace EvReg\Admin;
use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Export\RegistrationExportMapper;
use EvReg\Domain\Registration\RegistrationTypeCollection;
use EvReg\Domain\Schema\FormSchema;
defined( 'ABSPATH' ) || exit;

final class RegistrationsExporter {

	/**
	 * Buduje pełny tekst CSV (BOM + nagłówek + wiersze) dla eksportu zgłoszeń jednego eventu.
	 *
	 * @param array<int,array<string,mixed>> $rows         Wiersze evreg_registrations (ARRAY_A).
	 * @param array<int,array<string,mixed>> $bookingsById Mapa registration_id => wiersz bookingu.
	 */
	public function buildCsv(
		FormSchema $schema,
		AccommodationConfig $accommodation,
		RegistrationTypeCollection $types,
		array $rows,
		array $bookingsById
	): string {
		$mapper = new RegistrationExportMapper();

		$identity_headers = array(
			__( 'ID', 'event-registration' ),
			__( 'Status', 'event-registration' ),
			__( 'Typ', 'event-registration' ),
			__( 'E-mail', 'event-registration' ),
			__( 'Imię i nazwisko', 'event-registration' ),
			__( 'Cena', 'event-registration' ),
			__( 'Utworzono', 'event-registration' ),
			__( 'Potwierdzono', 'event-registration' ),
			__( 'Notatka', 'event-registration' ),
		);
		$answer_headers = array();
		foreach ( $mapper->answerColumns( $schema ) as $col ) {
			$answer_headers[] = $col['label'];
		}
		$accommodation_headers = array(
			__( 'Nocleg – pakiet', 'event-registration' ),
			__( 'Nocleg – pokój', 'event-registration' ),
			__( 'Nocleg – współlokator', 'event-registration' ),
		);

		$handle = fopen( 'php://temp', 'r+' );
		// Nagłówek — bez neutralizacji (labele autora / i18n).
		fputcsv( $handle, array_merge( $identity_headers, $answer_headers, $accommodation_headers ) );

		foreach ( $rows as $row ) {
			$id      = (int) $row['id'];
			$type    = $types->get( (string) $row['type_key'] );
			$data    = json_decode( (string) $row['data'], true );
			$data    = is_array( $data ) ? $data : array();
			$booking = $bookingsById[ $id ] ?? null;
			$acc     = $mapper->accommodationCells( $booking, $accommodation );

			$identity = array(
				(string) $row['id'],
				RegistrationsListTable::status_label( (string) $row['status'] ),
				null === $type ? (string) $row['type_key'] : $type->label,
				(string) $row['email'],
				(string) $row['name'],
				number_format( (float) $row['price_total'], 2, '.', '' ),
				(string) $row['created_at'],
				(string) ( $row['confirmed_at'] ?? '' ),
				(string) ( $row['note'] ?? '' ),
			);
			$cells = array_merge(
				$identity,
				$mapper->answerCells( $data, $schema ),
				array( $acc['package'], $acc['room'], $acc['roommate'] )
			);
			fputcsv( $handle, array_map( array( $this, 'neutralize' ), $cells ) );
		}

		rewind( $handle );
		$csv = (string) stream_get_contents( $handle );
		fclose( $handle );

		return "\xEF\xBB\xBF" . $csv;
	}

	/**
	 * Neutralizuje CSV injection: komórka danych zaczynająca się od = + - @ \t \r → poprzedzona '.
	 */
	private function neutralize( string $value ): string {
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}
		return $value;
	}
}
```

- [ ] **Step 4: Uruchom — ma przejść**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RegistrationsExporter`
Expected: PASS. Jeśli phpcs zgłasza `fopen`/`fputcsv`/`fclose` (WordPress.WP.AlternativeFunctions), dodaj celowany `// phpcs:ignore` z uzasadnieniem (in-memory bufor, nie FS) — jak istniejące ignore w repo.

- [ ] **Step 5: Commit**

```bash
git add src/Admin/RegistrationsExporter.php tests/Integration/Admin/RegistrationsExporterTest.php
git commit -m "feat: add CSV exporter with BOM and injection neutralization"
```

---

### Task 4: RegistrationsScreen — przycisk, handle_export, notice

**Files:**
- Modify: `src/Admin/RegistrationsScreen.php`
- Test: `tests/Integration/Admin/RegistrationExportScreenTest.php`

**Interfaces:**
- Consumes: `RegistrationsExporter::buildCsv`, `exportRegistrations`, `accommodationBookingsFor`, `EventFormLoader::load`, `EventConfigRepository::get`, `RegistrationTypeCollection`, `AccommodationConfig`, istniejące `redirect`/`render_list`/`render_notice`.
- Produces: `public const ACTION_EXPORT = 'evreg_export'`; `handle_export()`; przycisk na liście; notice `export_no_event`.

- [ ] **Step 1: Testy — guardy + wymóg eventu + przycisk**

W `tests/Integration/Admin/RegistrationExportScreenTest.php` (wzór `RegistrationsScreenTest`; filtr `wp_redirect` rzucający wyjątek; zły nonce/cap → `WPDieException`):

1. `handle_export` bez/zły nonce → `WPDieException`.
2. `handle_export` bez capa → `WPDieException`.
3. Poprawny nonce+cap ale **brak `event_id`** (0) → PRG na listę z `evreg_msg=export_no_event` (redirect złapany), bez streamingu.
4. Notice: `render_list` (albo `render_notice`) z `$_GET['evreg_msg']='export_no_event'` renderuje komunikat błędu.
5. Przycisk: `render_list` z wybranym `event_id` renderuje link „Eksportuj CSV" z `action=evreg_export`, `event_id` i nonce w URL.

(Streaming przy poprawnym evencie — nagłówki HTTP + `exit` — trudny w PHPUnit; pokrycie logiki CSV jest w Task 3. Tu wystarczą guardy + wymóg eventu + przycisk.)

- [ ] **Step 2: Uruchom — ma paść**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RegistrationExportScreen`
Expected: FAIL.

- [ ] **Step 3: Stała + rejestracja + notice**

W `RegistrationsScreen`: dodaj `public const ACTION_EXPORT = 'evreg_export';` przy innych `ACTION_`. W `register()` dodaj `add_action( 'admin_post_' . self::ACTION_EXPORT, array( self::class, 'handle_export' ) );`. W `render_notice()` `$messages` dodaj:
```php
'export_no_event' => array( 'error', __( 'Wybierz event, aby wyeksportować zgłoszenia.', 'event-registration' ) ),
```

- [ ] **Step 4: Przycisk „Eksportuj CSV" na liście**

W `render_list()`, po `render_notice()` a przed formularzem filtrów (albo obok `<h1>`), dodaj link eksportu niosący bieżące filtry. Czytaj filtry z `$_GET` (te same co lista):

```php
// Link eksportu z bieżącymi filtrami (serwer i tak je re-waliduje w handlerze).
$export_args = array( 'action' => self::ACTION_EXPORT );
foreach ( array( 'status', 'type_key', 'event_id' ) as $key ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET[ $key ] ) && '' !== (string) $_GET[ $key ] ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$export_args[ $key ] = sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) );
	}
}
$export_url = wp_nonce_url( add_query_arg( $export_args, admin_url( 'admin-post.php' ) ), self::ACTION_EXPORT );
printf(
	'<a class="button" href="%s">%s</a>',
	esc_url( $export_url ),
	esc_html__( 'Eksportuj CSV', 'event-registration' )
);
```

- [ ] **Step 5: handle_export**

```php
/** Handler eksportu CSV zgłoszeń (streaming, bez PRG na sukcesie). */
public static function handle_export(): void {
	check_admin_referer( self::ACTION_EXPORT );
	if ( ! current_user_can( Capabilities::CAP ) ) {
		wp_die( esc_html__( 'Brak uprawnień.', 'event-registration' ) );
	}

	$filters  = array();
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce sprawdzony wyżej.
	if ( isset( $_GET['status'] ) && '' !== (string) $_GET['status'] ) {
		$filters['status'] = sanitize_text_field( wp_unslash( (string) $_GET['status'] ) );
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['type_key'] ) && '' !== (string) $_GET['type_key'] ) {
		$filters['type_key'] = sanitize_text_field( wp_unslash( (string) $_GET['type_key'] ) );
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$event_id = isset( $_GET['event_id'] ) ? (int) $_GET['event_id'] : 0;
	if ( $event_id <= 0 ) {
		self::redirect( 'export_no_event' );
		return;
	}
	$filters['event_id'] = $event_id;

	$schema = ( new \EvReg\Frontend\EventFormLoader( new EventConfigRepository() ) )->load( $event_id );
	if ( null === $schema ) {
		self::redirect( 'export_no_event' );
		return;
	}
	$config        = ( new EventConfigRepository() )->get( $event_id );
	$types         = RegistrationTypeCollection::fromArray( is_array( $config['types'] ) ? $config['types'] : array() );
	$accommodation = AccommodationConfig::fromArray( is_array( $config['accommodation'] ) ? $config['accommodation'] : array() );

	$repository = new RegistrationRepository();
	$rows       = $repository->exportRegistrations( $filters );
	$ids        = array_map( 'intval', array_column( $rows, 'id' ) );
	$bookings   = $repository->accommodationBookingsFor( $ids );

	$csv      = ( new RegistrationsExporter() )->buildCsv( $schema, $accommodation, $types, $rows, $bookings );
	$filename = sanitize_file_name( 'zgloszenia-event-' . $event_id . '-' . current_time( 'Y-m-d' ) . '.csv' );

	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV binarny, nie HTML; komórki neutralizowane w buildCsv.
	exit;
}
```

Sprawdź istniejące `use` w `RegistrationsScreen` — dodaj brakujące (`RegistrationTypeCollection`, `AccommodationConfig`, `RegistrationsExporter`, `RegistrationRepository`, `EventConfigRepository`) jeśli nie zaimportowane; phpstan/phpcs wskaże.

- [ ] **Step 6: Uruchom — ma przejść**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RegistrationExportScreen`
Expected: PASS.

- [ ] **Step 7: Pełny zestaw + statyka**

Run:
```
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=1G
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
```
Expected: wszystko zielone. `DomainPurityTest` zielony (mapper w `src/Domain/Export`).

- [ ] **Step 8: Commit**

```bash
git add src/Admin/RegistrationsScreen.php tests/Integration/Admin/RegistrationExportScreenTest.php
git commit -m "feat: wire CSV export button, handler and notice"
```

---

## Uwagi wykonawcze

- **RED faza:** konsola PHPUnit w kontenerze zniekształca `Error` — weryfikuj jawnym try/catch drukującym `get_class($e)`/`getMessage()`.
- **Ekran/handler:** `set_current_screen(...)` przed instancjonowaniem `WP_List_Table` w testach; redirect handlera łap filtrem `wp_redirect` rzucającym wyjątek; zły nonce/cap → `WPDieException`.
- **Kolejność:** Task 3 zależy od Task 1 (mapper). Task 4 zależy od Task 1-3. Zachowaj 1→4.
- **CSV injection** neutralizowany TYLKO na komórkach danych, nie na nagłówkach (§6 spec).
- **phpcs `fopen`/`fputcsv`:** to bufor `php://temp` (in-memory), nie plik FS — jeśli sniff `WordPress.WP.AlternativeFunctions` protestuje, celowany `// phpcs:ignore` z tym uzasadnieniem.
- **Weryfikacja przeglądarkowa** (poza subagentami): kliknięcie „Eksportuj CSV" pobiera plik, otwiera się w Excelu z polskimi znakami (BOM), kolumny wg schematu, filtry zawężają zawartość, brak eventu → komunikat.
