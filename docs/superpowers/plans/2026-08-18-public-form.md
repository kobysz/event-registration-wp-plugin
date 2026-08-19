# Public Form (Plan 3B) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Zbudować publiczny frontend rejestracji — serwerowe renderowanie formularza ze złożonej schemy, submisję POST-to-self z walidacją i rezerwacją (Plan 3A), endpoint potwierdzenia, oraz lekki JS warunków — domykając cykl, w którym uczestnik realnie się zapisuje.

**Architecture:** `FormRenderer` renderuje `FormSchema` (z `SchemaAssembler` przez `EventFormLoader`) do HTML bez JS. `SubmitHandler` na `template_redirect` przechwytuje POST na bieżącą stronę: antyspam → `Validator` → ekstrakcja email/name/typu/noclegu → `ReservationService::reserve`. Sukces = PRG redirect; błąd = `SubmitResult` przekazany rendererowi (re-render inline z wartościami). Blok i shortcode osadzają formularz. Serwer jest arbitrem; JS tylko chowa/pokazuje sekcje.

**Tech Stack:** PHP 8.1, WordPress 6.4+, vanilla JS (bez webpacka — publiczny frontend), Playwright (E2E). PHPUnit integration w wp-env.

**Spec:** `docs/superpowers/specs/2026-08-18-public-form-design.md`

## Global Constraints

- Minimalne PHP 8.1, minimalne WordPress 6.4.
- Namespace `EvReg\`, PSR-4, `src/`. Prefiks funkcji/hooków `evreg_`, klasy CSS `evreg-*`.
- Każdy plik PHP poza `src/Domain/` i `tests/` zaczyna od `defined( 'ABSPATH' ) || exit;`. (Ten plan nie dodaje niczego do `src/Domain/`.)
- Text domain `event-registration` — wszystkie stringi UI przez `__()`/`esc_html__()`.
- **Escaping obowiązkowy:** każde wyjście HTML przez `esc_html`/`esc_attr`/`esc_textarea`/`esc_url`. Wartości użytkownika nigdy surowe. Zero `echo` bez escapingu.
- **Serwer jest arbitrem walidacji i widoczności** (`Validator` + `VisibilityResolver`). Klient tylko chowa/pokazuje. Pola niewidoczne odrzucane niezależnie od JS.
- Nonce na submisji; sanityzacja przez `Validator`; `ReservationService` (Plan 3A) jedyną drogą zmiany zajętości.
- Katalogi testów wielką literą (`tests/Integration/Frontend/…`), suity małą literą.
- PHPStan poziom 6, WPCS czyste (3 wykluczenia sniffów). `.gitattributes` wymusza LF.
- Publiczny JS/CSS to zwykłe pliki w `assets/public/` — bez wp-scripts/webpacka.

### Komendy referencyjne

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=512M
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
npm run test:e2e   # Task 8, wymaga wp-env + zbudowanego admina (npm run build)
```

Kwirk (z Planu 1): konsola PHPUnit zniekształca komunikat niezłapanego `Error` — RED przez try/catch. PHPStan wymaga `--memory-limit=512M` (kontener default 128M OOM-uje). Weryfikacja w przeglądarce: kontroler (ja), nie subagent — subagent pomija „weryfikację w przeglądarce".

### Interfejsy z Planów 1–3A (konsumowane — zweryfikowane sygnatury)

- `EvReg\Persistence\EventConfigRepository::get( int $eventId ): array` → `['schema'=>array,'types'=>array,'accommodation'=>array,'settings'=>array]`
- `EvReg\Domain\Schema\SchemaAssembler::assemble( array $schema, array $types, array $accommodation ): FormSchema`
- `EvReg\Domain\Schema\FormSchema` — `sections(): Section[]`, `allFields(): Field[]`, `findField( string ): ?Field`, const `TYPE_FIELD_KEY = '__type'`
- `EvReg\Domain\Schema\Section` — readonly `key`, `title`, `fields` (`Field[]`), `description`, `condition` (`?Condition`)
- `EvReg\Domain\Schema\Field` — readonly `key`, `type` (`FieldType`), `label`, `required`, `options` (`Option[]`), `config` (array), `description`, `condition`
- `EvReg\Domain\Schema\FieldType` — cases: `Text`,`Email`,`Tel`,`Textarea`,`Number`,`Date`,`Select`,`Radio`,`Checkbox`,`CheckboxGroup`,`Hidden`,`Heading`,`Paragraph`,`Accommodation` (wartości string jak nazwy)
- `EvReg\Domain\Schema\Option` — readonly `value`, `label`
- `EvReg\Domain\Validation\Validator::__construct( VisibilityResolver, FieldValidatorRegistry )`, `validate( FormSchema, array $answers ): ValidationResult`
- `EvReg\Domain\Validation\ValidationResult` — `isValid(): bool`, `errors(): array<string,string>` (klucz→kod), `values(): array<string,mixed>`
- `EvReg\Domain\Schema\VisibilityResolver::__construct( ConditionEngine )`, `resolve( FormSchema, array ): VisibilitySet`; `VisibilitySet::isFieldVisible( string ): bool`, `visibleFields(): Field[]`
- `EvReg\Domain\Validation\FieldValidatorRegistry` — `__construct()` (rejestruje domyślne)
- `EvReg\Domain\Conditions\ConditionEngine` — `__construct()`
- `EvReg\Domain\Accommodation\AccommodationSelection::__construct( string $packageKey, string $roomKey, string $roommatePref = '' )`
- `EvReg\Services\ReservationService::__construct( RegistrationRepository, EventConfigRepository )`, `reserve( int $eventId, ReservationRequest ): ReservationResult`, `confirm( string $token ): ConfirmationResult`
- `EvReg\Services\ReservationRequest::__construct( string $email, string $name, string $typeKey, array $data, ?AccommodationSelection $selection = null )`
- `EvReg\Services\ReservationResult` — readonly `code` (`reserved|waitlisted|rejected|duplicate`), `registrationId` (?int), `token` (?string), `accommodationGranted` (bool), `reason` (?string)
- `EvReg\Services\ConfirmationResult` — readonly `code` (`confirmed|already_confirmed|expired|waitlist|not_found`)
- `EvReg\Persistence\RegistrationRepository` — `__construct()`
- `EvReg\Admin\EventPostType::POST_TYPE` = `'evreg_event'`
- `EvReg\Plugin::plugin_file()`, `Plugin::VERSION`

---

## File Structure

| Plik | Odpowiedzialność |
|------|------------------|
| `src/Frontend/EventFormLoader.php` | Config eventu → złożony `FormSchema` (lub null) |
| `src/Frontend/FormRenderer.php` | `FormSchema` (+ wynik) → HTML |
| `src/Frontend/SubmitResult.php` | Wynik submisji (wartość) |
| `src/Frontend/SubmitHandler.php` | `process()` (walidacja+rezerwacja) + `handle()` (hook+PRG) + magazyn wyniku |
| `src/Frontend/Block.php` | Blok dynamiczny |
| `src/Frontend/Shortcode.php` | Shortcode `[evreg_form]` |
| `src/Frontend/ConfirmationController.php` | Endpoint `?evreg_confirm=token` |
| `assets/public/form.js` | Warunki na żywo |
| `assets/public/form.css` | Izolowany styl |
| `event-registration.php` | Modyfikacja: rejestracja frontendu |

---

## Task 1: EventFormLoader

**Files:**
- Create: `src/Frontend/EventFormLoader.php`
- Test: `tests/Integration/Frontend/EventFormLoaderTest.php`

**Interfaces:**
- Consumes: `EventConfigRepository::get`, `SchemaAssembler::assemble`
- Produces: `EventFormLoader::__construct( EventConfigRepository $config )`; `EventFormLoader::load( int $eventId ): ?FormSchema` — zwraca złożony `FormSchema` lub `null`, gdy schema pusta/niepoprawna

- [ ] **Step 1: Napisz failujący test**

`tests/Integration/Frontend/EventFormLoaderTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Frontend;

use EvReg\Domain\Schema\FormSchema;
use EvReg\Frontend\EventFormLoader;
use EvReg\Persistence\EventConfigRepository;
use WP_UnitTestCase;

final class EventFormLoaderTest extends WP_UnitTestCase {

	private EventFormLoader $loader;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		$this->event_id = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
		$this->loader   = new EventFormLoader( new EventConfigRepository() );
	}

	private function configure(): void {
		( new EventConfigRepository() )->save(
			$this->event_id,
			array(
				'schema' => array(
					'version'  => 1,
					'sections' => array(
						array(
							'key'    => 'dane',
							'title'  => 'Dane',
							'fields' => array(
								array( 'key' => '__type', 'type' => 'radio', 'label' => 'Typ' ),
								array( 'key' => 'email', 'type' => 'email', 'label' => 'E-mail', 'required' => true ),
							),
						),
					),
				),
				'types'         => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 450.0 ) ),
				'accommodation' => array( 'packages' => array(), 'rooms' => array(), 'inventory' => array() ),
			)
		);
	}

	public function test_load_returns_assembled_schema_with_injected_type_options(): void {
		$this->configure();

		$schema = $this->loader->load( $this->event_id );

		$this->assertInstanceOf( FormSchema::class, $schema );
		$type = $schema->findField( '__type' );
		$this->assertNotNull( $type );
		$values = array_map( static fn ( $o ) => $o->value, $type->options );
		$this->assertSame( array( 'uczestnik' ), $values );
	}

	public function test_load_returns_null_for_unconfigured_event(): void {
		$this->assertNull( $this->loader->load( $this->event_id ) );
	}
}
```

- [ ] **Step 2: Uruchom i potwierdź fail**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter EventFormLoaderTest
```

Oczekiwane: FAIL — brak klasy.

- [ ] **Step 3: Zaimplementuj `EventFormLoader`**

```php
<?php

declare( strict_types=1 );

namespace EvReg\Frontend;

use EvReg\Domain\Schema\FormSchema;
use EvReg\Domain\Schema\SchemaAssembler;
use EvReg\Domain\Schema\SchemaException;
use EvReg\Persistence\EventConfigRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Ładuje i składa kompletną FormSchema eventu do renderowania i walidacji.
 */
final class EventFormLoader {

	private SchemaAssembler $assembler;

	public function __construct( private readonly EventConfigRepository $config ) {
		$this->assembler = new SchemaAssembler();
	}

	/**
	 * Zwraca złożoną schemę eventu lub null, gdy brak/niepoprawna.
	 */
	public function load( int $event_id ): ?FormSchema {
		$config = $this->config->get( $event_id );
		$schema = is_array( $config['schema'] ) ? $config['schema'] : array();

		if ( array() === $schema || empty( $schema['sections'] ) ) {
			return null;
		}

		try {
			return $this->assembler->assemble(
				$schema,
				is_array( $config['types'] ) ? $config['types'] : array(),
				is_array( $config['accommodation'] ) ? $config['accommodation'] : array()
			);
		} catch ( SchemaException $e ) {
			return null;
		}
	}
}
```

- [ ] **Step 4: Uruchom testy + narzędzia**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter EventFormLoaderTest
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=512M
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
```

Oczekiwane: 2 testy zielone, PHPStan/WPCS czyste.

- [ ] **Step 5: Commit**

```bash
git add src/Frontend/EventFormLoader.php tests/Integration/Frontend/EventFormLoaderTest.php
git commit -m "feat: add event form loader assembling schema for rendering"
```

---

## Task 2: FormRenderer

**Files:**
- Create: `src/Frontend/SubmitResult.php`
- Create: `src/Frontend/FormRenderer.php`
- Test: `tests/Integration/Frontend/FormRendererTest.php`

**Interfaces:**
- Consumes: `FormSchema`, `Section`, `Field`, `FieldType`, `Option`
- Produces:
  - `SubmitResult` (pełny obiekt wartości — właściciel tego pliku; Task 3 tylko go konsumuje): statyczne `success( string $code )`, `invalid( array $errors, array $values )`, `spam()`, `duplicate()`, `rejected()`, `configError()`; metody `code(): string`, `isSuccess(): bool`, `errors(): array<string,string>`, `submittedValue( string $key ): mixed`
  - `FormRenderer::render( FormSchema $schema, int $eventId, ?SubmitResult $result = null ): string` — kompletny `<form>` HTML

**Uwaga:** `SubmitResult` powstaje TU (renderer go konsumuje przy re-renderze błędów). Task 3 (`SubmitHandler`) używa gotowego `SubmitResult` — nie tworzy go ponownie.

- [ ] **Step 1: Napisz failujące testy**

`tests/Integration/Frontend/FormRendererTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Frontend;

use EvReg\Domain\Schema\FormSchema;
use EvReg\Frontend\FormRenderer;
use EvReg\Frontend\SubmitResult;
use WP_UnitTestCase;

final class FormRendererTest extends WP_UnitTestCase {

	private FormRenderer $renderer;

	protected function setUp(): void {
		parent::setUp();
		$this->renderer = new FormRenderer();
	}

	private function schema(): FormSchema {
		return FormSchema::fromArray(
			array(
				'version'  => 1,
				'sections' => array(
					array(
						'key'    => 'dane',
						'title'  => 'Dane uczestnika',
						'fields' => array(
							array(
								'key'     => '__type',
								'type'    => 'radio',
								'label'   => 'Typ zgłoszenia',
								'options' => array( array( 'value' => 'uczestnik', 'label' => 'Uczestnik' ) ),
							),
							array( 'key' => 'email', 'type' => 'email', 'label' => 'E-mail', 'required' => true ),
							array( 'key' => 'uwagi', 'type' => 'textarea', 'label' => 'Uwagi' ),
						),
					),
				),
			)
		);
	}

	public function test_renders_form_with_fields_sections_and_hidden_controls(): void {
		$html = $this->renderer->render( $this->schema(), 123 );

		$this->assertStringContainsString( '<form', $html );
		$this->assertStringContainsString( 'Dane uczestnika', $html );
		$this->assertStringContainsString( 'name="email"', $html );
		$this->assertStringContainsString( 'type="radio"', $html );
		$this->assertStringContainsString( 'value="uczestnik"', $html );
		$this->assertStringContainsString( 'name="evreg_event"', $html );
		$this->assertStringContainsString( 'value="123"', $html );
		$this->assertStringContainsString( 'name="evreg_submit"', $html );
		$this->assertStringContainsString( 'name="evreg_hp"', $html );
		$this->assertStringContainsString( 'name="evreg_ts"', $html );
		$this->assertStringContainsString( 'evreg-nonce', $html );
	}

	public function test_escapes_field_labels(): void {
		$schema = FormSchema::fromArray(
			array(
				'version'  => 1,
				'sections' => array(
					array(
						'key'    => 's',
						'title'  => 'S',
						'fields' => array(
							array( 'key' => '__type', 'type' => 'radio', 'label' => '<script>x</script>', 'options' => array( array( 'value' => 'a', 'label' => 'A' ) ) ),
						),
					),
				),
			)
		);

		$html = $this->renderer->render( $schema, 1 );

		$this->assertStringNotContainsString( '<script>x</script>', $html );
	}

	public function test_error_re_render_shows_message_and_preserves_value(): void {
		$result = SubmitResult::invalid(
			array( 'email' => 'invalid_email' ),
			array( 'email' => 'zły@@adres' )
		);

		$html = $this->renderer->render( $this->schema(), 1, $result );

		$this->assertStringContainsString( 'zły@@adres', $html );          // wartość odtworzona
		$this->assertStringContainsString( 'evreg-field-error', $html );   // klasa błędu
	}
}
```

- [ ] **Step 2: Uruchom i potwierdź fail**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter FormRendererTest
```

Oczekiwane: FAIL — brak `FormRenderer` (i `SubmitResult`, jeśli Task 3 jeszcze nie zrobiony).

- [ ] **Step 3: Zaimplementuj `SubmitResult` (właściciel — renderer go konsumuje)**

`src/Frontend/SubmitResult.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Wynik próby submisji formularza.
 */
final class SubmitResult {

	/**
	 * @param array<string,string> $errors Klucz pola → kod błędu.
	 * @param array<string,mixed>  $values Wpisane wartości do odtworzenia.
	 */
	private function __construct(
		private readonly string $code,
		private readonly bool $success,
		private readonly array $errors = array(),
		private readonly array $values = array()
	) {
	}

	public static function success( string $code ): self {
		return new self( $code, true );
	}

	/**
	 * @param array<string,string> $errors
	 * @param array<string,mixed>  $values
	 */
	public static function invalid( array $errors, array $values ): self {
		return new self( 'invalid', false, $errors, $values );
	}

	public static function spam(): self {
		return new self( 'spam', false );
	}

	public static function duplicate(): self {
		return new self( 'duplicate', false );
	}

	public static function rejected(): self {
		return new self( 'rejected', false );
	}

	public static function configError(): self {
		return new self( 'config_error', false );
	}

	public function code(): string {
		return $this->code;
	}

	public function isSuccess(): bool {
		return $this->success;
	}

	/**
	 * @return array<string,string>
	 */
	public function errors(): array {
		return $this->errors;
	}

	public function submittedValue( string $key ): mixed {
		return $this->values[ $key ] ?? null;
	}
}
```

- [ ] **Step 3b: Zaimplementuj `FormRenderer`**

`src/Frontend/FormRenderer.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Frontend;

use EvReg\Domain\Schema\Field;
use EvReg\Domain\Schema\FieldType;
use EvReg\Domain\Schema\FormSchema;
use EvReg\Domain\Schema\Option;
use EvReg\Domain\Schema\Section;

defined( 'ABSPATH' ) || exit;

/**
 * Renderuje FormSchema do publicznego HTML formularza. Serwerowo, bez JS.
 */
final class FormRenderer {

	/**
	 * Renderuje kompletny formularz. $result niesie błędy i wpisane wartości do re-renderu.
	 */
	public function render( FormSchema $schema, int $event_id, ?SubmitResult $result = null ): string {
		$out  = '<form class="evreg-form" method="post">';
		$out .= wp_nonce_field( 'evreg_submit_' . $event_id, 'evreg-nonce', true, false );
		$out .= '<input type="hidden" name="evreg_event" value="' . esc_attr( (string) $event_id ) . '">';
		$out .= '<input type="hidden" name="evreg_submit" value="1">';
		$out .= '<input type="hidden" name="evreg_ts" value="' . esc_attr( (string) time() ) . '">';
		$out .= '<div class="evreg-hp" aria-hidden="true" style="position:absolute;left:-9999px;">'
			. '<label>Zostaw puste <input type="text" name="evreg_hp" value="" tabindex="-1" autocomplete="off"></label></div>';

		foreach ( $schema->sections() as $section ) {
			$out .= $this->renderSection( $section, $result );
		}

		$out .= '<button type="submit" class="evreg-submit">' . esc_html__( 'Wyślij zgłoszenie', 'event-registration' ) . '</button>';
		$out .= '</form>';

		return $out;
	}

	private function renderSection( Section $section, ?SubmitResult $result ): string {
		$attrs = '';
		if ( null !== $section->condition ) {
			$attrs = ' data-evreg-when-field="' . esc_attr( $section->condition->field ) . '"'
				. ' data-evreg-when-operator="' . esc_attr( $section->condition->operator->value ) . '"'
				. ' data-evreg-when-value="' . esc_attr( wp_json_encode( $section->condition->value ) ?: '' ) . '"';
		}

		$out  = '<fieldset class="evreg-section"' . $attrs . '>';
		$out .= '<legend>' . esc_html( $section->title ) . '</legend>';

		if ( '' !== $section->description ) {
			$out .= '<p class="evreg-section-desc">' . esc_html( $section->description ) . '</p>';
		}

		foreach ( $section->fields as $field ) {
			$out .= $this->renderField( $field, $result );
		}

		$out .= '</fieldset>';

		return $out;
	}

	private function renderField( Field $field, ?SubmitResult $result ): string {
		if ( FieldType::Heading === $field->type ) {
			return '<h3 class="evreg-heading">' . esc_html( $field->label ) . '</h3>';
		}
		if ( FieldType::Paragraph === $field->type ) {
			return '<p class="evreg-paragraph">' . esc_html( $field->label ) . '</p>';
		}

		$value = null === $result ? null : $result->submittedValue( $field->key );
		$error = null === $result ? null : ( $result->errors()[ $field->key ] ?? null );

		$class = 'evreg-field evreg-field-' . esc_attr( $field->type->value );
		if ( null !== $error ) {
			$class .= ' evreg-field-error';
		}

		$out  = '<div class="' . $class . '">';
		$out .= '<label class="evreg-label" for="evreg-' . esc_attr( $field->key ) . '">' . esc_html( $field->label );
		if ( $field->required ) {
			$out .= ' <span class="evreg-required">*</span>';
		}
		$out .= '</label>';
		$out .= $this->renderControl( $field, $value );

		if ( null !== $error ) {
			$out .= '<span class="evreg-error-msg">' . esc_html( $this->errorMessage( (string) $error ) ) . '</span>';
		}

		$out .= '</div>';

		return $out;
	}

	private function renderControl( Field $field, mixed $value ): string {
		$name = esc_attr( $field->key );
		$id   = 'evreg-' . esc_attr( $field->key );
		$req  = $field->required ? ' required' : '';

		switch ( $field->type ) {
			case FieldType::Textarea:
				return '<textarea id="' . $id . '" name="' . $name . '"' . $req . '>' . esc_textarea( (string) ( $value ?? '' ) ) . '</textarea>';

			case FieldType::Select:
				$opts = '';
				foreach ( $field->options as $option ) {
					$opts .= '<option value="' . esc_attr( $option->value ) . '"' . selected( (string) $value, $option->value, false ) . '>' . esc_html( $option->label ) . '</option>';
				}
				return '<select id="' . $id . '" name="' . $name . '"' . $req . '><option value="">—</option>' . $opts . '</select>';

			case FieldType::Radio:
				return $this->renderChoices( $field->options, $name, 'radio', $value );

			case FieldType::CheckboxGroup:
				return $this->renderChoices( $field->options, $name . '[]', 'checkbox', $value );

			case FieldType::Checkbox:
				return '<input type="checkbox" id="' . $id . '" name="' . $name . '" value="1"' . checked( '1', (string) $value, false ) . $req . '>';

			case FieldType::Accommodation:
				return $this->renderAccommodation( $field, $value );

			case FieldType::Number:
				return '<input type="number" id="' . $id . '" name="' . $name . '" value="' . esc_attr( (string) ( $value ?? '' ) ) . '"' . $req . '>';

			case FieldType::Date:
				return '<input type="date" id="' . $id . '" name="' . $name . '" value="' . esc_attr( (string) ( $value ?? '' ) ) . '"' . $req . '>';

			case FieldType::Email:
				return '<input type="email" id="' . $id . '" name="' . $name . '" value="' . esc_attr( (string) ( $value ?? '' ) ) . '"' . $req . '>';

			case FieldType::Tel:
				return '<input type="tel" id="' . $id . '" name="' . $name . '" value="' . esc_attr( (string) ( $value ?? '' ) ) . '"' . $req . '>';

			case FieldType::Hidden:
				return '<input type="hidden" name="' . $name . '" value="' . esc_attr( (string) ( $value ?? '' ) ) . '">';

			default: // Text i pozostałe.
				return '<input type="text" id="' . $id . '" name="' . $name . '" value="' . esc_attr( (string) ( $value ?? '' ) ) . '"' . $req . '>';
		}
	}

	/**
	 * @param Option[] $options
	 */
	private function renderChoices( array $options, string $name, string $type, mixed $value ): string {
		$selected = is_array( $value ) ? array_map( 'strval', $value ) : array( (string) $value );
		$out      = '<div class="evreg-choices">';
		foreach ( $options as $option ) {
			$checked = in_array( $option->value, $selected, true ) ? ' checked' : '';
			$out    .= '<label class="evreg-choice"><input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $option->value ) . '"' . $checked . '> ' . esc_html( $option->label ) . '</label>';
		}
		$out .= '</div>';

		return $out;
	}

	private function renderAccommodation( Field $field, mixed $value ): string {
		$config    = $field->config;
		$packages  = is_array( $config['packages'] ?? null ) ? $config['packages'] : array();
		$rooms      = is_array( $config['rooms'] ?? null ) ? $config['rooms'] : array();
		$inventory = is_array( $config['inventory'] ?? null ) ? $config['inventory'] : array();
		$available = array();
		foreach ( $inventory as $item ) {
			$available[ (string) ( $item['package'] ?? '' ) . '|' . (string) ( $item['room'] ?? '' ) ] = $item;
		}

		$current = is_array( $value ) ? ( (string) ( $value['package'] ?? '' ) . '|' . (string) ( $value['room'] ?? '' ) ) : '';

		$name = esc_attr( $field->key );
		$out  = '<div class="evreg-accommodation">';
		$out .= '<label class="evreg-choice"><input type="radio" name="' . $name . '[slot]" value=""' . checked( '', $current, false ) . '> ' . esc_html__( 'Bez noclegu', 'event-registration' ) . '</label>';

		foreach ( $packages as $pkg ) {
			foreach ( $rooms as $room ) {
				$slot = (string) ( $pkg['key'] ?? '' ) . '|' . (string) ( $room['key'] ?? '' );
				if ( ! isset( $available[ $slot ] ) ) {
					continue;
				}
				$label   = (string) ( $pkg['label'] ?? '' ) . ' — ' . (string) ( $room['label'] ?? '' );
				$out    .= '<label class="evreg-choice"><input type="radio" name="' . $name . '[slot]" value="' . esc_attr( $slot ) . '"' . checked( $slot, $current, false ) . '> ' . esc_html( $label ) . '</label>';
			}
		}

		$roommate = is_array( $value ) ? (string) ( $value['roommate'] ?? '' ) : '';
		$out     .= '<input type="text" name="' . $name . '[roommate]" placeholder="' . esc_attr__( 'Preferowana osoba w pokoju', 'event-registration' ) . '" value="' . esc_attr( $roommate ) . '">';
		$out     .= '</div>';

		return $out;
	}

	private function errorMessage( string $code ): string {
		$map = array(
			'required'              => __( 'To pole jest wymagane.', 'event-registration' ),
			'invalid_email'         => __( 'Nieprawidłowy adres e-mail.', 'event-registration' ),
			'invalid_tel'           => __( 'Nieprawidłowy numer telefonu.', 'event-registration' ),
			'invalid_number'        => __( 'Nieprawidłowa liczba.', 'event-registration' ),
			'invalid_date'          => __( 'Nieprawidłowa data.', 'event-registration' ),
			'not_in_options'        => __( 'Wybór spoza dostępnych opcji.', 'event-registration' ),
			'too_long'              => __( 'Wpis jest za długi.', 'event-registration' ),
			'invalid_accommodation' => __( 'Nieprawidłowy wybór noclegu.', 'event-registration' ),
			'roommate_not_allowed'  => __( 'Współlokator niedozwolony dla tego pokoju.', 'event-registration' ),
		);

		return $map[ $code ] ?? __( 'Nieprawidłowa wartość.', 'event-registration' );
	}
}
```

- [ ] **Step 4: Uruchom testy + narzędzia**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter FormRendererTest
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=512M
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
```

Oczekiwane: 3 testy zielone, PHPStan/WPCS czyste.

- [ ] **Step 5: Commit**

```bash
git add src/Frontend/FormRenderer.php tests/Integration/Frontend/FormRendererTest.php src/Frontend/SubmitResult.php
git commit -m "feat: add server-side form renderer with error re-render and escaping"
```

---

## Task 3: SubmitResult i SubmitHandler::process

**Files:**
- Create: `src/Frontend/SubmitHandler.php`
- Test: `tests/Integration/Frontend/SubmitHandlerTest.php`

**Interfaces:**
- Consumes: `SubmitResult` (utworzony w Task 2), `EventFormLoader`, `Validator`, `VisibilityResolver`, `ConditionEngine`, `FieldValidatorRegistry`, `ReservationService`, `ReservationRequest`, `AccommodationSelection`, `FieldType`, `FormSchema`
- Produces:
  - `SubmitHandler::__construct( EventFormLoader $loader, ReservationService $reservations )`
  - `SubmitHandler::process( int $eventId, array $post ): SubmitResult` — jądro testowalne (antyspam → walidacja → ekstrakcja → rezerwacja), bez redirectu

**SubmitResult istnieje z Taska 2** — nie twórz go ponownie. `process()` używa `SubmitResult::success('reserved'|'waitlisted')`, `invalid`, `spam`, `duplicate`, `rejected`, `configError`.

**Zasada ekstrakcji (spec §7):** `typeKey` = odpowiedź `__type`; `email` = pierwsze **widoczne** pole typu `email`, brak → `configError()`; `name` = pole `name`/`imie`, inaczej pierwsze `text`, inaczej email; `AccommodationSelection` z pola typu `accommodation` (`slot` = `"pakiet|pokój"`, `roommate`). Widoczność z `VisibilityResolver`.

**Antyspam:** honeypot `evreg_hp` niepusty → `spam()`; `time() − evreg_ts < 3` → `spam()`. (Rate-limit per IP realizuje `handle()` w Task 4 — `process()` skupia się na treści.)

- [ ] **Step 1: Napisz failujące testy**

`tests/Integration/Frontend/SubmitHandlerTest.php`:

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

final class SubmitHandlerTest extends WP_UnitTestCase {

	private SubmitHandler $handler;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'locks' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$this->event_id = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
		( new EventConfigRepository() )->save(
			$this->event_id,
			array(
				'schema' => array(
					'version'  => 1,
					'sections' => array(
						array(
							'key'    => 'dane',
							'title'  => 'Dane',
							'fields' => array(
								array( 'key' => '__type', 'type' => 'radio', 'label' => 'Typ' ),
								array( 'key' => 'imie', 'type' => 'text', 'label' => 'Imię', 'required' => true ),
								array( 'key' => 'email', 'type' => 'email', 'label' => 'E-mail', 'required' => true ),
							),
						),
					),
				),
				'types'         => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 450.0, 'capacity' => 1 ) ),
				'accommodation' => array( 'packages' => array(), 'rooms' => array(), 'inventory' => array() ),
				'settings'      => array( 'global_cap' => null, 'waitlist_enabled' => true ),
			)
		);

		$this->handler = new SubmitHandler(
			new EventFormLoader( new EventConfigRepository() ),
			new ReservationService( new RegistrationRepository(), new EventConfigRepository() )
		);
	}

	/**
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function post( array $overrides = array() ): array {
		return array_merge(
			array(
				'evreg_hp' => '',
				'evreg_ts' => (string) ( time() - 10 ),
				'__type'   => 'uczestnik',
				'imie'     => 'Jan',
				'email'    => 'jan@example.com',
			),
			$overrides
		);
	}

	public function test_valid_submission_reserves(): void {
		$result = $this->handler->process( $this->event_id, $this->post() );

		$this->assertTrue( $result->isSuccess() );
		$this->assertSame( 'reserved', $result->code() );
	}

	public function test_validation_error_returns_invalid_with_values(): void {
		$result = $this->handler->process( $this->event_id, $this->post( array( 'email' => 'zły@@' ) ) );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( 'invalid_email', $result->errors()['email'] );
		$this->assertSame( 'zły@@', $result->submittedValue( 'email' ) );
	}

	public function test_honeypot_filled_is_spam(): void {
		$result = $this->handler->process( $this->event_id, $this->post( array( 'evreg_hp' => 'bot' ) ) );

		$this->assertSame( 'spam', $result->code() );
	}

	public function test_too_fast_is_spam(): void {
		$result = $this->handler->process( $this->event_id, $this->post( array( 'evreg_ts' => (string) time() ) ) );

		$this->assertSame( 'spam', $result->code() );
	}

	public function test_duplicate_email_reported(): void {
		$this->handler->process( $this->event_id, $this->post() );

		$result = $this->handler->process( $this->event_id, $this->post() );

		$this->assertSame( 'duplicate', $result->code() );
	}

	public function test_second_registration_waitlisted_when_type_full(): void {
		$this->handler->process( $this->event_id, $this->post( array( 'email' => 'a@example.com' ) ) );

		$result = $this->handler->process( $this->event_id, $this->post( array( 'email' => 'b@example.com' ) ) );

		$this->assertSame( 'waitlisted', $result->code() );
	}
}
```

- [ ] **Step 2: Uruchom i potwierdź fail**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter SubmitHandlerTest
```

Oczekiwane: FAIL — brak klas.

- [ ] **Step 3: Zaimplementuj `SubmitHandler::process`** (`SubmitResult` już istnieje z Taska 2)

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
use EvReg\Services\ReservationService;

defined( 'ABSPATH' ) || exit;

/**
 * Obsługa submisji publicznego formularza (POST-to-self).
 */
final class SubmitHandler {

	private const MIN_FILL_SECONDS = 3;

	public function __construct(
		private readonly EventFormLoader $loader,
		private readonly ReservationService $reservations
	) {
	}

	/**
	 * Jądro submisji bez redirectu — testowalne.
	 *
	 * @param array<string,mixed> $post Surowe $_POST.
	 */
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

		$answers   = $this->extractAnswers( $schema, $post );
		$validator = new Validator( new VisibilityResolver( new ConditionEngine() ), new FieldValidatorRegistry() );
		$result    = $validator->validate( $schema, $answers );

		if ( ! $result->isValid() ) {
			return SubmitResult::invalid( $result->errors(), $answers );
		}

		$values = $result->values();

		$email = $this->extractEmail( $schema, $values );
		if ( '' === $email ) {
			return SubmitResult::configError();
		}

		$request = new ReservationRequest(
			$email,
			$this->extractName( $schema, $values, $email ),
			(string) ( $values[ FormSchema::TYPE_FIELD_KEY ] ?? '' ),
			$values,
			$this->extractSelection( $schema, $values )
		);

		$reservation = $this->reservations->reserve( $event_id, $request );

		return match ( $reservation->code ) {
			'reserved'   => SubmitResult::success( 'reserved' ),
			'waitlisted' => SubmitResult::success( 'waitlisted' ),
			'duplicate'  => SubmitResult::duplicate(),
			default      => SubmitResult::rejected(),
		};
	}

	/**
	 * @param array<string,mixed> $post
	 * @return array<string,mixed>
	 */
	private function extractAnswers( FormSchema $schema, array $post ): array {
		$answers = array();
		foreach ( $schema->allFields() as $field ) {
			if ( FieldType::Accommodation === $field->type ) {
				$raw                    = is_array( $post[ $field->key ] ?? null ) ? $post[ $field->key ] : array();
				$slot                   = (string) ( $raw['slot'] ?? '' );
				$parts                  = '' === $slot ? array( '', '' ) : explode( '|', $slot, 2 );
				$answers[ $field->key ] = array(
					'package'  => $parts[0] ?? '',
					'room'     => $parts[1] ?? '',
					'roommate' => (string) ( $raw['roommate'] ?? '' ),
				);
				continue;
			}
			if ( array_key_exists( $field->key, $post ) ) {
				$answers[ $field->key ] = $post[ $field->key ];
			}
		}

		return $answers;
	}

	/**
	 * @param array<string,mixed> $values
	 */
	private function extractEmail( FormSchema $schema, array $values ): string {
		foreach ( $schema->allFields() as $field ) {
			if ( FieldType::Email === $field->type && isset( $values[ $field->key ] ) ) {
				return (string) $values[ $field->key ];
			}
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $values
	 */
	private function extractName( FormSchema $schema, array $values, string $fallback ): string {
		foreach ( array( 'name', 'imie' ) as $key ) {
			if ( isset( $values[ $key ] ) && '' !== (string) $values[ $key ] ) {
				return (string) $values[ $key ];
			}
		}
		foreach ( $schema->allFields() as $field ) {
			if ( FieldType::Text === $field->type && isset( $values[ $field->key ] ) && '' !== (string) $values[ $field->key ] ) {
				return (string) $values[ $field->key ];
			}
		}

		return $fallback;
	}

	/**
	 * @param array<string,mixed> $values
	 */
	private function extractSelection( FormSchema $schema, array $values ): ?AccommodationSelection {
		foreach ( $schema->allFields() as $field ) {
			if ( FieldType::Accommodation !== $field->type ) {
				continue;
			}
			$sel = $values[ $field->key ] ?? null;
			if ( $sel instanceof AccommodationSelection ) {
				return $sel;
			}
			return null;
		}

		return null;
	}
}
```

Uwaga: `AccommodationValidator` (Plan 1) normalizuje odpowiedź pola `accommodation` do obiektu `AccommodationSelection` (lub `null`) w `values()`. Dlatego `extractSelection` czyta gotowy obiekt. Jeśli walidacja zwróciła coś innego niż `AccommodationSelection`, traktuj jako brak wyboru.

- [ ] **Step 4: Uruchom testy + narzędzia**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter SubmitHandlerTest
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=512M
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
```

Oczekiwane: 6 testów zielone, PHPStan/WPCS czyste.

- [ ] **Step 5: Commit**

```bash
git add src/Frontend/SubmitHandler.php tests/Integration/Frontend/SubmitHandlerTest.php
git commit -m "feat: add submission handler core with antispam, validation and reservation"
```

---

## Task 4: SubmitHandler::handle (hook + PRG + rate-limit) i magazyn wyniku

**Files:**
- Modify: `src/Frontend/SubmitHandler.php` (dodaj `handle`, `register`, magazyn, rate-limit)
- Modify: `event-registration.php` (rejestracja)
- Test: `tests/Integration/Frontend/SubmitRateLimitTest.php`

**Interfaces:**
- Produces:
  - `SubmitHandler::register(): void` — `add_action( 'template_redirect', ... )`
  - `SubmitHandler::handle(): void` — wykrywa POST (`evreg_submit` + nonce), rate-limit per IP, woła `process()`, na sukcesie PRG redirect, na błędzie zapisuje wynik do magazynu
  - `SubmitHandler::resultFor( int $eventId ): ?SubmitResult` — statyczny magazyn wyniku bieżącego żądania (czyta blok/shortcode w Task 5)
  - Rate-limit: transient per IP, hojny próg (np. 10/godz.)

- [ ] **Step 1: Napisz failujący test rate-limit**

`tests/Integration/Frontend/SubmitRateLimitTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Frontend;

use EvReg\Frontend\SubmitHandler;
use WP_UnitTestCase;

final class SubmitRateLimitTest extends WP_UnitTestCase {

	public function test_rate_limit_blocks_after_threshold(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';

		// Poniżej progu — dozwolone.
		for ( $i = 0; $i < SubmitHandler::RATE_LIMIT; $i++ ) {
			$this->assertFalse( SubmitHandler::isRateLimited(), "iteracja {$i}" );
			SubmitHandler::recordAttempt();
		}

		// Po progu — zablokowane.
		$this->assertTrue( SubmitHandler::isRateLimited() );
	}
}
```

- [ ] **Step 2: Uruchom i potwierdź fail**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter SubmitRateLimitTest
```

Oczekiwane: FAIL — brak `RATE_LIMIT`/`isRateLimited`.

- [ ] **Step 3: Dodaj hook, magazyn i rate-limit do `SubmitHandler`**

Dodaj stałe, statyczny magazyn i metody do klasy `SubmitHandler` (obok `process`):

```php
	public const RATE_LIMIT = 10;

	private const RATE_WINDOW = HOUR_IN_SECONDS;

	/** @var array<int,SubmitResult> */
	private static array $results = array();

	public static function register(): void {
		add_action(
			'template_redirect',
			static function (): void {
				( new self(
					new EventFormLoader( new \EvReg\Persistence\EventConfigRepository() ),
					new ReservationService( new \EvReg\Persistence\RegistrationRepository(), new \EvReg\Persistence\EventConfigRepository() )
				) )->handle();
			}
		);
	}

	public function handle(): void {
		if ( '1' !== (string) ( $_POST['evreg_submit'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		$event_id = isset( $_POST['evreg_event'] ) ? (int) $_POST['evreg_event'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! isset( $_POST['evreg-nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['evreg-nonce'] ) ), 'evreg_submit_' . $event_id ) ) {
			self::$results[ $event_id ] = SubmitResult::spam();
			return;
		}

		if ( self::isRateLimited() ) {
			self::$results[ $event_id ] = SubmitResult::spam();
			return;
		}
		self::recordAttempt();

		$post   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$result = $this->process( $event_id, is_array( $post ) ? $post : array() );

		if ( $result->isSuccess() ) {
			wp_safe_redirect( add_query_arg( 'evreg', $result->code(), remove_query_arg( array( 'evreg', 'evreg_confirm' ) ) ) );
			exit;
		}

		self::$results[ $event_id ] = $result;
	}

	public static function resultFor( int $event_id ): ?SubmitResult {
		return self::$results[ $event_id ] ?? null;
	}

	private static function rateKey(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		return 'evreg_rate_' . md5( $ip );
	}

	public static function isRateLimited(): bool {
		return (int) get_transient( self::rateKey() ) >= self::RATE_LIMIT;
	}

	public static function recordAttempt(): void {
		$key = self::rateKey();
		set_transient( $key, (int) get_transient( $key ) + 1, self::RATE_WINDOW );
	}
```

- [ ] **Step 4: Podepnij w `event-registration.php`**

```php
add_action( 'plugins_loaded', array( \EvReg\Frontend\SubmitHandler::class, 'register' ) );
```

- [ ] **Step 5: Uruchom testy**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter SubmitRateLimitTest
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=512M
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
```

Oczekiwane: zielone/czyste.

- [ ] **Step 6: Commit**

```bash
git add src/Frontend/SubmitHandler.php event-registration.php tests/Integration/Frontend/SubmitRateLimitTest.php
git commit -m "feat: wire submission handler on template_redirect with PRG and per-IP rate limit"
```

---

## Task 5: Block i Shortcode

**Files:**
- Create: `src/Frontend/Shortcode.php`
- Create: `src/Frontend/Block.php`
- Modify: `event-registration.php` (rejestracja)
- Test: `tests/Integration/Frontend/ShortcodeTest.php`

**Interfaces:**
- Consumes: `EventFormLoader`, `FormRenderer`, `SubmitHandler::resultFor`, `EventPostType::POST_TYPE`
- Produces:
  - `Shortcode::register(): void` (`add_shortcode( 'evreg_form', ... )`), `Shortcode::render( array $atts ): string`
  - `Block::register(): void` (`register_block_type` dynamiczny, `render_callback`)
  - Oba renderują: komunikat sukcesu, gdy `?evreg=reserved|waitlisted`; inaczej formularz (z ewentualnym `SubmitResult` z `resultFor`); enqueue `form.css`/`form.js` przy renderze

**Zasada:** wspólna logika renderu w prywatnym helperze (blok i shortcode ją wołają) — nie duplikować.

- [ ] **Step 1: Napisz failujący test shortcode**

`tests/Integration/Frontend/ShortcodeTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Frontend;

use EvReg\Frontend\Shortcode;
use EvReg\Persistence\EventConfigRepository;
use WP_UnitTestCase;

final class ShortcodeTest extends WP_UnitTestCase {

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		$this->event_id = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
		( new EventConfigRepository() )->save(
			$this->event_id,
			array(
				'schema' => array(
					'version'  => 1,
					'sections' => array(
						array(
							'key'    => 'dane',
							'title'  => 'Dane',
							'fields' => array(
								array( 'key' => '__type', 'type' => 'radio', 'label' => 'Typ' ),
								array( 'key' => 'email', 'type' => 'email', 'label' => 'E-mail', 'required' => true ),
							),
						),
					),
				),
				'types'         => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 0.0 ) ),
				'accommodation' => array( 'packages' => array(), 'rooms' => array(), 'inventory' => array() ),
			)
		);
	}

	public function test_shortcode_renders_form_for_event(): void {
		$html = Shortcode::render( array( 'event' => (string) $this->event_id ) );

		$this->assertStringContainsString( '<form', $html );
		$this->assertStringContainsString( 'name="email"', $html );
	}

	public function test_shortcode_shows_success_message_after_reserved(): void {
		$_GET['evreg'] = 'reserved';

		$html = Shortcode::render( array( 'event' => (string) $this->event_id ) );

		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringContainsString( 'evreg-success', $html );

		unset( $_GET['evreg'] );
	}

	public function test_shortcode_without_valid_event_renders_nothing_useful(): void {
		$html = Shortcode::render( array( 'event' => '0' ) );

		$this->assertStringNotContainsString( '<form', $html );
	}
}
```

- [ ] **Step 2: Uruchom i potwierdź fail**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter ShortcodeTest
```

Oczekiwane: FAIL — brak klasy.

- [ ] **Step 3: Zaimplementuj `Shortcode`**

```php
<?php

declare( strict_types=1 );

namespace EvReg\Frontend;

use EvReg\Persistence\EventConfigRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode [evreg_form event="ID"] osadzający formularz rejestracji.
 */
final class Shortcode {

	public static function register(): void {
		add_shortcode( 'evreg_form', array( self::class, 'render' ) );
	}

	/**
	 * @param array<string,mixed>|string $atts
	 */
	public static function render( $atts ): string {
		$atts     = shortcode_atts( array( 'event' => '0' ), is_array( $atts ) ? $atts : array(), 'evreg_form' );
		$event_id = (int) $atts['event'];

		return self::renderForm( $event_id );
	}

	public static function renderForm( int $event_id ): string {
		if ( $event_id <= 0 ) {
			return '';
		}

		$success = isset( $_GET['evreg'] ) ? sanitize_key( (string) $_GET['evreg'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'reserved' === $success || 'waitlisted' === $success ) {
			return self::successMessage( $success );
		}

		$schema = ( new EventFormLoader( new EventConfigRepository() ) )->load( $event_id );
		if ( null === $schema ) {
			return '<p class="evreg-unavailable">' . esc_html__( 'Rejestracja jest niedostępna.', 'event-registration' ) . '</p>';
		}

		wp_enqueue_style( 'evreg-public' );
		wp_enqueue_script( 'evreg-public' );

		$result = SubmitHandler::resultFor( $event_id );
		$notice = null === $result ? '' : self::resultNotice( $result );

		return $notice . ( new FormRenderer() )->render( $schema, $event_id, $result );
	}

	private static function successMessage( string $code ): string {
		$text = 'waitlisted' === $code
			? __( 'Jesteś na liście rezerwowej. Poinformujemy Cię, gdy zwolni się miejsce.', 'event-registration' )
			: __( 'Dziękujemy! Sprawdź e-mail i potwierdź zgłoszenie.', 'event-registration' );

		return '<div class="evreg-success">' . esc_html( $text ) . '</div>';
	}

	private static function resultNotice( SubmitResult $result ): string {
		$map = array(
			'duplicate'    => __( 'Jesteś już zapisany na to wydarzenie.', 'event-registration' ),
			'rejected'     => __( 'Brak wolnych miejsc.', 'event-registration' ),
			'spam'         => __( 'Nie udało się wysłać zgłoszenia. Spróbuj ponownie.', 'event-registration' ),
			'config_error' => __( 'Formularz jest nieprawidłowo skonfigurowany.', 'event-registration' ),
			'invalid'      => __( 'Popraw zaznaczone pola.', 'event-registration' ),
		);
		$text = $map[ $result->code() ] ?? '';

		return '' === $text ? '' : '<div class="evreg-notice">' . esc_html( $text ) . '</div>';
	}
}
```

- [ ] **Step 4: Zaimplementuj `Block`**

`Block` rejestruje dynamiczny typ bloku wskazujący event; `render_callback` woła `Shortcode::renderForm( $eventId )`. Minimalny blok bez własnego edytora JS — atrybut `eventId` (number) ustawiany w inspektorze przez `@wordpress/scripts`? Aby nie dokładać buildu publicznego bloku, zarejestruj blok serwerowo z atrybutem i renderem, a wybór eventu przez pole atrybutu. Jeśli pełny blok-edytor wykracza poza zakres, **shortcode jest ścieżką podstawową**; blok minimalny:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Dynamiczny blok osadzający formularz rejestracji eventu.
 */
final class Block {

	public static function register(): void {
		add_action( 'init', array( self::class, 'register_block' ) );
	}

	public static function register_block(): void {
		register_block_type(
			'evreg/form',
			array(
				'api_version'     => 3,
				'title'           => __( 'Formularz rejestracji', 'event-registration' ),
				'category'        => 'widgets',
				'attributes'      => array(
					'eventId' => array( 'type' => 'number', 'default' => 0 ),
				),
				'render_callback' => array( self::class, 'render' ),
			)
		);
	}

	/**
	 * @param array<string,mixed> $attributes
	 */
	public static function render( array $attributes ): string {
		return Shortcode::renderForm( (int) ( $attributes['eventId'] ?? 0 ) );
	}
}
```

Uwaga: blok bez pola edytora JS pozwoli wpisać `eventId` tylko przez edytor kodu bloku. Pełny inspektor (React) to opcjonalny polish — shortcode `[evreg_form event="ID"]` jest ścieżką podstawową i to on jest testowany oraz używany w E2E.

- [ ] **Step 5: Zarejestruj styl/skrypt i klasy w `event-registration.php`**

Dodaj rejestracje (assety właściwe powstają w Task 7 — tu rejestrujemy handle, by `wp_enqueue_*` działało; pliki dołączy Task 7):

```php
add_action( 'plugins_loaded', array( \EvReg\Frontend\Shortcode::class, 'register' ) );
add_action( 'plugins_loaded', array( \EvReg\Frontend\Block::class, 'register' ) );
```

Zarejestruj też handle assetów TU (pliki `assets/public/*` dołączy Task 7 — rejestracja handle URL-a nie wymaga istnienia pliku w teście; przeglądarka wczyta go dopiero po Tasku 7):

```php
add_action(
	'init',
	static function (): void {
		$url = plugin_dir_url( \EvReg\Plugin::plugin_file() );
		wp_register_style( 'evreg-public', $url . 'assets/public/form.css', array(), \EvReg\Plugin::VERSION );
		wp_register_script( 'evreg-public', $url . 'assets/public/form.js', array(), \EvReg\Plugin::VERSION, true );
	}
);
```

Dzięki temu `Shortcode::renderForm` (`wp_enqueue_style/script('evreg-public')`) działa bez ostrzeżeń w `ShortcodeTest`.

- [ ] **Step 6: Uruchom testy + narzędzia**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter ShortcodeTest
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=512M
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
```

Oczekiwane: 3 testy zielone (handle `evreg-public` zarejestrowany w Step 5, więc enqueue nie ostrzega).

- [ ] **Step 7: Commit**

```bash
git add src/Frontend/Shortcode.php src/Frontend/Block.php event-registration.php tests/Integration/Frontend/ShortcodeTest.php
git commit -m "feat: add form shortcode and dynamic block embedding the registration form"
```

---

## Task 6: ConfirmationController

**Files:**
- Create: `src/Frontend/ConfirmationController.php`
- Modify: `event-registration.php` (rejestracja)
- Test: `tests/Integration/Frontend/ConfirmationControllerTest.php`

**Interfaces:**
- Consumes: `ReservationService::confirm`
- Produces:
  - `ConfirmationController::register(): void` (`template_redirect`)
  - `ConfirmationController::handle(): void` — wykrywa `?evreg_confirm=token`, woła `confirm`, PRG redirect na `?evreg_confirmed=<kod>`
  - `ConfirmationController::confirmedMessage( string $code ): string` — komunikat wg kodu (do renderu na stronie)

**Zasada:** `handle()` robi PRG (redirect) — testujemy `resolve( string $token ): string` (zwraca kod) zamiast redirectu, oraz `confirmedMessage`. Redirect weryfikuje E2E + kontroler.

- [ ] **Step 1: Napisz failujący test**

`tests/Integration/Frontend/ConfirmationControllerTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Frontend;

use EvReg\Frontend\ConfirmationController;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Services\ReservationService;
use WP_UnitTestCase;

final class ConfirmationControllerTest extends WP_UnitTestCase {

	private ConfirmationController $controller;

	private RegistrationRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'locks' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$this->repository = new RegistrationRepository();
		$this->controller = new ConfirmationController(
			new ReservationService( $this->repository, new EventConfigRepository() )
		);
	}

	public function test_resolve_confirms_pending_token(): void {
		$token = bin2hex( random_bytes( 16 ) );
		$this->repository->insertRegistration(
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
			)
		);

		$this->assertSame( 'confirmed', $this->controller->resolve( $token ) );
		$this->assertSame( 'confirmed', $this->repository->findByToken( $token )['status'] );
	}

	public function test_resolve_unknown_token_is_not_found(): void {
		$this->assertSame( 'not_found', $this->controller->resolve( 'brak' ) );
	}

	public function test_confirmed_message_nonempty_for_each_code(): void {
		foreach ( array( 'confirmed', 'already_confirmed', 'expired', 'waitlist', 'not_found' ) as $code ) {
			$this->assertNotSame( '', ConfirmationController::confirmedMessage( $code ) );
		}
	}
}
```

- [ ] **Step 2: Uruchom i potwierdź fail**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter ConfirmationControllerTest
```

Oczekiwane: FAIL — brak klasy.

- [ ] **Step 3: Zaimplementuj `ConfirmationController`**

```php
<?php

declare( strict_types=1 );

namespace EvReg\Frontend;

use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Services\ReservationService;

defined( 'ABSPATH' ) || exit;

/**
 * Endpoint potwierdzenia double opt-in.
 */
final class ConfirmationController {

	public function __construct( private readonly ReservationService $reservations ) {
	}

	public static function register(): void {
		add_action(
			'template_redirect',
			static function (): void {
				( new self( new ReservationService( new RegistrationRepository(), new EventConfigRepository() ) ) )->handle();
			}
		);
	}

	public function handle(): void {
		if ( ! isset( $_GET['evreg_confirm'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$token = sanitize_text_field( wp_unslash( (string) $_GET['evreg_confirm'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code  = $this->resolve( $token );

		wp_safe_redirect( add_query_arg( 'evreg_confirmed', $code, remove_query_arg( array( 'evreg_confirm' ) ) ) );
		exit;
	}

	public function resolve( string $token ): string {
		return $this->reservations->confirm( $token )->code;
	}

	public static function confirmedMessage( string $code ): string {
		$map = array(
			'confirmed'         => __( 'Zgłoszenie potwierdzone. Do zobaczenia!', 'event-registration' ),
			'already_confirmed' => __( 'To zgłoszenie było już potwierdzone.', 'event-registration' ),
			'expired'           => __( 'Link potwierdzający wygasł.', 'event-registration' ),
			'waitlist'          => __( 'Jesteś na liście rezerwowej.', 'event-registration' ),
			'not_found'         => __( 'Nie znaleziono zgłoszenia dla tego linku.', 'event-registration' ),
		);

		return $map[ $code ] ?? __( 'Nieznany status potwierdzenia.', 'event-registration' );
	}
}
```

- [ ] **Step 4: Podepnij w `event-registration.php`**

```php
add_action( 'plugins_loaded', array( \EvReg\Frontend\ConfirmationController::class, 'register' ) );
```

- [ ] **Step 5: Uruchom testy + narzędzia; Commit**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter ConfirmationControllerTest
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=512M
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
git add src/Frontend/ConfirmationController.php event-registration.php tests/Integration/Frontend/ConfirmationControllerTest.php
git commit -m "feat: add double opt-in confirmation endpoint"
```

---

## Task 7: Publiczny JS, CSS i enqueue

**Files:**
- Create: `assets/public/form.js`
- Create: `assets/public/form.css`

**Interfaces:**
- Consumes: handle `evreg-public` zarejestrowany w Task 5 (wskazuje na te pliki)
- Produces: `form.js` chowa/pokazuje sekcje z `data-evreg-when-*` wg wartości pola `__type`; `form.css` izolowany styl

Uwaga: rejestracja handle (`wp_register_style/script('evreg-public', ...)`) powstała już w Task 5. Ten task tworzy tylko pliki, na które handle wskazuje.

- [ ] **Step 1: Utwórz `assets/public/form.css`**

```css
.evreg-form { max-width: 640px; }
.evreg-section { border: 1px solid var(--evreg-border, #ddd); padding: 1rem; margin: 0 0 1rem; }
.evreg-section > legend { font-weight: 600; padding: 0 .5rem; }
.evreg-field { margin: 0 0 .75rem; }
.evreg-label { display: block; margin-bottom: .25rem; }
.evreg-required { color: var(--evreg-required, #c00); }
.evreg-field input[type="text"],
.evreg-field input[type="email"],
.evreg-field input[type="tel"],
.evreg-field input[type="number"],
.evreg-field input[type="date"],
.evreg-field textarea,
.evreg-field select { width: 100%; box-sizing: border-box; }
.evreg-field-error input,
.evreg-field-error textarea,
.evreg-field-error select { border-color: var(--evreg-required, #c00); }
.evreg-error-msg { color: var(--evreg-required, #c00); font-size: .875rem; display: block; }
.evreg-choice { display: block; }
.evreg-submit { margin-top: 1rem; }
.evreg-success, .evreg-notice { padding: .75rem 1rem; margin: 0 0 1rem; border-left: 4px solid var(--evreg-accent, #2271b1); background: #f6f7f7; }
.evreg-section[hidden] { display: none; }
```

- [ ] **Step 2: Utwórz `assets/public/form.js`**

```js
( function () {
	function currentType( form ) {
		var checked = form.querySelector( 'input[name="__type"]:checked' );
		return checked ? checked.value : '';
	}

	function matches( type, operator, rawValue ) {
		var value;
		try { value = JSON.parse( rawValue ); } catch ( e ) { value = rawValue; }
		var list = Array.isArray( value ) ? value.map( String ) : [ String( value ) ];
		switch ( operator ) {
			case 'in': return list.indexOf( type ) !== -1;
			case 'not_in': return list.indexOf( type ) === -1;
			case 'equals': return String( value ) === type;
			case 'not_equals': return String( value ) !== type;
			case 'empty': return type === '';
			case 'not_empty': return type !== '';
			default: return true;
		}
	}

	function apply( form ) {
		var type = currentType( form );
		form.querySelectorAll( '[data-evreg-when-field]' ).forEach( function ( section ) {
			if ( section.getAttribute( 'data-evreg-when-field' ) !== '__type' ) { return; }
			var visible = matches(
				type,
				section.getAttribute( 'data-evreg-when-operator' ),
				section.getAttribute( 'data-evreg-when-value' )
			);
			section.hidden = ! visible;
		} );
	}

	document.querySelectorAll( '.evreg-form' ).forEach( function ( form ) {
		apply( form );
		form.addEventListener( 'change', function ( e ) {
			if ( e.target && e.target.name === '__type' ) { apply( form ); }
		} );
	} );
}() );
```

- [ ] **Step 3: Weryfikacja**

Handle `evreg-public` jest już zarejestrowany (Task 5). Utworzenie plików sprawia, że wskazany URL istnieje. Uruchom pełną suitę integracyjną i phpcs:

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
```

Oczekiwane: suita zielona; phpcs czysty (pliki JS/CSS nie są skanowane PHPCS wg `phpcs.xml.dist`). Kontroler zweryfikuje w przeglądarce, że warunki JS chowają/pokazują sekcje.

- [ ] **Step 4: Commit**

```bash
git add assets/public
git commit -m "feat: add public form styles and conditional-visibility script"
```

---

## Task 8: Ścieżka E2E Playwright

**Files:**
- Create: `tests/e2e/public-form.spec.js`
- (Reużywa `playwright.config.js` z Planu 2B)

**Interfaces:**
- Consumes: działająca wp-env (localhost:8891), skonfigurowany event z shortcode na stronie
- Produces: ścieżka happy-path: wypełnij formularz → submit → `pending` → potwierdź linkiem → `confirmed`

**Zasada:** izolowany task. Jeśli toolchain Playwright się opiera — BLOCKED z błędem, nie obchodź. Setup eventu i strony przez WP-CLI w `beforeAll` (lub przez UI admina z 2B).

- [ ] **Step 1: Przygotuj fixture przez WP-CLI (w spec `beforeAll` lub globalny setup)**

Ścieżka tworzy: event `evreg_event` z konfiguracją (schema z `__type`+email, typ, cap), oraz stronę zawierającą `[evreg_form event="<id>"]`. Użyj `wp post create`/`wp post meta set` przez `node scripts/wp-env.cjs run cli ...` w kroku setup skryptu lub ręcznie przed uruchomieniem. Ponieważ Playwright działa na hoście, setup wykonaj komendą przed `npm run test:e2e` i przekaż ID strony przez zmienną środowiskową lub twardo w spec po utworzeniu.

Najprościej: w spec `test.beforeAll` wywołaj `execSync` z `node scripts/wp-env.cjs run cli -- wp ...`, tworząc event, zapisując meta `_evreg_schema`/`_evreg_types`/`_evreg_settings` (JSON) i stronę z shortcode; zwróć slug strony.

- [ ] **Step 2: Napisz ścieżkę happy-path**

`tests/e2e/public-form.spec.js`:

```js
const { test, expect } = require( '@playwright/test' );
const { execSync } = require( 'child_process' );

function wp( args ) {
	const cmd = 'node scripts/wp-env.cjs run cli --env-cwd=wp-content/plugins/event-registration -- wp ' + args;
	return execSync( cmd, { encoding: 'utf8' } ).trim();
}

let pagePath;

test.beforeAll( () => {
	const schema = JSON.stringify( {
		version: 1,
		sections: [ {
			key: 'dane', title: 'Dane', fields: [
				{ key: '__type', type: 'radio', label: 'Typ' },
				{ key: 'imie', type: 'text', label: 'Imię', required: true },
				{ key: 'email', type: 'email', label: 'E-mail', required: true },
			],
		} ],
	} );
	const types = JSON.stringify( [ { key: 'uczestnik', label: 'Uczestnik', price: 0 } ] );
	const settings = JSON.stringify( { global_cap: null, waitlist_enabled: true } );

	const eventId = wp( `post create --post_type=evreg_event --post_status=publish --post_title="E2E Event" --porcelain` ).split( '\n' ).pop();
	// Zapis meta jako JSON (single-quoted, by uniknąć interpolacji powłoki — patrz uwaga).
	wp( `post meta update ${ eventId } _evreg_schema '${ schema }'` );
	wp( `post meta update ${ eventId } _evreg_types '${ types }'` );
	wp( `post meta update ${ eventId } _evreg_settings '${ settings }'` );

	const pageId = wp( `post create --post_type=page --post_status=publish --post_title="Zapisy" --post_content="[evreg_form event=\\"${ eventId }\\"]" --porcelain` ).split( '\n' ).pop();
	pagePath = '/?page_id=' + pageId;
} );

test( 'uczestnik wypełnia formularz, wysyła i potwierdza', async ( { page } ) => {
	await page.goto( pagePath );
	await expect( page.locator( '.evreg-form' ) ).toBeVisible();

	await page.check( 'input[name="__type"][value="uczestnik"]' );
	await page.fill( 'input[name="imie"]', 'Jan Testowy' );
	await page.fill( 'input[name="email"]', 'e2e@example.com' );

	// Odczekaj min. czas wypełnienia (antyspam ≥ 3 s).
	await page.waitForTimeout( 3500 );
	await page.getByRole( 'button', { name: 'Wyślij zgłoszenie' } ).click();

	await expect( page.locator( '.evreg-success' ) ).toBeVisible();

	// Pobierz token i potwierdź.
	const token = execSync(
		'node scripts/wp-env.cjs run cli --env-cwd=wp-content/plugins/event-registration -- wp db query "SELECT token FROM $(node scripts/wp-env.cjs run cli --env-cwd=wp-content/plugins/event-registration -- wp db prefix --allow-root 2>/dev/null | tr -d \'\\r\\n\')evreg_registrations WHERE email=\'e2e@example.com\'" --skip-column-names',
		{ encoding: 'utf8' }
	).trim().split( '\n' ).pop().trim();

	await page.goto( '/?evreg_confirm=' + token );
	await expect( page ).toHaveURL( /evreg_confirmed=confirmed/ );
} );
```

Uwaga o cudzysłowach: przekazywanie JSON z meta przez powłokę jest wrażliwe. Jeśli `post meta update` z inline JSON zawodzi na cytowaniu, zapisz JSON do pliku tymczasowego i użyj `wp post meta update <id> <key> "$(cat plik)"`, albo `wp eval` z `update_post_meta`. Pobranie tokenu: alternatywnie `wp post list`/`wp db query` z pełną nazwą tabeli (prefiks `wp_`). Dostosuj do realiów wp-env; jeśli setup przez CLI jest zbyt kruchy, utwórz event i stronę raz ręcznie i wskaż `pagePath` twardo.

- [ ] **Step 3: Zbuduj (admin) i uruchom E2E**

```bash
npm run build
node scripts/wp-env.cjs start
npm run test:e2e
```

Oczekiwane: ścieżka zielona (formularz → sukces → potwierdzenie). Jeśli setup CLI/cytowanie JSON się opiera lub Playwright nie startuje — BLOCKED z dokładnym błędem; nie obchodź. Ścieżka E2E jest izolowana; reszta Planu 3B dowieziona i zweryfikowana testami integracyjnymi + weryfikacją kontrolera w przeglądarce.

- [ ] **Step 4: Commit**

```bash
git add tests/e2e/public-form.spec.js
git commit -m "test: add Playwright e2e for public registration and confirmation"
```

---

## Definicja ukończenia Planu 3B

- [ ] `EventFormLoader` składa schemę eventu do renderu/walidacji
- [ ] `FormRenderer` renderuje wszystkie typy pól, escapuje, re-renderuje błędy z zachowaniem wartości, wypisuje honeypot/nonce/hidden
- [ ] `SubmitHandler::process` — antyspam + walidacja + ekstrakcja email/name/typu/noclegu + rezerwacja; `handle` — hook, PRG, rate-limit
- [ ] `Block` + `Shortcode` osadzają formularz; komunikat sukcesu po PRG
- [ ] `ConfirmationController` — token → confirmed z PRG
- [ ] `form.js` chowa/pokazuje sekcje wg `__type`; `form.css` izolowany
- [ ] Suita integracyjna zielona, PHPStan 6 i WPCS czyste, ścieżka E2E zielona (lub udokumentowany BLOCKED toolchainu)
- [ ] Kontroler zweryfikował formularz w przeglądarce (render, submit, błąd+re-render, potwierdzenie)

## Czego Plan 3B świadomie nie robi

Brak wysyłki maila niosącego link potwierdzający (Plan 4 — token i endpoint już działają). Brak walidacji inline i licznika miejsc na żywo (polish). Brak pełnego inspektora bloku w React (shortcode jest ścieżką podstawową; blok minimalny z atrybutem `eventId`). Brak automatycznej promocji z waitlisty (Plan 5).
