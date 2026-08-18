# Event Config React Admin (Plan 2B) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Zbudować jeden spójny React admin dla CPT `evreg_event` — cztery zakładki (Formularz, Typy, Noclegi, Ustawienia) montowane na ekranie edycji eventu, konsumujące endpoint REST konfiguracji z Planu 2A, z zapisem całości i renderem raportu walidacji.

**Architecture:** Cała logika mutacji configu (dodaj/usuń/przesuń pole, edycja typów, siatka inwentarza noclegów, mapowanie kodów walidacji) żyje w czystych modułach JS testowanych `jest`; komponenty React są cienkie i wołają te moduły. Aplikacja trzyma jeden obiekt stanu `{schema, types, accommodation, settings}`, ładuje go przez `GET`, zapisuje całość przez `PUT`. Warstwa PHP (`EventConfigAssets`) tylko enqueue'uje build i montuje kontener; REST i walidacja już istnieją z Planu 2A.

**Tech Stack:** `@wordpress/scripts` (webpack + jest + Playwright preset), `@wordpress/element` (React), `@wordpress/components`, `@wordpress/api-fetch`, `@wordpress/i18n`. PHP 8.1 / WP 6.4 dla cienkiego adaptera enqueue. Node 24 na hoście (build i jest bez kontenera).

**Spec:** `docs/superpowers/specs/2026-08-18-event-config-admin-design.md` (§8 — aplikacja React)

## Global Constraints

- Minimalne PHP 8.1, minimalne WordPress 6.4. Node ≥ 20 na hoście.
- Namespace PHP `EvReg\`, PSR-4, `src/`. Prefiks `evreg_`. Text domain `event-registration` (JS i18n używa tej samej domeny przez `@wordpress/i18n`).
- CPT slug `evreg_event`. REST namespace `evreg/v1` (z Planu 2A). Endpoint: `GET`/`PUT /evreg/v1/events/{id}/config`.
- Handle skryptu i stylu: `evreg-admin`. ID kontenera montowania: `evreg-admin-root`. Globalny obiekt danych: `window.evregAdmin` (`{ eventId }`).
- Źródła JS/SCSS w `assets/admin/`, build do `build/admin/` przez `@wordpress/scripts` (generuje `index.asset.php` z zależnościami i wersją — enqueue MUSI go użyć, nie hardkodować deps).
- Każdy plik PHP poza `src/Domain/` i `tests/` zaczyna się od `defined( 'ABSPATH' ) || exit;`.
- Testy PHP: katalogi wielką literą (`tests/Integration/...`), suity małą literą. Testy JS: pliki `*.test.js` obok modułów, uruchamiane `wp-scripts test-unit-js`.
- PHPStan poziom 6, WPCS czyste (trzy wykluczenia sniffów w `phpcs.xml.dist`). `.gitattributes` wymusza LF.
- **Logika mutacji configu MUSI żyć w czystych funkcjach** (moduły `ops`), testowanych `jest`. Komponenty nie zawierają nietrywialnej logiki transformacji stanu — inaczej nie da się jej przetestować bez renderowania.
- Klient jest lustrem; serwer (Plan 2A `SchemaAssembler` + `SchemaIntegrityChecker`) jest arbitrem walidacji. `validation.errors[].detail` z serwera to surowy tekst wyjątku — **escapować przy renderze** (React domyślnie escapuje, nie używać `dangerouslySetInnerHTML`).
- `build/` jest ignorowane w gicie (dodać do `.gitignore`), commitujemy tylko źródła. `node_modules/` już ignorowane.

### Komendy referencyjne

Instalacja zależności (host, nie kontener):

```bash
npm install
```

Build produkcyjny / watch:

```bash
npm run build
npm run start
```

Testy JS (jest, host):

```bash
npm run test:js
```

Testy PHP (kontener, przez wrapper — patrz `scripts/wp-env.cjs`):

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
```

E2E (Task 7): wp-env musi działać (`node scripts/wp-env.cjs start`), plugin zbudowany (`npm run build`), potem `npm run test:e2e`.

Uwaga środowiskowa: `wp-scripts` i `jest` działają na hoście przez zwykły `npm` (nie potrzebują wp-env ani wrappera DNS — to czysty webpack/jest). Tylko Playwright potrzebuje działającej instancji wp-env.

---

## File Structure

| Plik | Odpowiedzialność |
|------|------------------|
| `package.json` | Modyfikacja: `@wordpress/scripts` + skrypty build/start/test:js/test:e2e |
| `.gitignore` | Modyfikacja: dodać `/build/` |
| `src/Admin/EventConfigAssets.php` | Enqueue build na ekranie edycji `evreg_event`, mount kontenera, localize `eventId` |
| `event-registration.php` | Modyfikacja: rejestracja `EventConfigAssets` |
| `assets/admin/index.js` | Punkt wejścia: mount `<App>` do `#evreg-admin-root` |
| `assets/admin/api.js` | `loadConfig(eventId)`, `saveConfig(eventId, config)` przez `@wordpress/api-fetch` |
| `assets/admin/App.jsx` | Stan configu, nawigacja zakładek, Zapisz, raport walidacji |
| `assets/admin/components/ValidationReport.jsx` | Render raportu (kody → PL) |
| `assets/admin/components/FieldRow.jsx`, `SectionEditor.jsx` | Prezentacja edytora schemy |
| `assets/admin/tabs/FormTab.jsx` | Zakładka Formularz |
| `assets/admin/tabs/TypesTab.jsx` | Zakładka Typy |
| `assets/admin/tabs/AccommodationTab.jsx` | Zakładka Noclegi (siatka inwentarza) |
| `assets/admin/tabs/SettingsTab.jsx` | Zakładka Ustawienia |
| `assets/admin/ops/schemaOps.js` (+`.test.js`) | Czyste operacje na schemie |
| `assets/admin/ops/typesOps.js` (+`.test.js`) | Czyste operacje na typach |
| `assets/admin/ops/accommodationOps.js` (+`.test.js`) | Czyste operacje na noclegach |
| `assets/admin/ops/validationMessages.js` (+`.test.js`) | Kody walidacji → komunikaty PL |
| `assets/admin/ops/fieldTypes.js` | Stała: paleta typów pól i ich etykiety |
| `tests/e2e/config-admin.spec.js` | Ścieżka Playwright happy-path |
| `playwright.config.js` | Konfiguracja Playwright (Task 7) |

Zasada: `ops/*.js` to czyste funkcje (wejście → nowy obiekt, bez mutacji argumentu, bez efektów), w pełni `jest`-owalne. Komponenty importują ops i renderują.

---

## Task 1: Build tooling, enqueue i pusty shell React

**Files:**
- Modify: `package.json`
- Modify: `.gitignore`
- Create: `assets/admin/index.js`
- Create: `assets/admin/App.jsx`
- Create: `assets/admin/ops/smoke.test.js`
- Create: `src/Admin/EventConfigAssets.php`
- Modify: `event-registration.php`
- Test: `tests/Integration/Admin/EventConfigAssetsTest.php`

**Interfaces:**
- Consumes: `EvReg\Admin\EventPostType::POST_TYPE` (`'evreg_event'`)
- Produces:
  - `EvReg\Admin\EventConfigAssets::HANDLE` (`'evreg-admin'`), `EventConfigAssets::register(): void` (hooki `add_meta_boxes`/`admin_enqueue_scripts` + `edit_form_after_title`)
  - Build artefakt `build/admin/index.js` + `build/admin/index.asset.php`
  - Globalny `window.evregAdmin = { eventId: <int> }` na ekranie edycji
  - `#evreg-admin-root` w DOM pod tytułem

- [ ] **Step 1: Rozszerz `package.json`**

```json
{
  "name": "event-registration",
  "private": true,
  "scripts": {
    "build": "wp-scripts build assets/admin/index.js --output-path=build/admin",
    "start": "wp-scripts start assets/admin/index.js --output-path=build/admin",
    "test:js": "wp-scripts test-unit-js --config jest.config.js",
    "test:e2e": "playwright test"
  },
  "devDependencies": {
    "@wordpress/api-fetch": "^7.4.0",
    "@wordpress/components": "^28.4.0",
    "@wordpress/element": "^6.4.0",
    "@wordpress/env": "^10.0.0",
    "@wordpress/i18n": "^5.4.0",
    "@wordpress/scripts": "^27.9.0"
  }
}
```

Uwaga: `@wordpress/element`, `/components`, `/i18n`, `/api-fetch` są przy buildzie **externalizowane** (webpack wskazuje je na globalne `wp.*` przez DependencyExtractionWebpackPlugin — nie trafiają do bundla). Mimo to muszą być zainstalowane jako devDependencies, bo `jest` i IDE rozwiązują je z `node_modules` przy testach modułów `ops`, które importują `@wordpress/i18n`. Bez nich `npm run test:js` pada na „Cannot find module".

- [ ] **Step 2: Dodaj `/build/` do `.gitignore` i utwórz `jest.config.js`**

Dopisz linię `/build/` do istniejącego `.gitignore` (po `/vendor/`).

Utwórz `jest.config.js` w katalogu głównym — rozszerza preset wp-scripts i wyklucza katalogi, których jest nie ma dotykać (w tym `tests/e2e/`, bo domyślny preset matchuje `*.spec.js` i inaczej złapałby plik Playwrighta z Taska 7):

```js
const defaultConfig = require( '@wordpress/scripts/config/jest-unit.config.js' );

module.exports = {
	...defaultConfig,
	testPathIgnorePatterns: [ '/node_modules/', '/vendor/', '/build/', '/tests/e2e/' ],
};
```

- [ ] **Step 3: Zainstaluj zależności (host)**

```bash
npm install
```

Oczekiwane: `@wordpress/scripts` i jego zależności zainstalowane. Jeśli Node 24 powoduje ostrzeżenia peer-deps — nie są błędem; przerwać tylko przy realnym błędzie instalacji.

- [ ] **Step 4: Utwórz pusty shell React**

`assets/admin/App.jsx`:

```jsx
import { __ } from '@wordpress/i18n';

export default function App( { eventId } ) {
	return (
		<div className="evreg-admin">
			<p>{ __( 'Konfiguracja wydarzenia', 'event-registration' ) } #{ eventId }</p>
		</div>
	);
}
```

`assets/admin/index.js`:

```js
import { createRoot } from '@wordpress/element';
import App from './App';

const container = document.getElementById( 'evreg-admin-root' );

if ( container ) {
	const eventId = window.evregAdmin ? parseInt( window.evregAdmin.eventId, 10 ) : 0;
	createRoot( container ).render( <App eventId={ eventId } /> );
}
```

- [ ] **Step 5: Dodaj trywialny test jest, aby potwierdzić działanie toolchainu**

`assets/admin/ops/smoke.test.js`:

```js
describe( 'toolchain jest', () => {
	it( 'uruchamia testy JS', () => {
		expect( 1 + 1 ).toBe( 2 );
	} );
} );
```

- [ ] **Step 6: Zbuduj i uruchom test JS**

```bash
npm run build
npm run test:js
```

Oczekiwane: build tworzy `build/admin/index.js` oraz `build/admin/index.asset.php`; jest zgłasza 1 test zielony. Jeśli build zawiedzie na wersji Node, zaraportuj dokładny błąd (BLOCKED) — nie obchodź.

- [ ] **Step 7: Napisz failujący test enqueue**

`tests/Integration/Admin/EventConfigAssetsTest.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Admin;

use EvReg\Admin\EventConfigAssets;
use EvReg\Admin\EventPostType;
use WP_UnitTestCase;

final class EventConfigAssetsTest extends WP_UnitTestCase {

	public function test_assets_not_enqueued_outside_event_screen(): void {
		EventConfigAssets::register();

		do_action( 'admin_enqueue_scripts', 'edit.php' );

		$this->assertFalse( wp_script_is( EventConfigAssets::HANDLE, 'enqueued' ) );
	}

	public function test_mount_container_printed_after_title_for_event(): void {
		$event = self::factory()->post->create_and_get( array( 'post_type' => EventPostType::POST_TYPE ) );

		ob_start();
		do_action( 'edit_form_after_title', $event );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="evreg-admin-root"', $html );
	}

	public function test_no_mount_container_for_other_post_types(): void {
		$page = self::factory()->post->create_and_get( array( 'post_type' => 'page' ) );

		ob_start();
		do_action( 'edit_form_after_title', $page );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'evreg-admin-root', $html );
	}
}
```

- [ ] **Step 8: Uruchom test i potwierdź fail**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter EventConfigAssetsTest
```

Oczekiwane: FAIL — `Class "EvReg\Admin\EventConfigAssets" not found`.

- [ ] **Step 9: Zaimplementuj `EventConfigAssets`**

`src/Admin/EventConfigAssets.php`:

```php
<?php

declare( strict_types=1 );

namespace EvReg\Admin;

use EvReg\Plugin;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Ładowanie aplikacji React na ekranie edycji wydarzenia.
 */
final class EventConfigAssets {

	public const HANDLE = 'evreg-admin';

	/**
	 * Podpina enqueue i wypisanie kontenera montowania.
	 */
	public static function register(): void {
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'edit_form_after_title', array( self::class, 'render_root' ) );
	}

	/**
	 * Enqueue skryptu i stylu tylko na ekranie edycji evreg_event.
	 *
	 * @param string $hook_suffix Aktualny ekran admina.
	 */
	public static function enqueue( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( null === $screen || EventPostType::POST_TYPE !== $screen->post_type ) {
			return;
		}

		$dir      = plugin_dir_path( Plugin::plugin_file() );
		$url      = plugin_dir_url( Plugin::plugin_file() );
		$asset    = $dir . 'build/admin/index.asset.php';
		$manifest = file_exists( $asset ) ? require $asset : array( 'dependencies' => array(), 'version' => Plugin::VERSION );

		wp_enqueue_script(
			self::HANDLE,
			$url . 'build/admin/index.js',
			$manifest['dependencies'],
			$manifest['version'],
			true
		);

		wp_enqueue_style( 'wp-components' );

		$event_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- odczyt ID ekranu, nie akcja.

		wp_localize_script( self::HANDLE, 'evregAdmin', array( 'eventId' => $event_id ) );
	}

	/**
	 * Wypisuje kontener montowania pod tytułem, tylko dla evreg_event.
	 *
	 * @param WP_Post $post Edytowany wpis.
	 */
	public static function render_root( WP_Post $post ): void {
		if ( EventPostType::POST_TYPE !== $post->post_type ) {
			return;
		}

		echo '<div id="evreg-admin-root"></div>';
	}
}
```

- [ ] **Step 10: Podepnij rejestrację w `event-registration.php`**

Dodaj obok pozostałych rejestracji `plugins_loaded`:

```php
add_action( 'plugins_loaded', array( \EvReg\Admin\EventConfigAssets::class, 'register' ) );
```

- [ ] **Step 11: Uruchom testy integracyjne + narzędzia**

```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter EventConfigAssetsTest
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
```

Oczekiwane: 3 testy zielone, PHPStan i WPCS czyste.

- [ ] **Step 12: Commit**

```bash
git add package.json package-lock.json jest.config.js .gitignore assets src/Admin/EventConfigAssets.php event-registration.php tests/Integration/Admin/EventConfigAssetsTest.php
git commit -m "feat: add build tooling and React mount for event config admin"
```

---

## Task 2: Shell aplikacji — stan, zakładki, zapis, raport walidacji

**Files:**
- Create: `assets/admin/api.js`
- Create: `assets/admin/ops/validationMessages.js`
- Create: `assets/admin/ops/validationMessages.test.js`
- Create: `assets/admin/components/ValidationReport.jsx`
- Modify: `assets/admin/App.jsx`

**Interfaces:**
- Consumes: `window.evregAdmin.eventId`; endpoint `GET`/`PUT /evreg/v1/events/{id}/config`
- Produces:
  - `api.js`: `loadConfig( eventId ): Promise<object>`, `saveConfig( eventId, config ): Promise<object>` (zwraca payload z `validation`)
  - `validationMessages.js`: `messageForCode( code, detail ): string`
  - `<App>` z pełnym cyklem: ładowanie → edycja przez zakładki → zapis → raport

- [ ] **Step 1: Napisz failujący test mapowania komunikatów**

`assets/admin/ops/validationMessages.test.js`:

```js
import { messageForCode } from './validationMessages';

describe( 'messageForCode', () => {
	it( 'zwraca komunikat PL dla znanego kodu', () => {
		expect( messageForCode( 'schema_invalid', 'x' ) ).toContain( 'Konfiguracja' );
	} );

	it( 'dołącza detail dla schema_invalid', () => {
		expect( messageForCode( 'schema_invalid', 'brak pola __type' ) ).toContain( 'brak pola __type' );
	} );

	it( 'dla nieznanego kodu zwraca detail lub kod', () => {
		expect( messageForCode( 'nieznany', 'szczegół' ) ).toBe( 'szczegół' );
		expect( messageForCode( 'nieznany', '' ) ).toBe( 'nieznany' );
	} );
} );
```

- [ ] **Step 2: Uruchom i potwierdź fail**

```bash
npm run test:js
```

Oczekiwane: FAIL — `Cannot find module './validationMessages'`.

- [ ] **Step 3: Zaimplementuj `validationMessages.js`**

```js
import { __, sprintf } from '@wordpress/i18n';

const KNOWN = {
	schema_invalid: ( detail ) =>
		sprintf(
			/* translators: %s: szczegół błędu walidacji z serwera. */
			__( 'Konfiguracja formularza jest niepoprawna: %s', 'event-registration' ),
			detail
		),
};

export function messageForCode( code, detail ) {
	if ( KNOWN[ code ] ) {
		return KNOWN[ code ]( detail );
	}

	return detail || code;
}
```

- [ ] **Step 4: Zaimplementuj `api.js`**

```js
import apiFetch from '@wordpress/api-fetch';

const base = ( eventId ) => `/evreg/v1/events/${ eventId }/config`;

export function loadConfig( eventId ) {
	return apiFetch( { path: base( eventId ) } );
}

export function saveConfig( eventId, config ) {
	return apiFetch( {
		path: base( eventId ),
		method: 'PUT',
		data: config,
	} );
}
```

- [ ] **Step 5: Zaimplementuj `ValidationReport.jsx`**

```jsx
import { messageForCode } from '../ops/validationMessages';

export default function ValidationReport( { validation } ) {
	if ( ! validation ) {
		return null;
	}

	if ( validation.valid ) {
		return (
			<div className="notice notice-success inline">
				<p>Konfiguracja jest poprawna.</p>
			</div>
		);
	}

	return (
		<div className="notice notice-warning inline">
			<ul>
				{ validation.errors.map( ( error, index ) => (
					<li key={ index }>{ messageForCode( error.code, error.detail ) }</li>
				) ) }
			</ul>
		</div>
	);
}
```

Uwaga: `messageForCode` zwraca zwykły tekst renderowany przez Reacta (escapowany). Nie używać `dangerouslySetInnerHTML`.

- [ ] **Step 6: Przepisz `App.jsx` na pełny cykl**

```jsx
import { useState, useEffect } from '@wordpress/element';
import { Button, TabPanel, Spinner, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { loadConfig, saveConfig } from './api';
import ValidationReport from './components/ValidationReport';

const EMPTY = { schema: {}, types: [], accommodation: {}, settings: {} };

export default function App( { eventId } ) {
	const [ config, setConfig ] = useState( EMPTY );
	const [ validation, setValidation ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		loadConfig( eventId )
			.then( ( data ) => {
				setConfig( {
					schema: data.schema || {},
					types: data.types || [],
					accommodation: data.accommodation || {},
					settings: data.settings || {},
				} );
				setValidation( data.validation || null );
			} )
			.catch( () => setError( __( 'Nie udało się wczytać konfiguracji.', 'event-registration' ) ) )
			.finally( () => setLoading( false ) );
	}, [ eventId ] );

	const update = ( key ) => ( value ) =>
		setConfig( ( prev ) => ( { ...prev, [ key ]: value } ) );

	const onSave = () => {
		setSaving( true );
		setError( '' );
		saveConfig( eventId, config )
			.then( ( data ) => setValidation( data.validation || null ) )
			.catch( () => setError( __( 'Zapis nie powiódł się.', 'event-registration' ) ) )
			.finally( () => setSaving( false ) );
	};

	if ( loading ) {
		return <Spinner />;
	}

	const tabs = [
		{ name: 'form', title: __( 'Formularz', 'event-registration' ) },
		{ name: 'types', title: __( 'Typy zgłoszenia', 'event-registration' ) },
		{ name: 'accommodation', title: __( 'Noclegi', 'event-registration' ) },
		{ name: 'settings', title: __( 'Ustawienia', 'event-registration' ) },
	];

	return (
		<div className="evreg-admin">
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			<ValidationReport validation={ validation } />
			<TabPanel tabs={ tabs }>
				{ ( tab ) => (
					<TabRouter
						name={ tab.name }
						config={ config }
						update={ update }
					/>
				) }
			</TabPanel>
			<div className="evreg-admin__actions">
				<Button variant="primary" onClick={ onSave } isBusy={ saving } disabled={ saving }>
					{ __( 'Zapisz', 'event-registration' ) }
				</Button>
			</div>
		</div>
	);
}

function TabRouter( { name } ) {
	// Zakładki podłączane w kolejnych taskach (3–6). Na razie placeholder,
	// żeby shell działał end-to-end.
	return <p>{ name }</p>;
}
```

Uwaga dla wykonawcy: `TabRouter` jest tymczasowy — Taski 3–6 zastępują każdą gałąź realną zakładką. Nie usuwaj `config`/`update` z propsów; kolejne taski je konsumują.

- [ ] **Step 7: Zbuduj i uruchom testy JS**

```bash
npm run build
npm run test:js
```

Oczekiwane: build przechodzi, jest zielony (3 testy `validationMessages` + smoke).

- [ ] **Step 8: Zweryfikuj w przeglądarce (kontroler)**

Ten krok wykonuje kontroler po zakończeniu taska — nie subagent. (Subagent: pomiń, zaznacz jako do weryfikacji przez kontrolera.)

- [ ] **Step 9: Commit**

```bash
git add assets
git commit -m "feat: add app shell with config load/save and validation report"
```

---

## Task 3: Zakładka Formularz — edytor schemy

**Files:**
- Create: `assets/admin/ops/fieldTypes.js`
- Create: `assets/admin/ops/schemaOps.js`
- Create: `assets/admin/ops/schemaOps.test.js`
- Create: `assets/admin/components/FieldRow.jsx`
- Create: `assets/admin/components/SectionEditor.jsx`
- Create: `assets/admin/tabs/FormTab.jsx`
- Modify: `assets/admin/App.jsx` (podłącz `FormTab` w `TabRouter`)

**Interfaces:**
- Consumes: `config.schema` (kształt z Planu 1: `{version, sections:[{key,title,description,condition,fields:[{key,type,label,required,options,config,description,condition}]}]}`), `update('schema')`
- Produces:
  - `fieldTypes.js`: `FIELD_TYPES` (lista `{value,label}` dla palety), `isSpecial(key)` (`__type`), `isAccommodation(type)`
  - `schemaOps.js`: czyste funkcje `emptySchema()`, `addSection(schema, key, title)`, `removeSection(schema, sectionKey)`, `addField(schema, sectionKey, field)`, `removeField(schema, fieldKey)`, `moveField(schema, fieldKey, direction)`, `updateField(schema, fieldKey, patch)`, `ensureTypeField(schema)`
  - `<FormTab config update />`

**Zasada (Model 2 z Planu 1):** pole `__type` jest zawsze obecne (`ensureTypeField` je wstawia jeśli brak), nieusuwalne; jego `options` NIE są edytowane tu. Pole typu `accommodation` dodaje się z palety, ale jego `config` (inwentarz) NIE jest edytowany tu — tylko w zakładce Noclegi. Warunki widoczności v1: tylko z polem `__type`.

- [ ] **Step 1: Napisz failujące testy `schemaOps`**

`assets/admin/ops/schemaOps.test.js`:

```js
import {
	emptySchema,
	addSection,
	addField,
	removeField,
	moveField,
	updateField,
	ensureTypeField,
} from './schemaOps';

describe( 'schemaOps', () => {
	it( 'emptySchema ma wersję i pustą listę sekcji', () => {
		expect( emptySchema() ).toEqual( { version: 1, sections: [] } );
	} );

	it( 'ensureTypeField wstawia __type gdy brak', () => {
		const schema = ensureTypeField( addSection( emptySchema(), 'dane', 'Dane' ) );
		const keys = schema.sections[ 0 ].fields.map( ( f ) => f.key );
		expect( keys ).toContain( '__type' );
	} );

	it( 'ensureTypeField nie duplikuje __type', () => {
		let schema = ensureTypeField( addSection( emptySchema(), 'dane', 'Dane' ) );
		schema = ensureTypeField( schema );
		const count = schema.sections
			.flatMap( ( s ) => s.fields )
			.filter( ( f ) => f.key === '__type' ).length;
		expect( count ).toBe( 1 );
	} );

	it( 'addField dodaje pole do wskazanej sekcji', () => {
		let schema = addSection( emptySchema(), 'dane', 'Dane' );
		schema = addField( schema, 'dane', { key: 'email', type: 'email', label: 'E-mail' } );
		expect( schema.sections[ 0 ].fields ).toHaveLength( 1 );
		expect( schema.sections[ 0 ].fields[ 0 ].key ).toBe( 'email' );
	} );

	it( 'removeField usuwa pole po kluczu', () => {
		let schema = addField( addSection( emptySchema(), 'dane', 'Dane' ), 'dane', {
			key: 'email',
			type: 'email',
			label: 'E-mail',
		} );
		schema = removeField( schema, 'email' );
		expect( schema.sections[ 0 ].fields ).toHaveLength( 0 );
	} );

	it( 'removeField nie usuwa __type', () => {
		let schema = ensureTypeField( addSection( emptySchema(), 'dane', 'Dane' ) );
		schema = removeField( schema, '__type' );
		const keys = schema.sections.flatMap( ( s ) => s.fields ).map( ( f ) => f.key );
		expect( keys ).toContain( '__type' );
	} );

	it( 'moveField przesuwa pole w górę', () => {
		let schema = addSection( emptySchema(), 'dane', 'Dane' );
		schema = addField( schema, 'dane', { key: 'a', type: 'text', label: 'A' } );
		schema = addField( schema, 'dane', { key: 'b', type: 'text', label: 'B' } );
		schema = moveField( schema, 'b', 'up' );
		expect( schema.sections[ 0 ].fields.map( ( f ) => f.key ) ).toEqual( [ 'b', 'a' ] );
	} );

	it( 'updateField nadpisuje właściwości pola', () => {
		let schema = addField( addSection( emptySchema(), 'dane', 'Dane' ), 'dane', {
			key: 'email',
			type: 'email',
			label: 'E-mail',
		} );
		schema = updateField( schema, 'email', { required: true, label: 'Adres e-mail' } );
		const field = schema.sections[ 0 ].fields[ 0 ];
		expect( field.required ).toBe( true );
		expect( field.label ).toBe( 'Adres e-mail' );
	} );

	it( 'nie mutuje argumentu wejściowego', () => {
		const schema = addSection( emptySchema(), 'dane', 'Dane' );
		const before = JSON.stringify( schema );
		addField( schema, 'dane', { key: 'x', type: 'text', label: 'X' } );
		expect( JSON.stringify( schema ) ).toBe( before );
	} );
} );
```

- [ ] **Step 2: Uruchom i potwierdź fail**

```bash
npm run test:js
```

Oczekiwane: FAIL — `Cannot find module './schemaOps'`.

- [ ] **Step 3: Zaimplementuj `fieldTypes.js`**

```js
import { __ } from '@wordpress/i18n';

export const TYPE_FIELD_KEY = '__type';

export const FIELD_TYPES = [
	{ value: 'text', label: __( 'Tekst', 'event-registration' ) },
	{ value: 'email', label: __( 'E-mail', 'event-registration' ) },
	{ value: 'tel', label: __( 'Telefon', 'event-registration' ) },
	{ value: 'textarea', label: __( 'Pole wielolinijkowe', 'event-registration' ) },
	{ value: 'number', label: __( 'Liczba', 'event-registration' ) },
	{ value: 'date', label: __( 'Data', 'event-registration' ) },
	{ value: 'select', label: __( 'Lista rozwijana', 'event-registration' ) },
	{ value: 'radio', label: __( 'Wybór pojedynczy', 'event-registration' ) },
	{ value: 'checkbox', label: __( 'Zgoda (pojedynczy checkbox)', 'event-registration' ) },
	{ value: 'checkbox-group', label: __( 'Wybór wielokrotny', 'event-registration' ) },
	{ value: 'heading', label: __( 'Nagłówek', 'event-registration' ) },
	{ value: 'paragraph', label: __( 'Akapit', 'event-registration' ) },
	{ value: 'accommodation', label: __( 'Nocleg', 'event-registration' ) },
];

export function isSpecial( key ) {
	return TYPE_FIELD_KEY === key;
}

export function isAccommodation( type ) {
	return 'accommodation' === type;
}
```

- [ ] **Step 4: Zaimplementuj `schemaOps.js`**

```js
import { TYPE_FIELD_KEY } from './fieldTypes';

const clone = ( value ) => JSON.parse( JSON.stringify( value ) );

export function emptySchema() {
	return { version: 1, sections: [] };
}

export function addSection( schema, key, title ) {
	const next = clone( schema );
	next.sections = next.sections || [];
	next.sections.push( { key, title, description: '', condition: null, fields: [] } );
	return next;
}

export function removeSection( schema, sectionKey ) {
	const next = clone( schema );
	next.sections = ( next.sections || [] ).filter( ( s ) => s.key !== sectionKey );
	return next;
}

export function addField( schema, sectionKey, field ) {
	const next = clone( schema );
	( next.sections || [] ).forEach( ( section ) => {
		if ( section.key === sectionKey ) {
			section.fields = section.fields || [];
			section.fields.push( field );
		}
	} );
	return next;
}

export function removeField( schema, fieldKey ) {
	if ( fieldKey === TYPE_FIELD_KEY ) {
		return schema;
	}

	const next = clone( schema );
	( next.sections || [] ).forEach( ( section ) => {
		section.fields = ( section.fields || [] ).filter( ( f ) => f.key !== fieldKey );
	} );
	return next;
}

export function moveField( schema, fieldKey, direction ) {
	const next = clone( schema );
	( next.sections || [] ).forEach( ( section ) => {
		const fields = section.fields || [];
		const index = fields.findIndex( ( f ) => f.key === fieldKey );

		if ( index === -1 ) {
			return;
		}

		const target = 'up' === direction ? index - 1 : index + 1;

		if ( target < 0 || target >= fields.length ) {
			return;
		}

		[ fields[ index ], fields[ target ] ] = [ fields[ target ], fields[ index ] ];
	} );
	return next;
}

export function updateField( schema, fieldKey, patch ) {
	const next = clone( schema );
	( next.sections || [] ).forEach( ( section ) => {
		section.fields = ( section.fields || [] ).map( ( f ) =>
			f.key === fieldKey ? { ...f, ...patch } : f
		);
	} );
	return next;
}

export function ensureTypeField( schema ) {
	const hasType = ( schema.sections || [] )
		.flatMap( ( s ) => s.fields || [] )
		.some( ( f ) => f.key === TYPE_FIELD_KEY );

	if ( hasType ) {
		return schema;
	}

	const next = clone( schema );

	if ( ! next.sections || next.sections.length === 0 ) {
		next.sections = [ { key: 'dane', title: 'Dane', description: '', condition: null, fields: [] } ];
	}

	next.sections[ 0 ].fields = next.sections[ 0 ].fields || [];
	next.sections[ 0 ].fields.unshift( {
		key: TYPE_FIELD_KEY,
		type: 'radio',
		label: 'Typ zgłoszenia',
	} );

	return next;
}
```

- [ ] **Step 5: Uruchom testy JS**

```bash
npm run test:js
```

Oczekiwane: wszystkie testy `schemaOps` zielone.

- [ ] **Step 6: Zaimplementuj komponenty prezentacji**

`assets/admin/components/FieldRow.jsx`:

```jsx
import { Button, TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { isSpecial, isAccommodation } from '../ops/fieldTypes';

export default function FieldRow( { field, onChange, onRemove, onMove } ) {
	const special = isSpecial( field.key );

	return (
		<div className="evreg-field-row">
			<strong>{ field.type }</strong>
			<TextControl
				label={ __( 'Etykieta', 'event-registration' ) }
				value={ field.label || '' }
				onChange={ ( label ) => onChange( { label } ) }
			/>
			{ ! isAccommodation( field.type ) && field.type !== 'heading' && field.type !== 'paragraph' && (
				<ToggleControl
					label={ __( 'Wymagane', 'event-registration' ) }
					checked={ !! field.required }
					onChange={ ( required ) => onChange( { required } ) }
				/>
			) }
			{ special && (
				<p className="description">
					{ __( 'Opcje pochodzą z zakładki Typy zgłoszenia.', 'event-registration' ) }
				</p>
			) }
			{ isAccommodation( field.type ) && (
				<p className="description">
					{ __( 'Inwentarz konfigurujesz w zakładce Noclegi.', 'event-registration' ) }
				</p>
			) }
			<div className="evreg-field-row__actions">
				<Button onClick={ () => onMove( 'up' ) }>↑</Button>
				<Button onClick={ () => onMove( 'down' ) }>↓</Button>
				{ ! special && (
					<Button isDestructive onClick={ onRemove }>
						{ __( 'Usuń', 'event-registration' ) }
					</Button>
				) }
			</div>
		</div>
	);
}
```

`assets/admin/components/SectionEditor.jsx`:

```jsx
import { TextControl } from '@wordpress/components';
import FieldRow from './FieldRow';

export default function SectionEditor( { section, onFieldChange, onFieldRemove, onFieldMove } ) {
	return (
		<div className="evreg-section">
			<h3>{ section.title || section.key }</h3>
			{ ( section.fields || [] ).map( ( field ) => (
				<FieldRow
					key={ field.key }
					field={ field }
					onChange={ ( patch ) => onFieldChange( field.key, patch ) }
					onRemove={ () => onFieldRemove( field.key ) }
					onMove={ ( dir ) => onFieldMove( field.key, dir ) }
				/>
			) ) }
		</div>
	);
}
```

- [ ] **Step 7: Zaimplementuj `FormTab.jsx`**

```jsx
import { useState } from '@wordpress/element';
import { Button, SelectControl, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import SectionEditor from '../components/SectionEditor';
import { FIELD_TYPES } from '../ops/fieldTypes';
import {
	emptySchema,
	ensureTypeField,
	addSection,
	addField,
	removeField,
	moveField,
	updateField,
} from '../ops/schemaOps';

export default function FormTab( { config, update } ) {
	const schema = ensureTypeField(
		config.schema && config.schema.sections ? config.schema : emptySchema()
	);
	const setSchema = update( 'schema' );

	const [ newSection, setNewSection ] = useState( '' );
	const [ fieldType, setFieldType ] = useState( 'text' );
	const [ fieldKey, setFieldKey ] = useState( '' );
	const targetSection = schema.sections[ 0 ] ? schema.sections[ 0 ].key : 'dane';

	const onAddSection = () => {
		if ( ! newSection ) {
			return;
		}
		setSchema( addSection( schema, newSection, newSection ) );
		setNewSection( '' );
	};

	const onAddField = () => {
		if ( ! fieldKey ) {
			return;
		}
		setSchema(
			addField( schema, targetSection, { key: fieldKey, type: fieldType, label: fieldKey } )
		);
		setFieldKey( '' );
	};

	return (
		<div className="evreg-form-tab">
			{ schema.sections.map( ( section ) => (
				<SectionEditor
					key={ section.key }
					section={ section }
					onFieldChange={ ( key, patch ) => setSchema( updateField( schema, key, patch ) ) }
					onFieldRemove={ ( key ) => setSchema( removeField( schema, key ) ) }
					onFieldMove={ ( key, dir ) => setSchema( moveField( schema, key, dir ) ) }
				/>
			) ) }

			<div className="evreg-form-tab__add-field">
				<TextControl
					label={ __( 'Klucz nowego pola', 'event-registration' ) }
					value={ fieldKey }
					onChange={ setFieldKey }
				/>
				<SelectControl
					label={ __( 'Typ', 'event-registration' ) }
					value={ fieldType }
					options={ FIELD_TYPES }
					onChange={ setFieldType }
				/>
				<Button variant="secondary" onClick={ onAddField }>
					{ __( 'Dodaj pole', 'event-registration' ) }
				</Button>
			</div>

			<div className="evreg-form-tab__add-section">
				<TextControl
					label={ __( 'Nowa sekcja', 'event-registration' ) }
					value={ newSection }
					onChange={ setNewSection }
				/>
				<Button variant="secondary" onClick={ onAddSection }>
					{ __( 'Dodaj sekcję', 'event-registration' ) }
				</Button>
			</div>
		</div>
	);
}
```

- [ ] **Step 8: Podłącz `FormTab` w `App.jsx`**

W `App.jsx` zastąp `TabRouter` tak, aby dla `name === 'form'` renderował `<FormTab config={ config } update={ update } />` (import na górze). Pozostałe gałęzie zostają placeholderami do Tasków 4–6:

```jsx
import FormTab from './tabs/FormTab';

function TabRouter( { name, config, update } ) {
	if ( 'form' === name ) {
		return <FormTab config={ config } update={ update } />;
	}
	return <p>{ name }</p>;
}
```

- [ ] **Step 9: Zbuduj i uruchom testy JS**

```bash
npm run build
npm run test:js
```

Oczekiwane: build przechodzi, wszystkie testy zielone.

- [ ] **Step 10: Commit**

```bash
git add assets
git commit -m "feat: add form schema editor tab with pure schema operations"
```

---

## Task 4: Zakładka Typy zgłoszenia

**Files:**
- Create: `assets/admin/ops/typesOps.js`
- Create: `assets/admin/ops/typesOps.test.js`
- Create: `assets/admin/tabs/TypesTab.jsx`
- Modify: `assets/admin/App.jsx` (podłącz `TypesTab`)

**Interfaces:**
- Consumes: `config.types` (lista `{key,label,price,capacity,active}`), `update('types')`
- Produces:
  - `typesOps.js`: `addType(types)`, `removeType(types, index)`, `updateType(types, index, patch)`
  - `<TypesTab config update />`

- [ ] **Step 1: Napisz failujące testy `typesOps`**

`assets/admin/ops/typesOps.test.js`:

```js
import { addType, removeType, updateType } from './typesOps';

describe( 'typesOps', () => {
	it( 'addType dodaje typ z domyślnymi wartościami', () => {
		const types = addType( [] );
		expect( types ).toHaveLength( 1 );
		expect( types[ 0 ] ).toMatchObject( { key: '', label: '', price: 0, capacity: null, active: true } );
	} );

	it( 'removeType usuwa po indeksie', () => {
		const types = addType( addType( [] ) );
		expect( removeType( types, 0 ) ).toHaveLength( 1 );
	} );

	it( 'updateType nadpisuje pola wskazanego typu', () => {
		let types = addType( [] );
		types = updateType( types, 0, { key: 'uczestnik', price: 450 } );
		expect( types[ 0 ].key ).toBe( 'uczestnik' );
		expect( types[ 0 ].price ).toBe( 450 );
	} );

	it( 'nie mutuje wejścia', () => {
		const types = addType( [] );
		const before = JSON.stringify( types );
		updateType( types, 0, { key: 'x' } );
		expect( JSON.stringify( types ) ).toBe( before );
	} );
} );
```

- [ ] **Step 2: Uruchom i potwierdź fail**

```bash
npm run test:js
```

Oczekiwane: FAIL — brak modułu.

- [ ] **Step 3: Zaimplementuj `typesOps.js`**

```js
export function addType( types ) {
	return [ ...( types || [] ), { key: '', label: '', price: 0, capacity: null, active: true } ];
}

export function removeType( types, index ) {
	return ( types || [] ).filter( ( _, i ) => i !== index );
}

export function updateType( types, index, patch ) {
	return ( types || [] ).map( ( type, i ) => ( i === index ? { ...type, ...patch } : type ) );
}
```

- [ ] **Step 4: Uruchom testy JS**

```bash
npm run test:js
```

Oczekiwane: testy `typesOps` zielone.

- [ ] **Step 5: Zaimplementuj `TypesTab.jsx`**

```jsx
import { Button, TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { addType, removeType, updateType } from '../ops/typesOps';

export default function TypesTab( { config, update } ) {
	const types = config.types || [];
	const setTypes = update( 'types' );

	return (
		<div className="evreg-types-tab">
			{ types.map( ( type, index ) => (
				<div className="evreg-type-row" key={ index }>
					<TextControl
						label={ __( 'Klucz', 'event-registration' ) }
						value={ type.key || '' }
						onChange={ ( key ) => setTypes( updateType( types, index, { key } ) ) }
					/>
					<TextControl
						label={ __( 'Nazwa', 'event-registration' ) }
						value={ type.label || '' }
						onChange={ ( label ) => setTypes( updateType( types, index, { label } ) ) }
					/>
					<TextControl
						type="number"
						label={ __( 'Cena', 'event-registration' ) }
						value={ type.price ?? 0 }
						onChange={ ( price ) =>
							setTypes( updateType( types, index, { price: parseFloat( price ) || 0 } ) )
						}
					/>
					<TextControl
						type="number"
						label={ __( 'Limit (puste = brak)', 'event-registration' ) }
						value={ type.capacity ?? '' }
						onChange={ ( capacity ) =>
							setTypes(
								updateType( types, index, {
									capacity: '' === capacity ? null : parseInt( capacity, 10 ),
								} )
							)
						}
					/>
					<ToggleControl
						label={ __( 'Aktywny', 'event-registration' ) }
						checked={ type.active !== false }
						onChange={ ( active ) => setTypes( updateType( types, index, { active } ) ) }
					/>
					<Button isDestructive onClick={ () => setTypes( removeType( types, index ) ) }>
						{ __( 'Usuń', 'event-registration' ) }
					</Button>
				</div>
			) ) }
			<Button variant="secondary" onClick={ () => setTypes( addType( types ) ) }>
				{ __( 'Dodaj typ', 'event-registration' ) }
			</Button>
		</div>
	);
}
```

- [ ] **Step 6: Podłącz `TypesTab` w `App.jsx`**

Dodaj import i gałąź w `TabRouter`:

```jsx
import TypesTab from './tabs/TypesTab';
// w TabRouter:
if ( 'types' === name ) {
	return <TypesTab config={ config } update={ update } />;
}
```

- [ ] **Step 7: Zbuduj i uruchom testy JS**

```bash
npm run build
npm run test:js
```

Oczekiwane: build i testy zielone.

- [ ] **Step 8: Commit**

```bash
git add assets
git commit -m "feat: add registration types tab with pure type operations"
```

---

## Task 5: Zakładka Noclegi — pakiety, pokoje, siatka inwentarza

**Files:**
- Create: `assets/admin/ops/accommodationOps.js`
- Create: `assets/admin/ops/accommodationOps.test.js`
- Create: `assets/admin/tabs/AccommodationTab.jsx`
- Modify: `assets/admin/App.jsx` (podłącz `AccommodationTab`)

**Interfaces:**
- Consumes: `config.accommodation` (`{packages:[{key,label}], rooms:[{key,label,roommate_field}], inventory:[{package,room,capacity,price}], allow_none}`), `update('accommodation')`
- Produces:
  - `accommodationOps.js`: `emptyAccommodation()`, `addPackage(acc)`, `removePackage(acc, key)`, `updatePackage(acc, key, patch)`, `addRoom(acc)`, `removeRoom(acc, key)`, `updateRoom(acc, key, patch)`, `setInventoryCell(acc, packageKey, roomKey, patch)`, `getInventoryCell(acc, packageKey, roomKey)`, `setAllowNone(acc, value)`
  - `<AccommodationTab config update />`

**Zasada:** inwentarz to macierz par pakiet × pokój. `setInventoryCell` tworzy lub aktualizuje wpis dla pary; usunięcie pakietu/pokoju usuwa też jego wpisy inwentarza. Cena i limit żyją na parze.

- [ ] **Step 1: Napisz failujące testy `accommodationOps`**

`assets/admin/ops/accommodationOps.test.js`:

```js
import {
	emptyAccommodation,
	addPackage,
	removePackage,
	updatePackage,
	addRoom,
	setInventoryCell,
	getInventoryCell,
	setAllowNone,
} from './accommodationOps';

describe( 'accommodationOps', () => {
	it( 'emptyAccommodation ma puste listy i allow_none domyślnie true', () => {
		expect( emptyAccommodation() ).toEqual( {
			packages: [],
			rooms: [],
			inventory: [],
			allow_none: true,
		} );
	} );

	it( 'addPackage dodaje pakiet', () => {
		const acc = addPackage( emptyAccommodation() );
		expect( acc.packages ).toHaveLength( 1 );
	} );

	it( 'updatePackage zmienia klucz i etykietę', () => {
		let acc = addPackage( emptyAccommodation() );
		const key = acc.packages[ 0 ].key;
		acc = updatePackage( acc, key, { key: 'n12', label: 'Noc 1–2' } );
		expect( acc.packages[ 0 ].key ).toBe( 'n12' );
	} );

	it( 'setInventoryCell tworzy wpis dla pary pakiet×pokój', () => {
		let acc = emptyAccommodation();
		acc = setInventoryCell( acc, 'n12', 'double', { capacity: 20, price: 180 } );
		expect( getInventoryCell( acc, 'n12', 'double' ) ).toMatchObject( { capacity: 20, price: 180 } );
	} );

	it( 'setInventoryCell aktualizuje istniejący wpis, nie duplikuje', () => {
		let acc = emptyAccommodation();
		acc = setInventoryCell( acc, 'n12', 'double', { capacity: 20, price: 180 } );
		acc = setInventoryCell( acc, 'n12', 'double', { capacity: 25 } );
		expect( acc.inventory ).toHaveLength( 1 );
		expect( getInventoryCell( acc, 'n12', 'double' ) ).toMatchObject( { capacity: 25, price: 180 } );
	} );

	it( 'removePackage usuwa pakiet i jego wpisy inwentarza', () => {
		let acc = emptyAccommodation();
		acc = setInventoryCell( acc, 'n12', 'double', { capacity: 20, price: 180 } );
		acc.packages = [ { key: 'n12', label: 'Noc 1–2' } ];
		acc = removePackage( acc, 'n12' );
		expect( acc.packages ).toHaveLength( 0 );
		expect( acc.inventory ).toHaveLength( 0 );
	} );

	it( 'setAllowNone przełącza flagę', () => {
		expect( setAllowNone( emptyAccommodation(), false ).allow_none ).toBe( false );
	} );

	it( 'nie mutuje wejścia', () => {
		const acc = emptyAccommodation();
		const before = JSON.stringify( acc );
		addPackage( acc );
		expect( JSON.stringify( acc ) ).toBe( before );
	} );
} );
```

- [ ] **Step 2: Uruchom i potwierdź fail**

```bash
npm run test:js
```

Oczekiwane: FAIL — brak modułu.

- [ ] **Step 3: Zaimplementuj `accommodationOps.js`**

```js
const clone = ( value ) => JSON.parse( JSON.stringify( value ) );

let counter = 0;
const nextKey = ( prefix ) => `${ prefix }_${ ++counter }`;

export function emptyAccommodation() {
	return { packages: [], rooms: [], inventory: [], allow_none: true };
}

function ensure( acc ) {
	const next = clone( acc || {} );
	next.packages = next.packages || [];
	next.rooms = next.rooms || [];
	next.inventory = next.inventory || [];
	if ( typeof next.allow_none === 'undefined' ) {
		next.allow_none = true;
	}
	return next;
}

export function addPackage( acc ) {
	const next = ensure( acc );
	next.packages.push( { key: nextKey( 'pkg' ), label: '' } );
	return next;
}

export function removePackage( acc, key ) {
	const next = ensure( acc );
	next.packages = next.packages.filter( ( p ) => p.key !== key );
	next.inventory = next.inventory.filter( ( i ) => i.package !== key );
	return next;
}

export function updatePackage( acc, key, patch ) {
	const next = ensure( acc );
	next.packages = next.packages.map( ( p ) => ( p.key === key ? { ...p, ...patch } : p ) );
	if ( patch.key && patch.key !== key ) {
		next.inventory = next.inventory.map( ( i ) =>
			i.package === key ? { ...i, package: patch.key } : i
		);
	}
	return next;
}

export function addRoom( acc ) {
	const next = ensure( acc );
	next.rooms.push( { key: nextKey( 'room' ), label: '', roommate_field: false } );
	return next;
}

export function removeRoom( acc, key ) {
	const next = ensure( acc );
	next.rooms = next.rooms.filter( ( r ) => r.key !== key );
	next.inventory = next.inventory.filter( ( i ) => i.room !== key );
	return next;
}

export function updateRoom( acc, key, patch ) {
	const next = ensure( acc );
	next.rooms = next.rooms.map( ( r ) => ( r.key === key ? { ...r, ...patch } : r ) );
	if ( patch.key && patch.key !== key ) {
		next.inventory = next.inventory.map( ( i ) =>
			i.room === key ? { ...i, room: patch.key } : i
		);
	}
	return next;
}

export function getInventoryCell( acc, packageKey, roomKey ) {
	const list = ( acc && acc.inventory ) || [];
	return list.find( ( i ) => i.package === packageKey && i.room === roomKey ) || null;
}

export function setInventoryCell( acc, packageKey, roomKey, patch ) {
	const next = ensure( acc );
	const index = next.inventory.findIndex(
		( i ) => i.package === packageKey && i.room === roomKey
	);

	if ( index === -1 ) {
		next.inventory.push( {
			package: packageKey,
			room: roomKey,
			capacity: 0,
			price: 0,
			...patch,
		} );
	} else {
		next.inventory[ index ] = { ...next.inventory[ index ], ...patch };
	}

	return next;
}

export function setAllowNone( acc, value ) {
	const next = ensure( acc );
	next.allow_none = !! value;
	return next;
}
```

Uwaga: `nextKey` używa modułowego licznika — deterministyczny w testach uruchamianych sekwencyjnie w jednym module; nie polegać na konkretnej wartości klucza w asercjach (testy sprawdzają liczność i zawartość, nie dosłowny klucz).

- [ ] **Step 4: Uruchom testy JS**

```bash
npm run test:js
```

Oczekiwane: testy `accommodationOps` zielone.

- [ ] **Step 5: Zaimplementuj `AccommodationTab.jsx`**

```jsx
import { Button, TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import {
	emptyAccommodation,
	addPackage,
	removePackage,
	updatePackage,
	addRoom,
	removeRoom,
	updateRoom,
	setInventoryCell,
	getInventoryCell,
	setAllowNone,
} from '../ops/accommodationOps';

export default function AccommodationTab( { config, update } ) {
	const acc = config.accommodation && config.accommodation.packages
		? config.accommodation
		: emptyAccommodation();
	const setAcc = update( 'accommodation' );

	return (
		<div className="evreg-accommodation-tab">
			<h3>{ __( 'Pakiety', 'event-registration' ) }</h3>
			{ ( acc.packages || [] ).map( ( pkg ) => (
				<div className="evreg-pkg-row" key={ pkg.key }>
					<TextControl
						label={ __( 'Klucz', 'event-registration' ) }
						value={ pkg.key }
						onChange={ ( key ) => setAcc( updatePackage( acc, pkg.key, { key } ) ) }
					/>
					<TextControl
						label={ __( 'Nazwa', 'event-registration' ) }
						value={ pkg.label || '' }
						onChange={ ( label ) => setAcc( updatePackage( acc, pkg.key, { label } ) ) }
					/>
					<Button isDestructive onClick={ () => setAcc( removePackage( acc, pkg.key ) ) }>
						{ __( 'Usuń', 'event-registration' ) }
					</Button>
				</div>
			) ) }
			<Button variant="secondary" onClick={ () => setAcc( addPackage( acc ) ) }>
				{ __( 'Dodaj pakiet', 'event-registration' ) }
			</Button>

			<h3>{ __( 'Pokoje', 'event-registration' ) }</h3>
			{ ( acc.rooms || [] ).map( ( room ) => (
				<div className="evreg-room-row" key={ room.key }>
					<TextControl
						label={ __( 'Klucz', 'event-registration' ) }
						value={ room.key }
						onChange={ ( key ) => setAcc( updateRoom( acc, room.key, { key } ) ) }
					/>
					<TextControl
						label={ __( 'Nazwa', 'event-registration' ) }
						value={ room.label || '' }
						onChange={ ( label ) => setAcc( updateRoom( acc, room.key, { label } ) ) }
					/>
					<ToggleControl
						label={ __( 'Pole współlokatora', 'event-registration' ) }
						checked={ !! room.roommate_field }
						onChange={ ( roommate_field ) =>
							setAcc( updateRoom( acc, room.key, { roommate_field } ) )
						}
					/>
					<Button isDestructive onClick={ () => setAcc( removeRoom( acc, room.key ) ) }>
						{ __( 'Usuń', 'event-registration' ) }
					</Button>
				</div>
			) ) }
			<Button variant="secondary" onClick={ () => setAcc( addRoom( acc ) ) }>
				{ __( 'Dodaj pokój', 'event-registration' ) }
			</Button>

			<h3>{ __( 'Inwentarz (limit / cena za parę)', 'event-registration' ) }</h3>
			<table className="evreg-inventory widefat">
				<thead>
					<tr>
						<th>{ __( 'Pakiet \\ Pokój', 'event-registration' ) }</th>
						{ ( acc.rooms || [] ).map( ( room ) => (
							<th key={ room.key }>{ room.label || room.key }</th>
						) ) }
					</tr>
				</thead>
				<tbody>
					{ ( acc.packages || [] ).map( ( pkg ) => (
						<tr key={ pkg.key }>
							<th>{ pkg.label || pkg.key }</th>
							{ ( acc.rooms || [] ).map( ( room ) => {
								const cell = getInventoryCell( acc, pkg.key, room.key ) || {};
								return (
									<td key={ room.key }>
										<TextControl
											type="number"
											label={ __( 'Limit', 'event-registration' ) }
											value={ cell.capacity ?? '' }
											onChange={ ( capacity ) =>
												setAcc(
													setInventoryCell( acc, pkg.key, room.key, {
														capacity: parseInt( capacity, 10 ) || 0,
													} )
												)
											}
										/>
										<TextControl
											type="number"
											label={ __( 'Cena', 'event-registration' ) }
											value={ cell.price ?? '' }
											onChange={ ( price ) =>
												setAcc(
													setInventoryCell( acc, pkg.key, room.key, {
														price: parseFloat( price ) || 0,
													} )
												)
											}
										/>
									</td>
								);
							} ) }
						</tr>
					) ) }
				</tbody>
			</table>

			<ToggleControl
				label={ __( 'Zezwól na brak noclegu', 'event-registration' ) }
				checked={ acc.allow_none !== false }
				onChange={ ( value ) => setAcc( setAllowNone( acc, value ) ) }
			/>
		</div>
	);
}
```

- [ ] **Step 6: Podłącz `AccommodationTab` w `App.jsx`**

```jsx
import AccommodationTab from './tabs/AccommodationTab';
// w TabRouter:
if ( 'accommodation' === name ) {
	return <AccommodationTab config={ config } update={ update } />;
}
```

- [ ] **Step 7: Zbuduj i uruchom testy JS**

```bash
npm run build
npm run test:js
```

Oczekiwane: build i testy zielone.

- [ ] **Step 8: Commit**

```bash
git add assets
git commit -m "feat: add accommodation tab with packages, rooms and inventory grid"
```

---

## Task 6: Zakładka Ustawienia

**Files:**
- Create: `assets/admin/tabs/SettingsTab.jsx`
- Modify: `assets/admin/App.jsx` (podłącz `SettingsTab`)

**Interfaces:**
- Consumes: `config.settings` (`{start_date,end_date,global_cap,waitlist_enabled,registration_opens,registration_closes,organizer_emails}`), `update('settings')`
- Produces: `<SettingsTab config update />`

Ustawienia są płaskie — komponent aktualizuje pola bezpośrednio przez `{ ...settings, [key]: value }`, bez osobnego modułu ops (brak nietrywialnej logiki do przetestowania). Wyjątek: `organizer_emails` to lista adresów edytowana jako tekst rozdzielony przecinkami; parsowanie/serializacja to jedyna logika i jest trywialna oraz inline.

- [ ] **Step 1: Zaimplementuj `SettingsTab.jsx`**

```jsx
import { TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function SettingsTab( { config, update } ) {
	const settings = config.settings || {};
	const setSettings = update( 'settings' );
	const set = ( key ) => ( value ) => setSettings( { ...settings, [ key ]: value } );

	const emails = Array.isArray( settings.organizer_emails )
		? settings.organizer_emails.join( ', ' )
		: '';

	return (
		<div className="evreg-settings-tab">
			<TextControl
				type="date"
				label={ __( 'Data rozpoczęcia', 'event-registration' ) }
				value={ settings.start_date || '' }
				onChange={ set( 'start_date' ) }
			/>
			<TextControl
				type="date"
				label={ __( 'Data zakończenia', 'event-registration' ) }
				value={ settings.end_date || '' }
				onChange={ set( 'end_date' ) }
			/>
			<TextControl
				type="number"
				label={ __( 'Limit miejsc (puste = brak)', 'event-registration' ) }
				value={ settings.global_cap ?? '' }
				onChange={ ( value ) =>
					setSettings( {
						...settings,
						global_cap: '' === value ? null : parseInt( value, 10 ),
					} )
				}
			/>
			<ToggleControl
				label={ __( 'Lista rezerwowa włączona', 'event-registration' ) }
				checked={ settings.waitlist_enabled !== false }
				onChange={ set( 'waitlist_enabled' ) }
			/>
			<TextControl
				type="date"
				label={ __( 'Otwarcie zapisów', 'event-registration' ) }
				value={ settings.registration_opens || '' }
				onChange={ set( 'registration_opens' ) }
			/>
			<TextControl
				type="date"
				label={ __( 'Zamknięcie zapisów', 'event-registration' ) }
				value={ settings.registration_closes || '' }
				onChange={ set( 'registration_closes' ) }
			/>
			<TextControl
				label={ __( 'E-maile organizatora (oddzielone przecinkami)', 'event-registration' ) }
				value={ emails }
				onChange={ ( value ) =>
					setSettings( {
						...settings,
						organizer_emails: value
							.split( ',' )
							.map( ( item ) => item.trim() )
							.filter( Boolean ),
					} )
				}
			/>
		</div>
	);
}
```

- [ ] **Step 2: Podłącz `SettingsTab` w `App.jsx` i usuń placeholder**

```jsx
import SettingsTab from './tabs/SettingsTab';
// w TabRouter — ostatnia gałąź:
if ( 'settings' === name ) {
	return <SettingsTab config={ config } update={ update } />;
}
return null;
```

Po tym tasku `TabRouter` nie ma już gałęzi placeholder — wszystkie cztery zakładki podłączone.

- [ ] **Step 3: Zbuduj i uruchom testy JS**

```bash
npm run build
npm run test:js
```

Oczekiwane: build przechodzi, wszystkie testy JS zielone (bez nowych — Settings nie ma modułu ops).

- [ ] **Step 4: Commit**

```bash
git add assets
git commit -m "feat: add event settings tab"
```

---

## Task 7: Ścieżka E2E Playwright

**Files:**
- Create: `playwright.config.js`
- Create: `tests/e2e/config-admin.spec.js`
- Modify: `package.json` (devDependency `@playwright/test`)
- Modify: `.github/workflows/ci.yml` (opcjonalny job E2E)

**Interfaces:**
- Consumes: działająca instancja wp-env (`http://localhost:8891`), zbudowany plugin (`npm run build`)
- Produces: `npm run test:e2e` przechodzący happy-path

**Zasada:** jedna ścieżka happy-path — zaloguj się jako admin, utwórz event, otwórz edycję, dodaj typ i pole, zapisz, przeładuj, potwierdź utrwalenie. To task izolowany: jeśli toolchain Playwright na tej maszynie się opiera, pozostałe taski są dowiezione i zweryfikowane (jest + weryfikacja kontrolera w przeglądarce). Zaraportuj BLOCKED z dokładnym błędem zamiast obchodzić.

- [ ] **Step 1: Zainstaluj Playwright (host)**

```bash
npm install --save-dev @playwright/test
npx playwright install chromium
```

Oczekiwane: chromium pobrany. Jeśli pobieranie przeglądarki zawiedzie (sieć/DNS jak przy wp-env) — zaraportuj BLOCKED z błędem; nie obchodź.

- [ ] **Step 2: Utwórz `playwright.config.js`**

```js
const { defineConfig } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: './tests/e2e',
	timeout: 60000,
	use: {
		baseURL: 'http://localhost:8891',
		headless: true,
	},
	reporter: 'line',
} );
```

- [ ] **Step 3: Napisz ścieżkę happy-path**

`tests/e2e/config-admin.spec.js`:

```js
const { test, expect } = require( '@playwright/test' );

async function login( page ) {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await expect( page ).toHaveURL( /wp-admin/ );
}

test( 'admin konfiguruje event i zapis się utrwala', async ( { page } ) => {
	await login( page );

	// Utwórz nowy event.
	await page.goto( '/wp-admin/post-new.php?post_type=evreg_event' );
	await page.fill( '#title', 'Event E2E' );

	// Aplikacja React montuje się pod tytułem.
	const root = page.locator( '#evreg-admin-root' );
	await expect( root ).toBeVisible();

	// Zakładka Typy — dodaj typ.
	await page.getByRole( 'tab', { name: 'Typy zgłoszenia' } ).click();
	await page.getByRole( 'button', { name: 'Dodaj typ' } ).click();
	await page.getByLabel( 'Klucz' ).first().fill( 'uczestnik' );
	await page.getByLabel( 'Nazwa' ).first().fill( 'Uczestnik' );

	// Zapisz.
	await page.getByRole( 'button', { name: 'Zapisz' } ).click();

	// Poczekaj na zakończenie zapisu (przycisk przestaje być zajęty).
	await expect( page.getByRole( 'button', { name: 'Zapisz' } ) ).toBeEnabled();

	// Przeładuj i potwierdź utrwalenie typu.
	await page.reload();
	await page.getByRole( 'tab', { name: 'Typy zgłoszenia' } ).click();
	await expect( page.getByLabel( 'Klucz' ).first() ).toHaveValue( 'uczestnik' );
} );
```

Uwaga: domyślne dane logowania wp-env to `admin`/`password`. Selektor tytułu w klasycznym edytorze to `#title`.

- [ ] **Step 4: Zbuduj plugin i uruchom E2E**

```bash
npm run build
node scripts/wp-env.cjs start
npm run test:e2e
```

Oczekiwane: 1 test E2E zielony. Jeśli test jest niestabilny na timeoutach montowania React, dodaj `await page.waitForSelector('#evreg-admin-root .evreg-admin')` po wejściu na ekran edycji. Jeśli toolchain nie startuje — BLOCKED z błędem.

- [ ] **Step 5: Dodaj job E2E do CI (opcjonalnie, jeśli E2E działa lokalnie)**

W `.github/workflows/ci.yml` dodaj job po `integration`:

```yaml
  e2e:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: '20'
      - run: npm ci
      - run: npm run build
      - run: npx playwright install --with-deps chromium
      - run: node scripts/wp-env.cjs start
      - run: npm run test:e2e
```

Jeśli E2E jest zbyt niestabilny w CI, pozostaw job zakomentowany z notatką — lokalne przejście wystarcza dla Planu 2B; utwardzenie CI to Plan 6.

- [ ] **Step 6: Commit**

```bash
git add playwright.config.js tests/e2e package.json package-lock.json .github/workflows/ci.yml
git commit -m "test: add Playwright e2e happy path for event config admin"
```

---

## Definicja ukończenia Planu 2B

- [ ] `npm run build` produkuje `build/admin/` bez błędów; enqueue ładuje aplikację tylko na ekranie edycji `evreg_event`
- [ ] Cztery zakładki funkcjonalne: Formularz (edytor schemy), Typy, Noclegi (siatka inwentarza), Ustawienia
- [ ] Konfiguracja ładuje się przez `GET`, zapisuje przez `PUT`, raport walidacji renderowany (escapowany)
- [ ] Cała logika mutacji w czystych modułach `ops/*`, pokryta `jest`
- [ ] `npm run test:js` zielony; PHPStan 6 i WPCS czyste; testy PHP integracyjne zielone
- [ ] Ścieżka Playwright E2E przechodzi lokalnie (lub udokumentowany BLOCKED toolchainu)
- [ ] Kontroler zweryfikował aplikację w przeglądarce (utworzenie eventu, edycja, zapis, przeładowanie)

## Czego Plan 2B świadomie nie robi

Brak drag & drop (kolejność strzałkami), brak edycji opcji `__type` inline (pochodzą z zakładki Typy), brak podglądu formularza publicznego (Plan 3), brak granularnych testów komponentów React (logika wyekstrahowana do `ops` i testowana `jest`; integracja przez E2E i weryfikację kontrolera). Warunki widoczności w UI ograniczone do pola `__type` (silnik generyczny z Planu 1 gotów na rozszerzenie w v4).
