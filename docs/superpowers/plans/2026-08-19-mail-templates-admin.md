# Mail Template Editor (Plan 4B-Templates) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dać organizatorowi edycję treści pięciu maili transakcyjnych per event (piąta zakładka React zapisująca `_evreg_mail_templates` przez osobny endpoint REST), i zamknąć dwie luki z 4A: mismatch klucza `organizer_emails`/`notify_emails` oraz brak pola `form_page_id` w UI.

**Architecture:** Nowy `MailTemplateController` (GET/POST `evreg/v1/events/<id>/mail-templates`) sanityzuje treść przez `sanitize_textarea_field` i zapisuje przez rozszerzone `MailTemplateRepository::save()` (z `wp_slash`). Piąta zakładka React (`MailTemplatesTab`) edytuje szablony; logika mutacji w czystym `ops/mailTemplateOps.js` (jest). Globalny „Zapisz" w `App.jsx` orkiestruje PUT configu + POST szablonów. `SettingsTab` dostaje fix klucza i dropdown stron. Serwer jest arbitrem sanityzacji; klient lustrem.

**Tech Stack:** PHP 8.1, WordPress 6.4+, REST `evreg/v1`, React (`@wordpress/element`/`components`/`api-fetch`/`i18n`), webpack (`wp-scripts`), jest (moduły `ops/`), PHPUnit integration (wp-env).

**Spec:** `docs/superpowers/specs/2026-08-19-mail-templates-admin-design.md`

**Gałąź:** `plan-4b-templates` od `master`.

## Global Constraints

- Minimalne PHP 8.1, minimalne WordPress 6.4.
- Namespace `EvReg\`, PSR-4, `src/`. Prefiks hooków/meta `evreg_`/`_evreg_`. REST `evreg/v1`.
- Każdy plik PHP poza `src/Domain/` i `tests/` zaczyna od `defined( 'ABSPATH' ) || exit;`. (Ten plan nie dotyka `src/Domain/`.)
- **Serwer jest arbitrem sanityzacji i whitelisty typów.** Klient tylko lustrem. `body` przez `sanitize_textarea_field` (zachowuje `\n`), `subject` przez `sanitize_text_field`. Whitelist typów z `DefaultTemplates::keys()`.
- **`wp_slash` przy każdym `update_post_meta` z treścią JSON** — bez tego `wp_unslash` w środku `update_post_meta` psuje polskie znaki i `\n`. (Pułapka naprawiona w 4A dla `EventConfigRepository`.)
- **React: cała logika mutacji w czystych modułach `assets/admin/ops/*` testowanych `jest`.** Komponenty cienkie — tylko wołają ops i renderują. Ops nie mutują wejścia (zwracają nowe obiekty).
- **Wszystkie stringi UI (PHP i JS) przez i18n**, text domain `event-registration`. W JS `__()` z `@wordpress/i18n`.
- `permission_callback` na każdej trasie REST: `Capabilities::CAP` **oraz** `current_user_can( 'edit_post', $id )`.
- Styl PHP jak w repo: tabulatory, `array()` zamiast `[]`, warunki Yody, docblock `@param`/`@return` na metodach publicznych. Styl JS jak istniejące `ops/`/`tabs/` (spacje w nawiasach, `import` z `@wordpress/*`).
- PHPStan poziom 6, bez nowych `@phpstan-ignore`. `phpcs` czysto pod istniejącym `phpcs.xml.dist`.
- Katalogi testów PHP wielką literą (`tests/Integration/Rest/`, `tests/Integration/Persistence/`), suity małą.
- `build/` ignorowane w gicie — commituj źródła `assets/`, nie artefakty. `.gitattributes` wymusza LF.
- Commity po każdym tasku, po angielsku, Conventional Commits.

### Komendy referencyjne

```bash
# JS (host, bez kontenera)
npm run test:js          # jest, moduły ops/
npm run build            # webpack → build/admin/ (potrzebne do weryfikacji w przeglądarce)

# PHP (kontener przez wrapper)
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs src
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=512M
```

Host nie ma PHP — komendy PHP wyłącznie przez `node scripts/wp-env.cjs`. Nie wołaj gołego `npx wp-env`. **phpcs tylko na ścieżkach `src`** (nie `tests/...` — `phpcs.xml.dist` nie skanuje testów). PHPStan wymaga `--memory-limit=512M`. Konsola PHPUnit zniekształca komunikat niezłapanego `Error` — fazę RED weryfikuj po nazwie klasy błędu. Weryfikacja w przeglądarce: kontroler (człowiek), nie subagent.

### Interfejsy z Planów 2/4A (konsumowane — sygnatury zweryfikowane w kodzie)

- `EvReg\Rest\EventConfigController` — `REST_NAMESPACE = 'evreg/v1'`, `register()` (na `rest_api_init`), `can_edit( WP_REST_Request ): bool|WP_Error` (wzorzec do skopiowania), trasa `/events/(?P<id>\d+)/config`
- `EvReg\Admin\Capabilities::CAP = 'edit_evreg_events'`, `Capabilities::grant()`
- `EvReg\Admin\EventPostType::POST_TYPE = 'evreg_event'`
- `EvReg\Admin\EventConfigAssets` — `HANDLE`, `enqueue( string $hook_suffix )` woła `wp_localize_script( self::HANDLE, 'evregAdmin', array( 'eventId' => $event_id ) )`
- `EvReg\Mail\DefaultTemplates` — `KEY_OPTIN='optin'`, `KEY_CONFIRMED='confirmed'`, `KEY_WAITLIST='waitlist'`, `KEY_EXPIRED='expired'`, `KEY_ADMIN_NEW='admin_new'`; `static keys(): array<int,string>`; `static get( string ): array{subject:string,body:string}`
- `EvReg\Persistence\MailTemplateRepository` — `META_KEY='_evreg_mail_templates'`, `get( int ): array<string,array<string,string>>` (ten plan dokłada `save()`)
- `EvReg\Mail\Subscriber` — czyta `_evreg_settings['notify_emails']` (tablica albo lista po przecinku), fallback `get_option('admin_email')`; `PlaceholderFactory` czyta `_evreg_settings['form_page_id']` (int)
- `EvReg\Plugin` — `plugin_file()`, `VERSION`, `TEXT_DOMAIN='event-registration'`
- React: `assets/admin/App.jsx` (stan `config`, `update(key)`, `TabPanel`, `TabRouter`), `assets/admin/api.js` (`loadConfig`/`saveConfig` przez `@wordpress/api-fetch`), `assets/admin/ops/typesOps.js` (wzorzec czystego modułu: `updateType` zwraca nowy obiekt, nie mutuje), `assets/admin/tabs/SettingsTab.jsx` (dziś zapisuje `organizer_emails` — do zmiany)

---

## File Structure

**Nowe pliki produkcyjne**

| Plik | Odpowiedzialność |
|---|---|
| `src/Rest/MailTemplateController.php` | Endpoint GET/POST szablonów; sanityzacja `subject`/`body`; whitelist typów; `can_edit` |
| `assets/admin/ops/mailTemplateOps.js` | Czysta logika: `TEMPLATE_TYPES`, `PLACEHOLDERS_BY_TYPE`, `setField`, `normalizeForSave`, `mergeLoaded` |
| `assets/admin/tabs/MailTemplatesTab.jsx` | Piąta zakładka (cienka): 5 typów × temat+treść + ściąga placeholderów |

**Modyfikowane pliki produkcyjne**

| Plik | Zmiana |
|---|---|
| `src/Persistence/MailTemplateRepository.php` | `save( int, array ): void` z `wp_slash`; pusty wynik kasuje meta |
| `src/Admin/EventConfigAssets.php` | Dołożenie `pages` (opublikowane strony) do `wp_localize_script` |
| `assets/admin/api.js` | `loadTemplates( id )` / `saveTemplates( id, templates )` |
| `assets/admin/App.jsx` | Piąta zakładka; load szablonów; globalny „Zapisz" orkiestruje oba wywołania |
| `assets/admin/tabs/SettingsTab.jsx` | `organizer_emails` → `notify_emails`; nowe pole `form_page_id` (`SelectControl`) |
| `event-registration.php` | Rejestracja `MailTemplateController::register` na `plugins_loaded` |

**Nowe pliki testowe**

`assets/admin/ops/mailTemplateOps.test.js`,
`tests/Integration/Persistence/MailTemplateRepositorySaveTest.php`,
`tests/Integration/Rest/MailTemplateControllerTest.php`,
`tests/Integration/Mail/NotifyEmailsWiringTest.php`.

**Świadomie nietykane:** `EventConfigController` (osobny endpoint — jego kontrakt i testy zostają), `src/Mail/*` (4A gotowe; ten plan tylko czyta ich kontrakty), `phpcs.xml.dist`, `jest.config.js`.

---
## Task 1: MailTemplateRepository::save()

**Files:**
- Modify: `src/Persistence/MailTemplateRepository.php`
- Test: `tests/Integration/Persistence/MailTemplateRepositorySaveTest.php`

**Interfaces:**
- Consumes: `MailTemplateRepository::get()`, `META_KEY`, `update_post_meta`, `delete_post_meta`, `wp_slash`, `wp_json_encode`
- Produces: `MailTemplateRepository::save( int $event_id, array<string,array<string,string>> $templates ): void`

Zasady: normalizuje do znanego kształtu (tylko klucze łańcuchowe, pola `subject`/`body` łańcuchowe i niepuste); typ bez żadnego niepustego pola wypada; wynik pusty → `delete_post_meta`; wynik niepusty → `update_post_meta` z `wp_slash( wp_json_encode(...) )`.

- [ ] **Step 1: Napisz test (RED)**

`tests/Integration/Persistence/MailTemplateRepositorySaveTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Persistence;

use EvReg\Persistence\MailTemplateRepository;
use WP_UnitTestCase;

final class MailTemplateRepositorySaveTest extends WP_UnitTestCase {

	private MailTemplateRepository $repository;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		$this->repository = new MailTemplateRepository();
		$this->event_id   = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
	}

	public function test_save_then_get_round_trips(): void {
		$this->repository->save(
			$this->event_id,
			array( 'optin' => array( 'subject' => 'Temat', 'body' => "Linia 1\nLinia 2" ) )
		);

		$templates = $this->repository->get( $this->event_id );

		$this->assertSame( 'Temat', $templates['optin']['subject'] );
		$this->assertSame( "Linia 1\nLinia 2", $templates['optin']['body'] );
	}

	public function test_save_preserves_non_ascii_and_newlines(): void {
		$body = "Cześć Michał,\n\npozdrawiamy – zespół.";
		$this->repository->save( $this->event_id, array( 'confirmed' => array( 'subject' => 'Zażółć', 'body' => $body ) ) );

		$templates = $this->repository->get( $this->event_id );

		$this->assertSame( 'Zażółć', $templates['confirmed']['subject'] );
		$this->assertSame( $body, $templates['confirmed']['body'] );
	}

	public function test_save_drops_empty_fields_and_types(): void {
		$this->repository->save(
			$this->event_id,
			array(
				'optin'     => array( 'subject' => 'Tylko temat', 'body' => '' ),
				'confirmed' => array( 'subject' => '', 'body' => '' ),
			)
		);

		$templates = $this->repository->get( $this->event_id );

		$this->assertSame( array( 'subject' => 'Tylko temat' ), $templates['optin'] );
		$this->assertArrayNotHasKey( 'confirmed', $templates );
	}

	public function test_save_empty_result_deletes_meta(): void {
		$this->repository->save( $this->event_id, array( 'optin' => array( 'subject' => 'X', 'body' => '' ) ) );
		$this->repository->save( $this->event_id, array( 'optin' => array( 'subject' => '', 'body' => '' ) ) );

		$this->assertSame( array(), $this->repository->get( $this->event_id ) );
		$this->assertSame( '', get_post_meta( $this->event_id, MailTemplateRepository::META_KEY, true ) );
	}

	public function test_save_ignores_non_string_fields(): void {
		// Celowo brudne wejście — save() musi odsiać nie-łańcuchowe pola i nie-łańcuchowe klucze.
		// (PHPStan skanuje tylko src/, nie tests/ — brak potrzeby ignore.)
		$this->repository->save(
			$this->event_id,
			array( 'optin' => array( 'subject' => 'OK', 'body' => 123 ), 5 => array( 'subject' => 'zły klucz' ) )
		);

		$templates = $this->repository->get( $this->event_id );

		$this->assertSame( array( 'subject' => 'OK' ), $templates['optin'] );
		$this->assertArrayNotHasKey( 5, $templates );
	}
}
```

Uwaga: `save()` nie robi whitelisty typów — to zadanie kontrolera (Task 2). `save()` odsiewa tylko strukturę (klucze łańcuchowe, pola łańcuchowe niepuste). Test `test_save_ignores_non_string_fields` pilnuje odsiania struktury, nie nazw typów.

- [ ] **Step 2: Uruchom test i potwierdź RED**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter MailTemplateRepositorySaveTest
```
Expected: FAIL — `Call to undefined method EvReg\Persistence\MailTemplateRepository::save()`.

- [ ] **Step 3: Zaimplementuj save()**

W `src/Persistence/MailTemplateRepository.php`, po metodzie `get()` (przed `normalize()`), dodaj:

```php
	/**
	 * Zapisuje nadpisania szablonów eventu. Puste pola i typy są odsiewane;
	 * pusty wynik kasuje meta.
	 *
	 * @param int                                 $event_id  ID posta eventu.
	 * @param array<string,array<string,string>>  $templates Surowe nadpisania z żądania.
	 */
	public function save( int $event_id, array $templates ): void {
		$clean = array();

		foreach ( $templates as $key => $template ) {
			if ( ! is_string( $key ) || ! is_array( $template ) ) {
				continue;
			}

			$fields = array();

			foreach ( array( 'subject', 'body' ) as $field ) {
				$value = $template[ $field ] ?? '';

				if ( is_string( $value ) && '' !== $value ) {
					$fields[ $field ] = $value;
				}
			}

			if ( array() !== $fields ) {
				$clean[ $key ] = $fields;
			}
		}

		if ( array() === $clean ) {
			delete_post_meta( $event_id, self::META_KEY );
			return;
		}

		update_post_meta( $event_id, self::META_KEY, wp_slash( (string) wp_json_encode( $clean ) ) );
	}
```

- [ ] **Step 4: Uruchom test i potwierdź GREEN**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter 'MailTemplateRepositorySaveTest|MailTemplateRepositoryTest'
```
Expected: PASS (nowy test + istniejący `MailTemplateRepositoryTest` z 4A zielone).

- [ ] **Step 5: Styl i statyka**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs src/Persistence
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=512M
```
Expected: zero błędów.

- [ ] **Step 6: Commit**

```bash
git add src/Persistence/MailTemplateRepository.php tests/Integration/Persistence/MailTemplateRepositorySaveTest.php
git commit -m "feat: add mail template repository save with wp_slash"
```

---

## Task 2: MailTemplateController (REST) i rejestracja

**Files:**
- Create: `src/Rest/MailTemplateController.php`
- Modify: `event-registration.php` (rejestracja na `plugins_loaded`)
- Test: `tests/Integration/Rest/MailTemplateControllerTest.php`

**Interfaces:**
- Consumes: `MailTemplateRepository::get()`/`save()`, `DefaultTemplates::keys()`/`get()`, `Capabilities::CAP`, `EventPostType::POST_TYPE`, `sanitize_text_field`, `sanitize_textarea_field`
- Produces:
  - `EvReg\Rest\MailTemplateController::REST_NAMESPACE = 'evreg/v1'`
  - `static register(): void` (podpina `rest_api_init`)
  - trasy GET/POST `/events/(?P<id>\d+)/mail-templates`
  - GET/POST odpowiedź: `array{ templates: array<string,array<string,string>>, defaults: array<string,array{subject:string,body:string}> }`

- [ ] **Step 1: Napisz test (RED)**

`tests/Integration/Rest/MailTemplateControllerTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Rest;

use EvReg\Admin\Capabilities;
use EvReg\Mail\DefaultTemplates;
use EvReg\Persistence\MailTemplateRepository;
use WP_REST_Request;
use WP_UnitTestCase;

final class MailTemplateControllerTest extends WP_UnitTestCase {

	private int $event_id;

	private int $admin_id;

	protected function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		$this->event_id = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
		Capabilities::grant();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	private function path(): string {
		return "/evreg/v1/events/{$this->event_id}/mail-templates";
	}

	public function test_get_requires_capability(): void {
		wp_set_current_user( 0 );

		$response = rest_do_request( new WP_REST_Request( 'GET', $this->path() ) );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_get_returns_defaults_and_empty_templates(): void {
		wp_set_current_user( $this->admin_id );

		$data = rest_do_request( new WP_REST_Request( 'GET', $this->path() ) )->get_data();

		$this->assertSame( array(), $data['templates'] );
		$this->assertSame( DefaultTemplates::get( 'optin' ), $data['defaults']['optin'] );
		$this->assertCount( count( DefaultTemplates::keys() ), $data['defaults'] );
	}

	public function test_post_saves_and_get_round_trips(): void {
		wp_set_current_user( $this->admin_id );

		$post = new WP_REST_Request( 'POST', $this->path() );
		$post->set_body_params( array( 'templates' => array( 'optin' => array( 'subject' => 'Mój temat', 'body' => "Linia 1\nLinia 2" ) ) ) );
		$response = rest_do_request( $post );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Mój temat', $response->get_data()['templates']['optin']['subject'] );

		$get = rest_do_request( new WP_REST_Request( 'GET', $this->path() ) )->get_data();
		$this->assertSame( "Linia 1\nLinia 2", $get['templates']['optin']['body'] );
	}

	public function test_post_preserves_newlines_via_textarea_sanitize(): void {
		wp_set_current_user( $this->admin_id );

		$body = "Wiersz 1\n\nWiersz 3 z Michałem";
		$post = new WP_REST_Request( 'POST', $this->path() );
		$post->set_body_params( array( 'templates' => array( 'confirmed' => array( 'subject' => 'Temat', 'body' => $body ) ) ) );
		rest_do_request( $post );

		$saved = ( new MailTemplateRepository() )->get( $this->event_id );
		$this->assertSame( $body, $saved['confirmed']['body'] );
	}

	public function test_post_ignores_unknown_type(): void {
		wp_set_current_user( $this->admin_id );

		$post = new WP_REST_Request( 'POST', $this->path() );
		$post->set_body_params( array( 'templates' => array( 'nie_ma_takiego' => array( 'subject' => 'X', 'body' => 'Y' ) ) ) );
		rest_do_request( $post );

		$this->assertSame( array(), ( new MailTemplateRepository() )->get( $this->event_id ) );
	}

	public function test_post_drops_empty_type(): void {
		wp_set_current_user( $this->admin_id );

		$post = new WP_REST_Request( 'POST', $this->path() );
		$post->set_body_params( array( 'templates' => array( 'optin' => array( 'subject' => '', 'body' => '' ) ) ) );
		$data = rest_do_request( $post )->get_data();

		$this->assertSame( array(), $data['templates'] );
	}

	public function test_post_strips_html_from_body(): void {
		wp_set_current_user( $this->admin_id );

		$post = new WP_REST_Request( 'POST', $this->path() );
		$post->set_body_params( array( 'templates' => array( 'optin' => array( 'subject' => 'T', 'body' => "Tekst <script>alert(1)</script> koniec" ) ) ) );
		rest_do_request( $post );

		$saved = ( new MailTemplateRepository() )->get( $this->event_id )['optin']['body'];
		$this->assertStringNotContainsString( '<script>', $saved );
	}
}
```

- [ ] **Step 2: Uruchom test i potwierdź RED**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter MailTemplateControllerTest
```
Expected: FAIL — trasa nie istnieje, GET zwraca 404 (`rest_no_route`), asercje padają.

- [ ] **Step 3: Zaimplementuj MailTemplateController**

`src/Rest/MailTemplateController.php`:

```php
<?php
/**
 * Endpoint REST edycji szablonów maili eventu.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Rest;

use EvReg\Admin\Capabilities;
use EvReg\Admin\EventPostType;
use EvReg\Mail\DefaultTemplates;
use EvReg\Persistence\MailTemplateRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Endpoint REST edycji szablonów maili eventu.
 *
 * Osobny od EventConfigController: treść jest wieloliniowa i wymaga
 * sanitize_textarea_field, a kontrakt konfiguracji nie może się zmienić.
 */
final class MailTemplateController {

	public const REST_NAMESPACE = 'evreg/v1';

	/**
	 * Repozytorium szablonów.
	 *
	 * @var MailTemplateRepository
	 */
	private MailTemplateRepository $repository;

	/**
	 * Tworzy kontroler z repozytorium.
	 */
	public function __construct() {
		$this->repository = new MailTemplateRepository();
	}

	/**
	 * Podpina rejestrację tras.
	 */
	public static function register(): void {
		add_action(
			'rest_api_init',
			static function (): void {
				( new self() )->register_routes();
			}
		);
	}

	/**
	 * Rejestruje trasy GET/POST.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/events/(?P<id>\d+)/mail-templates',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_templates' ),
					'permission_callback' => array( $this, 'can_edit' ),
					'args'                => array(
						'id' => array( 'validate_callback' => static fn ( $value ): bool => is_numeric( $value ) ),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_templates' ),
					'permission_callback' => array( $this, 'can_edit' ),
					'args'                => array(
						'id' => array( 'validate_callback' => static fn ( $value ): bool => is_numeric( $value ) ),
					),
				),
			)
		);
	}

	/**
	 * Sprawdza uprawnienia do edycji szablonów eventu.
	 *
	 * @param WP_REST_Request $request Żądanie REST.
	 * @return bool|WP_Error
	 */
	public function can_edit( WP_REST_Request $request ) {
		$event_id = (int) $request['id'];

		if ( EventPostType::POST_TYPE !== get_post_type( $event_id ) ) {
			return new WP_Error(
				'evreg_not_found',
				__( 'Nie znaleziono wydarzenia.', 'event-registration' ),
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( Capabilities::CAP ) || ! current_user_can( 'edit_post', $event_id ) ) {
			return new WP_Error(
				'evreg_forbidden',
				__( 'Brak uprawnień do edycji tego wydarzenia.', 'event-registration' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Zwraca zapisane nadpisania i teksty domyślne.
	 *
	 * @param WP_REST_Request $request Żądanie REST.
	 */
	public function get_templates( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( $this->payload( (int) $request['id'] ), 200 );
	}

	/**
	 * Sanityzuje i zapisuje nadpisania, po czym zwraca świeży stan.
	 *
	 * @param WP_REST_Request $request Żądanie REST.
	 */
	public function update_templates( WP_REST_Request $request ): WP_REST_Response {
		$event_id = (int) $request['id'];
		$incoming = $request->get_param( 'templates' );
		$clean    = array();

		if ( is_array( $incoming ) ) {
			foreach ( DefaultTemplates::keys() as $type ) {
				if ( ! isset( $incoming[ $type ] ) || ! is_array( $incoming[ $type ] ) ) {
					continue;
				}

				$clean[ $type ] = array(
					'subject' => sanitize_text_field( (string) ( $incoming[ $type ]['subject'] ?? '' ) ),
					'body'    => sanitize_textarea_field( (string) ( $incoming[ $type ]['body'] ?? '' ) ),
				);
			}
		}

		$this->repository->save( $event_id, $clean );

		return new WP_REST_Response( $this->payload( $event_id ), 200 );
	}

	/**
	 * Buduje odpowiedź: zapisane nadpisania + teksty domyślne.
	 *
	 * @param int $event_id ID posta eventu.
	 * @return array{templates: array<string,array<string,string>>, defaults: array<string,array{subject:string,body:string}>}
	 */
	private function payload( int $event_id ): array {
		$defaults = array();

		foreach ( DefaultTemplates::keys() as $type ) {
			$defaults[ $type ] = DefaultTemplates::get( $type );
		}

		return array(
			'templates' => $this->repository->get( $event_id ),
			'defaults'  => $defaults,
		);
	}
}
```

- [ ] **Step 4: Zarejestruj kontroler w bootstrapie**

W `event-registration.php`, obok istniejącej rejestracji `EventConfigController`, dodaj linię:

```php
add_action( 'plugins_loaded', array( \EvReg\Rest\MailTemplateController::class, 'register' ) );
```

- [ ] **Step 5: Uruchom test i potwierdź GREEN**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter MailTemplateControllerTest
```
Expected: PASS, 8 testów.

- [ ] **Step 6: Regresja EventConfigController, styl, statyka**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter EventConfigControllerTest
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs src
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=512M
```
Expected: `EventConfigControllerTest` nadal zielony (kontrakt nietknięty), zero błędów stylu i statyki.

- [ ] **Step 7: Commit**

```bash
git add src/Rest/MailTemplateController.php event-registration.php tests/Integration/Rest/MailTemplateControllerTest.php
git commit -m "feat: add mail template REST endpoint with textarea sanitization"
```

---
## Task 3: mailTemplateOps (czysta logika + jest)

**Files:**
- Create: `assets/admin/ops/mailTemplateOps.js`
- Test: `assets/admin/ops/mailTemplateOps.test.js`

**Interfaces:**
- Consumes: `@wordpress/i18n` (`__`)
- Produces:
  - `TEMPLATE_TYPES`: `Array<{ key: string, label: string }>` — pięć typów w kolejności `optin`, `confirmed`, `waitlist`, `expired`, `admin_new`
  - `PLACEHOLDERS_BY_TYPE`: `Record<string, string[]>` — lista placeholderów per typ
  - `setField( templates, type, field, value ): object` — nowy obiekt, nie mutuje
  - `normalizeForSave( templates ): object` — zrzuca puste pola i puste typy
  - `mergeLoaded( templates ): object` — normalizuje wczytane nadpisania do `{ type: { subject, body } }` z pustymi stringami dla braków

- [ ] **Step 1: Napisz test (RED)**

`assets/admin/ops/mailTemplateOps.test.js`:

```js
import {
	TEMPLATE_TYPES,
	PLACEHOLDERS_BY_TYPE,
	setField,
	normalizeForSave,
	mergeLoaded,
} from './mailTemplateOps';

describe( 'mailTemplateOps', () => {
	it( 'TEMPLATE_TYPES pokrywa pięć typów maili', () => {
		const keys = TEMPLATE_TYPES.map( ( t ) => t.key );
		expect( keys ).toEqual( [ 'optin', 'confirmed', 'waitlist', 'expired', 'admin_new' ] );
	} );

	it( 'PLACEHOLDERS_BY_TYPE ma wpis dla każdego typu', () => {
		TEMPLATE_TYPES.forEach( ( { key } ) => {
			expect( Array.isArray( PLACEHOLDERS_BY_TYPE[ key ] ) ).toBe( true );
			expect( PLACEHOLDERS_BY_TYPE[ key ].length ).toBeGreaterThan( 0 );
		} );
	} );

	it( 'setField ustawia pole i nie mutuje wejścia', () => {
		const before = { optin: { subject: 'A', body: '' } };
		const snapshot = JSON.stringify( before );
		const after = setField( before, 'optin', 'body', 'Nowa treść' );

		expect( after.optin.body ).toBe( 'Nowa treść' );
		expect( after.optin.subject ).toBe( 'A' );
		expect( JSON.stringify( before ) ).toBe( snapshot );
	} );

	it( 'setField tworzy brakujący typ', () => {
		const after = setField( {}, 'confirmed', 'subject', 'X' );
		expect( after.confirmed ).toEqual( { subject: 'X', body: '' } );
	} );

	it( 'normalizeForSave zrzuca puste pola i puste typy', () => {
		const result = normalizeForSave( {
			optin: { subject: 'Temat', body: '' },
			confirmed: { subject: '', body: '' },
			waitlist: { subject: '', body: 'Treść' },
		} );

		expect( result ).toEqual( {
			optin: { subject: 'Temat' },
			waitlist: { body: 'Treść' },
		} );
	} );

	it( 'normalizeForSave przycina białe znaki przy ocenie pustości', () => {
		const result = normalizeForSave( { optin: { subject: '   ', body: 'x' } } );
		expect( result.optin ).toEqual( { body: 'x' } );
	} );

	it( 'mergeLoaded uzupełnia brakujące pola pustym stringiem dla każdego typu', () => {
		const merged = mergeLoaded( { optin: { subject: 'Zapisany temat' } } );

		expect( merged.optin ).toEqual( { subject: 'Zapisany temat', body: '' } );
		expect( merged.confirmed ).toEqual( { subject: '', body: '' } );
		expect( Object.keys( merged ) ).toHaveLength( TEMPLATE_TYPES.length );
	} );

	it( 'mergeLoaded znosi wartości spoza pól subject/body', () => {
		const merged = mergeLoaded( { optin: { subject: 'S', body: 'B', extra: 'X' } } );
		expect( merged.optin ).toEqual( { subject: 'S', body: 'B' } );
	} );
} );
```

- [ ] **Step 2: Uruchom test i potwierdź RED**

Run: `npm run test:js -- mailTemplateOps`
Expected: FAIL — `Cannot find module './mailTemplateOps'`.

- [ ] **Step 3: Zaimplementuj mailTemplateOps**

`assets/admin/ops/mailTemplateOps.js`:

```js
import { __ } from '@wordpress/i18n';

export const TEMPLATE_TYPES = [
	{ key: 'optin', label: __( 'Potwierdzenie zgłoszenia (opt-in)', 'event-registration' ) },
	{ key: 'confirmed', label: __( 'Zgłoszenie potwierdzone', 'event-registration' ) },
	{ key: 'waitlist', label: __( 'Lista rezerwowa', 'event-registration' ) },
	{ key: 'expired', label: __( 'Rezerwacja wygasła', 'event-registration' ) },
	{ key: 'admin_new', label: __( 'Powiadomienie organizatora', 'event-registration' ) },
];

const PARTICIPANT_PLACEHOLDERS = [
	'{imie}',
	'{email}',
	'{event}',
	'{typ}',
	'{nocleg}',
	'{link_potwierdzenia}',
	'{podsumowanie}',
];

export const PLACEHOLDERS_BY_TYPE = {
	optin: PARTICIPANT_PLACEHOLDERS,
	confirmed: PARTICIPANT_PLACEHOLDERS,
	waitlist: PARTICIPANT_PLACEHOLDERS,
	expired: PARTICIPANT_PLACEHOLDERS,
	admin_new: PARTICIPANT_PLACEHOLDERS,
};

export function setField( templates, type, field, value ) {
	const current = templates[ type ] || { subject: '', body: '' };
	return {
		...templates,
		[ type ]: { subject: '', body: '', ...current, [ field ]: value },
	};
}

export function normalizeForSave( templates ) {
	const result = {};

	TEMPLATE_TYPES.forEach( ( { key } ) => {
		const entry = templates[ key ] || {};
		const fields = {};

		[ 'subject', 'body' ].forEach( ( field ) => {
			const value = ( entry[ field ] || '' ).trim();
			if ( '' !== value ) {
				fields[ field ] = entry[ field ];
			}
		} );

		if ( 0 < Object.keys( fields ).length ) {
			result[ key ] = fields;
		}
	} );

	return result;
}

export function mergeLoaded( templates ) {
	const result = {};

	TEMPLATE_TYPES.forEach( ( { key } ) => {
		const entry = templates[ key ] || {};
		result[ key ] = {
			subject: 'string' === typeof entry.subject ? entry.subject : '',
			body: 'string' === typeof entry.body ? entry.body : '',
		};
	} );

	return result;
}
```

- [ ] **Step 4: Uruchom test i potwierdź GREEN**

Run: `npm run test:js -- mailTemplateOps`
Expected: PASS, 8 testów.

- [ ] **Step 5: Cała suita jest (regresja)**

Run: `npm run test:js`
Expected: PASS bez regresji istniejących modułów `ops/`.

- [ ] **Step 6: Commit**

```bash
git add assets/admin/ops/mailTemplateOps.js assets/admin/ops/mailTemplateOps.test.js
git commit -m "feat: add pure mail template ops module"
```

---

## Task 4: MailTemplatesTab, api.js i orkiestracja w App.jsx

Komponenty cienkie — brak testów jest (inwariant Planu 2: logika w `ops/`, tu tylko render i wołanie ops). Weryfikacja: `npm run build` przechodzi + kontroler sprawdza w przeglądarce (subagent pomija krok przeglądarki).

**Files:**
- Create: `assets/admin/tabs/MailTemplatesTab.jsx`
- Modify: `assets/admin/api.js`
- Modify: `assets/admin/App.jsx`

**Interfaces:**
- Consumes: `mailTemplateOps` (`TEMPLATE_TYPES`, `PLACEHOLDERS_BY_TYPE`, `setField`, `normalizeForSave`, `mergeLoaded`), `@wordpress/api-fetch`, `@wordpress/components` (`TextControl`, `TextareaControl`), `@wordpress/element`, `@wordpress/i18n`
- Produces:
  - `api.js`: `loadTemplates( eventId ): Promise<{templates, defaults}>`, `saveTemplates( eventId, templates ): Promise<{templates, defaults}>`
  - `MailTemplatesTab` — props `templates`, `defaults`, `update` (setter `update('mailTemplates')`)

- [ ] **Step 1: Dodaj funkcje API**

W `assets/admin/api.js`, po istniejących `loadConfig`/`saveConfig`, dodaj:

```js
const templatesBase = ( eventId ) => `/evreg/v1/events/${ eventId }/mail-templates`;

export function loadTemplates( eventId ) {
	return apiFetch( { path: templatesBase( eventId ) } );
}

export function saveTemplates( eventId, templates ) {
	return apiFetch( {
		path: templatesBase( eventId ),
		method: 'POST',
		data: { templates },
	} );
}
```

- [ ] **Step 2: Zaimplementuj MailTemplatesTab**

`assets/admin/tabs/MailTemplatesTab.jsx`:

```jsx
import { TextControl, TextareaControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { TEMPLATE_TYPES, PLACEHOLDERS_BY_TYPE, setField } from '../ops/mailTemplateOps';

export default function MailTemplatesTab( { templates, defaults, update } ) {
	const setTemplates = update( 'mailTemplates' );
	const values = templates || {};
	const fallback = defaults || {};

	return (
		<div className="evreg-mail-templates-tab">
			<p className="evreg-mail-templates-tab__hint">
				{ __(
					'Puste pole = użyty zostanie tekst domyślny (widoczny jako podpowiedź).',
					'event-registration'
				) }
			</p>
			{ TEMPLATE_TYPES.map( ( { key, label } ) => {
				const entry = values[ key ] || { subject: '', body: '' };
				const def = fallback[ key ] || { subject: '', body: '' };

				return (
					<fieldset className="evreg-mail-template" key={ key }>
						<legend>{ label }</legend>
						<TextControl
							label={ __( 'Temat', 'event-registration' ) }
							value={ entry.subject || '' }
							placeholder={ def.subject }
							onChange={ ( value ) => setTemplates( setField( values, key, 'subject', value ) ) }
						/>
						<TextareaControl
							label={ __( 'Treść', 'event-registration' ) }
							value={ entry.body || '' }
							placeholder={ def.body }
							rows={ 10 }
							onChange={ ( value ) => setTemplates( setField( values, key, 'body', value ) ) }
						/>
						<p className="evreg-mail-template__placeholders">
							{ __( 'Dostępne pola:', 'event-registration' ) }{ ' ' }
							<code>{ PLACEHOLDERS_BY_TYPE[ key ].join( ' ' ) }</code>
						</p>
					</fieldset>
				);
			} ) }
		</div>
	);
}
```

- [ ] **Step 3: Wepnij zakładkę i orkiestrację w App.jsx**

W `assets/admin/App.jsx`:

1. Dodaj importy pod istniejące:

```jsx
import MailTemplatesTab from './tabs/MailTemplatesTab';
import { loadTemplates, saveTemplates } from './api';
import { mergeLoaded, normalizeForSave } from './ops/mailTemplateOps';
```

(`loadConfig`/`saveConfig` już są importowane z `./api` — dopisz `loadTemplates`, `saveTemplates` do istniejącego importu zamiast drugiej linii, jeśli tak wygląda plik.)

2. Dodaj stan defaults obok istniejących `useState`:

```jsx
	const [ templateDefaults, setTemplateDefaults ] = useState( {} );
```

3. W `useEffect` ładującym config, po `loadConfig(...).then(...)`, dołóż równoległe ładowanie szablonów (w tym samym `if ( eventId )` bloku, po łańcuchu `loadConfig`):

```jsx
		loadTemplates( eventId )
			.then( ( data ) => {
				setConfig( ( prev ) => ( { ...prev, mailTemplates: mergeLoaded( data.templates || {} ) } ) );
				setTemplateDefaults( data.defaults || {} );
			} )
			.catch( () =>
				setError( __( 'Nie udało się wczytać szablonów maili.', 'event-registration' ) )
			);
```

4. Podmień `onSave` na orkiestrację obu zapisów:

```jsx
	const onSave = () => {
		setSaving( true );
		setError( '' );
		Promise.allSettled( [
			saveConfig( eventId, config ),
			saveTemplates( eventId, normalizeForSave( config.mailTemplates || {} ) ),
		] )
			.then( ( [ cfg, tpl ] ) => {
				if ( 'fulfilled' === cfg.status ) {
					setValidation( cfg.value.validation || null );
				}
				if ( 'fulfilled' === tpl.status ) {
					setConfig( ( prev ) => ( { ...prev, mailTemplates: mergeLoaded( tpl.value.templates || {} ) } ) );
					setTemplateDefaults( tpl.value.defaults || {} );
				}
				if ( 'rejected' === cfg.status && 'rejected' === tpl.status ) {
					setError( __( 'Zapis nie powiódł się.', 'event-registration' ) );
				} else if ( 'rejected' === cfg.status ) {
					setError( __( 'Konfiguracja nie zapisana; szablony zapisane.', 'event-registration' ) );
				} else if ( 'rejected' === tpl.status ) {
					setError( __( 'Szablony nie zapisane; konfiguracja zapisana.', 'event-registration' ) );
				}
			} )
			.finally( () => setSaving( false ) );
	};
```

5. Dodaj zakładkę do tablicy `tabs` (na końcu):

```jsx
		{ name: 'mail', title: __( 'Szablony maili', 'event-registration' ) },
```

6. W `TabRouter` dodaj gałąź przed `return null`:

```jsx
	if ( 'mail' === name ) {
		return (
			<MailTemplatesTab
				templates={ config.mailTemplates }
				defaults={ templateDefaults }
				update={ update }
			/>
		);
	}
```

Uwaga: `TabRouter` dostaje dziś `config` i `update`. `templateDefaults` żyje w `App` — przekaż go do `TabRouter` jako dodatkowy prop (`<TabRouter ... templateDefaults={ templateDefaults } />` w JSX `TabPanel`) i odbierz w sygnaturze `function TabRouter( { name, config, update, templateDefaults } )`, a w gałęzi `'mail'` użyj `defaults={ templateDefaults }`.

- [ ] **Step 4: Zbuduj i potwierdź brak błędów kompilacji**

Run: `npm run build`
Expected: build kończy się bez błędów; `build/admin/index.js` powstaje. (Ostrzeżenia lint webpacka o nieużywanych importach = błąd do naprawy.)

- [ ] **Step 5: Regresja jest (komponenty nie psują modułów ops)**

Run: `npm run test:js`
Expected: PASS bez regresji.

- [ ] **Step 6: Commit**

```bash
git add assets/admin/api.js assets/admin/tabs/MailTemplatesTab.jsx assets/admin/App.jsx
git commit -m "feat: add mail templates tab and orchestrated save"
```

- [ ] **Step 7: Weryfikacja w przeglądarce (kontroler, nie subagent)**

Kontroler: `npm run build`, wejść na edycję eventu `evreg_event`, zakładka „Szablony maili", wpisać temat, „Zapisz", przeładować — wartość trwa; puste pole pokazuje domyślny tekst jako podpowiedź. Subagent pomija ten krok i raportuje, że wymaga weryfikacji człowieka.

---
## Task 5: SettingsTab (notify_emails + form_page_id), lista stron w assetach, test spięcia

**Files:**
- Modify: `assets/admin/tabs/SettingsTab.jsx`
- Modify: `src/Admin/EventConfigAssets.php`
- Test: `tests/Integration/Mail/NotifyEmailsWiringTest.php`

**Interfaces:**
- Consumes: `@wordpress/components` (`SelectControl`), `window.evregAdmin.pages`, `EventConfigRepository::save()`, `Subscriber` (4A), `PlaceholderFactory` (4A)
- Produces: `SettingsTab` zapisuje `settings.notify_emails` (nie `organizer_emails`) i `settings.form_page_id` (int); `evregAdmin.pages` = `Array<{ value: number, label: string }>`

Ten task zamyka żywy bug: `Subscriber` (4A) czyta `notify_emails`, a UI zapisywało `organizer_emails`. Test integracyjny dowodzi spięcia końcem w koniec.

- [ ] **Step 1: Napisz test spięcia (RED-owalny bez zmian UI — sprawdza kontrakt danych)**

`tests/Integration/Mail/NotifyEmailsWiringTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Mail;

use EvReg\Mail\Subscriber;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\Migrations;
use EvReg\Persistence\RegistrationRepository;
use EvReg\Services\ReservationRequest;
use EvReg\Services\ReservationService;
use WP_UnitTestCase;

final class NotifyEmailsWiringTest extends WP_UnitTestCase {

	private ReservationService $service;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		foreach ( array( 'registrations', 'accommodation_bookings', 'locks', 'mail_queue' ) as $table ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$config         = new EventConfigRepository();
		$this->service  = new ReservationService( new RegistrationRepository(), $config );
		$this->event_id = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );

		$config->save(
			$this->event_id,
			array(
				'types'    => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 0.0, 'capacity' => 5 ) ),
				'settings' => array( 'waitlist_enabled' => true, 'notify_emails' => array( 'biuro@example.com' ) ),
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

	public function test_reservation_notifies_configured_organizer_address(): void {
		$this->service->reserve(
			$this->event_id,
			new ReservationRequest( 'jan@example.com', 'Jan', 'uczestnik', array( '__type' => 'uczestnik' ) )
		);

		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$recipients = $wpdb->get_col( "SELECT recipient FROM " . Migrations::table( 'mail_queue' ) . " WHERE template_key LIKE 'admin_new%'" );

		$this->assertContains( 'biuro@example.com', $recipients );
		$this->assertNotContains( get_option( 'admin_email' ), $recipients );
	}
}
```

- [ ] **Step 2: Uruchom test i potwierdź, że przechodzi (dowód kontraktu 4A)**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter NotifyEmailsWiringTest
```
Expected: PASS. Test dowodzi, że gdy `notify_emails` jest zapisane, `Subscriber` je czyta. To potwierdza, że fix po stronie UI (Krok 3) trafia we właściwy klucz — sam mail layer już jest poprawny (4A). Gdyby test padł, problem leży w 4A, nie w tym tasku — zgłoś to.

- [ ] **Step 3: Zmień klucz w SettingsTab na notify_emails**

W `assets/admin/tabs/SettingsTab.jsx` podmień odczyt `emails` (na górze komponentu):

```jsx
	const emails = Array.isArray( settings.notify_emails )
		? settings.notify_emails.join( ', ' )
		: '';
```

i zapis w `onChange` pola e-maili:

```jsx
				onChange={ ( value ) =>
					setSettings( {
						...settings,
						notify_emails: value
							.split( ',' )
							.map( ( item ) => item.trim() )
							.filter( Boolean ),
					} )
				}
```

- [ ] **Step 4: Dodaj pole form_page_id (SelectControl) w SettingsTab**

Dołóż import na górze `SettingsTab.jsx`:

```jsx
import { TextControl, ToggleControl, SelectControl } from '@wordpress/components';
```

(podmień istniejący import `@wordpress/components`, dopisując `SelectControl`).

Przed zamykającym `</div>` komponentu dodaj:

```jsx
			<SelectControl
				label={ __( 'Strona z formularzem (link potwierdzenia)', 'event-registration' ) }
				value={ String( settings.form_page_id || 0 ) }
				options={ [
					{ value: '0', label: __( '— użyj strony wydarzenia —', 'event-registration' ) },
					...( ( window.evregAdmin && window.evregAdmin.pages ) || [] ).map( ( page ) => ( {
						value: String( page.value ),
						label: page.label,
					} ) ),
				] }
				onChange={ ( value ) =>
					setSettings( { ...settings, form_page_id: parseInt( value, 10 ) } )
				}
			/>
```

- [ ] **Step 5: Dostarcz listę stron do skryptu w EventConfigAssets**

W `src/Admin/EventConfigAssets.php`, w metodzie `enqueue()`, podmień wywołanie `wp_localize_script` na wersję z listą stron:

```php
		$pages = array_map(
			static function ( \WP_Post $page ): array {
				return array(
					'value' => $page->ID,
					'label' => $page->post_title,
				);
			},
			get_posts(
				array(
					'post_type'      => 'page',
					'post_status'    => 'publish',
					'numberposts'    => 200,
					'orderby'        => 'title',
					'order'          => 'ASC',
					'suppress_filters' => false,
				)
			)
		);

		wp_localize_script(
			self::HANDLE,
			'evregAdmin',
			array(
				'eventId' => $event_id,
				'pages'   => $pages,
			)
		);
```

- [ ] **Step 6: Zbuduj i uruchom testy JS**

Run:
```bash
npm run build
npm run test:js
```
Expected: build bez błędów; jest zielony (SettingsTab cienki, bez własnych testów; regresja modułów `ops/` przechodzi).

- [ ] **Step 7: Styl i statyka PHP**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs src
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=512M
```
Expected: zero błędów.

- [ ] **Step 8: Commit**

```bash
git add assets/admin/tabs/SettingsTab.jsx src/Admin/EventConfigAssets.php tests/Integration/Mail/NotifyEmailsWiringTest.php
git commit -m "fix: align organizer email key to notify_emails and add form page picker"
```

- [ ] **Step 9: Weryfikacja w przeglądarce (kontroler, nie subagent)**

Kontroler: wejść w Ustawienia eventu, wpisać adres organizatora, wybrać stronę formularza z dropdowna, Zapisać, przeładować — oba trwają. Wykonać testowe zgłoszenie i sprawdzić, że powiadomienie organizatora poszło na wpisany adres (log `wp_mail`/MailHog). Subagent pomija ten krok.

---

## Task 6: Dokumentacja

**Files:**
- Modify: `README.md` (Status, drzewo)
- Modify: `CLAUDE.md` (opis edytora szablonów, fix mismatchu)

- [ ] **Step 1: Zaktualizuj README**

W sekcji Status zamień punkt 5 (dziś „⬜ Edytor szablonów maili i ekran kolejki w adminie (Plan 4B)") na rozbicie:

```
5. 🔶 Admin maili — edytor szablonów per event gotowy; ekran kolejki (podgląd/wznowienie) osobny plan
```

(Jeśli wolisz binarne znaczniki: zostaw `⬜` i dopisz w nawiasie „edytor szablonów gotowy". Trzymaj listę consecutively numbered.)

W drzewie katalogów, przy `assets/admin/`, dopisz wzmiankę o piątej zakładce, jeśli drzewo wymienia zakładki; jeśli nie — pomiń (drzewo jest wysokopoziomowe).

- [ ] **Step 2: Zaktualizuj CLAUDE.md**

W akapicie o roadmapie dopisz do scalonych „Plan 4B-Szablony (edytor szablonów maili)" i zmień „Kolejny" na „Plan 4B-Kolejka (ekran kolejki mailowej w adminie)".

Pod akapitem o kolejce mailowej (4A) dodaj:

```
Edytor szablonów (4B): piąta zakładka React `MailTemplatesTab` (5 typów × temat+treść, fallback per pole na `DefaultTemplates` widoczny jako placeholder). Zapis osobnym endpointem `MailTemplateController` (`evreg/v1/events/<id>/mail-templates`, GET/POST) — NIE przez `EventConfigController` (jego `sanitize()` zjada `\n`, testy asertują 4-kluczową mapę). `MailTemplateRepository::save()` owija JSON w `wp_slash` (pułapka `wp_unslash` z 4A). Logika mutacji w czystym `ops/mailTemplateOps.js`. Globalny „Zapisz" w `App.jsx` orkiestruje PUT config + POST szablonów przez `Promise.allSettled`. `form_page_id` i `notify_emails` dostały UI w `SettingsTab` (dropdown stron + poprawiony klucz — wcześniej UI zapisywało `organizer_emails`, którego mail layer nie czytał).
```

W sekcji Gotchas dopisz:

```
- **Klucz adresów organizatora to `notify_emails`** (nie `organizer_emails`). `SettingsTab` do Planu 4B zapisywał zły klucz — powiadomienia spadały na `admin_email`. Naprawione; przy zmianach ustawień pilnuj tej nazwy.
```

- [ ] **Step 3: Commit**

```bash
git add README.md CLAUDE.md
git commit -m "docs: document mail template editor and notify_emails fix"
```

---

## Definicja ukończenia Planu 4B-Szablony

- `npm run test:js` — zielone (moduły `ops/`, w tym `mailTemplateOps`)
- `npm run build` — bez błędów kompilacji
- `vendor/bin/phpunit -c phpunit-integration.xml.dist` — zielone, w tym `EventConfigControllerTest` (kontrakt nietknięty) i testy 4A
- `vendor/bin/phpcs src` i `vendor/bin/phpstan analyse --memory-limit=512M` — zero błędów
- Zapis szablonu przez zakładkę trwa po przeładowaniu; puste pole → domyślny tekst jako podpowiedź
- Nadpisany szablon faktycznie steruje wysyłką (`Subscriber` kolejkuje nadpisaną treść) — dowiedzione integracyjnie
- Adres organizatora wpisany w Ustawieniach trafia do powiadomienia (`notify_emails` czytane przez `Subscriber`)
- `form_page_id` wybrany z dropdowna buduje `{link_potwierdzenia}` na wskazaną stronę
- Weryfikacja w przeglądarce (kontroler): zakładka Szablony maili + Ustawienia działają end-to-end

## Czego Plan 4B-Szablony świadomie nie robi

Brak ekranu kolejki mailowej (lista, podgląd treści, ręczne wznowienie) — osobny plan 4B-Kolejka. Brak podglądu renderu na żywo (placeholder inputa pokazuje domyślny; realny render widać w mailu). Brak maili HTML/WYSIWYG (sprzeczne z formatem 4A). Brak migracji istniejących `organizer_emails` (wtyczka wewnętrzna, mail layer świeży).
