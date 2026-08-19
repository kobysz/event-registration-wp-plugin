# Mail Queue (Plan 4A) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Zbudować silnik kolejki mailowej i wpiąć pięć maili transakcyjnych w istniejący cykl życia zgłoszenia, domykając double opt-in: uczestnik dostaje maila z linkiem, którego endpoint (Plan 3B) już obsługuje.

**Architecture:** `ReservationService` i cron wygasania emitują hooki cyklu życia PO COMMIT. `Mail\Subscriber` je łapie, buduje `Placeholders` (`PlaceholderFactory`) i woła `MailQueue::enqueue`, które renderuje temat i treść z rozstrzygniętego szablonu (`TemplateResolver` → meta eventu albo `DefaultTemplates`) i wstawia gotowy snapshot do tabeli. `Dispatcher` z crona odzyskuje osierocone wiersze, przejmuje wiersz warunkowym UPDATE-em, wysyła `wp_mail` i zapisuje `sent` albo ponawia wg `RetryPolicy`. Podstawianie placeholderów, podsumowanie odpowiedzi i polityka ponawiania to czysta domena bez WordPressa.

**Tech Stack:** PHP 8.1, WordPress 6.4+, `wp_mail`, WP-Cron, PHPUnit (unit bez WP + integration w wp-env). Zero JS, zero buildów.

**Spec:** `docs/superpowers/specs/2026-08-19-mail-queue-design.md`

**Gałąź:** `plan-4a-mail-queue` od `master`.

## Global Constraints

- Minimalne PHP 8.1, minimalne WordPress 6.4.
- Namespace `EvReg\`, PSR-4, `src/`. Prefiks hooków/opcji `evreg_`, meta `_evreg_`.
- **`src/Domain/` = zero WordPressa i zero `$wpdb`.** Pilnuje `tests/Unit/Architecture/DomainPurityTest.php`. Żadnego `__()`, `wp_*`, `esc_*` w `src/Domain/Mail/`.
- Każdy plik PHP poza `src/Domain/` i `tests/` zaczyna od `defined( 'ABSPATH' ) || exit;`.
- Text domain `event-registration` — wszystkie stringi widziane przez człowieka (tematy, treści domyślne, etykiety w podsumowaniu) przez `__()`.
- Wszystkie zapytania przez `$wpdb->prepare`. SQL kolejki wyłącznie w `MailQueueRepository`.
- Czas zawsze UTC: `current_time( 'mysql', true )` po stronie WP, `gmdate( 'Y-m-d H:i:s', ... )` przy liczeniu terminów.
- Maile plain text. Brak HTML, brak multipart.
- Katalogi testów wielką literą (`tests/Unit/Domain/Mail/`, `tests/Integration/Mail/`), suity małą (`--testsuite unit`).
- PHPStan poziom 6 bez nowych `@phpstan-ignore`. `phpcs` czysto pod istniejącym `phpcs.xml.dist` (bez dokładania wykluczeń sniffów).
- Styl kodu jak w repo: tabulatory, `array()` zamiast `[]`, warunki Yody, docblock z `@param`/`@return` na każdej metodzie publicznej.
- Commity po każdym tasku, w języku angielskim, Conventional Commits.

### Komendy referencyjne

```bash
# unit (kontener, bez WP)
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
# integration (kontener, z WP)
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
# statyczna analiza i styl
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=512M
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
```

Kwirk (z Planu 1): konsola PHPUnit w tym kontenerze zniekształca komunikat niezłapanego `Error` — fazę RED weryfikuj po nazwie klasy błędu, a gdy komunikat jest nieczytelny, opakuj wywołanie w try/catch drukujący `get_class($e)`. PHPStan wymaga `--memory-limit=512M` (default kontenera OOM-uje).

Host nie ma PHP — **każda** komenda PHP idzie przez `node scripts/wp-env.cjs`. Nie wołaj gołego `npx wp-env`.

### Interfejsy z Planów 1–3B (konsumowane — sygnatury zweryfikowane w kodzie)

- `EvReg\Persistence\Migrations` — `public const DB_VERSION = 2` (ten plan podnosi na 3), `VERSION_OPTION = 'evreg_db_version'`, `static table( string $name ): string`, `static install(): void`, `static maybe_upgrade(): void`, `private static statements( string $charset ): array`
- `EvReg\Persistence\RegistrationRepository` — `__construct()`, `findByToken( string $token ): ?array`, `insertRegistration( array $row ): int`, `markConfirmed( int $id ): void`, `expirePending( string $now ): int` (ten plan zmienia zwracany typ)
- `EvReg\Persistence\EventConfigRepository` — `__construct()`, `get( int $event_id ): array` → klucze `schema|types|accommodation|settings` (ten plan ich NIE rusza)
- `EvReg\Services\ReservationService` — `__construct( RegistrationRepository, EventConfigRepository )`, `reserve( int $event_id, ReservationRequest ): ReservationResult`, `confirm( string $token ): ConfirmationResult`
- `EvReg\Services\ReservationResult` — readonly `code` (`reserved|waitlisted|rejected|duplicate`), `registrationId` (?int), `token` (?string), `accommodationGranted` (bool), `reason` (?string)
- `EvReg\Services\ReservationRequest::__construct( string $email, string $name, string $typeKey, array $data, ?AccommodationSelection $selection = null )`
- `EvReg\Cron\ExpirePending` — `HOOK = 'evreg_expire_pending'`, `INTERVAL = 'evreg_15min'`, `static register|schedule|unschedule|add_interval|run`
- `EvReg\Frontend\EventFormLoader` — `__construct( EventConfigRepository )`, `load( int $event_id ): ?FormSchema`
- `EvReg\Domain\Schema\FormSchema` — `sections(): Section[]`, `allFields(): Field[]`, `findField( string ): ?Field`, const `TYPE_FIELD_KEY = '__type'`
- `EvReg\Domain\Schema\Field` — readonly `key`, `type` (`FieldType`), `label`, `required`, `options` (`Option[]`), `config`, `description`, `condition`
- `EvReg\Domain\Schema\FieldType` — `isInput(): bool` (false tylko dla `Heading`/`Paragraph`), `hasOptions(): bool` (`Select`/`Radio`/`CheckboxGroup`), `isMultiValue(): bool` (`CheckboxGroup`)
- `EvReg\Domain\Schema\Option` — readonly `value`, `label`
- `EvReg\Domain\Schema\VisibilityResolver::__construct( ConditionEngine )`, `resolve( FormSchema, array $answers ): VisibilitySet`; `VisibilitySet::visibleFields(): Field[]`
- `EvReg\Domain\Registration\RegistrationTypeCollection::fromArray( array ): self`, `get( string $key ): ?RegistrationType`; `RegistrationType` readonly `key`, `label`, `price`, `capacity`, `active`
- `EvReg\Domain\Accommodation\AccommodationConfig::fromArray( array ): self`, `packages(): Package[]`, `rooms(): RoomType[]`, `room( string $key ): ?RoomType`; `Package` readonly `key`,`label`; `RoomType` readonly `key`,`label`,`roommateField`
- `EvReg\Plugin` — `VERSION`, `TEXT_DOMAIN = 'event-registration'`, `plugin_file()`

### Kształt wiersza zgłoszenia (tabela `evreg_registrations`)

`id`, `event_id`, `type_key`, `status`, `email`, `name`, `token`, `data` (JSON odpowiedzi po walidacji), `price_total`, `note`, `created_at`, `expires_at`, `confirmed_at`, `updated_at`. Odczyt `$wpdb` z `ARRAY_A` zwraca stringi — rzutuj jawnie.

---

## File Structure

**Nowe pliki produkcyjne**

| Plik | Odpowiedzialność |
|---|---|
| `src/Domain/Mail/Placeholders.php` | Niemutowalna mapa `klucz => wartość` dla szablonu |
| `src/Domain/Mail/TemplateRenderer.php` | Jednoprzebiegowe podstawianie `{klucz}` |
| `src/Domain/Mail/RetryPolicy.php` | `attempts` → opóźnienie w sekundach albo `null` |
| `src/Domain/Mail/SummaryBuilder.php` | `FormSchema` + odpowiedzi → tekst podsumowania |
| `src/Persistence/MailQueueRepository.php` | Jedyne `$wpdb` dla `evreg_mail_queue` |
| `src/Persistence/MailTemplateRepository.php` | Odczyt meta `_evreg_mail_templates` |
| `src/Mail/DefaultTemplates.php` | Domyślne teksty pięciu maili przez `__()` |
| `src/Mail/TemplateResolver.php` | Szablon eventu z fallbackiem na domyślny, per pole |
| `src/Mail/PlaceholderFactory.php` | Wiersz zgłoszenia + config eventu → `Placeholders` |
| `src/Mail/MailQueue.php` | `enqueue()`: render snapshot → INSERT idempotentny |
| `src/Mail/Dispatcher.php` | Odzysk → claim → `wp_mail` → `sent`/retry/`failed` |
| `src/Mail/Subscriber.php` | Hooki cyklu życia → `MailQueue` |
| `src/Cron/DispatchMail.php` | Interwał minutowy, planowanie, wywołanie `Dispatcher` |
| `src/Cron/PurgeMailQueue.php` | Dzienne kasowanie `sent` starszych niż 30 dni |

**Modyfikowane pliki produkcyjne**

| Plik | Zmiana |
|---|---|
| `src/Persistence/Migrations.php` | `DB_VERSION` 2 → 3; kolumna `headers`, indeks unikalny w `mail_queue` |
| `src/Persistence/RegistrationRepository.php` | `findById()`, `findAccommodationBooking()`; `expirePending()` zwraca wiersze |
| `src/Services/ReservationService.php` | `do_action` po COMMIT w `reserve()` i w `confirm()` |
| `src/Cron/ExpirePending.php` | Hook per wygasłe zgłoszenie obok istniejącego licznika |
| `event-registration.php` | Rejestracja `Subscriber`, `DispatchMail`, `PurgeMailQueue` + aktywacja/dezaktywacja |

**Nowe pliki testowe**

`tests/Unit/Domain/Mail/{PlaceholdersTest,TemplateRendererTest,RetryPolicyTest,SummaryBuilderTest}.php`,
`tests/Integration/Persistence/{MailQueueMigrationTest,MailQueueRepositoryTest,MailTemplateRepositoryTest}.php`,
`tests/Integration/Mail/{TemplateResolverTest,PlaceholderFactoryTest,MailQueueTest,DispatcherTest,SubscriberTest}.php`,
`tests/Integration/Cron/{DispatchMailTest,PurgeMailQueueTest}.php`,
`tests/Integration/Services/ReservationHooksTest.php`.

**Świadomie nietykane:** `EventConfigRepository` — szablony maili dostają własne repozytorium, żeby nie ruszać kontraktu `get()`, na którym stoją testy REST i konfiguracji. `assets/`, `phpcs.xml.dist`, `phpstan.neon.dist` bez zmian.

---
## Task 1: Domain/Mail — Placeholders, TemplateRenderer, RetryPolicy

**Files:**
- Create: `src/Domain/Mail/Placeholders.php`
- Create: `src/Domain/Mail/TemplateRenderer.php`
- Create: `src/Domain/Mail/RetryPolicy.php`
- Test: `tests/Unit/Domain/Mail/PlaceholdersTest.php`
- Test: `tests/Unit/Domain/Mail/TemplateRendererTest.php`
- Test: `tests/Unit/Domain/Mail/RetryPolicyTest.php`

**Interfaces:**
- Consumes: nic (czysta domena, zero zależności)
- Produces:
  - `EvReg\Domain\Mail\Placeholders::__construct( array<string,string> $values = array() )`, `with( string $key, string $value ): self`, `has( string $key ): bool`, `get( string $key ): string`, `toArray(): array<string,string>`
  - `EvReg\Domain\Mail\TemplateRenderer::render( string $template, Placeholders $values ): string`
  - `EvReg\Domain\Mail\RetryPolicy::MAX_ATTEMPTS = 3`, `next( int $attempts ): ?int`

- [ ] **Step 1: Napisz testy jednostkowe (RED)**

`tests/Unit/Domain/Mail/PlaceholdersTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Mail;

use EvReg\Domain\Mail\Placeholders;
use PHPUnit\Framework\TestCase;

final class PlaceholdersTest extends TestCase {

	public function test_get_returns_value_for_known_key(): void {
		$values = new Placeholders( array( 'imie' => 'Jan' ) );

		$this->assertTrue( $values->has( 'imie' ) );
		$this->assertSame( 'Jan', $values->get( 'imie' ) );
	}

	public function test_get_returns_empty_string_for_unknown_key(): void {
		$values = new Placeholders();

		$this->assertFalse( $values->has( 'imie' ) );
		$this->assertSame( '', $values->get( 'imie' ) );
	}

	public function test_with_returns_new_instance_and_leaves_original_untouched(): void {
		$original = new Placeholders( array( 'imie' => 'Jan' ) );

		$extended = $original->with( 'email', 'jan@example.com' );

		$this->assertNotSame( $original, $extended );
		$this->assertFalse( $original->has( 'email' ) );
		$this->assertSame( 'jan@example.com', $extended->get( 'email' ) );
		$this->assertSame( 'Jan', $extended->get( 'imie' ) );
	}

	public function test_to_array_returns_all_values(): void {
		$values = new Placeholders( array( 'imie' => 'Jan', 'email' => 'jan@example.com' ) );

		$this->assertSame( array( 'imie' => 'Jan', 'email' => 'jan@example.com' ), $values->toArray() );
	}
}
```

`tests/Unit/Domain/Mail/TemplateRendererTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Mail;

use EvReg\Domain\Mail\Placeholders;
use EvReg\Domain\Mail\TemplateRenderer;
use PHPUnit\Framework\TestCase;

final class TemplateRendererTest extends TestCase {

	private TemplateRenderer $renderer;

	protected function setUp(): void {
		$this->renderer = new TemplateRenderer();
	}

	public function test_substitutes_known_placeholders(): void {
		$out = $this->renderer->render(
			'Cześć {imie}, zapisano {email}.',
			new Placeholders( array( 'imie' => 'Jan', 'email' => 'jan@example.com' ) )
		);

		$this->assertSame( 'Cześć Jan, zapisano jan@example.com.', $out );
	}

	public function test_leaves_unknown_placeholder_literally(): void {
		$out = $this->renderer->render( 'Cześć {imie_uczestnika}.', new Placeholders( array( 'imie' => 'Jan' ) ) );

		$this->assertSame( 'Cześć {imie_uczestnika}.', $out );
	}

	public function test_does_not_expand_placeholders_coming_from_values(): void {
		$out = $this->renderer->render( 'Uwaga: {podsumowanie}', new Placeholders( array( 'podsumowanie' => 'Pole: {imie}', 'imie' => 'Jan' ) ) );

		$this->assertSame( 'Uwaga: Pole: {imie}', $out );
	}

	public function test_substitutes_repeated_placeholder_everywhere(): void {
		$out = $this->renderer->render( '{imie} i jeszcze raz {imie}', new Placeholders( array( 'imie' => 'Jan' ) ) );

		$this->assertSame( 'Jan i jeszcze raz Jan', $out );
	}

	public function test_returns_template_unchanged_without_placeholders(): void {
		$this->assertSame( 'Zwykły tekst.', $this->renderer->render( 'Zwykły tekst.', new Placeholders() ) );
	}
}
```

`tests/Unit/Domain/Mail/RetryPolicyTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Mail;

use EvReg\Domain\Mail\RetryPolicy;
use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase {

	private RetryPolicy $policy;

	protected function setUp(): void {
		$this->policy = new RetryPolicy();
	}

	public function test_first_failure_retries_after_one_minute(): void {
		$this->assertSame( 60, $this->policy->next( 1 ) );
	}

	public function test_second_failure_retries_after_five_minutes(): void {
		$this->assertSame( 300, $this->policy->next( 2 ) );
	}

	public function test_third_failure_gives_up(): void {
		$this->assertNull( $this->policy->next( 3 ) );
	}

	public function test_attempts_beyond_maximum_give_up(): void {
		$this->assertNull( $this->policy->next( 7 ) );
	}

	public function test_max_attempts_is_three(): void {
		$this->assertSame( 3, RetryPolicy::MAX_ATTEMPTS );
	}
}
```

- [ ] **Step 2: Uruchom testy i potwierdź RED**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit --filter 'PlaceholdersTest|TemplateRendererTest|RetryPolicyTest'
```
Expected: FAIL — `Error: Class "EvReg\Domain\Mail\Placeholders" not found` (i analogicznie dla pozostałych dwóch klas).

- [ ] **Step 3: Zaimplementuj Placeholders**

`src/Domain/Mail/Placeholders.php`:

```php
<?php
/**
 * Zbiór wartości podstawianych w szablonie maila.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Mail;

/**
 * Zbiór wartości podstawianych w szablonie maila. Klucze bez klamr.
 */
final class Placeholders {

	/**
	 * Wartości placeholderów.
	 *
	 * @var array<string,string>
	 */
	private array $values;

	/**
	 * Tworzy zbiór z mapy klucz => wartość.
	 *
	 * @param array<string,string> $values Wartości placeholderów, klucze bez klamr.
	 */
	public function __construct( array $values = array() ) {
		$this->values = $values;
	}

	/**
	 * Zwraca nowy zbiór z dodaną wartością. Nie modyfikuje bieżącego.
	 *
	 * @param string $key   Klucz bez klamr.
	 * @param string $value Wartość do podstawienia.
	 */
	public function with( string $key, string $value ): self {
		$values         = $this->values;
		$values[ $key ] = $value;

		return new self( $values );
	}

	/**
	 * Czy zbiór zna podany klucz.
	 *
	 * @param string $key Klucz bez klamr.
	 */
	public function has( string $key ): bool {
		return array_key_exists( $key, $this->values );
	}

	/**
	 * Zwraca wartość klucza albo pusty łańcuch, gdy klucza brak.
	 *
	 * @param string $key Klucz bez klamr.
	 */
	public function get( string $key ): string {
		return $this->values[ $key ] ?? '';
	}

	/**
	 * Zwraca wszystkie wartości.
	 *
	 * @return array<string,string>
	 */
	public function toArray(): array {
		return $this->values;
	}
}
```

- [ ] **Step 4: Zaimplementuj TemplateRenderer**

`src/Domain/Mail/TemplateRenderer.php`:

```php
<?php
/**
 * Podstawianie placeholderów w szablonie maila.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Mail;

/**
 * Podstawianie placeholderów w szablonie maila.
 *
 * Jeden przebieg: wartość zawierająca {klucz} nie jest rozwijana ponownie.
 * Nieznany placeholder zostaje w treści dosłownie, żeby literówka w szablonie
 * była widoczna zamiast zamieniać się w pustkę.
 */
final class TemplateRenderer {

	/**
	 * Renderuje szablon, podstawiając znane placeholdery.
	 *
	 * @param string       $template Szablon z placeholderami w klamrach.
	 * @param Placeholders $values   Wartości do podstawienia.
	 */
	public function render( string $template, Placeholders $values ): string {
		$rendered = preg_replace_callback(
			'/\{([a-z0-9_]+)\}/i',
			static function ( array $match ) use ( $values ): string {
				return $values->has( $match[1] ) ? $values->get( $match[1] ) : $match[0];
			},
			$template
		);

		return null === $rendered ? $template : $rendered;
	}
}
```

- [ ] **Step 5: Zaimplementuj RetryPolicy**

`src/Domain/Mail/RetryPolicy.php`:

```php
<?php
/**
 * Polityka ponawiania nieudanych wysyłek.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Mail;

/**
 * Polityka ponawiania nieudanych wysyłek: trzy próby, narastające opóźnienie.
 */
final class RetryPolicy {

	public const MAX_ATTEMPTS = 3;

	/**
	 * Opóźnienie w sekundach po n-tej nieudanej próbie.
	 *
	 * @var array<int,int>
	 */
	private const DELAYS = array(
		1 => 60,
		2 => 300,
	);

	/**
	 * Zwraca opóźnienie do następnej próby albo null, gdy próby się wyczerpały.
	 *
	 * @param int $attempts Liczba prób wykonanych do tej pory (łącznie z tą, która właśnie zawiodła).
	 *
	 * @return int|null Sekundy do następnej próby; null oznacza status failed.
	 */
	public function next( int $attempts ): ?int {
		return self::DELAYS[ $attempts ] ?? null;
	}
}
```

- [ ] **Step 6: Uruchom testy i potwierdź GREEN**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit --filter 'PlaceholdersTest|TemplateRendererTest|RetryPolicyTest'
```
Expected: PASS, 14 testów.

- [ ] **Step 7: Potwierdź czystość domeny i styl**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit --filter DomainPurityTest
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs src/Domain/Mail tests/Unit/Domain/Mail
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=512M
```
Expected: PASS, zero błędów phpcs, zero błędów phpstan.

- [ ] **Step 8: Commit**

```bash
git add src/Domain/Mail tests/Unit/Domain/Mail
git commit -m "feat: add pure mail domain primitives (placeholders, renderer, retry policy)"
```

---

## Task 2: Domain/Mail — SummaryBuilder

Buduje tekst `{podsumowanie}`: odpowiedzi uczestnika w kolejności pól schemy, wyłącznie te widoczne.

**Files:**
- Create: `src/Domain/Mail/SummaryBuilder.php`
- Test: `tests/Unit/Domain/Mail/SummaryBuilderTest.php`

**Interfaces:**
- Consumes: `VisibilityResolver::resolve( FormSchema, array ): VisibilitySet`, `VisibilitySet::visibleFields(): Field[]`, `Field` (readonly `key`, `type`, `label`, `options`), `FieldType::isInput()/hasOptions()/isMultiValue()`, `Option` (readonly `value`, `label`), `FormSchema::TYPE_FIELD_KEY`
- Produces: `EvReg\Domain\Mail\SummaryBuilder::__construct( VisibilityResolver $resolver, string $yesLabel )`, `build( FormSchema $schema, array<string,mixed> $answers ): string`

Reguły:
- pomija pola nie-wejściowe (`Heading`, `Paragraph`), pole `__type` (ma własny placeholder `{typ}`) i pola typu `accommodation` (nocleg dokłada `PlaceholderFactory` w Tasku 5),
- pomija pola niewidoczne przy tych odpowiedziach oraz wartości puste,
- pola z opcjami pokazują etykietę opcji, nie surową wartość,
- `checkbox-group` łączy etykiety przecinkiem,
- `checkbox` (wartość `bool`) pokazuje `$yesLabel`, gdy `true`; `false` znika,
- format linii: `Etykieta: wartość`, linie łączone `\n`.

`$yesLabel` wstrzykiwany, bo domena nie może wołać `__()`.

- [ ] **Step 1: Napisz test (RED)**

`tests/Unit/Domain/Mail/SummaryBuilderTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Mail;

use EvReg\Domain\Conditions\ConditionEngine;
use EvReg\Domain\Mail\SummaryBuilder;
use EvReg\Domain\Schema\FormSchema;
use EvReg\Domain\Schema\VisibilityResolver;
use PHPUnit\Framework\TestCase;

final class SummaryBuilderTest extends TestCase {

	private SummaryBuilder $builder;

	protected function setUp(): void {
		$this->builder = new SummaryBuilder( new VisibilityResolver( new ConditionEngine() ), 'Tak' );
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
							array( 'key' => 'naglowek', 'type' => 'heading', 'label' => 'Twoje dane' ),
							array( 'key' => '__type', 'type' => 'radio', 'label' => 'Typ', 'options' => array( array( 'value' => 'uczestnik', 'label' => 'Uczestnik' ) ) ),
							array( 'key' => 'imie', 'type' => 'text', 'label' => 'Imię i nazwisko' ),
							array( 'key' => 'email', 'type' => 'email', 'label' => 'E-mail' ),
							array( 'key' => 'dieta', 'type' => 'select', 'label' => 'Dieta', 'options' => array( array( 'value' => 'wege', 'label' => 'Wegetariańska' ) ) ),
							array( 'key' => 'warsztaty', 'type' => 'checkbox-group', 'label' => 'Warsztaty', 'options' => array( array( 'value' => 'a', 'label' => 'Warsztat A' ), array( 'value' => 'b', 'label' => 'Warsztat B' ) ) ),
							array( 'key' => 'zgoda', 'type' => 'checkbox', 'label' => 'Zgoda na regulamin' ),
							array( 'key' => 'faktura', 'type' => 'text', 'label' => 'Dane do faktury', 'condition' => array( 'field' => '__type', 'operator' => 'in', 'value' => array( 'firma' ) ) ),
						),
					),
				),
			)
		);
	}

	public function test_lists_visible_answers_in_schema_order(): void {
		$summary = $this->builder->build(
			$this->schema(),
			array(
				'__type' => 'uczestnik',
				'imie'   => 'Jan Kowalski',
				'email'  => 'jan@example.com',
				'zgoda'  => true,
			)
		);

		$this->assertSame(
			"Imię i nazwisko: Jan Kowalski\nE-mail: jan@example.com\nZgoda na regulamin: Tak",
			$summary
		);
	}

	public function test_uses_option_labels_and_joins_multi_values(): void {
		$summary = $this->builder->build(
			$this->schema(),
			array(
				'__type'    => 'uczestnik',
				'imie'      => 'Jan',
				'dieta'     => 'wege',
				'warsztaty' => array( 'a', 'b' ),
			)
		);

		$this->assertStringContainsString( 'Dieta: Wegetariańska', $summary );
		$this->assertStringContainsString( 'Warsztaty: Warsztat A, Warsztat B', $summary );
	}

	public function test_skips_type_field_headings_empty_values_and_false_checkbox(): void {
		$summary = $this->builder->build(
			$this->schema(),
			array(
				'__type' => 'uczestnik',
				'imie'   => 'Jan',
				'email'  => '',
				'zgoda'  => false,
			)
		);

		$this->assertSame( 'Imię i nazwisko: Jan', $summary );
	}

	public function test_skips_fields_hidden_by_conditions(): void {
		$summary = $this->builder->build(
			$this->schema(),
			array(
				'__type'  => 'uczestnik',
				'imie'    => 'Jan',
				'faktura' => 'NIP 123',
			)
		);

		$this->assertStringNotContainsString( 'Dane do faktury', $summary );
	}

	public function test_returns_empty_string_when_nothing_to_show(): void {
		$this->assertSame( '', $this->builder->build( $this->schema(), array( '__type' => 'uczestnik' ) ) );
	}
}
```

- [ ] **Step 2: Uruchom test i potwierdź RED**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit --filter SummaryBuilderTest
```
Expected: FAIL — `Error: Class "EvReg\Domain\Mail\SummaryBuilder" not found`.

- [ ] **Step 3: Zaimplementuj SummaryBuilder**

`src/Domain/Mail/SummaryBuilder.php`:

```php
<?php
/**
 * Budowa tekstowego podsumowania odpowiedzi zgłoszenia.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Mail;

use EvReg\Domain\Schema\Field;
use EvReg\Domain\Schema\FieldType;
use EvReg\Domain\Schema\FormSchema;
use EvReg\Domain\Schema\VisibilityResolver;

/**
 * Budowa tekstowego podsumowania odpowiedzi zgłoszenia.
 *
 * Etykieta „tak” jest wstrzykiwana, bo domena nie tłumaczy stringów.
 */
final class SummaryBuilder {

	/**
	 * Tworzy builder.
	 *
	 * @param VisibilityResolver $resolver Rozstrzyganie widoczności pól.
	 * @param string             $yesLabel Etykieta dla zaznaczonego pola typu checkbox.
	 */
	public function __construct(
		private readonly VisibilityResolver $resolver,
		private readonly string $yesLabel
	) {
	}

	/**
	 * Buduje podsumowanie widocznych, niepustych odpowiedzi.
	 *
	 * @param FormSchema          $schema  Złożona schema formularza.
	 * @param array<string,mixed> $answers Odpowiedzi po walidacji.
	 */
	public function build( FormSchema $schema, array $answers ): string {
		$lines = array();

		foreach ( $this->resolver->resolve( $schema, $answers )->visibleFields() as $field ) {
			if ( ! $this->isReportable( $field ) ) {
				continue;
			}

			$value = $this->format( $field, $answers[ $field->key ] ?? null );

			if ( '' === $value ) {
				continue;
			}

			$lines[] = $field->label . ': ' . $value;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Czy pole w ogóle trafia do podsumowania.
	 *
	 * @param Field $field Pole schemy.
	 */
	private function isReportable( Field $field ): bool {
		if ( FormSchema::TYPE_FIELD_KEY === $field->key ) {
			return false;
		}

		if ( FieldType::Accommodation === $field->type ) {
			return false;
		}

		return $field->type->isInput();
	}

	/**
	 * Formatuje wartość odpowiedzi do jednej linii tekstu.
	 *
	 * @param Field $field Pole schemy.
	 * @param mixed $value Wartość odpowiedzi.
	 */
	private function format( Field $field, mixed $value ): string {
		if ( null === $value || false === $value ) {
			return '';
		}

		if ( true === $value ) {
			return $this->yesLabel;
		}

		if ( is_array( $value ) ) {
			$labels = array();

			foreach ( $value as $item ) {
				$label = $this->label( $field, (string) $item );

				if ( '' !== $label ) {
					$labels[] = $label;
				}
			}

			return implode( ', ', $labels );
		}

		if ( is_scalar( $value ) ) {
			return $this->label( $field, (string) $value );
		}

		return '';
	}

	/**
	 * Zamienia wartość na etykietę opcji, gdy pole ma opcje.
	 *
	 * @param Field  $field Pole schemy.
	 * @param string $value Surowa wartość odpowiedzi.
	 */
	private function label( Field $field, string $value ): string {
		if ( ! $field->type->hasOptions() ) {
			return trim( $value );
		}

		foreach ( $field->options as $option ) {
			if ( $option->value === $value ) {
				return $option->label;
			}
		}

		return trim( $value );
	}
}
```

- [ ] **Step 4: Uruchom test i potwierdź GREEN**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit --filter SummaryBuilderTest
```
Expected: PASS, 5 testów.

- [ ] **Step 5: Cała suita unit + statyka**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs src/Domain/Mail tests/Unit/Domain/Mail
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=512M
```
Expected: PASS bez regresji, `DomainPurityTest` zielony, zero błędów phpcs i phpstan.

- [ ] **Step 6: Commit**

```bash
git add src/Domain/Mail/SummaryBuilder.php tests/Unit/Domain/Mail/SummaryBuilderTest.php
git commit -m "feat: add answer summary builder for mail templates"
```

---
## Task 3: Migracja DB_VERSION 3 i MailQueueRepository

**Files:**
- Modify: `src/Persistence/Migrations.php` (stała `DB_VERSION`, definicja tabeli `mail_queue`)
- Create: `src/Persistence/MailQueueRepository.php`
- Modify: `tests/Integration/Persistence/LocksMigrationTest.php:34` (asercja `test_db_version_is_two`)
- Test: `tests/Integration/Persistence/MailQueueMigrationTest.php`
- Test: `tests/Integration/Persistence/MailQueueRepositoryTest.php`

**Interfaces:**
- Consumes: `Migrations::table( string ): string`, `Migrations::install(): void`
- Produces:
  - `EvReg\Persistence\Migrations::DB_VERSION = 3`
  - `EvReg\Persistence\MailQueueRepository` — consts `STATUS_QUEUED = 'queued'`, `STATUS_SENDING = 'sending'`, `STATUS_SENT = 'sent'`, `STATUS_FAILED = 'failed'`
  - `insert( array $row ): bool` — `$row` = `array{registration_id: int|null, event_id: int, template_key: string, recipient: string, subject: string, body: string, headers: string, scheduled_at: string}`; `true` gdy wiersz powstał, `false` gdy odbity przez indeks unikalny albo zapis się nie powiódł
  - `find( int $id ): ?array<string,mixed>`
  - `due( string $now, int $limit ): array<int,array<string,mixed>>`
  - `claim( int $id, string $now ): bool`
  - `markSent( int $id, string $now ): void`
  - `reschedule( int $id, string $scheduled_at, string $error ): void`
  - `markFailed( int $id, string $error ): void`
  - `recoverStale( string $threshold ): int`
  - `purgeSent( string $before ): int`

- [ ] **Step 1: Napisz testy migracji i repozytorium (RED)**

`tests/Integration/Persistence/MailQueueMigrationTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Persistence;

use EvReg\Persistence\Migrations;
use WP_UnitTestCase;

final class MailQueueMigrationTest extends WP_UnitTestCase {

	public function test_db_version_is_three(): void {
		$this->assertSame( 3, Migrations::DB_VERSION );
	}

	public function test_mail_queue_has_headers_column(): void {
		global $wpdb;

		Migrations::install();

		$columns = $wpdb->get_col( 'DESC ' . Migrations::table( 'mail_queue' ), 0 );

		$this->assertContains( 'headers', $columns );
	}

	public function test_mail_queue_has_unique_registration_template_index(): void {
		global $wpdb;

		Migrations::install();

		$table = Migrations::table( 'mail_queue' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$index = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'uniq_registration_template'", ARRAY_A );

		$this->assertCount( 2, $index );
		$this->assertSame( '0', (string) $index[0]['Non_unique'] );
		$this->assertSame( array( 'registration_id', 'template_key' ), array( $index[0]['Column_name'], $index[1]['Column_name'] ) );
	}

	public function test_install_records_current_db_version(): void {
		Migrations::install();

		$this->assertSame( 3, (int) get_option( Migrations::VERSION_OPTION ) );
	}
}
```

`tests/Integration/Persistence/MailQueueRepositoryTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Persistence;

use EvReg\Persistence\MailQueueRepository;
use EvReg\Persistence\Migrations;
use WP_UnitTestCase;

final class MailQueueRepositoryTest extends WP_UnitTestCase {

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
	 * @return array<string,mixed>
	 */
	private function row( array $overrides = array() ): array {
		return array_merge(
			array(
				'registration_id' => 7,
				'event_id'        => 1,
				'template_key'    => 'optin',
				'recipient'       => 'jan@example.com',
				'subject'         => 'Potwierdź zgłoszenie',
				'body'            => 'Treść',
				'headers'         => '',
				'scheduled_at'    => '2026-08-19 10:00:00',
			),
			$overrides
		);
	}

	/**
	 * Zwraca id ostatnio wstawionego wiersza kolejki.
	 */
	private function last_id(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . Migrations::table( 'mail_queue' ) );
	}

	public function test_insert_creates_queued_row(): void {
		$this->assertTrue( $this->repository->insert( $this->row() ) );

		$row = $this->repository->find( $this->last_id() );

		$this->assertNotNull( $row );
		$this->assertSame( MailQueueRepository::STATUS_QUEUED, $row['status'] );
		$this->assertSame( '0', (string) $row['attempts'] );
		$this->assertSame( 'jan@example.com', $row['recipient'] );
		$this->assertSame( '7', (string) $row['registration_id'] );
	}

	public function test_insert_is_ignored_for_duplicate_registration_and_template(): void {
		$this->assertTrue( $this->repository->insert( $this->row() ) );
		$this->assertFalse( $this->repository->insert( $this->row( array( 'subject' => 'Inny temat' ) ) ) );

		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Migrations::table( 'mail_queue' ) );

		$this->assertSame( 1, $count );
	}

	public function test_insert_allows_same_registration_with_different_template_key(): void {
		$this->assertTrue( $this->repository->insert( $this->row() ) );
		$this->assertTrue( $this->repository->insert( $this->row( array( 'template_key' => 'admin_new:abc' ) ) ) );
		$this->assertTrue( $this->repository->insert( $this->row( array( 'template_key' => 'admin_new:def' ) ) ) );
	}

	public function test_insert_allows_many_rows_without_registration_id(): void {
		$this->assertTrue( $this->repository->insert( $this->row( array( 'registration_id' => null ) ) ) );
		$this->assertTrue( $this->repository->insert( $this->row( array( 'registration_id' => null ) ) ) );

		$row = $this->repository->find( $this->last_id() );

		$this->assertNotNull( $row );
		$this->assertNull( $row['registration_id'] );
	}

	public function test_due_returns_only_queued_rows_scheduled_up_to_now_ordered(): void {
		$this->repository->insert( $this->row( array( 'template_key' => 'a', 'scheduled_at' => '2026-08-19 09:00:00' ) ) );
		$this->repository->insert( $this->row( array( 'template_key' => 'b', 'scheduled_at' => '2026-08-19 08:00:00' ) ) );
		$this->repository->insert( $this->row( array( 'template_key' => 'c', 'scheduled_at' => '2026-08-19 23:00:00' ) ) );

		$due = $this->repository->due( '2026-08-19 10:00:00', 10 );

		$this->assertCount( 2, $due );
		$this->assertSame( array( 'b', 'a' ), array( $due[0]['template_key'], $due[1]['template_key'] ) );
	}

	public function test_due_respects_limit(): void {
		$this->repository->insert( $this->row( array( 'template_key' => 'a' ) ) );
		$this->repository->insert( $this->row( array( 'template_key' => 'b' ) ) );

		$this->assertCount( 1, $this->repository->due( '2026-08-19 10:00:00', 1 ) );
	}

	public function test_claim_marks_row_sending_and_increments_attempts(): void {
		$this->repository->insert( $this->row() );
		$id = $this->last_id();

		$this->assertTrue( $this->repository->claim( $id, '2026-08-19 10:05:00' ) );

		$row = $this->repository->find( $id );

		$this->assertNotNull( $row );
		$this->assertSame( MailQueueRepository::STATUS_SENDING, $row['status'] );
		$this->assertSame( '1', (string) $row['attempts'] );
		$this->assertSame( '2026-08-19 10:05:00', $row['scheduled_at'] );
	}

	public function test_second_claim_of_the_same_row_fails(): void {
		$this->repository->insert( $this->row() );
		$id = $this->last_id();

		$this->assertTrue( $this->repository->claim( $id, '2026-08-19 10:05:00' ) );
		$this->assertFalse( $this->repository->claim( $id, '2026-08-19 10:05:01' ) );
	}

	public function test_mark_sent_records_timestamp(): void {
		$this->repository->insert( $this->row() );
		$id = $this->last_id();
		$this->repository->claim( $id, '2026-08-19 10:05:00' );

		$this->repository->markSent( $id, '2026-08-19 10:05:02' );

		$row = $this->repository->find( $id );

		$this->assertNotNull( $row );
		$this->assertSame( MailQueueRepository::STATUS_SENT, $row['status'] );
		$this->assertSame( '2026-08-19 10:05:02', $row['sent_at'] );
	}

	public function test_reschedule_returns_row_to_queue_with_error(): void {
		$this->repository->insert( $this->row() );
		$id = $this->last_id();
		$this->repository->claim( $id, '2026-08-19 10:05:00' );

		$this->repository->reschedule( $id, '2026-08-19 10:06:00', 'SMTP timeout' );

		$row = $this->repository->find( $id );

		$this->assertNotNull( $row );
		$this->assertSame( MailQueueRepository::STATUS_QUEUED, $row['status'] );
		$this->assertSame( '2026-08-19 10:06:00', $row['scheduled_at'] );
		$this->assertSame( 'SMTP timeout', $row['last_error'] );
		$this->assertSame( '1', (string) $row['attempts'] );
	}

	public function test_mark_failed_keeps_error(): void {
		$this->repository->insert( $this->row() );
		$id = $this->last_id();

		$this->repository->markFailed( $id, 'Nadawca odrzucony' );

		$row = $this->repository->find( $id );

		$this->assertNotNull( $row );
		$this->assertSame( MailQueueRepository::STATUS_FAILED, $row['status'] );
		$this->assertSame( 'Nadawca odrzucony', $row['last_error'] );
	}

	public function test_recover_stale_returns_orphaned_sending_rows_to_queue(): void {
		$this->repository->insert( $this->row( array( 'template_key' => 'stale' ) ) );
		$stale = $this->last_id();
		$this->repository->claim( $stale, '2026-08-19 10:00:00' );

		$this->repository->insert( $this->row( array( 'template_key' => 'fresh' ) ) );
		$fresh = $this->last_id();
		$this->repository->claim( $fresh, '2026-08-19 10:09:00' );

		$recovered = $this->repository->recoverStale( '2026-08-19 10:05:00' );

		$this->assertSame( 1, $recovered );
		$this->assertSame( MailQueueRepository::STATUS_QUEUED, $this->repository->find( $stale )['status'] );
		$this->assertSame( MailQueueRepository::STATUS_SENDING, $this->repository->find( $fresh )['status'] );
	}

	public function test_purge_sent_deletes_only_old_sent_rows(): void {
		$this->repository->insert( $this->row( array( 'template_key' => 'old' ) ) );
		$old = $this->last_id();
		$this->repository->markSent( $old, '2026-06-01 10:00:00' );

		$this->repository->insert( $this->row( array( 'template_key' => 'recent' ) ) );
		$recent = $this->last_id();
		$this->repository->markSent( $recent, '2026-08-18 10:00:00' );

		$this->repository->insert( $this->row( array( 'template_key' => 'broken' ) ) );
		$broken = $this->last_id();
		$this->repository->markFailed( $broken, 'boom' );

		$deleted = $this->repository->purgeSent( '2026-07-20 00:00:00' );

		$this->assertSame( 1, $deleted );
		$this->assertNull( $this->repository->find( $old ) );
		$this->assertNotNull( $this->repository->find( $recent ) );
		$this->assertNotNull( $this->repository->find( $broken ) );
	}

	public function test_find_returns_null_for_unknown_id(): void {
		$this->assertNull( $this->repository->find( 987654 ) );
	}
}
```

- [ ] **Step 2: Uruchom testy i potwierdź RED**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter 'MailQueueMigrationTest|MailQueueRepositoryTest'
```
Expected: FAIL — `Class "EvReg\Persistence\MailQueueRepository" not found` oraz `Failed asserting that 2 is identical to 3` w `test_db_version_is_three`.

- [ ] **Step 3: Podnieś wersję schematu i dołóż kolumnę oraz indeks**

W `src/Persistence/Migrations.php` zmień stałą:

```php
	public const DB_VERSION = 3;
```

i podmień blok `CREATE TABLE {$mail_queue}` na:

```php
			"CREATE TABLE {$mail_queue} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				registration_id bigint(20) unsigned NULL,
				event_id bigint(20) unsigned NOT NULL,
				template_key varchar(64) NOT NULL,
				recipient varchar(191) NOT NULL,
				subject text NOT NULL,
				body longtext NOT NULL,
				headers text NULL,
				status varchar(20) NOT NULL,
				attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
				last_error text NULL,
				scheduled_at datetime NOT NULL,
				sent_at datetime NULL,
				PRIMARY KEY  (id),
				KEY idx_dispatch (status, scheduled_at),
				UNIQUE KEY uniq_registration_template (registration_id,template_key)
			) ENGINE=InnoDB {$charset};",
```

Uwaga na `dbDelta`: w definicji indeksu **nie ma spacji po przecinku** między kolumnami (`(registration_id,template_key)`). Przy spacji `dbDelta` porównuje definicję z tym, co zwraca MySQL, uznaje ją za inną i przy każdym uruchomieniu próbuje dodać indeks ponownie.

- [ ] **Step 4: Popraw asercję wersji w istniejącym teście**

W `tests/Integration/Persistence/LocksMigrationTest.php` zamień metodę `test_db_version_is_two` na:

```php
	public function test_db_version_is_at_least_two(): void {
		$this->assertGreaterThanOrEqual( 2, Migrations::DB_VERSION );
	}
```

Dokładną wartość pilnuje teraz `MailQueueMigrationTest::test_db_version_is_three` — test zamków nie ma powodu pękać przy każdej kolejnej migracji.

- [ ] **Step 5: Zaimplementuj MailQueueRepository**

`src/Persistence/MailQueueRepository.php`:

```php
<?php
/**
 * Odczyt i zapis kolejki mailowej.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Persistence;

defined( 'ABSPATH' ) || exit;

/**
 * Odczyt i zapis kolejki mailowej.
 *
 * Jedyny adapter dotykający $wpdb dla tabeli evreg_mail_queue. Tylko czyta/pisze —
 * nie decyduje, co i kiedy wysłać.
 */
final class MailQueueRepository {

	public const STATUS_QUEUED  = 'queued';
	public const STATUS_SENDING = 'sending';
	public const STATUS_SENT    = 'sent';
	public const STATUS_FAILED  = 'failed';

	/**
	 * Zwraca pełną nazwę tabeli kolejki.
	 */
	private function table(): string {
		return Migrations::table( 'mail_queue' );
	}

	/**
	 * Wstawia wiersz w stanie queued. Duplikat (registration_id, template_key) jest pomijany.
	 *
	 * @param array{registration_id: int|null, event_id: int, template_key: string, recipient: string, subject: string, body: string, headers: string, scheduled_at: string} $row Dane wiersza.
	 *
	 * @return bool True, gdy wiersz powstał; false przy duplikacie lub błędzie zapisu.
	 */
	public function insert( array $row ): bool {
		global $wpdb;

		$columns = '(registration_id, event_id, template_key, recipient, subject, body, headers, status, attempts, scheduled_at)';

		if ( null === $row['registration_id'] ) {
			$sql = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"INSERT IGNORE INTO {$this->table()} {$columns} VALUES (NULL, %d, %s, %s, %s, %s, %s, %s, 0, %s)",
				$row['event_id'],
				$row['template_key'],
				$row['recipient'],
				$row['subject'],
				$row['body'],
				$row['headers'],
				self::STATUS_QUEUED,
				$row['scheduled_at']
			);
		} else {
			$sql = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"INSERT IGNORE INTO {$this->table()} {$columns} VALUES (%d, %d, %s, %s, %s, %s, %s, %s, 0, %s)",
				$row['registration_id'],
				$row['event_id'],
				$row['template_key'],
				$row['recipient'],
				$row['subject'],
				$row['body'],
				$row['headers'],
				self::STATUS_QUEUED,
				$row['scheduled_at']
			);
		}

		return 1 === (int) $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Zwraca wiersz kolejki albo null.
	 *
	 * @param int $id ID wiersza.
	 *
	 * @return array<string,mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Zwraca wiersze gotowe do wysyłki, najstarszym terminem naprzód.
	 *
	 * @param string $now   Moment odniesienia (Y-m-d H:i:s, UTC).
	 * @param int    $limit Maksymalna liczba wierszy.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function due( string $now, int $limit ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$this->table()} WHERE status = %s AND scheduled_at <= %s ORDER BY scheduled_at ASC, id ASC LIMIT %d",
				self::STATUS_QUEUED,
				$now,
				$limit
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Przejmuje wiersz do wysyłki. Powodzenie oznacza wyłączność na ten wiersz.
	 *
	 * @param int    $id  ID wiersza.
	 * @param string $now Moment przejęcia (Y-m-d H:i:s, UTC).
	 */
	public function claim( int $id, string $now ): bool {
		global $wpdb;

		$result = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$this->table()} SET status = %s, attempts = attempts + 1, scheduled_at = %s WHERE id = %d AND status = %s",
				self::STATUS_SENDING,
				$now,
				$id,
				self::STATUS_QUEUED
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

		return 1 === (int) $result;
	}

	/**
	 * Oznacza wiersz jako wysłany.
	 *
	 * @param int    $id  ID wiersza.
	 * @param string $now Moment wysyłki (Y-m-d H:i:s, UTC).
	 */
	public function markSent( int $id, string $now ): void {
		global $wpdb;

		$wpdb->update(
			$this->table(),
			array(
				'status'  => self::STATUS_SENT,
				'sent_at' => $now,
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Zwraca wiersz do kolejki z nowym terminem i zapisanym błędem.
	 *
	 * @param int    $id           ID wiersza.
	 * @param string $scheduled_at Termin następnej próby (Y-m-d H:i:s, UTC).
	 * @param string $error        Komunikat błędu.
	 */
	public function reschedule( int $id, string $scheduled_at, string $error ): void {
		global $wpdb;

		$wpdb->update(
			$this->table(),
			array(
				'status'       => self::STATUS_QUEUED,
				'scheduled_at' => $scheduled_at,
				'last_error'   => $error,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Oznacza wiersz jako nieudany na dobre.
	 *
	 * @param int    $id    ID wiersza.
	 * @param string $error Komunikat błędu.
	 */
	public function markFailed( int $id, string $error ): void {
		global $wpdb;

		$wpdb->update(
			$this->table(),
			array(
				'status'     => self::STATUS_FAILED,
				'last_error' => $error,
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Zwraca do kolejki wiersze porzucone w stanie sending.
	 *
	 * @param string $threshold Wiersze przejęte wcześniej niż ten moment (Y-m-d H:i:s, UTC).
	 *
	 * @return int Liczba odzyskanych wierszy.
	 */
	public function recoverStale( string $threshold ): int {
		global $wpdb;

		$result = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$this->table()} SET status = %s WHERE status = %s AND scheduled_at < %s",
				self::STATUS_QUEUED,
				self::STATUS_SENDING,
				$threshold
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Kasuje wysłane wiersze starsze niż podany moment.
	 *
	 * @param string $before Granica retencji (Y-m-d H:i:s, UTC).
	 *
	 * @return int Liczba skasowanych wierszy.
	 */
	public function purgeSent( string $before ): int {
		global $wpdb;

		$result = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"DELETE FROM {$this->table()} WHERE status = %s AND sent_at IS NOT NULL AND sent_at < %s",
				self::STATUS_SENT,
				$before
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

		return false === $result ? 0 : (int) $result;
	}
}
```

- [ ] **Step 6: Uruchom testy i potwierdź GREEN**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter 'MailQueueMigrationTest|MailQueueRepositoryTest|LocksMigrationTest|MigrationsTest'
```
Expected: PASS. Gdyby `test_mail_queue_has_unique_registration_template_index` padł mimo poprawnej definicji, sprawdź, czy tabela nie została utworzona przed zmianą — `Migrations::install()` w `setUp` woła `dbDelta`, który dokłada indeks do istniejącej tabeli.

- [ ] **Step 7: Styl i statyka**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs src/Persistence tests/Integration/Persistence
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=512M
```
Expected: zero błędów.

- [ ] **Step 8: Commit**

```bash
git add src/Persistence/Migrations.php src/Persistence/MailQueueRepository.php tests/Integration/Persistence
git commit -m "feat: add mail queue repository and db version 3 migration"
```

---

## Task 4: DefaultTemplates, MailTemplateRepository i TemplateResolver

**Files:**
- Create: `src/Mail/DefaultTemplates.php`
- Create: `src/Persistence/MailTemplateRepository.php`
- Create: `src/Mail/TemplateResolver.php`
- Test: `tests/Integration/Persistence/MailTemplateRepositoryTest.php`
- Test: `tests/Integration/Mail/TemplateResolverTest.php`

**Interfaces:**
- Consumes: `get_post_meta()`, `__()`
- Produces:
  - `EvReg\Mail\DefaultTemplates` — consts `KEY_OPTIN = 'optin'`, `KEY_CONFIRMED = 'confirmed'`, `KEY_WAITLIST = 'waitlist'`, `KEY_EXPIRED = 'expired'`, `KEY_ADMIN_NEW = 'admin_new'`; `static get( string $key ): array{subject: string, body: string}` (puste stringi dla nieznanego klucza); `static keys(): array<int,string>`
  - `EvReg\Persistence\MailTemplateRepository::META_KEY = '_evreg_mail_templates'`, `get( int $event_id ): array<string,array<string,string>>`
  - `EvReg\Mail\TemplateResolver::__construct( MailTemplateRepository $templates )`, `resolve( int $event_id, string $template_key ): array{subject: string, body: string}`, `static baseKey( string $template_key ): string`

Rozstrzyganie jest per pole: event może nadpisać sam temat i zostać przy domyślnej treści. Klucz warianty (`admin_new:<hash>`) sprowadzany do bazowego przez `baseKey()`.

- [ ] **Step 1: Napisz testy (RED)**

`tests/Integration/Persistence/MailTemplateRepositoryTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Persistence;

use EvReg\Persistence\MailTemplateRepository;
use WP_UnitTestCase;

final class MailTemplateRepositoryTest extends WP_UnitTestCase {

	private MailTemplateRepository $repository;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		$this->repository = new MailTemplateRepository();
		$this->event_id   = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
	}

	public function test_returns_empty_array_when_meta_missing(): void {
		$this->assertSame( array(), $this->repository->get( $this->event_id ) );
	}

	public function test_reads_templates_stored_as_json_string(): void {
		update_post_meta(
			$this->event_id,
			MailTemplateRepository::META_KEY,
			(string) wp_json_encode( array( 'optin' => array( 'subject' => 'Temat', 'body' => "Linia 1\nLinia 2" ) ) )
		);

		$templates = $this->repository->get( $this->event_id );

		$this->assertSame( 'Temat', $templates['optin']['subject'] );
		$this->assertSame( "Linia 1\nLinia 2", $templates['optin']['body'] );
	}

	public function test_returns_empty_array_for_broken_json(): void {
		update_post_meta( $this->event_id, MailTemplateRepository::META_KEY, '{nie-json' );

		$this->assertSame( array(), $this->repository->get( $this->event_id ) );
	}

	public function test_reads_templates_stored_as_array(): void {
		update_post_meta( $this->event_id, MailTemplateRepository::META_KEY, array( 'optin' => array( 'subject' => 'Z tablicy' ) ) );

		$this->assertSame( 'Z tablicy', $this->repository->get( $this->event_id )['optin']['subject'] );
	}
}
```

`tests/Integration/Mail/TemplateResolverTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Mail;

use EvReg\Mail\DefaultTemplates;
use EvReg\Mail\TemplateResolver;
use EvReg\Persistence\MailTemplateRepository;
use WP_UnitTestCase;

final class TemplateResolverTest extends WP_UnitTestCase {

	private TemplateResolver $resolver;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		$this->resolver = new TemplateResolver( new MailTemplateRepository() );
		$this->event_id = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
	}

	public function test_falls_back_to_default_template(): void {
		$template = $this->resolver->resolve( $this->event_id, DefaultTemplates::KEY_OPTIN );

		$this->assertSame( DefaultTemplates::get( DefaultTemplates::KEY_OPTIN ), $template );
		$this->assertStringContainsString( '{link_potwierdzenia}', $template['body'] );
	}

	public function test_event_override_wins_per_field(): void {
		update_post_meta(
			$this->event_id,
			MailTemplateRepository::META_KEY,
			(string) wp_json_encode( array( 'optin' => array( 'subject' => 'Własny temat', 'body' => '' ) ) )
		);

		$template = $this->resolver->resolve( $this->event_id, DefaultTemplates::KEY_OPTIN );

		$this->assertSame( 'Własny temat', $template['subject'] );
		$this->assertSame( DefaultTemplates::get( DefaultTemplates::KEY_OPTIN )['body'], $template['body'] );
	}

	public function test_variant_key_resolves_to_base_template(): void {
		update_post_meta(
			$this->event_id,
			MailTemplateRepository::META_KEY,
			(string) wp_json_encode( array( 'admin_new' => array( 'subject' => 'Nowe zgłoszenie' ) ) )
		);

		$template = $this->resolver->resolve( $this->event_id, 'admin_new:9e107d9d372bb6826bd81d3542a419d6' );

		$this->assertSame( 'Nowe zgłoszenie', $template['subject'] );
	}

	public function test_unknown_key_resolves_to_empty_template(): void {
		$this->assertSame( array( 'subject' => '', 'body' => '' ), $this->resolver->resolve( $this->event_id, 'nie_ma_takiego' ) );
	}

	public function test_base_key_strips_variant_suffix(): void {
		$this->assertSame( 'admin_new', TemplateResolver::baseKey( 'admin_new:abc' ) );
		$this->assertSame( 'optin', TemplateResolver::baseKey( 'optin' ) );
	}

	public function test_every_default_template_has_subject_and_body(): void {
		foreach ( DefaultTemplates::keys() as $key ) {
			$template = DefaultTemplates::get( $key );

			$this->assertNotSame( '', $template['subject'], $key );
			$this->assertNotSame( '', $template['body'], $key );
		}
	}
}
```

- [ ] **Step 2: Uruchom testy i potwierdź RED**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter 'MailTemplateRepositoryTest|TemplateResolverTest'
```
Expected: FAIL — `Class "EvReg\Persistence\MailTemplateRepository" not found`.

- [ ] **Step 3: Zaimplementuj MailTemplateRepository**

`src/Persistence/MailTemplateRepository.php`:

```php
<?php
/**
 * Odczyt szablonów maili zapisanych przy evencie.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Persistence;

defined( 'ABSPATH' ) || exit;

/**
 * Odczyt szablonów maili zapisanych przy evencie.
 *
 * Plan 4A tylko czyta. Zapis (edytor w adminie) dochodzi w Planie 4B.
 */
final class MailTemplateRepository {

	public const META_KEY = '_evreg_mail_templates';

	/**
	 * Zwraca szablony eventu: klucz szablonu => tablica z polami subject/body.
	 *
	 * @param int $event_id ID posta eventu.
	 *
	 * @return array<string,array<string,string>>
	 */
	public function get( int $event_id ): array {
		$raw = get_post_meta( $event_id, self::META_KEY, true );

		if ( is_array( $raw ) ) {
			return $this->normalize( $raw );
		}

		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $this->normalize( $decoded ) : array();
	}

	/**
	 * Sprowadza surowe meta do mapy string => array<string,string>.
	 *
	 * @param array<mixed> $raw Surowa zawartość meta.
	 *
	 * @return array<string,array<string,string>>
	 */
	private function normalize( array $raw ): array {
		$templates = array();

		foreach ( $raw as $key => $template ) {
			if ( ! is_string( $key ) || ! is_array( $template ) ) {
				continue;
			}

			$fields = array();

			foreach ( array( 'subject', 'body' ) as $field ) {
				if ( isset( $template[ $field ] ) && is_string( $template[ $field ] ) ) {
					$fields[ $field ] = $template[ $field ];
				}
			}

			$templates[ $key ] = $fields;
		}

		return $templates;
	}
}
```

- [ ] **Step 4: Zaimplementuj DefaultTemplates**

`src/Mail/DefaultTemplates.php`:

```php
<?php
/**
 * Domyślne treści maili transakcyjnych.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Domyślne treści maili transakcyjnych.
 *
 * Używane, gdy event nie ma własnego szablonu danego typu. Plain text —
 * link potwierdzenia stoi w osobnej linii jako goły URL.
 */
final class DefaultTemplates {

	public const KEY_OPTIN     = 'optin';
	public const KEY_CONFIRMED = 'confirmed';
	public const KEY_WAITLIST  = 'waitlist';
	public const KEY_EXPIRED   = 'expired';
	public const KEY_ADMIN_NEW = 'admin_new';

	/**
	 * Zwraca listę obsługiwanych kluczy szablonów.
	 *
	 * @return array<int,string>
	 */
	public static function keys(): array {
		return array(
			self::KEY_OPTIN,
			self::KEY_CONFIRMED,
			self::KEY_WAITLIST,
			self::KEY_EXPIRED,
			self::KEY_ADMIN_NEW,
		);
	}

	/**
	 * Zwraca domyślny szablon o podanym kluczu. Nieznany klucz daje puste pola.
	 *
	 * @param string $key Klucz szablonu.
	 *
	 * @return array{subject: string, body: string}
	 */
	public static function get( string $key ): array {
		$templates = array(
			self::KEY_OPTIN     => array(
				'subject' => __( 'Potwierdź zgłoszenie na {event}', 'event-registration' ),
				'body'    => __(
					"Cześć {imie},

dziękujemy za zgłoszenie na {event} ({typ}).

Potwierdź je, otwierając ten link:
{link_potwierdzenia}

Bez potwierdzenia rezerwacja wygaśnie po 48 godzinach, a miejsce wróci do puli.

Twoje zgłoszenie:
{podsumowanie}",
					'event-registration'
				),
			),
			self::KEY_CONFIRMED => array(
				'subject' => __( 'Zgłoszenie na {event} potwierdzone', 'event-registration' ),
				'body'    => __(
					"Cześć {imie},

Twoje zgłoszenie na {event} ({typ}) jest potwierdzone. Do zobaczenia.

Twoje zgłoszenie:
{podsumowanie}",
					'event-registration'
				),
			),
			self::KEY_WAITLIST  => array(
				'subject' => __( 'Lista rezerwowa — {event}', 'event-registration' ),
				'body'    => __(
					"Cześć {imie},

komplet miejsc na {event} ({typ}) został wyczerpany, więc Twoje zgłoszenie trafiło na listę rezerwową.

Odezwiemy się, gdy zwolni się miejsce.

Twoje zgłoszenie:
{podsumowanie}",
					'event-registration'
				),
			),
			self::KEY_EXPIRED   => array(
				'subject' => __( 'Rezerwacja na {event} wygasła', 'event-registration' ),
				'body'    => __(
					"Cześć {imie},

Twoja rezerwacja na {event} wygasła, bo zgłoszenie nie zostało potwierdzone w ciągu 48 godzin. Miejsce wróciło do puli.

Jeśli nadal chcesz wziąć udział, wypełnij formularz ponownie.",
					'event-registration'
				),
			),
			self::KEY_ADMIN_NEW => array(
				'subject' => __( 'Nowe zgłoszenie: {event}', 'event-registration' ),
				'body'    => __(
					"Nowe zgłoszenie na {event}.

Osoba: {imie} ({email})
Typ: {typ}
Nocleg: {nocleg}

Odpowiedzi:
{podsumowanie}",
					'event-registration'
				),
			),
		);

		return $templates[ $key ] ?? array(
			'subject' => '',
			'body'    => '',
		);
	}
}
```

- [ ] **Step 5: Zaimplementuj TemplateResolver**

`src/Mail/TemplateResolver.php`:

```php
<?php
/**
 * Rozstrzyganie szablonu maila: własny szablon eventu z fallbackiem na domyślny.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Mail;

use EvReg\Persistence\MailTemplateRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Rozstrzyganie szablonu maila: własny szablon eventu z fallbackiem na domyślny.
 *
 * Fallback działa per pole — event może nadpisać sam temat i zostać przy
 * domyślnej treści.
 */
final class TemplateResolver {

	/**
	 * Tworzy resolver.
	 *
	 * @param MailTemplateRepository $templates Repozytorium szablonów eventu.
	 */
	public function __construct( private readonly MailTemplateRepository $templates ) {
	}

	/**
	 * Sprowadza klucz wariantu (np. admin_new:<hash>) do klucza bazowego.
	 *
	 * @param string $template_key Klucz szablonu, ewentualnie z wariantem po dwukropku.
	 */
	public static function baseKey( string $template_key ): string {
		$position = strpos( $template_key, ':' );

		return false === $position ? $template_key : substr( $template_key, 0, $position );
	}

	/**
	 * Zwraca temat i treść szablonu dla eventu.
	 *
	 * @param int    $event_id     ID posta eventu.
	 * @param string $template_key Klucz szablonu (może zawierać wariant po dwukropku).
	 *
	 * @return array{subject: string, body: string}
	 */
	public function resolve( int $event_id, string $template_key ): array {
		$key      = self::baseKey( $template_key );
		$default  = DefaultTemplates::get( $key );
		$override = $this->templates->get( $event_id )[ $key ] ?? array();

		$subject = isset( $override['subject'] ) && '' !== $override['subject'] ? $override['subject'] : $default['subject'];
		$body    = isset( $override['body'] ) && '' !== $override['body'] ? $override['body'] : $default['body'];

		return array(
			'subject' => $subject,
			'body'    => $body,
		);
	}
}
```

- [ ] **Step 6: Uruchom testy i potwierdź GREEN**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter 'MailTemplateRepositoryTest|TemplateResolverTest'
```
Expected: PASS, 10 testów.

- [ ] **Step 7: Styl i statyka**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs src/Mail src/Persistence tests/Integration/Mail tests/Integration/Persistence
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=512M
```
Expected: zero błędów.

- [ ] **Step 8: Commit**

```bash
git add src/Mail src/Persistence/MailTemplateRepository.php tests/Integration/Mail tests/Integration/Persistence/MailTemplateRepositoryTest.php
git commit -m "feat: add default mail templates and per-event template resolution"
```

---
## Task 5: PlaceholderFactory i odczyty zgłoszenia

**Files:**
- Modify: `src/Persistence/RegistrationRepository.php` (dwie nowe metody odczytu)
- Create: `src/Mail/PlaceholderFactory.php`
- Test: `tests/Integration/Mail/PlaceholderFactoryTest.php`

**Interfaces:**
- Consumes: `EventConfigRepository::get()`, `EventFormLoader::load()`, `SummaryBuilder`, `Placeholders`, `RegistrationTypeCollection`, `AccommodationConfig`, `get_the_title()`, `get_permalink()`, `add_query_arg()`
- Produces:
  - `EvReg\Persistence\RegistrationRepository::findById( int $id ): ?array<string,mixed>`
  - `EvReg\Persistence\RegistrationRepository::findAccommodationBooking( int $registration_id ): ?array<string,mixed>`
  - `EvReg\Mail\PlaceholderFactory::__construct( EventConfigRepository $config, RegistrationRepository $registrations, EventFormLoader $loader )`
  - `EvReg\Mail\PlaceholderFactory::build( array<string,mixed> $registration ): Placeholders` — klucze `imie`, `email`, `event`, `typ`, `nocleg`, `link_potwierdzenia`, `podsumowanie`

Uwaga: `{nocleg}` jako osobny placeholder zostaje do dyspozycji organizatora, ale linia noclegu dopisywana jest też na końcu `{podsumowanie}` — dzięki temu domyślne szablony uczestnika nie muszą zostawiać pustej linii „Nocleg:" u osób bez rezerwacji noclegowej.

- [ ] **Step 1: Napisz test (RED)**

`tests/Integration/Mail/PlaceholderFactoryTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Mail;

use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Frontend\EventFormLoader;
use EvReg\Mail\PlaceholderFactory;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use WP_UnitTestCase;

final class PlaceholderFactoryTest extends WP_UnitTestCase {

	private PlaceholderFactory $factory;

	private RegistrationRepository $registrations;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings' ) as $table ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$config              = new EventConfigRepository();
		$this->registrations = new RegistrationRepository();
		$this->factory       = new PlaceholderFactory( $config, $this->registrations, new EventFormLoader( $config ) );
		$this->event_id      = self::factory()->post->create(
			array(
				'post_type'   => 'evreg_event',
				'post_title'  => 'Zjazd 2026',
				'post_status' => 'publish',
			)
		);

		$config->save(
			$this->event_id,
			array(
				'schema'        => array(
					'version'  => 1,
					'sections' => array(
						array(
							'key'    => 'dane',
							'title'  => 'Dane',
							'fields' => array(
								array( 'key' => '__type', 'type' => 'radio', 'label' => 'Typ zgłoszenia' ),
								array( 'key' => 'imie', 'type' => 'text', 'label' => 'Imię i nazwisko' ),
								array( 'key' => 'email', 'type' => 'email', 'label' => 'E-mail' ),
							),
						),
					),
				),
				'types'         => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 450.0 ) ),
				'accommodation' => array(
					'packages'  => array( array( 'key' => 'n12', 'label' => 'Noc 1–2' ) ),
					'rooms'     => array( array( 'key' => 'double', 'label' => 'Pokój 2-osobowy', 'roommate_field' => true ) ),
					'inventory' => array( array( 'package' => 'n12', 'room' => 'double', 'capacity' => 5, 'price' => 180.0 ) ),
				),
				'settings'      => array( 'global_cap' => 100 ),
			)
		);
	}

	/**
	 * Wstawia zgłoszenie i zwraca jego wiersz.
	 *
	 * @param array<string,mixed> $overrides Nadpisania kolumn.
	 * @return array<string,mixed>
	 */
	private function registration( array $overrides = array() ): array {
		$id = $this->registrations->insertRegistration(
			array_merge(
				array(
					'event_id'    => $this->event_id,
					'type_key'    => 'uczestnik',
					'status'      => 'pending',
					'email'       => 'jan@example.com',
					'name'        => 'Jan Kowalski',
					'token'       => 'aabbccddeeff00112233445566778899',
					'data'        => (string) wp_json_encode(
						array(
							'__type' => 'uczestnik',
							'imie'   => 'Jan Kowalski',
							'email'  => 'jan@example.com',
						)
					),
					'price_total' => 450.0,
					'expires_at'  => '2099-01-01 00:00:00',
				),
				$overrides
			)
		);

		$row = $this->registrations->findById( $id );

		$this->assertNotNull( $row );

		return $row;
	}

	public function test_builds_basic_placeholders(): void {
		$values = $this->factory->build( $this->registration() );

		$this->assertSame( 'Jan Kowalski', $values->get( 'imie' ) );
		$this->assertSame( 'jan@example.com', $values->get( 'email' ) );
		$this->assertSame( 'Zjazd 2026', $values->get( 'event' ) );
		$this->assertSame( 'Uczestnik', $values->get( 'typ' ) );
	}

	public function test_type_falls_back_to_key_when_unknown(): void {
		$values = $this->factory->build( $this->registration( array( 'type_key' => 'wykladowca' ) ) );

		$this->assertSame( 'wykladowca', $values->get( 'typ' ) );
	}

	public function test_confirmation_link_carries_token(): void {
		$link = $this->factory->build( $this->registration() )->get( 'link_potwierdzenia' );

		$this->assertStringContainsString( 'evreg_confirm=aabbccddeeff00112233445566778899', $link );
		$this->assertStringStartsWith( 'http', $link );
	}

	public function test_confirmation_link_uses_form_page_when_configured(): void {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		( new EventConfigRepository() )->save( $this->event_id, array( 'settings' => array( 'form_page_id' => $page_id ) ) );

		$link = $this->factory->build( $this->registration() )->get( 'link_potwierdzenia' );

		$this->assertStringContainsString( (string) get_permalink( $page_id ), $link );
	}

	public function test_summary_lists_answers(): void {
		$summary = $this->factory->build( $this->registration() )->get( 'podsumowanie' );

		$this->assertStringContainsString( 'Imię i nazwisko: Jan Kowalski', $summary );
		$this->assertStringContainsString( 'E-mail: jan@example.com', $summary );
		$this->assertStringNotContainsString( 'Typ zgłoszenia', $summary );
	}

	public function test_accommodation_is_empty_without_booking(): void {
		$this->assertSame( '', $this->factory->build( $this->registration() )->get( 'nocleg' ) );
	}

	public function test_accommodation_uses_labels_and_roommate(): void {
		$row = $this->registration();
		$this->registrations->insertAccommodationBooking(
			(int) $row['id'],
			new AccommodationSelection( 'n12', 'double', 'Piotr N.' ),
			180.0
		);

		$values = $this->factory->build( $row );

		$this->assertStringContainsString( 'Noc 1–2', $values->get( 'nocleg' ) );
		$this->assertStringContainsString( 'Pokój 2-osobowy', $values->get( 'nocleg' ) );
		$this->assertStringContainsString( 'Piotr N.', $values->get( 'nocleg' ) );
		$this->assertStringContainsString( 'Noc 1–2', $values->get( 'podsumowanie' ) );
	}

	public function test_summary_is_empty_when_schema_missing(): void {
		$other  = self::factory()->post->create( array( 'post_type' => 'evreg_event', 'post_title' => 'Bez schemy' ) );
		$values = $this->factory->build( $this->registration( array( 'event_id' => $other, 'email' => 'inny@example.com' ) ) );

		$this->assertSame( '', $values->get( 'podsumowanie' ) );
		$this->assertSame( 'Bez schemy', $values->get( 'event' ) );
	}
}
```

- [ ] **Step 2: Uruchom test i potwierdź RED**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter PlaceholderFactoryTest
```
Expected: FAIL — `Call to undefined method EvReg\Persistence\RegistrationRepository::findById()`.

- [ ] **Step 3: Dołóż odczyty do RegistrationRepository**

W `src/Persistence/RegistrationRepository.php`, tuż po `findByToken()`:

```php
	/**
	 * Zwraca zgłoszenie po ID.
	 *
	 * @param int $id ID zgłoszenia.
	 *
	 * @return array<string,mixed>|null
	 */
	public function findById( int $id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->registrations()} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Zwraca rezerwację noclegową zgłoszenia albo null.
	 *
	 * @param int $registration_id ID zgłoszenia.
	 *
	 * @return array<string,mixed>|null
	 */
	public function findAccommodationBooking( int $registration_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$this->bookings()} WHERE registration_id = %d ORDER BY id ASC LIMIT 1",
				$registration_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}
```

- [ ] **Step 4: Zaimplementuj PlaceholderFactory**

`src/Mail/PlaceholderFactory.php`:

```php
<?php
/**
 * Budowa wartości placeholderów dla maila o zgłoszeniu.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Mail;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Conditions\ConditionEngine;
use EvReg\Domain\Mail\Placeholders;
use EvReg\Domain\Mail\SummaryBuilder;
use EvReg\Domain\Registration\RegistrationTypeCollection;
use EvReg\Domain\Schema\VisibilityResolver;
use EvReg\Frontend\EventFormLoader;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\RegistrationRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Budowa wartości placeholderów dla maila o zgłoszeniu.
 */
final class PlaceholderFactory {

	/**
	 * Tworzy fabrykę.
	 *
	 * @param EventConfigRepository  $config        Repozytorium konfiguracji eventu.
	 * @param RegistrationRepository $registrations Repozytorium zgłoszeń.
	 * @param EventFormLoader        $loader        Loader złożonej schemy.
	 */
	public function __construct(
		private readonly EventConfigRepository $config,
		private readonly RegistrationRepository $registrations,
		private readonly EventFormLoader $loader
	) {
	}

	/**
	 * Buduje zbiór placeholderów dla wiersza zgłoszenia.
	 *
	 * @param array<string,mixed> $registration Wiersz tabeli zgłoszeń.
	 */
	public function build( array $registration ): Placeholders {
		$event_id      = (int) ( $registration['event_id'] ?? 0 );
		$config        = $this->config->get( $event_id );
		$accommodation = $this->accommodationLabel( $config, (int) ( $registration['id'] ?? 0 ) );

		return new Placeholders(
			array(
				'imie'               => (string) ( $registration['name'] ?? '' ),
				'email'              => (string) ( $registration['email'] ?? '' ),
				'event'              => (string) get_the_title( $event_id ),
				'typ'                => $this->typeLabel( $config, (string) ( $registration['type_key'] ?? '' ) ),
				'nocleg'             => $accommodation,
				'link_potwierdzenia' => $this->confirmationUrl( $config, $event_id, (string) ( $registration['token'] ?? '' ) ),
				'podsumowanie'       => $this->summary( $event_id, $registration, $accommodation ),
			)
		);
	}

	/**
	 * Zwraca etykietę typu zgłoszenia, a przy nieznanym typie sam klucz.
	 *
	 * @param array<string,mixed> $config   Konfiguracja eventu.
	 * @param string              $type_key Klucz typu zgłoszenia.
	 */
	private function typeLabel( array $config, string $type_key ): string {
		$types = RegistrationTypeCollection::fromArray( is_array( $config['types'] ) ? $config['types'] : array() );
		$type  = $types->get( $type_key );

		return null === $type ? $type_key : $type->label;
	}

	/**
	 * Zwraca opis rezerwacji noclegowej albo pusty łańcuch.
	 *
	 * @param array<string,mixed> $config          Konfiguracja eventu.
	 * @param int                 $registration_id ID zgłoszenia.
	 */
	private function accommodationLabel( array $config, int $registration_id ): string {
		$booking = $this->registrations->findAccommodationBooking( $registration_id );

		if ( null === $booking ) {
			return '';
		}

		$accommodation = AccommodationConfig::fromArray( is_array( $config['accommodation'] ) ? $config['accommodation'] : array() );
		$package_key   = (string) ( $booking['package_key'] ?? '' );
		$room_key      = (string) ( $booking['room_type_key'] ?? '' );
		$package_label = $package_key;

		foreach ( $accommodation->packages() as $package ) {
			if ( $package->key === $package_key ) {
				$package_label = $package->label;
				break;
			}
		}

		$room       = $accommodation->room( $room_key );
		$room_label = null === $room ? $room_key : $room->label;
		$label      = trim( $package_label . ' / ' . $room_label, ' /' );
		$roommate   = trim( (string) ( $booking['roommate_pref'] ?? '' ) );

		if ( '' === $roommate ) {
			return $label;
		}

		/* translators: 1: opis noclegu, 2: preferowany współlokator. */
		return sprintf( __( '%1$s (współlokator: %2$s)', 'event-registration' ), $label, $roommate );
	}

	/**
	 * Buduje adres potwierdzenia zgłoszenia.
	 *
	 * @param array<string,mixed> $config   Konfiguracja eventu.
	 * @param int                 $event_id ID eventu.
	 * @param string              $token    Token zgłoszenia.
	 */
	private function confirmationUrl( array $config, int $event_id, string $token ): string {
		if ( '' === $token ) {
			return '';
		}

		$settings = is_array( $config['settings'] ) ? $config['settings'] : array();
		$page_id  = isset( $settings['form_page_id'] ) ? (int) $settings['form_page_id'] : 0;
		$base     = $page_id > 0 ? get_permalink( $page_id ) : get_permalink( $event_id );

		if ( ! is_string( $base ) || '' === $base ) {
			$base = home_url( '/' );
		}

		return add_query_arg( 'evreg_confirm', $token, $base );
	}

	/**
	 * Buduje podsumowanie odpowiedzi, dopisując linię noclegu.
	 *
	 * @param int                 $event_id      ID eventu.
	 * @param array<string,mixed> $registration  Wiersz zgłoszenia.
	 * @param string              $accommodation Opis noclegu albo pusty łańcuch.
	 */
	private function summary( int $event_id, array $registration, string $accommodation ): string {
		$schema = $this->loader->load( $event_id );

		if ( null === $schema ) {
			return '';
		}

		$decoded = json_decode( (string) ( $registration['data'] ?? '' ), true );
		$answers = is_array( $decoded ) ? $decoded : array();
		$builder = new SummaryBuilder(
			new VisibilityResolver( new ConditionEngine() ),
			__( 'Tak', 'event-registration' )
		);

		$summary = $builder->build( $schema, $answers );

		if ( '' === $accommodation ) {
			return $summary;
		}

		/* translators: %s: opis rezerwacji noclegowej. */
		$line = sprintf( __( 'Nocleg: %s', 'event-registration' ), $accommodation );

		return '' === $summary ? $line : $summary . "\n" . $line;
	}
}
```

- [ ] **Step 5: Uruchom test i potwierdź GREEN**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter PlaceholderFactoryTest
```
Expected: PASS, 8 testów.

- [ ] **Step 6: Regresja repozytorium zgłoszeń, styl, statyka**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RegistrationRepositoryTest
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs src/Mail src/Persistence tests/Integration/Mail
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=512M
```
Expected: PASS, zero błędów.

- [ ] **Step 7: Commit**

```bash
git add src/Mail/PlaceholderFactory.php src/Persistence/RegistrationRepository.php tests/Integration/Mail/PlaceholderFactoryTest.php
git commit -m "feat: build mail placeholders from registration and event config"
```

---

## Task 6: MailQueue — kolejkowanie ze snapshotem treści

**Files:**
- Create: `src/Mail/MailQueue.php`
- Test: `tests/Integration/Mail/MailQueueTest.php`

**Interfaces:**
- Consumes: `MailQueueRepository::insert()`, `TemplateResolver::resolve()`, `TemplateRenderer::render()`, `Placeholders`, `sanitize_email()`, `wp_schedule_single_event()`
- Produces:
  - `EvReg\Mail\MailQueue::DISPATCH_HOOK = 'evreg_dispatch_mail_now'` — zdarzenie jednorazowe, osobne od cyklicznego `evreg_dispatch_mail` z Taska 10. Osobna nazwa jest konieczna: `wp_next_scheduled()` nie odróżnia zdarzenia jednorazowego od cyklicznego, więc wspólny hook sprawiłby, że planowanie crona co minutę widziałoby przypadkowy strzał jednorazowy i uznało zadanie cykliczne za już zaplanowane
  - `EvReg\Mail\MailQueue::__construct( MailQueueRepository $queue, TemplateResolver $templates, TemplateRenderer $renderer )`
  - `EvReg\Mail\MailQueue::enqueue( string $template_key, int $event_id, ?int $registration_id, string $recipient, Placeholders $values, array<int,string> $headers = array(), bool $immediate = false ): bool`

Zasady: pusty albo niepoprawny adres → `false` bez wiersza. Nieznany szablon (pusty temat i treść) → `false` bez wiersza. Duplikat `(registration_id, template_key)` → `false`, wiersz zostaje ten, który był. `true` + `$immediate` → `wp_schedule_single_event( time(), self::DISPATCH_HOOK )`.

- [ ] **Step 1: Napisz test (RED)**

`tests/Integration/Mail/MailQueueTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Mail;

use EvReg\Domain\Mail\Placeholders;
use EvReg\Domain\Mail\TemplateRenderer;
use EvReg\Mail\DefaultTemplates;
use EvReg\Mail\MailQueue;
use EvReg\Mail\TemplateResolver;
use EvReg\Persistence\MailQueueRepository;
use EvReg\Persistence\MailTemplateRepository;
use EvReg\Persistence\Migrations;
use WP_UnitTestCase;

final class MailQueueTest extends WP_UnitTestCase {

	private MailQueue $mail_queue;

	private MailQueueRepository $repository;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( 'mail_queue' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$this->repository = new MailQueueRepository();
		$this->mail_queue = new MailQueue(
			$this->repository,
			new TemplateResolver( new MailTemplateRepository() ),
			new TemplateRenderer()
		);
		$this->event_id   = self::factory()->post->create( array( 'post_type' => 'evreg_event', 'post_title' => 'Zjazd 2026' ) );

		wp_clear_scheduled_hook( MailQueue::DISPATCH_HOOK );
	}

	private function values(): Placeholders {
		return new Placeholders(
			array(
				'imie'               => 'Jan',
				'email'              => 'jan@example.com',
				'event'              => 'Zjazd 2026',
				'typ'                => 'Uczestnik',
				'nocleg'             => '',
				'link_potwierdzenia' => 'https://example.org/?evreg_confirm=abc',
				'podsumowanie'       => 'Imię i nazwisko: Jan',
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function only_row(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( 'SELECT * FROM ' . Migrations::table( 'mail_queue' ), ARRAY_A );

		$this->assertCount( 1, $rows );

		return $rows[0];
	}

	public function test_enqueue_stores_rendered_snapshot(): void {
		$this->assertTrue(
			$this->mail_queue->enqueue( DefaultTemplates::KEY_OPTIN, $this->event_id, 7, 'jan@example.com', $this->values() )
		);

		$row = $this->only_row();

		$this->assertStringContainsString( 'Zjazd 2026', $row['subject'] );
		$this->assertStringContainsString( 'https://example.org/?evreg_confirm=abc', $row['body'] );
		$this->assertStringContainsString( 'Imię i nazwisko: Jan', $row['body'] );
		$this->assertStringNotContainsString( '{imie}', $row['body'] );
		$this->assertSame( MailQueueRepository::STATUS_QUEUED, $row['status'] );
	}

	public function test_enqueue_is_idempotent_per_registration_and_template(): void {
		$this->assertTrue( $this->mail_queue->enqueue( DefaultTemplates::KEY_OPTIN, $this->event_id, 7, 'jan@example.com', $this->values() ) );
		$this->assertFalse( $this->mail_queue->enqueue( DefaultTemplates::KEY_OPTIN, $this->event_id, 7, 'jan@example.com', $this->values() ) );

		$this->only_row();
	}

	public function test_enqueue_stores_headers_as_json(): void {
		$this->mail_queue->enqueue(
			DefaultTemplates::KEY_ADMIN_NEW,
			$this->event_id,
			7,
			'organizator@example.com',
			$this->values(),
			array( 'Reply-To: jan@example.com' )
		);

		$this->assertSame( array( 'Reply-To: jan@example.com' ), json_decode( (string) $this->only_row()['headers'], true ) );
	}

	public function test_enqueue_rejects_invalid_recipient(): void {
		$this->assertFalse( $this->mail_queue->enqueue( DefaultTemplates::KEY_OPTIN, $this->event_id, 7, 'nie-adres', $this->values() ) );
		$this->assertSame( array(), $this->repository->due( '2099-01-01 00:00:00', 10 ) );
	}

	public function test_enqueue_rejects_unknown_template(): void {
		$this->assertFalse( $this->mail_queue->enqueue( 'nie_ma_takiego', $this->event_id, 7, 'jan@example.com', $this->values() ) );
		$this->assertSame( array(), $this->repository->due( '2099-01-01 00:00:00', 10 ) );
	}

	public function test_immediate_enqueue_schedules_single_dispatch(): void {
		$this->mail_queue->enqueue( DefaultTemplates::KEY_OPTIN, $this->event_id, 7, 'jan@example.com', $this->values(), array(), true );

		$this->assertNotFalse( wp_next_scheduled( MailQueue::DISPATCH_HOOK ) );
	}

	public function test_non_immediate_enqueue_does_not_schedule(): void {
		$this->mail_queue->enqueue( DefaultTemplates::KEY_ADMIN_NEW, $this->event_id, 7, 'organizator@example.com', $this->values() );

		$this->assertFalse( wp_next_scheduled( MailQueue::DISPATCH_HOOK ) );
	}
}
```

- [ ] **Step 2: Uruchom test i potwierdź RED**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter MailQueueTest
```
Expected: FAIL — `Class "EvReg\Mail\MailQueue" not found`.

- [ ] **Step 3: Zaimplementuj MailQueue**

`src/Mail/MailQueue.php`:

```php
<?php
/**
 * Kolejkowanie maili z renderowaniem treści w chwili zapisu.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Mail;

use EvReg\Domain\Mail\Placeholders;
use EvReg\Domain\Mail\TemplateRenderer;
use EvReg\Persistence\MailQueueRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Kolejkowanie maili z renderowaniem treści w chwili zapisu.
 *
 * Treść jest snapshotem: późniejsza edycja szablonu nie zmienia tego,
 * co już czeka w kolejce, a dispatcher nie potrzebuje eventu ani configu.
 */
final class MailQueue {

	/**
	 * Zdarzenie jednorazowe wymuszające natychmiastowy przebieg dispatchera.
	 *
	 * Osobne od cyklicznego evreg_dispatch_mail: wp_next_scheduled() nie odróżnia
	 * zdarzenia jednorazowego od cyklicznego, więc wspólna nazwa blokowałaby
	 * zaplanowanie zadania cyklicznego. Kolejka zna samą nazwę, nie klasę crona.
	 */
	public const DISPATCH_HOOK = 'evreg_dispatch_mail_now';

	/**
	 * Tworzy kolejkę.
	 *
	 * @param MailQueueRepository $queue     Repozytorium kolejki.
	 * @param TemplateResolver    $templates Rozstrzyganie szablonów.
	 * @param TemplateRenderer    $renderer  Podstawianie placeholderów.
	 */
	public function __construct(
		private readonly MailQueueRepository $queue,
		private readonly TemplateResolver $templates,
		private readonly TemplateRenderer $renderer
	) {
	}

	/**
	 * Wstawia mail do kolejki. Duplikat dla tego samego zgłoszenia i szablonu jest pomijany.
	 *
	 * @param string           $template_key    Klucz szablonu (może zawierać wariant po dwukropku).
	 * @param int              $event_id        ID eventu.
	 * @param int|null         $registration_id ID zgłoszenia albo null.
	 * @param string           $recipient       Adres odbiorcy.
	 * @param Placeholders     $values          Wartości placeholderów.
	 * @param array<int,string> $headers        Nagłówki maila (np. Reply-To).
	 * @param bool             $immediate       Czy zaplanować natychmiastowy przebieg dispatchera.
	 *
	 * @return bool True, gdy wiersz powstał.
	 */
	public function enqueue(
		string $template_key,
		int $event_id,
		?int $registration_id,
		string $recipient,
		Placeholders $values,
		array $headers = array(),
		bool $immediate = false
	): bool {
		$address = sanitize_email( $recipient );

		if ( '' === $address ) {
			return false;
		}

		$template = $this->templates->resolve( $event_id, $template_key );

		if ( '' === $template['subject'] && '' === $template['body'] ) {
			return false;
		}

		$inserted = $this->queue->insert(
			array(
				'registration_id' => $registration_id,
				'event_id'        => $event_id,
				'template_key'    => $template_key,
				'recipient'       => $address,
				'subject'         => $this->renderer->render( $template['subject'], $values ),
				'body'            => $this->renderer->render( $template['body'], $values ),
				'headers'         => array() === $headers ? '' : (string) wp_json_encode( array_values( $headers ) ),
				'scheduled_at'    => current_time( 'mysql', true ),
			)
		);

		if ( $inserted && $immediate ) {
			wp_schedule_single_event( time(), self::DISPATCH_HOOK );
		}

		return $inserted;
	}
}
```

- [ ] **Step 4: Uruchom test i potwierdź GREEN**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter MailQueueTest
```
Expected: PASS, 7 testów.

- [ ] **Step 5: Styl i statyka**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs src/Mail tests/Integration/Mail
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=512M
```
Expected: zero błędów.

- [ ] **Step 6: Commit**

```bash
git add src/Mail/MailQueue.php tests/Integration/Mail/MailQueueTest.php
git commit -m "feat: add idempotent mail enqueueing with rendered snapshot"
```

---
## Task 7: Dispatcher — odzysk, claim, wysyłka, ponawianie

**Files:**
- Create: `src/Mail/Dispatcher.php`
- Test: `tests/Integration/Mail/DispatcherTest.php`

**Interfaces:**
- Consumes: `MailQueueRepository` (`recoverStale`, `due`, `claim`, `markSent`, `reschedule`, `markFailed`), `RetryPolicy::next()`, `wp_mail()`, `apply_filters()`
- Produces:
  - `EvReg\Mail\Dispatcher::STALE_AFTER = 300`, `DEFAULT_BATCH = 20`
  - `EvReg\Mail\Dispatcher::__construct( MailQueueRepository $queue, RetryPolicy $policy )`
  - `EvReg\Mail\Dispatcher::run(): void`
  - Filtr `evreg_mail_batch_size` (int) — rozmiar batcha na przebieg

Przebieg: odzysk `sending` starszych niż `STALE_AFTER` → pobranie wierszy `queued` z terminem w przeszłości → per wiersz claim (nieudany claim = wiersz wziął ktoś inny, pomiń) → `wp_mail` → `sent` albo ponowienie/`failed`. Wyjątek z `wp_mail` jest łapany i traktowany jak porażka: jeden zły wiersz nie może przerwać przebiegu crona.

- [ ] **Step 1: Napisz test (RED)**

`tests/Integration/Mail/DispatcherTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Mail;

use EvReg\Domain\Mail\RetryPolicy;
use EvReg\Mail\Dispatcher;
use EvReg\Persistence\MailQueueRepository;
use EvReg\Persistence\Migrations;
use WP_Error;
use WP_UnitTestCase;

final class DispatcherTest extends WP_UnitTestCase {

	private Dispatcher $dispatcher;

	private MailQueueRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( 'mail_queue' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$this->repository = new MailQueueRepository();
		$this->dispatcher = new Dispatcher( $this->repository, new RetryPolicy() );
	}

	protected function tearDown(): void {
		remove_all_filters( 'pre_wp_mail' );
		remove_all_filters( 'evreg_mail_batch_size' );
		parent::tearDown();
	}

	/**
	 * Podstawia wynik wp_mail bez dotykania SMTP.
	 *
	 * @param bool   $result  Wynik zwracany przez wp_mail.
	 * @param string $message Komunikat zgłaszany przez wp_mail_failed przy porażce.
	 */
	private function fake_mail( bool $result, string $message = '' ): void {
		add_filter(
			'pre_wp_mail',
			static function () use ( $result, $message ) {
				if ( ! $result && '' !== $message ) {
					do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', $message ) );
				}

				return $result;
			}
		);
	}

	/**
	 * Wstawia wiersz kolejki wymagalny od dawna i zwraca jego id.
	 *
	 * @param string $template_key Klucz szablonu (musi być unikalny w teście).
	 */
	private function queue_row( string $template_key = 'optin' ): int {
		global $wpdb;

		$this->repository->insert(
			array(
				'registration_id' => null,
				'event_id'        => 1,
				'template_key'    => $template_key,
				'recipient'       => 'jan@example.com',
				'subject'         => 'Temat',
				'body'            => 'Treść',
				'headers'         => '',
				'scheduled_at'    => '2020-01-01 00:00:00',
			)
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . Migrations::table( 'mail_queue' ) );
	}

	public function test_successful_send_marks_row_sent(): void {
		$this->fake_mail( true );
		$id = $this->queue_row();

		$this->dispatcher->run();

		$row = $this->repository->find( $id );

		$this->assertSame( MailQueueRepository::STATUS_SENT, $row['status'] );
		$this->assertNotNull( $row['sent_at'] );
		$this->assertSame( '1', (string) $row['attempts'] );
	}

	public function test_first_failure_reschedules_in_one_minute_with_error(): void {
		$this->fake_mail( false, 'SMTP timeout' );
		$id = $this->queue_row();

		$this->dispatcher->run();

		$row = $this->repository->find( $id );

		$this->assertSame( MailQueueRepository::STATUS_QUEUED, $row['status'] );
		$this->assertSame( 'SMTP timeout', $row['last_error'] );
		$this->assertSame( '1', (string) $row['attempts'] );

		$delay = strtotime( (string) $row['scheduled_at'] ) - time();
		$this->assertGreaterThan( 45, $delay );
		$this->assertLessThan( 75, $delay );
	}

	public function test_third_failure_marks_row_failed(): void {
		$this->fake_mail( false, 'SMTP down' );
		$id = $this->queue_row();

		$this->dispatcher->run();
		$this->repository->reschedule( $id, '2020-01-01 00:00:00', 'SMTP down' );
		$this->dispatcher->run();
		$this->repository->reschedule( $id, '2020-01-01 00:00:00', 'SMTP down' );
		$this->dispatcher->run();

		$row = $this->repository->find( $id );

		$this->assertSame( MailQueueRepository::STATUS_FAILED, $row['status'] );
		$this->assertSame( '3', (string) $row['attempts'] );
		$this->assertSame( 'SMTP down', $row['last_error'] );
	}

	public function test_failure_without_wp_error_records_generic_message(): void {
		$this->fake_mail( false );
		$id = $this->queue_row();

		$this->dispatcher->run();

		$this->assertNotSame( '', (string) $this->repository->find( $id )['last_error'] );
	}

	public function test_exception_from_wp_mail_is_treated_as_failure(): void {
		add_filter(
			'pre_wp_mail',
			static function (): bool {
				throw new \RuntimeException( 'Wtyczka SMTP wybuchła' );
			}
		);
		$id = $this->queue_row();

		$this->dispatcher->run();

		$row = $this->repository->find( $id );

		$this->assertSame( MailQueueRepository::STATUS_QUEUED, $row['status'] );
		$this->assertSame( 'Wtyczka SMTP wybuchła', $row['last_error'] );
	}

	public function test_stale_sending_row_is_recovered_and_sent(): void {
		$this->fake_mail( true );
		$id = $this->queue_row();
		$this->repository->claim( $id, gmdate( 'Y-m-d H:i:s', time() - 600 ) );

		$this->dispatcher->run();

		$this->assertSame( MailQueueRepository::STATUS_SENT, $this->repository->find( $id )['status'] );
	}

	public function test_recently_claimed_row_is_left_alone(): void {
		$this->fake_mail( true );
		$id = $this->queue_row();
		$this->repository->claim( $id, gmdate( 'Y-m-d H:i:s', time() - 10 ) );

		$this->dispatcher->run();

		$this->assertSame( MailQueueRepository::STATUS_SENDING, $this->repository->find( $id )['status'] );
	}

	public function test_batch_size_filter_limits_one_run(): void {
		$this->fake_mail( true );
		$first  = $this->queue_row( 'optin' );
		$second = $this->queue_row( 'confirmed' );

		add_filter( 'evreg_mail_batch_size', static fn (): int => 1 );

		$this->dispatcher->run();

		$statuses = array(
			$this->repository->find( $first )['status'],
			$this->repository->find( $second )['status'],
		);

		$this->assertContains( MailQueueRepository::STATUS_SENT, $statuses );
		$this->assertContains( MailQueueRepository::STATUS_QUEUED, $statuses );
	}

	public function test_run_without_due_rows_does_nothing(): void {
		$this->fake_mail( true );

		$this->dispatcher->run();

		$this->assertSame( array(), $this->repository->due( '2099-01-01 00:00:00', 10 ) );
	}
}
```

- [ ] **Step 2: Uruchom test i potwierdź RED**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter DispatcherTest
```
Expected: FAIL — `Class "EvReg\Mail\Dispatcher" not found`.

- [ ] **Step 3: Zaimplementuj Dispatcher**

`src/Mail/Dispatcher.php`:

```php
<?php
/**
 * Wysyłka kolejki mailowej z ponawianiem.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Mail;

use EvReg\Domain\Mail\RetryPolicy;
use EvReg\Persistence\MailQueueRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Wysyłka kolejki mailowej z ponawianiem.
 */
final class Dispatcher {

	/**
	 * Po tylu sekundach wiersz w stanie sending uznajemy za porzucony.
	 */
	public const STALE_AFTER = 300;

	/**
	 * Domyślna liczba wierszy obsłużonych w jednym przebiegu.
	 */
	public const DEFAULT_BATCH = 20;

	/**
	 * Tworzy dispatchera.
	 *
	 * @param MailQueueRepository $queue  Repozytorium kolejki.
	 * @param RetryPolicy         $policy Polityka ponawiania.
	 */
	public function __construct(
		private readonly MailQueueRepository $queue,
		private readonly RetryPolicy $policy
	) {
	}

	/**
	 * Odzyskuje porzucone wiersze i wysyła jedną paczkę wymagalnych maili.
	 */
	public function run(): void {
		$this->queue->recoverStale( gmdate( 'Y-m-d H:i:s', time() - self::STALE_AFTER ) );

		foreach ( $this->queue->due( current_time( 'mysql', true ), $this->batchSize() ) as $row ) {
			$id = (int) $row['id'];

			if ( ! $this->queue->claim( $id, current_time( 'mysql', true ) ) ) {
				continue;
			}

			$error = '';

			if ( $this->send( $row, $error ) ) {
				$this->queue->markSent( $id, current_time( 'mysql', true ) );
				continue;
			}

			$delay = $this->policy->next( (int) $row['attempts'] + 1 );

			if ( null === $delay ) {
				$this->queue->markFailed( $id, $error );
				continue;
			}

			$this->queue->reschedule( $id, gmdate( 'Y-m-d H:i:s', time() + $delay ), $error );
		}
	}

	/**
	 * Zwraca rozmiar batcha, z filtrem i zabezpieczeniem przed wartością bezsensowną.
	 */
	private function batchSize(): int {
		$size = (int) apply_filters( 'evreg_mail_batch_size', self::DEFAULT_BATCH );

		return $size > 0 ? $size : self::DEFAULT_BATCH;
	}

	/**
	 * Wysyła pojedynczy wiersz. Powód porażki ląduje w $error.
	 *
	 * @param array<string,mixed> $row   Wiersz kolejki.
	 * @param string              $error Referencja na komunikat błędu.
	 */
	private function send( array $row, string &$error ): bool {
		$captured = '';
		$listener = static function ( $failure ) use ( &$captured ): void {
			if ( $failure instanceof WP_Error ) {
				$captured = $failure->get_error_message();
			}
		};

		add_action( 'wp_mail_failed', $listener );

		try {
			$sent = wp_mail(
				(string) $row['recipient'],
				(string) $row['subject'],
				(string) $row['body'],
				$this->headers( $row )
			);
		} catch ( \Throwable $e ) {
			$sent     = false;
			$captured = $e->getMessage();
		} finally {
			remove_action( 'wp_mail_failed', $listener );
		}

		if ( ! $sent && '' === $captured ) {
			$captured = __( 'wp_mail zwróciło false bez podania przyczyny.', 'event-registration' );
		}

		$error = $captured;

		return (bool) $sent;
	}

	/**
	 * Dekoduje nagłówki maila z wiersza kolejki.
	 *
	 * @param array<string,mixed> $row Wiersz kolejki.
	 *
	 * @return array<int,string>
	 */
	private function headers( array $row ): array {
		$raw = (string) ( $row['headers'] ?? '' );

		if ( '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$headers = array();

		foreach ( $decoded as $header ) {
			if ( is_string( $header ) ) {
				$headers[] = $header;
			}
		}

		return $headers;
	}
}
```

- [ ] **Step 4: Uruchom test i potwierdź GREEN**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter DispatcherTest
```
Expected: PASS, 9 testów.

- [ ] **Step 5: Styl i statyka**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs src/Mail tests/Integration/Mail
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=512M
```
Expected: zero błędów. Gdyby PHPStan marudził na `wp_mail` w bloku `try`, upewnij się, że zmienna `$sent` jest zainicjowana we wszystkich ścieżkach — `catch` ustawia ją jawnie.

- [ ] **Step 6: Commit**

```bash
git add src/Mail/Dispatcher.php tests/Integration/Mail/DispatcherTest.php
git commit -m "feat: add mail dispatcher with claim, retry and failure capture"
```

---

## Task 8: Hooki cyklu życia zgłoszenia

Bez tego kroku kolejka nie ma czym się karmić. `ReservationService` emituje zdarzenia po COMMIT, a cron wygasania — po jednym na wygasłe zgłoszenie.

**Files:**
- Modify: `src/Services/ReservationService.php` (`reserve()` po COMMIT, `confirm()`/`doConfirm()`)
- Modify: `src/Persistence/RegistrationRepository.php:213` (`expirePending()` zwraca wiersze)
- Modify: `src/Cron/ExpirePending.php:66` (`run()` emituje hook per wiersz)
- Modify: `tests/Integration/Persistence/RegistrationRepositoryTest.php:104-113` (asercja zwracanej wartości)
- Test: `tests/Integration/Services/ReservationHooksTest.php`
- Test: `tests/Integration/Cron/ExpirePendingTest.php` (dopisany test hooka)

**Interfaces:**
- Consumes: `ReservationService`, `RegistrationRepository`, `ExpirePending`
- Produces (kontrakt hooków dla Taska 9):
  - `do_action( 'evreg_registration_reserved', int $registration_id, int $event_id, string $token )`
  - `do_action( 'evreg_registration_waitlisted', int $registration_id, int $event_id )`
  - `do_action( 'evreg_registration_confirmed', int $registration_id, int $event_id )`
  - `do_action( 'evreg_registration_expired', int $registration_id, int $event_id )`
  - `RegistrationRepository::expirePending( string $now ): array<int,array{id: int, event_id: int}>`
  - `do_action( 'evreg_pending_expired', int $count )` — bez zmian, nadal emitowany

- [ ] **Step 1: Napisz testy (RED)**

`tests/Integration/Services/ReservationHooksTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Services;

use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Services\ReservationRequest;
use EvReg\Services\ReservationService;
use WP_UnitTestCase;

final class ReservationHooksTest extends WP_UnitTestCase {

	private ReservationService $service;

	private int $event_id;

	/**
	 * Zebrane wywołania hooków.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $captured = array();

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'locks' ) as $table ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$config         = new EventConfigRepository();
		$this->service  = new ReservationService( new RegistrationRepository(), $config );
		$this->event_id = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
		$this->captured = array();

		$config->save(
			$this->event_id,
			array(
				'types'    => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 0.0, 'capacity' => 1 ) ),
				'settings' => array( 'waitlist_enabled' => true ),
			)
		);

		foreach ( array( 'evreg_registration_reserved', 'evreg_registration_waitlisted', 'evreg_registration_confirmed' ) as $hook ) {
			add_action(
				$hook,
				function ( int $registration_id, int $event_id ) use ( $hook ): void {
					$this->captured[] = array(
						'hook'  => $hook,
						'id'    => $registration_id,
						'event' => $event_id,
					);
				},
				10,
				2
			);
		}
	}

	protected function tearDown(): void {
		foreach ( array( 'evreg_registration_reserved', 'evreg_registration_waitlisted', 'evreg_registration_confirmed' ) as $hook ) {
			remove_all_actions( $hook );
		}
		parent::tearDown();
	}

	private function request( string $email ): ReservationRequest {
		return new ReservationRequest( $email, 'Jan', 'uczestnik', array( '__type' => 'uczestnik' ) );
	}

	public function test_reserve_fires_reserved_hook_with_token(): void {
		$token = null;
		add_action(
			'evreg_registration_reserved',
			static function ( int $id, int $event_id, string $passed ) use ( &$token ): void {
				$token = $passed;
			},
			10,
			3
		);

		$result = $this->service->reserve( $this->event_id, $this->request( 'jan@example.com' ) );

		$this->assertSame( 'reserved', $result->code );
		$this->assertSame( $result->token, $token );
		$this->assertSame(
			array( array( 'hook' => 'evreg_registration_reserved', 'id' => $result->registrationId, 'event' => $this->event_id ) ),
			$this->captured
		);
	}

	public function test_waitlisted_reservation_fires_waitlisted_hook(): void {
		$this->service->reserve( $this->event_id, $this->request( 'pierwszy@example.com' ) );
		$this->captured = array();

		$result = $this->service->reserve( $this->event_id, $this->request( 'drugi@example.com' ) );

		$this->assertSame( 'waitlisted', $result->code );
		$this->assertSame( 'evreg_registration_waitlisted', $this->captured[0]['hook'] );
		$this->assertSame( $result->registrationId, $this->captured[0]['id'] );
	}

	public function test_confirm_fires_confirmed_hook_once(): void {
		$result = $this->service->reserve( $this->event_id, $this->request( 'jan@example.com' ) );
		$this->captured = array();

		$this->service->confirm( (string) $result->token );
		$this->service->confirm( (string) $result->token );

		$this->assertCount( 1, $this->captured );
		$this->assertSame( 'evreg_registration_confirmed', $this->captured[0]['hook'] );
		$this->assertSame( $result->registrationId, $this->captured[0]['id'] );
		$this->assertSame( $this->event_id, $this->captured[0]['event'] );
	}

	public function test_rejected_reservation_fires_nothing(): void {
		$config = new EventConfigRepository();
		$config->save( $this->event_id, array( 'settings' => array( 'waitlist_enabled' => false ) ) );

		$this->service->reserve( $this->event_id, $this->request( 'pierwszy@example.com' ) );
		$this->captured = array();

		$result = $this->service->reserve( $this->event_id, $this->request( 'drugi@example.com' ) );

		$this->assertSame( 'rejected', $result->code );
		$this->assertSame( array(), $this->captured );
	}
}
```

Dopisz do `tests/Integration/Cron/ExpirePendingTest.php`:

```php
	public function test_run_fires_expired_hook_per_registration(): void {
		$captured = array();
		add_action(
			'evreg_registration_expired',
			static function ( int $registration_id, int $event_id ) use ( &$captured ): void {
				$captured[] = array( $registration_id, $event_id );
			},
			10,
			2
		);

		$this->insert( array( 'expires_at' => '2000-01-01 00:00:00', 'email' => 'due1@example.com' ) );
		$this->insert( array( 'expires_at' => '2000-01-01 00:00:00', 'email' => 'due2@example.com' ) );
		$this->insert( array( 'expires_at' => '2099-01-01 00:00:00', 'email' => 'future@example.com' ) );

		ExpirePending::run();

		remove_all_actions( 'evreg_registration_expired' );

		$this->assertCount( 2, $captured );
		$this->assertSame( 1, $captured[0][1] );
	}
```

Podmień w `tests/Integration/Persistence/RegistrationRepositoryTest.php` metodę `test_expire_pending_cancels_past_due_and_returns_count` na:

```php
	public function test_expire_pending_cancels_past_due_and_returns_rows(): void {
		$due = $this->repository->insertRegistration( $this->row( array( 'status' => 'pending', 'expires_at' => '2000-01-01 00:00:00', 'token' => str_repeat( 'j', 32 ) ) ) );
		$this->repository->insertRegistration( $this->row( array( 'status' => 'pending', 'expires_at' => '2099-01-01 00:00:00', 'token' => str_repeat( 'k', 32 ) ) ) );

		$rows = $this->repository->expirePending( '2020-01-01 00:00:00' );

		$this->assertCount( 1, $rows );
		$this->assertSame( $due, $rows[0]['id'] );
		$this->assertIsInt( $rows[0]['event_id'] );
		$this->assertSame( 'cancelled', $this->repository->findByToken( str_repeat( 'j', 32 ) )['status'] );
		$this->assertSame( 'pending', $this->repository->findByToken( str_repeat( 'k', 32 ) )['status'] );
	}
```

- [ ] **Step 2: Uruchom testy i potwierdź RED**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter 'ReservationHooksTest|ExpirePendingTest|RegistrationRepositoryTest'
```
Expected: FAIL — `ReservationHooksTest` nie widzi żadnych wywołań (`Failed asserting that two arrays are equal`), `expirePending` zwraca `int` zamiast tablicy.

- [ ] **Step 3: Emituj hooki w ReservationService**

W `src/Services/ReservationService.php`, w `reserve()`, podmień końcówkę bloku `try` (od `$wpdb->query( 'COMMIT' );`) na:

```php
			$wpdb->query( 'COMMIT' );

			if ( $is_waitlist ) {
				do_action( 'evreg_registration_waitlisted', $id, $event_id );

				return ReservationResult::waitlisted( $id, $token, (string) $decision->reason );
			}

			do_action( 'evreg_registration_reserved', $id, $event_id, $token );

			return ReservationResult::reserved( $id, $token, $decision->accommodationGranted, $decision->reason );
```

Hooki idą **po** COMMIT — nasłuchujący nigdy nie wykona się wewnątrz transakcji rezerwacji i nie ma jak jej wywrócić.

W `confirm()` przekaż ID eventu do `doConfirm()`:

```php
		return match ( $status ) {
			RegistrationStatus::Pending   => $this->doConfirm( (int) $row['id'], (int) $row['event_id'] ),
			RegistrationStatus::Confirmed => ConfirmationResult::alreadyConfirmed(),
			RegistrationStatus::Cancelled => ConfirmationResult::expired(),
			RegistrationStatus::Waitlist  => ConfirmationResult::onWaitlist(),
			default                       => ConfirmationResult::notFound(),
		};
```

i podmień `doConfirm()`:

```php
	/**
	 * Oznacza zgłoszenie jako potwierdzone i ogłasza zdarzenie.
	 *
	 * @param int $registration_id ID zgłoszenia.
	 * @param int $event_id        ID eventu.
	 */
	private function doConfirm( int $registration_id, int $event_id ): ConfirmationResult {
		$this->repository->markConfirmed( $registration_id );

		do_action( 'evreg_registration_confirmed', $registration_id, $event_id );

		return ConfirmationResult::confirmed();
	}
```

- [ ] **Step 4: Zwróć wygasłe wiersze z repozytorium**

W `src/Persistence/RegistrationRepository.php` podmień `expirePending()` na:

```php
	/**
	 * Anuluje zgłoszenia oczekujące, których termin wygasł przed podanym momentem.
	 *
	 * @param string $now Aktualny moment (Y-m-d H:i:s) do porównania z expires_at.
	 *
	 * @return array<int,array{id: int, event_id: int}> Wygaszone zgłoszenia.
	 */
	public function expirePending( string $now ): array {
		global $wpdb;

		$candidates = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id, event_id FROM {$this->registrations()} WHERE status = %s AND expires_at IS NOT NULL AND expires_at < %s",
				RegistrationStatus::Pending->value,
				$now
			),
			ARRAY_A
		);

		if ( ! is_array( $candidates ) || array() === $candidates ) {
			return array();
		}

		$ids          = array_map( static fn ( array $row ): int => (int) $row['id'], $candidates );
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		$result = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$this->registrations()} SET status = %s, updated_at = %s WHERE id IN ({$placeholders}) AND status = %s",
				array_merge(
					array( RegistrationStatus::Cancelled->value, current_time( 'mysql', true ) ),
					$ids,
					array( RegistrationStatus::Pending->value )
				)
			)
		);

		if ( false === $result ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'evreg expirePending failed: ' . $wpdb->last_error );
			return array();
		}

		return array_map(
			static fn ( array $row ): array => array(
				'id'       => (int) $row['id'],
				'event_id' => (int) $row['event_id'],
			),
			$candidates
		);
	}
```

Zwracamy kandydatów, nie wiersze faktycznie zmienione przez UPDATE. Gdyby dwa przebiegi crona nałożyły się na tym samym zgłoszeniu, oba wyemitują hook, ale `UNIQUE (registration_id, template_key)` z Taska 3 zablokuje drugi wiersz maila. To dlatego ta ścieżka nie potrzebuje `SELECT ... FOR UPDATE` — i dlatego cron wygasania trzyma się z dala od blokad, które `ReservationService` bierze w ustalonej kolejności.

- [ ] **Step 5: Emituj hook per wygasłe zgłoszenie**

W `src/Cron/ExpirePending.php` podmień `run()`:

```php
	/**
	 * Wygasza przeterminowane rezerwacje pending i ogłasza zdarzenia.
	 */
	public static function run(): void {
		$expired = ( new RegistrationRepository() )->expirePending( current_time( 'mysql', true ) );

		foreach ( $expired as $registration ) {
			do_action( 'evreg_registration_expired', $registration['id'], $registration['event_id'] );
		}

		if ( array() !== $expired ) {
			do_action( 'evreg_pending_expired', count( $expired ) );
		}
	}
```

- [ ] **Step 6: Uruchom testy i potwierdź GREEN**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter 'ReservationHooksTest|ExpirePendingTest|RegistrationRepositoryTest|ReservationServiceTest|ConfirmationTest|ReservationConcurrencyTest'
```
Expected: PASS. Testy współbieżności z Planu 3A muszą pozostać zielone — kolejność blokad nie została ruszona.

- [ ] **Step 7: Styl i statyka**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs src tests
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=512M
```
Expected: zero błędów.

- [ ] **Step 8: Commit**

```bash
git add src/Services/ReservationService.php src/Persistence/RegistrationRepository.php src/Cron/ExpirePending.php tests/Integration
git commit -m "feat: emit registration lifecycle hooks for reserve, confirm and expiry"
```

---
## Task 9: Subscriber — zdarzenia cyklu życia zamieniane na maile

**Files:**
- Create: `src/Mail/Subscriber.php`
- Test: `tests/Integration/Mail/SubscriberTest.php`

**Interfaces:**
- Consumes: hooki z Taska 8, `MailQueue::enqueue()`, `PlaceholderFactory::build()`, `RegistrationRepository::findById()`, `EventConfigRepository::get()`, `get_option( 'admin_email' )`
- Produces:
  - `EvReg\Mail\Subscriber::register(): void`
  - `EvReg\Mail\Subscriber::on_reserved( int $registration_id, int $event_id ): void`
  - `EvReg\Mail\Subscriber::on_waitlisted( int $registration_id, int $event_id ): void`
  - `EvReg\Mail\Subscriber::on_confirmed( int $registration_id, int $event_id ): void`
  - `EvReg\Mail\Subscriber::on_expired( int $registration_id, int $event_id ): void`

Mapowanie: `reserved` → `optin` + `admin_new`, `waitlisted` → `waitlist` + `admin_new`, `confirmed` → `confirmed`, `expired` → `expired`. Maile do uczestnika idą z `$immediate = true`; powiadomienie organizatora czeka na zwykły przebieg crona.

Adresy organizatora: `_evreg_settings['notify_emails']` (tablica albo lista po przecinku), pusto → `get_option( 'admin_email' )`. Każdy adres dostaje własny wiersz z kluczem `admin_new:<md5(adres)>` — inaczej indeks unikalny przepuściłby tylko pierwszego organizatora. Nagłówek `Reply-To` wskazuje uczestnika.

- [ ] **Step 1: Napisz test (RED)**

`tests/Integration/Mail/SubscriberTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Mail;

use EvReg\Cron\ExpirePending;
use EvReg\Mail\Subscriber;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Services\ReservationRequest;
use EvReg\Services\ReservationService;
use WP_UnitTestCase;

final class SubscriberTest extends WP_UnitTestCase {

	private ReservationService $service;

	private RegistrationRepository $registrations;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'locks', 'mail_queue' ) as $table ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$config              = new EventConfigRepository();
		$this->registrations = new RegistrationRepository();
		$this->service       = new ReservationService( $this->registrations, $config );
		$this->event_id      = self::factory()->post->create( array( 'post_type' => 'evreg_event', 'post_title' => 'Zjazd 2026' ) );

		$config->save(
			$this->event_id,
			array(
				'types'    => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 0.0, 'capacity' => 1 ) ),
				'settings' => array( 'waitlist_enabled' => true, 'notify_emails' => array( 'biuro@example.com', 'szef@example.com' ) ),
			)
		);

		Subscriber::register();
	}

	protected function tearDown(): void {
		foreach ( array( 'evreg_registration_reserved', 'evreg_registration_waitlisted', 'evreg_registration_confirmed', 'evreg_registration_expired' ) as $hook ) {
			remove_all_actions( $hook );
		}
		parent::tearDown();
	}

	/**
	 * Zwraca wiersze kolejki jako mapę template_key => recipient.
	 *
	 * @return array<string,string>
	 */
	private function queued(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( 'SELECT template_key, recipient FROM ' . Migrations::table( 'mail_queue' ) . ' ORDER BY id ASC', ARRAY_A );

		$map = array();

		foreach ( (array) $rows as $row ) {
			$map[ (string) $row['template_key'] ] = (string) $row['recipient'];
		}

		return $map;
	}

	private function reserve( string $email ): \EvReg\Services\ReservationResult {
		return $this->service->reserve(
			$this->event_id,
			new ReservationRequest( $email, 'Jan', 'uczestnik', array( '__type' => 'uczestnik' ) )
		);
	}

	public function test_reservation_queues_optin_and_admin_notifications(): void {
		$this->reserve( 'jan@example.com' );

		$queued = $this->queued();

		$this->assertSame( 'jan@example.com', $queued['optin'] );
		$this->assertSame( 'biuro@example.com', $queued[ 'admin_new:' . md5( 'biuro@example.com' ) ] );
		$this->assertSame( 'szef@example.com', $queued[ 'admin_new:' . md5( 'szef@example.com' ) ] );
		$this->assertCount( 3, $queued );
	}

	public function test_admin_notification_carries_reply_to_participant(): void {
		$this->reserve( 'jan@example.com' );

		global $wpdb;
		$headers = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT headers FROM ' . Migrations::table( 'mail_queue' ) . ' WHERE template_key = %s',
				'admin_new:' . md5( 'biuro@example.com' )
			)
		);

		$this->assertSame( array( 'Reply-To: jan@example.com' ), json_decode( (string) $headers, true ) );
	}

	public function test_waitlisted_reservation_queues_waitlist_mail(): void {
		$this->reserve( 'pierwszy@example.com' );
		$this->reserve( 'drugi@example.com' );

		$queued = $this->queued();

		$this->assertSame( 'drugi@example.com', $queued['waitlist'] );
	}

	public function test_confirmation_queues_confirmed_mail(): void {
		$result = $this->reserve( 'jan@example.com' );

		$this->service->confirm( (string) $result->token );

		$this->assertSame( 'jan@example.com', $this->queued()['confirmed'] );
	}

	public function test_expiry_queues_expired_mail(): void {
		$result = $this->reserve( 'jan@example.com' );

		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Migrations::table( 'registrations' ),
			array( 'expires_at' => '2000-01-01 00:00:00' ),
			array( 'id' => $result->registrationId ),
			array( '%s' ),
			array( '%d' )
		);

		ExpirePending::run();

		$this->assertSame( 'jan@example.com', $this->queued()['expired'] );
	}

	public function test_repeated_event_does_not_duplicate_mail(): void {
		$result = $this->reserve( 'jan@example.com' );

		do_action( 'evreg_registration_reserved', $result->registrationId, $this->event_id, (string) $result->token );

		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . Migrations::table( 'mail_queue' ) . " WHERE template_key = 'optin'" );

		$this->assertSame( 1, $count );
	}

	public function test_falls_back_to_site_admin_when_no_notify_emails(): void {
		( new EventConfigRepository() )->save( $this->event_id, array( 'settings' => array( 'waitlist_enabled' => true ) ) );

		$this->reserve( 'jan@example.com' );

		$this->assertArrayHasKey( 'admin_new:' . md5( (string) get_option( 'admin_email' ) ), $this->queued() );
	}

	public function test_unknown_registration_id_is_ignored(): void {
		do_action( 'evreg_registration_confirmed', 987654, $this->event_id );

		$this->assertSame( array(), $this->queued() );
	}
}
```

- [ ] **Step 2: Uruchom test i potwierdź RED**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter SubscriberTest
```
Expected: FAIL — `Class "EvReg\Mail\Subscriber" not found`.

- [ ] **Step 3: Zaimplementuj Subscriber**

`src/Mail/Subscriber.php`:

```php
<?php
/**
 * Zamiana zdarzeń cyklu życia zgłoszenia na maile w kolejce.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Mail;

use EvReg\Domain\Mail\TemplateRenderer;
use EvReg\Frontend\EventFormLoader;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\MailQueueRepository;
use EvReg\Persistence\MailTemplateRepository;
use EvReg\Persistence\RegistrationRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Zamiana zdarzeń cyklu życia zgłoszenia na maile w kolejce.
 */
final class Subscriber {

	/**
	 * Podpina nasłuch zdarzeń cyklu życia zgłoszenia.
	 */
	public static function register(): void {
		add_action( 'evreg_registration_reserved', array( self::class, 'on_reserved' ), 10, 2 );
		add_action( 'evreg_registration_waitlisted', array( self::class, 'on_waitlisted' ), 10, 2 );
		add_action( 'evreg_registration_confirmed', array( self::class, 'on_confirmed' ), 10, 2 );
		add_action( 'evreg_registration_expired', array( self::class, 'on_expired' ), 10, 2 );
	}

	/**
	 * Zgłoszenie przyjęte: opt-in do uczestnika, powiadomienie do organizatora.
	 *
	 * @param int $registration_id ID zgłoszenia.
	 * @param int $event_id        ID eventu.
	 */
	public static function on_reserved( int $registration_id, int $event_id ): void {
		self::queue( $registration_id, $event_id, DefaultTemplates::KEY_OPTIN, true );
		self::queue_admin( $registration_id, $event_id );
	}

	/**
	 * Zgłoszenie na liście rezerwowej.
	 *
	 * @param int $registration_id ID zgłoszenia.
	 * @param int $event_id        ID eventu.
	 */
	public static function on_waitlisted( int $registration_id, int $event_id ): void {
		self::queue( $registration_id, $event_id, DefaultTemplates::KEY_WAITLIST, true );
		self::queue_admin( $registration_id, $event_id );
	}

	/**
	 * Zgłoszenie potwierdzone.
	 *
	 * @param int $registration_id ID zgłoszenia.
	 * @param int $event_id        ID eventu.
	 */
	public static function on_confirmed( int $registration_id, int $event_id ): void {
		self::queue( $registration_id, $event_id, DefaultTemplates::KEY_CONFIRMED, true );
	}

	/**
	 * Rezerwacja wygasła.
	 *
	 * @param int $registration_id ID zgłoszenia.
	 * @param int $event_id        ID eventu.
	 */
	public static function on_expired( int $registration_id, int $event_id ): void {
		self::queue( $registration_id, $event_id, DefaultTemplates::KEY_EXPIRED, true );
	}

	/**
	 * Kolejkuje mail do uczestnika.
	 *
	 * @param int    $registration_id ID zgłoszenia.
	 * @param int    $event_id        ID eventu.
	 * @param string $template_key    Klucz szablonu.
	 * @param bool   $immediate       Czy wymusić natychmiastowy przebieg dispatchera.
	 */
	private static function queue( int $registration_id, int $event_id, string $template_key, bool $immediate ): void {
		$row = ( new RegistrationRepository() )->findById( $registration_id );

		if ( null === $row ) {
			return;
		}

		self::mailQueue()->enqueue(
			$template_key,
			$event_id,
			$registration_id,
			(string) $row['email'],
			self::placeholders()->build( $row ),
			array(),
			$immediate
		);
	}

	/**
	 * Kolejkuje powiadomienia dla organizatorów — po jednym wierszu na adres.
	 *
	 * @param int $registration_id ID zgłoszenia.
	 * @param int $event_id        ID eventu.
	 */
	private static function queue_admin( int $registration_id, int $event_id ): void {
		$row = ( new RegistrationRepository() )->findById( $registration_id );

		if ( null === $row ) {
			return;
		}

		$values  = self::placeholders()->build( $row );
		$queue   = self::mailQueue();
		$headers = array( 'Reply-To: ' . (string) $row['email'] );

		foreach ( self::recipients( $event_id ) as $recipient ) {
			$queue->enqueue(
				DefaultTemplates::KEY_ADMIN_NEW . ':' . md5( $recipient ),
				$event_id,
				$registration_id,
				$recipient,
				$values,
				$headers
			);
		}
	}

	/**
	 * Zwraca adresy organizatorów: z ustawień eventu, a gdy pusto — adres administratora strony.
	 *
	 * @param int $event_id ID eventu.
	 *
	 * @return array<int,string>
	 */
	private static function recipients( int $event_id ): array {
		$settings = ( new EventConfigRepository() )->get( $event_id )['settings'];
		$raw      = array();

		if ( is_array( $settings ) && isset( $settings['notify_emails'] ) ) {
			$configured = $settings['notify_emails'];
			$raw        = is_array( $configured ) ? $configured : explode( ',', (string) $configured );
		}

		$emails = array();

		foreach ( $raw as $candidate ) {
			$email = sanitize_email( trim( (string) $candidate ) );

			if ( '' !== $email ) {
				$emails[] = $email;
			}
		}

		if ( array() === $emails ) {
			$fallback = sanitize_email( (string) get_option( 'admin_email' ) );

			if ( '' !== $fallback ) {
				$emails[] = $fallback;
			}
		}

		return array_values( array_unique( $emails ) );
	}

	/**
	 * Składa kolejkę mailową.
	 */
	private static function mailQueue(): MailQueue {
		return new MailQueue(
			new MailQueueRepository(),
			new TemplateResolver( new MailTemplateRepository() ),
			new TemplateRenderer()
		);
	}

	/**
	 * Składa fabrykę placeholderów.
	 */
	private static function placeholders(): PlaceholderFactory {
		$config = new EventConfigRepository();

		return new PlaceholderFactory( $config, new RegistrationRepository(), new EventFormLoader( $config ) );
	}
}
```

- [ ] **Step 4: Uruchom test i potwierdź GREEN**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter SubscriberTest
```
Expected: PASS, 8 testów.

- [ ] **Step 5: Styl i statyka**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs src/Mail tests/Integration/Mail
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=512M
```
Expected: zero błędów.

- [ ] **Step 6: Commit**

```bash
git add src/Mail/Subscriber.php tests/Integration/Mail/SubscriberTest.php
git commit -m "feat: queue transactional mails from registration lifecycle events"
```

---

## Task 10: Crony wysyłki i sprzątania oraz podpięcie wtyczki

**Files:**
- Create: `src/Cron/DispatchMail.php`
- Create: `src/Cron/PurgeMailQueue.php`
- Modify: `event-registration.php` (rejestracja, planowanie, aktywacja, dezaktywacja)
- Test: `tests/Integration/Cron/DispatchMailTest.php`
- Test: `tests/Integration/Cron/PurgeMailQueueTest.php`

**Interfaces:**
- Consumes: `Dispatcher::run()`, `MailQueueRepository::purgeSent()`, `MailQueue::DISPATCH_HOOK`
- Produces:
  - `EvReg\Cron\DispatchMail::HOOK = 'evreg_dispatch_mail'`, `IMMEDIATE_HOOK = MailQueue::DISPATCH_HOOK`, `INTERVAL = 'evreg_1min'`, `static register|schedule|unschedule|add_interval|run`
  - `EvReg\Cron\PurgeMailQueue::HOOK = 'evreg_purge_mail_queue'`, `RETENTION_DAYS = 30`, `static register|schedule|unschedule|run`

Zadanie cykliczne i strzał jednorazowy mają **osobne nazwy zdarzeń**, ale wspólny handler. Wspólna nazwa sprawiłaby, że `wp_next_scheduled()` w `schedule()` widziałby przypadkowy strzał jednorazowy i nigdy nie zaplanował zadania cyklicznego.

- [ ] **Step 1: Napisz testy (RED)**

`tests/Integration/Cron/DispatchMailTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Cron;

use EvReg\Cron\DispatchMail;
use EvReg\Mail\MailQueue;
use EvReg\Persistence\MailQueueRepository;
use EvReg\Persistence\Migrations;
use WP_UnitTestCase;

final class DispatchMailTest extends WP_UnitTestCase {

	private MailQueueRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( 'mail_queue' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->repository = new MailQueueRepository();

		wp_clear_scheduled_hook( DispatchMail::HOOK );
		wp_clear_scheduled_hook( DispatchMail::IMMEDIATE_HOOK );
	}

	protected function tearDown(): void {
		remove_all_filters( 'pre_wp_mail' );
		wp_clear_scheduled_hook( DispatchMail::HOOK );
		wp_clear_scheduled_hook( DispatchMail::IMMEDIATE_HOOK );
		parent::tearDown();
	}

	public function test_registers_one_minute_interval(): void {
		DispatchMail::register();

		$schedules = apply_filters( 'cron_schedules', array() );

		$this->assertArrayHasKey( DispatchMail::INTERVAL, $schedules );
		$this->assertSame( 60, $schedules[ DispatchMail::INTERVAL ]['interval'] );
	}

	public function test_schedule_registers_recurring_event(): void {
		DispatchMail::register();
		DispatchMail::schedule();

		$this->assertSame( DispatchMail::INTERVAL, wp_get_schedule( DispatchMail::HOOK ) );
	}

	public function test_schedule_is_not_blocked_by_pending_immediate_event(): void {
		DispatchMail::register();
		wp_schedule_single_event( time() + 5, DispatchMail::IMMEDIATE_HOOK );

		DispatchMail::schedule();

		$this->assertSame( DispatchMail::INTERVAL, wp_get_schedule( DispatchMail::HOOK ) );
	}

	public function test_unschedule_clears_both_hooks(): void {
		DispatchMail::register();
		DispatchMail::schedule();
		wp_schedule_single_event( time() + 5, DispatchMail::IMMEDIATE_HOOK );

		DispatchMail::unschedule();

		$this->assertFalse( wp_next_scheduled( DispatchMail::HOOK ) );
		$this->assertFalse( wp_next_scheduled( DispatchMail::IMMEDIATE_HOOK ) );
	}

	public function test_run_sends_due_mail(): void {
		add_filter( 'pre_wp_mail', static fn (): bool => true );

		$this->repository->insert(
			array(
				'registration_id' => null,
				'event_id'        => 1,
				'template_key'    => 'optin',
				'recipient'       => 'jan@example.com',
				'subject'         => 'Temat',
				'body'            => 'Treść',
				'headers'         => '',
				'scheduled_at'    => '2020-01-01 00:00:00',
			)
		);

		DispatchMail::run();

		$this->assertSame( array(), $this->repository->due( '2099-01-01 00:00:00', 10 ) );
	}

	public function test_immediate_hook_shares_handler(): void {
		DispatchMail::register();

		$this->assertNotFalse( has_action( DispatchMail::IMMEDIATE_HOOK, array( DispatchMail::class, 'run' ) ) );
		$this->assertSame( MailQueue::DISPATCH_HOOK, DispatchMail::IMMEDIATE_HOOK );
	}
}
```

`tests/Integration/Cron/PurgeMailQueueTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Cron;

use EvReg\Cron\PurgeMailQueue;
use EvReg\Persistence\MailQueueRepository;
use EvReg\Persistence\Migrations;
use WP_UnitTestCase;

final class PurgeMailQueueTest extends WP_UnitTestCase {

	private MailQueueRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( 'mail_queue' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->repository = new MailQueueRepository();
		wp_clear_scheduled_hook( PurgeMailQueue::HOOK );
	}

	protected function tearDown(): void {
		wp_clear_scheduled_hook( PurgeMailQueue::HOOK );
		parent::tearDown();
	}

	/**
	 * Wstawia wiersz i zwraca jego id.
	 *
	 * @param string $template_key Klucz szablonu.
	 */
	private function insert( string $template_key ): int {
		global $wpdb;

		$this->repository->insert(
			array(
				'registration_id' => null,
				'event_id'        => 1,
				'template_key'    => $template_key,
				'recipient'       => 'jan@example.com',
				'subject'         => 'Temat',
				'body'            => 'Treść',
				'headers'         => '',
				'scheduled_at'    => '2020-01-01 00:00:00',
			)
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . Migrations::table( 'mail_queue' ) );
	}

	public function test_schedule_registers_daily_event(): void {
		PurgeMailQueue::register();
		PurgeMailQueue::schedule();

		$this->assertSame( 'daily', wp_get_schedule( PurgeMailQueue::HOOK ) );
	}

	public function test_run_deletes_sent_rows_past_retention(): void {
		$old = $this->insert( 'old' );
		$this->repository->markSent( $old, gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) );

		$fresh = $this->insert( 'fresh' );
		$this->repository->markSent( $fresh, gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );

		$failed = $this->insert( 'failed' );
		$this->repository->markFailed( $failed, 'boom' );

		PurgeMailQueue::run();

		$this->assertNull( $this->repository->find( $old ) );
		$this->assertNotNull( $this->repository->find( $fresh ) );
		$this->assertNotNull( $this->repository->find( $failed ) );
	}

	public function test_retention_is_thirty_days(): void {
		$this->assertSame( 30, PurgeMailQueue::RETENTION_DAYS );
	}
}
```

- [ ] **Step 2: Uruchom testy i potwierdź RED**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter 'DispatchMailTest|PurgeMailQueueTest'
```
Expected: FAIL — `Class "EvReg\Cron\DispatchMail" not found`.

- [ ] **Step 3: Zaimplementuj DispatchMail**

`src/Cron/DispatchMail.php`:

```php
<?php
/**
 * Cron wysyłający kolejkę mailową.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Cron;

use EvReg\Domain\Mail\RetryPolicy;
use EvReg\Mail\Dispatcher;
use EvReg\Mail\MailQueue;
use EvReg\Persistence\MailQueueRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Cron wysyłający kolejkę mailową.
 *
 * Zadanie cykliczne (HOOK) i strzał jednorazowy planowany przy kolejkowaniu
 * (IMMEDIATE_HOOK) mają osobne nazwy zdarzeń i wspólny handler. Wspólna nazwa
 * sprawiłaby, że wp_next_scheduled() widziałby strzał jednorazowy i uznał
 * zadanie cykliczne za już zaplanowane.
 */
final class DispatchMail {

	public const HOOK           = 'evreg_dispatch_mail';
	public const IMMEDIATE_HOOK = MailQueue::DISPATCH_HOOK;
	public const INTERVAL       = 'evreg_1min';

	/**
	 * Podpina interwał i oba handlery.
	 */
	public static function register(): void {
		add_filter( 'cron_schedules', array( self::class, 'add_interval' ) );
		add_action( self::HOOK, array( self::class, 'run' ) );
		add_action( self::IMMEDIATE_HOOK, array( self::class, 'run' ) );
	}

	/**
	 * Planuje zadanie cykliczne, jeśli jeszcze niezaplanowane.
	 */
	public static function schedule(): void {
		if ( false === wp_get_schedule( self::HOOK ) ) {
			wp_schedule_event( time(), self::INTERVAL, self::HOOK );
		}
	}

	/**
	 * Usuwa zaplanowane zadania (dezaktywacja).
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::IMMEDIATE_HOOK );
	}

	/**
	 * Dodaje interwał minutowy.
	 *
	 * @param array<string,array<string,mixed>> $schedules Zarejestrowane interwały.
	 * @return array<string,array<string,mixed>>
	 */
	public static function add_interval( array $schedules ): array {
		$schedules[ self::INTERVAL ] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Co minutę (Event Registration)', 'event-registration' ),
		);

		return $schedules;
	}

	/**
	 * Wysyła jedną paczkę wymagalnych maili.
	 */
	public static function run(): void {
		( new Dispatcher( new MailQueueRepository(), new RetryPolicy() ) )->run();
	}
}
```

- [ ] **Step 4: Zaimplementuj PurgeMailQueue**

`src/Cron/PurgeMailQueue.php`:

```php
<?php
/**
 * Cron kasujący stare wysłane maile z kolejki.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Cron;

use EvReg\Persistence\MailQueueRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Cron kasujący stare wysłane maile z kolejki.
 *
 * Wiersze failed i queued zostają — decyzja o nich należy do organizatora.
 */
final class PurgeMailQueue {

	public const HOOK           = 'evreg_purge_mail_queue';
	public const RETENTION_DAYS = 30;

	/**
	 * Podpina handler.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );
	}

	/**
	 * Planuje zadanie dzienne, jeśli jeszcze niezaplanowane.
	 */
	public static function schedule(): void {
		if ( false === wp_get_schedule( self::HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::HOOK );
		}
	}

	/**
	 * Usuwa zaplanowane zadanie (dezaktywacja).
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Kasuje wysłane wiersze starsze niż okno retencji.
	 */
	public static function run(): void {
		( new MailQueueRepository() )->purgeSent( gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS ) );
	}
}
```

- [ ] **Step 5: Podepnij wtyczkę**

W `event-registration.php`, po linii z `ExpirePending::schedule`, dopisz:

```php
add_action( 'plugins_loaded', array( \EvReg\Mail\Subscriber::class, 'register' ) );
add_action( 'plugins_loaded', array( \EvReg\Cron\DispatchMail::class, 'register' ) );
add_action( 'plugins_loaded', array( \EvReg\Cron\DispatchMail::class, 'schedule' ) );
add_action( 'plugins_loaded', array( \EvReg\Cron\PurgeMailQueue::class, 'register' ) );
add_action( 'plugins_loaded', array( \EvReg\Cron\PurgeMailQueue::class, 'schedule' ) );
```

W `register_activation_hook` dopisz przed zamknięciem funkcji anonimowej (kolejność ma znaczenie: `register()` dokłada interwał `evreg_1min` do `cron_schedules`, `schedule()` z niego korzysta):

```php
		\EvReg\Cron\DispatchMail::register();
		\EvReg\Cron\DispatchMail::schedule();
		\EvReg\Cron\PurgeMailQueue::register();
		\EvReg\Cron\PurgeMailQueue::schedule();
```

Podmień `register_deactivation_hook` na wersję sprzątającą wszystkie trzy zadania:

```php
register_deactivation_hook(
	__FILE__,
	static function (): void {
		\EvReg\Cron\ExpirePending::unschedule();
		\EvReg\Cron\DispatchMail::unschedule();
		\EvReg\Cron\PurgeMailQueue::unschedule();
	}
);
```

- [ ] **Step 6: Uruchom testy i potwierdź GREEN**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter 'DispatchMailTest|PurgeMailQueueTest'
```
Expected: PASS, 9 testów.

- [ ] **Step 7: Pełna weryfikacja obu suit, stylu i statyki**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=512M
```
Expected: wszystko zielone, zero błędów stylu i statyki.

- [ ] **Step 8: Commit**

```bash
git add src/Cron event-registration.php tests/Integration/Cron
git commit -m "feat: schedule mail dispatch and queue purge cron jobs"
```

---

## Task 11: Dokumentacja

**Files:**
- Modify: `README.md` (sekcja Status, drzewo katalogów)
- Modify: `CLAUDE.md` (opis warstwy mailowej, komendy bez zmian)
- Modify: `docs/superpowers/plans/2026-08-19-mail-queue.md` (nagłówek: status ukończenia)

- [ ] **Step 1: Zaktualizuj README**

W drzewie katalogów dopisz linię pod `Frontend/`:

```
└─ Mail/           Kolejka mailowa (szablony, placeholdery, kolejkowanie, dispatcher, subskrybent zdarzeń)
```

W sekcji Status zamień punkt 4 na:

```
4. ✅ Kolejka mailowa — silnik (double opt-in, retry, wygasanie, powiadomienia organizatora)
```

i dopisz nowy punkt przed panelem zgłoszeń:

```
5. ⬜ Edytor szablonów maili i ekran kolejki w adminie (Plan 4B)
```

Przenumeruj pozostałe punkty listy.

- [ ] **Step 2: Zaktualizuj CLAUDE.md**

W akapicie o roadmapie dopisz Plan 4A do scalonych i zmień „Kolejny" na Plan 4B. Pod akapitem o formularzu publicznym dodaj:

```
Kolejka mailowa (4A): `src/Mail/` — `Subscriber` (hooki `evreg_registration_reserved|waitlisted|confirmed|expired` → kolejka), `MailQueue::enqueue` (render snapshotu przy zapisie, INSERT IGNORE pod `UNIQUE(registration_id, template_key)` = idempotencja), `Dispatcher` (odzysk `sending` >5 min → claim warunkowym UPDATE-em → `wp_mail` → `sent`/retry 1 min, 5 min/`failed`, powód z `wp_mail_failed`), `TemplateResolver` (meta `_evreg_mail_templates` z fallbackiem per pole na `DefaultTemplates`), `PlaceholderFactory`. Czysta domena w `src/Domain/Mail/`. Crony: `evreg_dispatch_mail` co minutę + jednorazowy `evreg_dispatch_mail_now` po zakolejkowaniu maila uczestnika, `evreg_purge_mail_queue` dziennie (kasuje `sent` starsze niż 30 dni). Maile plain text; edytor szablonów i ekran kolejki to Plan 4B.
```

W sekcji inwariantów dopisz:

```
- **Hooki cyklu życia zgłoszenia emitowane wyłącznie po COMMIT.** Nasłuchujący nigdy nie działa w transakcji rezerwacji. Idempotencję maili gwarantuje indeks unikalny w kolejce, nie dyscyplina wołających.
```

- [ ] **Step 3: Commit**

```bash
git add README.md CLAUDE.md docs/superpowers/plans/2026-08-19-mail-queue.md
git commit -m "docs: document mail queue engine and Plan 4A completion"
```

---

## Definicja ukończenia Planu 4A

- `vendor/bin/phpunit --testsuite unit` — zielone, w tym `DomainPurityTest`
- `vendor/bin/phpunit -c phpunit-integration.xml.dist` — zielone, w tym testy współbieżności z Planu 3A
- `vendor/bin/phpcs` i `vendor/bin/phpstan analyse --memory-limit=512M` — zero błędów
- Zgłoszenie przez publiczny formularz tworzy wiersze `optin` i `admin_new:<hash>` w `evreg_mail_queue`
- Kliknięcie w link z maila opt-in potwierdza zgłoszenie i kolejkuje mail `confirmed`
- Wygaśnięcie rezerwacji kolejkuje mail `expired` i zwalnia miejsce
- Powtórna emisja tego samego zdarzenia nie tworzy drugiego maila
- Weryfikacja ręczna w przeglądarce (kontroler, nie subagent): zapis na testowy event z `MAILHOG`/logiem `wp_mail`, sprawdzenie treści maila i działania linku

## Czego Plan 4A świadomie nie robi

Brak edytora szablonów w adminie i ekranu kolejki z ręcznym wznowieniem (Plan 4B — wymaga też wyjątku na `sanitize_text_field` w `EventConfigController`, który zjada znaki nowej linii). Brak pól `form_page_id` i `notify_emails` w UI ustawień (czytane, ustawiane tylko programowo). Brak maila `bulk` i promocji z listy rezerwowej (Plan 5). Brak maili HTML. Brak śledzenia bounce'ów — `wp_mail` zwracające `true` nie dowodzi doręczenia.
