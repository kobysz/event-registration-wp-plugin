# Field/Section Conditional Visibility Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a form builder show/hide any field or section based on the value of another field, end to end.

**Architecture:** The domain engine (`Condition`, `ConditionEngine`, `VisibilityResolver`) and server-side enforcement already exist. This plan adds: pure builder ops to set/clear conditions; a shared `conditionAttrs` renderer so fields emit the same `data-evreg-when-*` attributes sections already do; a generic `form.js` engine that mirrors `ConditionEngine` for any trigger field (replacing the `__type`-only hardcode); a React condition editor in `FieldRow` and `SectionEditor`; and a Playwright e2e proving client↔server parity.

**Tech Stack:** PHP 8.1 (WordPress plugin), `@wordpress/element`/`@wordpress/components` React admin, plain `form.js` (no build), jest (admin ops), PHPUnit (wp-env container), Playwright e2e.

**Spec:** `docs/superpowers/specs/2026-09-18-field-conditional-visibility-design.md`

## Global Constraints

- `src/Domain/**` = zero WordPress, zero `$wpdb`. Do not touch the engine.
- Builder mutation logic lives in pure `assets/admin/ops/*` modules, jest-tested, immutable (never mutate input). Components stay thin.
- All UI strings via i18n, text domain `event-registration` (`__()` from `@wordpress/i18n` in JS).
- Server is the validation arbiter; the client only mirrors visibility.
- Form fields are namespaced `evreg_field[<key>]` (checkbox-group: `evreg_field[<key>][]`).
- Operator values (exact): `equals`, `not_equals`, `in`, `not_in`, `empty`, `not_empty`. `empty`/`not_empty` carry no value.
- Choice field types (exact): `select`, `radio`, `checkbox-group` (`isChoice` in `assets/admin/ops/fieldTypes.js`). Single `checkbox` is NOT a choice field.
- PHP commands run in the wp-env container via `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- <cmd>`. JS/jest run on host via `npm`.
- After any test-requiring change, rebuild `event-registration.zip` via `bash scripts/build-zip.sh` (not part of per-task commits; done once at the end).

---

## Task 1: Builder ops — set/clear condition on field and section

**Files:**
- Modify: `assets/admin/ops/schemaOps.js`
- Test: `assets/admin/ops/schemaOps.test.js`

**Interfaces:**
- Consumes: existing `clone`, `addSection`, `addField`, `emptySchema` in `schemaOps.js`.
- Produces:
  - `setFieldCondition(schema, fieldKey, condition) -> schema`
  - `clearFieldCondition(schema, fieldKey) -> schema`
  - `setSectionCondition(schema, sectionKey, condition) -> schema`
  - `clearSectionCondition(schema, sectionKey) -> schema`
  - `condition` shape: `{ field: string, operator: string, value?: string|string[] }` or `null`.

- [ ] **Step 1: Write the failing tests**

Append to `assets/admin/ops/schemaOps.test.js` (add the four names to the existing import from `./schemaOps`):

```js
it( 'setFieldCondition ustawia warunek na polu', () => {
	let schema = addField( addSection( emptySchema(), 'dane', 'Dane' ), 'dane', { key: 'pwz', type: 'text', label: 'PWZ' } );
	schema = setFieldCondition( schema, 'pwz', { field: '__type', operator: 'equals', value: 'prelegent' } );
	expect( schema.sections[ 0 ].fields[ 0 ].condition ).toEqual( { field: '__type', operator: 'equals', value: 'prelegent' } );
} );

it( 'clearFieldCondition usuwa warunek (null)', () => {
	let schema = addField( addSection( emptySchema(), 'dane', 'Dane' ), 'dane', { key: 'pwz', type: 'text', label: 'PWZ' } );
	schema = setFieldCondition( schema, 'pwz', { field: '__type', operator: 'not_empty' } );
	schema = clearFieldCondition( schema, 'pwz' );
	expect( schema.sections[ 0 ].fields[ 0 ].condition ).toBeNull();
} );

it( 'setSectionCondition ustawia warunek na sekcji', () => {
	let schema = addSection( emptySchema(), 'extra', 'Extra' );
	schema = setSectionCondition( schema, 'extra', { field: '__type', operator: 'in', value: [ 'a', 'b' ] } );
	expect( schema.sections[ 0 ].condition ).toEqual( { field: '__type', operator: 'in', value: [ 'a', 'b' ] } );
} );

it( 'clearSectionCondition usuwa warunek sekcji (null)', () => {
	let schema = setSectionCondition( addSection( emptySchema(), 'extra', 'Extra' ), 'extra', { field: '__type', operator: 'not_empty' } );
	schema = clearSectionCondition( schema, 'extra' );
	expect( schema.sections[ 0 ].condition ).toBeNull();
} );

it( 'ops warunku nie mutują wejścia', () => {
	const schema = addField( addSection( emptySchema(), 'dane', 'Dane' ), 'dane', { key: 'pwz', type: 'text', label: 'PWZ' } );
	const before = JSON.stringify( schema );
	setFieldCondition( schema, 'pwz', { field: '__type', operator: 'equals', value: 'x' } );
	setSectionCondition( schema, 'dane', { field: '__type', operator: 'not_empty' } );
	expect( JSON.stringify( schema ) ).toBe( before );
} );
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `npm run test:js -- schemaOps`
Expected: FAIL — `setFieldCondition is not a function` (and siblings).

- [ ] **Step 3: Write minimal implementation**

Add to `assets/admin/ops/schemaOps.js` (before `ensureTypeField`):

```js
export function setFieldCondition( schema, fieldKey, condition ) {
	const next = clone( schema );
	( next.sections || [] ).forEach( ( section ) => {
		( section.fields || [] ).forEach( ( field ) => {
			if ( field.key === fieldKey ) {
				field.condition = condition;
			}
		} );
	} );
	return next;
}

export function clearFieldCondition( schema, fieldKey ) {
	return setFieldCondition( schema, fieldKey, null );
}

export function setSectionCondition( schema, sectionKey, condition ) {
	const next = clone( schema );
	( next.sections || [] ).forEach( ( section ) => {
		if ( section.key === sectionKey ) {
			section.condition = condition;
		}
	} );
	return next;
}

export function clearSectionCondition( schema, sectionKey ) {
	return setSectionCondition( schema, sectionKey, null );
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `npm run test:js -- schemaOps`
Expected: PASS (all schemaOps tests green).

- [ ] **Step 5: Commit**

```bash
git add assets/admin/ops/schemaOps.js assets/admin/ops/schemaOps.test.js
git commit -m "feat: schema ops to set/clear field and section conditions"
```

---

## Task 2: Condition UI helpers — operators and trigger options

**Files:**
- Create: `assets/admin/ops/conditionOps.js`
- Test: `assets/admin/ops/conditionOps.test.js`

**Interfaces:**
- Consumes: nothing (pure; imports `__` from `@wordpress/i18n` and `isChoice` from `./fieldTypes`).
- Produces:
  - `OPERATORS` — array of `{ value, label, needsValue, choiceOnly }`.
  - `availableOperators(triggerField) -> {value,label}[]` — choice field → all; non-choice → only `empty`/`not_empty`.
  - `triggerOptions(triggerField, types) -> {value,label}[]` — `__type` → from `types`; other choice → from `field.options`; else `[]`.
  - `eligibleTriggers(fields, selfKey) -> {key,label,type}[]` — fields usable as trigger.
  - `operatorNeedsValue(operator) -> boolean`.

- [ ] **Step 1: Write the failing tests**

Create `assets/admin/ops/conditionOps.test.js`:

```js
import {
	availableOperators,
	triggerOptions,
	eligibleTriggers,
	operatorNeedsValue,
} from './conditionOps';

describe( 'conditionOps', () => {
	it( 'availableOperators dla pola wyboru daje wszystkie', () => {
		const ops = availableOperators( { key: 't', type: 'radio' } ).map( ( o ) => o.value );
		expect( ops ).toEqual( [ 'equals', 'not_equals', 'in', 'not_in', 'empty', 'not_empty' ] );
	} );

	it( 'availableOperators dla pola nie-wyboru daje tylko empty/not_empty', () => {
		const ops = availableOperators( { key: 't', type: 'text' } ).map( ( o ) => o.value );
		expect( ops ).toEqual( [ 'empty', 'not_empty' ] );
	} );

	it( 'triggerOptions dla __type bierze z types', () => {
		const opts = triggerOptions(
			{ key: '__type', type: 'radio', options: [] },
			[ { key: 'pacjent', label: 'Pacjent' }, { key: 'prelegent', label: 'Prelegent' } ]
		);
		expect( opts ).toEqual( [
			{ value: 'pacjent', label: 'Pacjent' },
			{ value: 'prelegent', label: 'Prelegent' },
		] );
	} );

	it( 'triggerOptions dla zwykłego pola wyboru bierze z options', () => {
		const opts = triggerOptions( { key: 'roz', type: 'select', options: [ { value: 's', label: 'S' } ] }, [] );
		expect( opts ).toEqual( [ { value: 's', label: 'S' } ] );
	} );

	it( 'eligibleTriggers pomija siebie oraz heading/paragraph/accommodation', () => {
		const fields = [
			{ key: '__type', type: 'radio', label: 'Typ' },
			{ key: 'naglowek', type: 'heading', label: 'H' },
			{ key: 'nocleg', type: 'accommodation', label: 'N' },
			{ key: 'pwz', type: 'text', label: 'PWZ' },
		];
		const keys = eligibleTriggers( fields, 'pwz' ).map( ( f ) => f.key );
		expect( keys ).toEqual( [ '__type' ] );
	} );

	it( 'operatorNeedsValue rozróżnia empty/not_empty od reszty', () => {
		expect( operatorNeedsValue( 'equals' ) ).toBe( true );
		expect( operatorNeedsValue( 'empty' ) ).toBe( false );
		expect( operatorNeedsValue( 'not_empty' ) ).toBe( false );
	} );
} );
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `npm run test:js -- conditionOps`
Expected: FAIL — module not found.

- [ ] **Step 3: Write minimal implementation**

Create `assets/admin/ops/conditionOps.js`:

```js
import { __ } from '@wordpress/i18n';
import { isChoice } from './fieldTypes';

export const OPERATORS = [
	{ value: 'equals', label: __( 'równe', 'event-registration' ), needsValue: true, choiceOnly: true },
	{ value: 'not_equals', label: __( 'różne od', 'event-registration' ), needsValue: true, choiceOnly: true },
	{ value: 'in', label: __( 'jest jedną z', 'event-registration' ), needsValue: true, choiceOnly: true },
	{ value: 'not_in', label: __( 'nie jest żadną z', 'event-registration' ), needsValue: true, choiceOnly: true },
	{ value: 'empty', label: __( 'puste', 'event-registration' ), needsValue: false, choiceOnly: false },
	{ value: 'not_empty', label: __( 'niepuste', 'event-registration' ), needsValue: false, choiceOnly: false },
];

export function operatorNeedsValue( operator ) {
	const found = OPERATORS.find( ( o ) => o.value === operator );
	return found ? found.needsValue : false;
}

export function availableOperators( triggerField ) {
	const choice = !! triggerField && isChoice( triggerField.type );
	return OPERATORS.filter( ( o ) => choice || ! o.choiceOnly ).map( ( o ) => ( {
		value: o.value,
		label: o.label,
	} ) );
}

export function triggerOptions( triggerField, types ) {
	if ( ! triggerField ) {
		return [];
	}
	if ( triggerField.key === '__type' ) {
		return ( types || [] ).map( ( t ) => ( { value: t.key, label: t.label || t.key } ) );
	}
	if ( isChoice( triggerField.type ) ) {
		return ( triggerField.options || [] ).map( ( o ) => ( { value: o.value, label: o.label || o.value } ) );
	}
	return [];
}

export function eligibleTriggers( fields, selfKey ) {
	const excluded = [ 'heading', 'paragraph', 'accommodation' ];
	return ( fields || [] ).filter(
		( f ) => f.key !== selfKey && ! excluded.includes( f.type )
	);
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `npm run test:js -- conditionOps`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add assets/admin/ops/conditionOps.js assets/admin/ops/conditionOps.test.js
git commit -m "feat: condition UI helpers (operators, trigger options)"
```

---

## Task 3: Render condition attributes on fields (shared helper)

**Files:**
- Modify: `src/Frontend/FormRenderer.php`
- Test: `tests/Integration/Frontend/FormRendererTest.php`

**Interfaces:**
- Consumes: existing `renderSection`, `renderField`; `Condition` value object (`src/Domain/Conditions/Condition.php`) with `->field`, `->operator->value`, `->operator->needsValue()`, `->value`.
- Produces: private `conditionAttrs( ?Condition $condition ): string` used by both `renderSection` and `renderField`. Field wrapper `<div>` gains `data-evreg-when-*` when the field has a condition.

- [ ] **Step 1: Write the failing test**

Add to `tests/Integration/Frontend/FormRendererTest.php`:

```php
public function test_field_with_condition_emits_when_attributes(): void {
	$schema = FormSchema::fromArray(
		array(
			'version'  => 1,
			'sections' => array(
				array(
					'key'    => 'dane',
					'title'  => 'Dane',
					'fields' => array(
						array( 'key' => '__type', 'type' => 'radio', 'label' => 'Typ', 'options' => array( array( 'value' => 'prelegent', 'label' => 'Prelegent' ) ) ),
						array(
							'key'       => 'pwz',
							'type'      => 'text',
							'label'     => 'PWZ',
							'condition' => array( 'field' => '__type', 'operator' => 'equals', 'value' => 'prelegent' ),
						),
					),
				),
			),
		)
	);

	$html = $this->renderer->render( $schema, 1 );

	$this->assertMatchesRegularExpression(
		'/<div class="evreg-field evreg-field-text[^"]*"[^>]*data-evreg-when-field="__type"[^>]*data-evreg-when-operator="equals"[^>]*data-evreg-when-value="&quot;prelegent&quot;"/',
		$html
	);
}

public function test_field_condition_empty_operator_has_no_value_attr(): void {
	$schema = FormSchema::fromArray(
		array(
			'version'  => 1,
			'sections' => array(
				array(
					'key'    => 'dane',
					'title'  => 'Dane',
					'fields' => array(
						array( 'key' => 'email', 'type' => 'email', 'label' => 'E-mail' ),
						array( 'key' => 'pwz', 'type' => 'text', 'label' => 'PWZ', 'condition' => array( 'field' => 'email', 'operator' => 'not_empty' ) ),
					),
				),
			),
		)
	);

	$html = $this->renderer->render( $schema, 1 );

	$this->assertStringContainsString( 'data-evreg-when-operator="not_empty"', $html );
	$this->assertStringNotContainsString( 'data-evreg-when-value', $html );
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter test_field_with_condition_emits_when_attributes`
Expected: FAIL — field wrapper has no `data-evreg-when-field`.

- [ ] **Step 3: Write minimal implementation**

In `src/Frontend/FormRenderer.php`:

Add the import near the other `use` lines:

```php
use EvReg\Domain\Conditions\Condition;
```

Add the shared helper (place it right after `renderSection`):

```php
	/**
	 * Serializuje warunek widoczności do atrybutów data-evreg-when-*.
	 * Wspólne dla sekcji i pól. Operatory bez wartości (empty/not_empty)
	 * pomijają data-evreg-when-value.
	 *
	 * @param Condition|null $condition Warunek albo null.
	 */
	private function conditionAttrs( ?Condition $condition ): string {
		if ( null === $condition ) {
			return '';
		}

		$attrs = ' data-evreg-when-field="' . esc_attr( $condition->field ) . '"'
			. ' data-evreg-when-operator="' . esc_attr( $condition->operator->value ) . '"';

		if ( $condition->operator->needsValue() ) {
			$value  = wp_json_encode( $condition->value );
			$attrs .= ' data-evreg-when-value="' . esc_attr( false !== $value ? $value : '' ) . '"';
		}

		return $attrs;
	}
```

Replace the inline attribute block in `renderSection` with a call to the helper. Change:

```php
		$attrs = '';
		if ( null !== $section->condition ) {
			$condition_value = wp_json_encode( $section->condition->value );
			$attrs           = ' data-evreg-when-field="' . esc_attr( $section->condition->field ) . '"'
				. ' data-evreg-when-operator="' . esc_attr( $section->condition->operator->value ) . '"'
				. ' data-evreg-when-value="' . esc_attr( false !== $condition_value ? $condition_value : '' ) . '"';
		}

		$out  = '<fieldset class="evreg-section mb-4"' . $attrs . '>';
```

to:

```php
		$out = '<fieldset class="evreg-section mb-4"' . $this->conditionAttrs( $section->condition ) . '>';
```

In `renderField`, add the condition attrs to the field wrapper `<div>`. Both branches currently open with `$out = '<div class="' . $class . '">';` (checkbox branch adds ` form-check`). Change the wrapper openings to include the attrs. For the non-checkbox branch:

```php
			$out  = '<div class="' . $class . '"' . $this->conditionAttrs( $field->condition ) . '>';
```

For the checkbox branch:

```php
			$out  = '<div class="' . $class . ' form-check"' . $this->conditionAttrs( $field->condition ) . '>';
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter FormRendererTest`
Expected: PASS (both new tests and all existing FormRenderer tests — the section-condition rendering is unchanged for value-carrying operators).

- [ ] **Step 5: phpcs + phpstan**

Run:
```bash
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcbf src/Frontend/FormRenderer.php
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs src/Frontend/FormRenderer.php
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=1G
```
Expected: phpcs clean, phpstan "No errors".

- [ ] **Step 6: Commit**

```bash
git add src/Frontend/FormRenderer.php tests/Integration/Frontend/FormRendererTest.php
git commit -m "feat: emit conditional-visibility attributes on field wrappers"
```

---

## Task 4: Generic client engine in form.js

**Files:**
- Modify: `assets/public/form.js`

**Interfaces:**
- Consumes: the `data-evreg-when-*` attributes rendered by Task 3 (fields) and existing sections.
- Produces: generic evaluation replacing the `__type`-only `currentType`/`matches`/`applyConditional`. Keeps `applyRoommate` untouched.

**Note:** `form.js` is served raw (no build, no jest). Its behavior is verified by the Playwright e2e in Task 6. This task has no unit test; treat Task 6 as its test and keep the JS a faithful mirror of `ConditionEngine`.

- [ ] **Step 1: Replace the conditional engine**

Rewrite `assets/public/form.js` so the top (everything except the `applyRoommate`/accommodation section) becomes:

```js
( function () {
	function toList( value ) {
		if ( value === null || value === undefined ) { return []; }
		if ( Array.isArray( value ) ) { return value.map( String ); }
		var s = String( value );
		return s === '' ? [] : [ s ];
	}

	function listEquals( a, b ) {
		if ( a.length !== b.length ) { return false; }
		for ( var i = 0; i < a.length; i++ ) { if ( a[ i ] !== b[ i ] ) { return false; } }
		return true;
	}

	function intersects( a, b ) {
		for ( var i = 0; i < a.length; i++ ) { if ( b.indexOf( a[ i ] ) !== -1 ) { return true; } }
		return false;
	}

	// Lustro EvReg\Domain\Conditions\ConditionEngine::isMet (semantyka listowa).
	function isMet( operator, answer, expected ) {
		switch ( operator ) {
			case 'equals': return listEquals( answer, expected );
			case 'not_equals': return ! listEquals( answer, expected );
			case 'in': return intersects( answer, expected );
			case 'not_in': return ! intersects( answer, expected );
			case 'empty': return answer.length === 0;
			case 'not_empty': return answer.length > 0;
			default: return true;
		}
	}

	// Bieżąca wartość pola triggera jako lista stringów (lustro toList na odpowiedzi).
	function readTrigger( form, key ) {
		var out = [];
		var nodes = form.querySelectorAll(
			'[name="evreg_field[' + key + ']"], [name="evreg_field[' + key + '][]"]'
		);
		nodes.forEach( function ( node ) {
			if ( node.type === 'checkbox' || node.type === 'radio' ) {
				if ( node.checked ) { out.push( String( node.value ) ); }
			} else if ( node.value !== '' ) {
				out.push( String( node.value ) );
			}
		} );
		return out;
	}

	function apply( form ) {
		form.querySelectorAll( '[data-evreg-when-field]' ).forEach( function ( el ) {
			var key = el.getAttribute( 'data-evreg-when-field' );
			var operator = el.getAttribute( 'data-evreg-when-operator' );
			var raw = el.getAttribute( 'data-evreg-when-value' );
			var expected;
			try { expected = toList( JSON.parse( raw ) ); } catch ( e ) { expected = toList( raw ); }
			el.hidden = ! isMet( operator, readTrigger( form, key ), expected );
		} );
	}

	function selectedSlotAllowsRoommate( container ) {
		var radio = container.querySelector( 'input[type="radio"][name$="[slot]"]:checked' );
		if ( radio ) { return radio.getAttribute( 'data-evreg-roommate' ) === '1'; }
		var select = container.querySelector( 'select[name$="[slot]"]' );
		if ( select && select.selectedIndex >= 0 ) {
			var option = select.options[ select.selectedIndex ];
			return !! ( option && option.getAttribute( 'data-evreg-roommate' ) === '1' );
		}
		return false;
	}

	function applyRoommate( container ) {
		var input = container.querySelector( '[data-evreg-roommate-input]' );
		if ( ! input ) { return; }
		input.hidden = ! selectedSlotAllowsRoommate( container );
	}

	document.querySelectorAll( '.evreg-form' ).forEach( function ( form ) {
		apply( form );
		form.addEventListener( 'change', function () { apply( form ); } );
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
}() );
```

This removes `currentType`/`matches`/`applyConditional` (the `__type` hardcode) and the `input[name="__type"]`-scoped listener, replacing them with the generic `apply` triggered by any `change`/`input`.

- [ ] **Step 2: Syntax check**

Run: `node --check assets/public/form.js`
Expected: no output (valid).

- [ ] **Step 3: Commit**

```bash
git add assets/public/form.js
git commit -m "feat: generic conditional-visibility engine in form.js (any trigger)"
```

---

## Task 5: Condition editor in the builder (FieldRow + SectionEditor)

**Files:**
- Create: `assets/admin/components/ConditionEditor.jsx`
- Modify: `assets/admin/components/FieldRow.jsx`
- Modify: `assets/admin/components/SectionEditor.jsx`
- Modify: `assets/admin/tabs/FormTab.jsx`
- Modify: `assets/admin/style.css`

**Interfaces:**
- Consumes: `availableOperators`, `triggerOptions`, `eligibleTriggers`, `operatorNeedsValue` from `../ops/conditionOps`; `setFieldCondition`/`clearFieldCondition`/`setSectionCondition`/`clearSectionCondition` from `../ops/schemaOps`.
- Produces: `ConditionEditor` React component.
- `ConditionEditor` props:
  - `condition`: current condition object or `null`.
  - `triggers`: `{key,label,type,options?}[]` — eligible trigger fields.
  - `types`: `config.types` (for `__type` options).
  - `onChange( condition | null )`: called with the new condition or `null` to clear.

**Note:** React components have no unit-test harness in this repo (only ops via jest); the pure logic is already covered by Task 2. This component is verified live in wp-env and by the Task 6 e2e. Keep it thin — it only wires the Task 2 helpers to controls.

- [ ] **Step 1: Create the component**

Create `assets/admin/components/ConditionEditor.jsx`:

```jsx
import { ToggleControl, SelectControl, CheckboxControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import {
	availableOperators,
	triggerOptions,
	operatorNeedsValue,
} from '../ops/conditionOps';

export default function ConditionEditor( { condition, triggers, types, onChange } ) {
	const enabled = !! condition;

	const triggerField = condition
		? triggers.find( ( t ) => t.key === condition.field ) || null
		: null;
	const operators = availableOperators( triggerField );
	const options = triggerOptions( triggerField, types );
	const selected = Array.isArray( condition?.value )
		? condition.value
		: condition?.value
		? [ condition.value ]
		: [];

	const onToggle = ( on ) => {
		if ( ! on ) {
			onChange( null );
			return;
		}
		const first = triggers[ 0 ] || null;
		onChange( { field: first ? first.key : '', operator: 'not_empty' } );
	};

	const onTrigger = ( field ) => {
		// Reset operator/value gdy zmienia się trigger (opcje mogą zniknąć).
		onChange( { field, operator: 'not_empty' } );
	};

	const onOperator = ( operator ) => {
		const next = { field: condition.field, operator };
		if ( operatorNeedsValue( operator ) ) {
			next.value = 'in' === operator || 'not_in' === operator ? [] : '';
		}
		onChange( next );
	};

	const onSingleValue = ( value ) => onChange( { ...condition, value } );

	const onMultiValue = ( optionValue, checked ) => {
		const set = new Set( selected );
		if ( checked ) {
			set.add( optionValue );
		} else {
			set.delete( optionValue );
		}
		onChange( { ...condition, value: Array.from( set ) } );
	};

	return (
		<div className="evreg-condition">
			<ToggleControl
				label={ __( 'Pokaż warunkowo', 'event-registration' ) }
				checked={ enabled }
				onChange={ onToggle }
			/>

			{ enabled && (
				<div className="evreg-condition__body">
					<SelectControl
						label={ __( 'Gdy pole', 'event-registration' ) }
						value={ condition.field }
						options={ triggers.map( ( t ) => ( { value: t.key, label: t.label || t.key } ) ) }
						onChange={ onTrigger }
					/>
					<SelectControl
						label={ __( 'Operator', 'event-registration' ) }
						value={ condition.operator }
						options={ operators }
						onChange={ onOperator }
					/>

					{ operatorNeedsValue( condition.operator ) &&
						( 'in' === condition.operator || 'not_in' === condition.operator ) && (
							<div className="evreg-condition__values">
								{ options.map( ( o ) => (
									<CheckboxControl
										key={ o.value }
										label={ o.label }
										checked={ selected.includes( o.value ) }
										onChange={ ( checked ) => onMultiValue( o.value, checked ) }
									/>
								) ) }
							</div>
						) }

					{ operatorNeedsValue( condition.operator ) &&
						'in' !== condition.operator &&
						'not_in' !== condition.operator && (
							<SelectControl
								label={ __( 'Wartość', 'event-registration' ) }
								value={ Array.isArray( condition.value ) ? '' : condition.value || '' }
								options={ [ { value: '', label: __( '— wybierz —', 'event-registration' ) }, ...options ] }
								onChange={ onSingleValue }
							/>
						) }
				</div>
			) }
		</div>
	);
}
```

- [ ] **Step 2: Wire into FieldRow**

In `assets/admin/components/FieldRow.jsx`:

Add imports:

```jsx
import ConditionEditor from './ConditionEditor';
```

Add props `triggers`, `types`, `onConditionChange` to the component signature (alongside `field`, `onChange`, `onRemove`, `pinned`, ...). For the pinned `__type` field, do NOT render the editor (its visibility is not conditional). Render the editor inside `evreg-field-row__body`, after the options editor block and before the special note:

```jsx
{ ! special && (
	<ConditionEditor
		condition={ field.condition || null }
		triggers={ triggers }
		types={ types }
		onChange={ ( condition ) => onConditionChange( field.key, condition ) }
	/>
) }
```

- [ ] **Step 3: Wire into SectionEditor**

In `assets/admin/components/SectionEditor.jsx`, add props `triggers`, `types`, `onSectionConditionChange`, and render the editor inside the section body (below the fields list) :

```jsx
<ConditionEditor
	condition={ section.condition || null }
	triggers={ triggers }
	types={ types }
	onChange={ ( condition ) => onSectionConditionChange( section.key, condition ) }
/>
```

Add `import ConditionEditor from './ConditionEditor';` at the top.

- [ ] **Step 4: Wire FormTab (compute triggers, pass handlers)**

In `assets/admin/tabs/FormTab.jsx`:

Add imports:

```jsx
import { setFieldCondition, setSectionCondition } from '../ops/schemaOps';
import { eligibleTriggers } from '../ops/conditionOps';
```

Compute the flat field list once inside the component:

```jsx
const allFields = schema.sections.flatMap( ( s ) => s.fields || [] );
```

Pass to each `SectionEditor` the trigger list and handlers. For fields, triggers exclude the field itself; for sections, triggers are all eligible fields. Add these props to the `<SectionEditor ... />` usage:

```jsx
triggers={ eligibleTriggers( allFields, null ) }
types={ config.types || [] }
onFieldConditionChange={ ( key, condition ) => setSchema( setFieldCondition( schema, key, condition ) ) }
onSectionConditionChange={ ( key, condition ) => setSchema( setSectionCondition( schema, key, condition ) ) }
```

In `SectionEditor`, pass per-field triggers to each `SortableFieldRow`/`FieldRow`: compute `eligibleTriggers( <all fields>, field.key )`. To keep `SectionEditor` from needing the whole schema, pass `triggers` (all eligible) down and let `FieldRow` filter self out, OR pass an `allFields` array to `SectionEditor` and compute per field. Simplest: pass `allFields` into `SectionEditor`, and inside its field map compute `eligibleTriggers( allFields, field.key )` for each `FieldRow`. Update `FormTab` to pass `allFields={ allFields }` and `types` and the two handlers; update `SectionEditor` to accept `allFields`, `types`, `onFieldConditionChange`, `onSectionConditionChange`, import `eligibleTriggers`, and pass computed `triggers`/`types`/`onConditionChange` into each `FieldRow` (and `triggers={ eligibleTriggers( allFields, null ) }` into its own `ConditionEditor`).

- [ ] **Step 5: CSS**

Append to `assets/admin/style.css`:

```css
.evreg-condition {
	margin-top: 8px;
	padding: 10px 12px;
	border: 1px dashed #c3c4c7;
	border-radius: 4px;
	background: #fbfbfc;
}

.evreg-condition__body {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-top: 6px;
}

.evreg-condition__values {
	display: flex;
	flex-direction: column;
	gap: 2px;
}
```

- [ ] **Step 6: Build + jest + verify live**

Run:
```bash
npm run test:js
npm run build
```
Expected: all jest green; build succeeds.

Then in wp-env (`node scripts/wp-env.cjs start` if needed), on an event's Formularz tab: add a text field, enable "Pokaż warunkowo", pick `__type` = a type value with `equals`, Save → "Konfiguracja jest poprawna". Confirm the saved `_evreg_schema` field has the `condition`.

- [ ] **Step 7: Commit**

```bash
git add assets/admin/components/ConditionEditor.jsx assets/admin/components/FieldRow.jsx assets/admin/components/SectionEditor.jsx assets/admin/tabs/FormTab.jsx assets/admin/style.css
git commit -m "feat: conditional-visibility editor in the form builder"
```

---

## Task 6: e2e — conditional visibility on the public form

**Files:**
- Create: `tests/e2e/conditional-visibility.spec.js`
- Create: `tests/e2e/setup-conditional.php`

**Interfaces:**
- Consumes: the rendered `data-evreg-when-*` attributes (Task 3) and the generic `form.js` (Task 4).
- Produces: an e2e proving a field shows/hides as the trigger changes, and that submitting with a hidden required field does not block (server parity via `VisibilityResolver`).

- [ ] **Step 1: Write the PHP setup (event + page)**

Create `tests/e2e/setup-conditional.php` modeled on `tests/e2e/setup-public-form.php` (read that file first for the exact repository/meta calls). It must:
- Create an `evreg_event` post.
- Save config with a schema whose `dane` section has: `__type` radio; an `email` field (required); and a `pwz` text field with `condition = { field:'__type', operator:'equals', value:'prelegent' }`.
- Save `types` with two types: `pacjent` and `prelegent`.
- Create a published page containing `[evreg_form event="<id>"]`.
- Echo `"<eventId> <pageId>"` for the spec to parse (same contract as `setup-public-form.php`).

- [ ] **Step 2: Write the failing e2e**

Create `tests/e2e/conditional-visibility.spec.js` (mirror the structure of `tests/e2e/public-form.spec.js` for setup/parsing):

```js
const { test, expect } = require( '@playwright/test' );
const { execSync } = require( 'child_process' );

test( 'conditional field toggles with the trigger', async ( { page } ) => {
	const out = execSync(
		'node scripts/wp-env.cjs run cli --env-cwd=wp-content/plugins/event-registration -- wp eval-file tests/e2e/setup-conditional.php',
		{ encoding: 'utf8' }
	);
	const line = out.trim().split( '\n' ).pop().trim();
	const [ , pageId ] = line.split( ' ' );

	await page.goto( `http://localhost:8891/?page_id=${ pageId }` );

	const pwz = page.locator( '.evreg-field-text' ).filter( { hasText: 'PWZ' } );

	// Domyślnie żaden typ nie wybrany → warunek equals=prelegent niespełniony → ukryte.
	await expect( pwz ).toBeHidden();

	// Wybór "pacjent" — nadal ukryte.
	await page.locator( 'input[name="evreg_field[__type]"][value="pacjent"]' ).check();
	await expect( pwz ).toBeHidden();

	// Wybór "prelegent" — pokazane.
	await page.locator( 'input[name="evreg_field[__type]"][value="prelegent"]' ).check();
	await expect( pwz ).toBeVisible();
} );
```

- [ ] **Step 3: Run it to verify it fails (before Tasks 3–4 exist) or passes (after)**

Run: `npm run test:e2e -- conditional-visibility`
Expected after Tasks 3–5: PASS. (If run earlier, it fails because the field never hides.)

- [ ] **Step 4: Commit**

```bash
git add tests/e2e/conditional-visibility.spec.js tests/e2e/setup-conditional.php
git commit -m "test: e2e for conditional field visibility on the public form"
```

---

## Task 7: Full verification + package

**Files:** none (verification only).

- [ ] **Step 1: Full test suites**

Run:
```bash
npm run test:js
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=1G
npm run test:e2e
```
Expected: all green.

- [ ] **Step 2: Rebuild the package**

Run: `npm run build && bash scripts/build-zip.sh`
Expected: `event-registration.zip` rebuilt.

- [ ] **Step 3: Update backlog**

Mark B1 done in `docs/superpowers/backlog.md`. Commit:

```bash
git add docs/superpowers/backlog.md
git commit -m "docs: mark backlog B1 (conditional visibility) done"
```

---

## Self-Review Notes

- **Spec coverage:** ops (Task 1), UI helpers (Task 2), render attrs + shared helper (Task 3), generic client engine (Task 4), builder editor for fields+sections (Task 5), e2e parity (Task 6), verification+package (Task 7). All spec sections covered.
- **Parity:** `isMet` in Task 4 mirrors `ConditionEngine::isMet` operator-for-operator (equals=list-equal, in=intersect, empty=empty-list); `readTrigger` mirrors `toList`. e2e (Task 6) verifies in a real browser.
- **`__type` special case:** `triggerOptions` (Task 2) sources `__type` values from `config.types`; covered by a jest test.
- **Type consistency:** condition shape `{field, operator, value?}` is identical across ops (Task 1), helpers (Task 2), render test (Task 3), form.js (Task 4), editor (Task 5), e2e setup (Task 6).
