const { test, expect } = require( '@playwright/test' );
const { execSync } = require( 'child_process' );

let statePageId;
let submitPageId;

test.beforeAll( () => {
	const out = execSync(
		'node scripts/wp-env.cjs run cli --env-cwd=wp-content/plugins/event-registration -- wp eval-file tests/e2e/setup-night-seats.php',
		{ encoding: 'utf8' }
	);
	const line = out.trim().split( '\n' ).pop().trim();
	const parts = line.split( /\s+/ );
	statePageId = parts[ 1 ];
	submitPageId = parts[ 3 ];
} );

const night = ( page, key ) => page.locator( `input[name="evreg_field[nocleg][nights][]"][value="${ key }"]` );
const noteOf = ( page, key, kind ) =>
	night( page, key )
		.locator( 'xpath=ancestor::div[contains(concat(" ",@class," ")," evreg-night ")]' )
		.locator( `[data-evreg-night-note="${ kind }"]` );

test( 'night checkboxes mirror seats and the companion toggle in both directions', async ( { page } ) => {
	await page.goto( `http://localhost:8891/?page_id=${ statePageId }` );

	const companion = page.locator( 'input[name="evreg_companion"]' );
	const dropped = page.locator( '.evreg-nights-dropped' );

	// Initial state, lone registrant: n1, n2 and n4 are open, n3 has no seats.
	await expect( night( page, 'n1' ) ).toBeEnabled();
	await expect( night( page, 'n2' ) ).toBeEnabled();
	await expect( night( page, 'n4' ) ).toBeEnabled();
	await expect( night( page, 'n3' ) ).toBeDisabled();
	await expect( noteOf( page, 'n3', 'full' ) ).toBeVisible();
	await expect( noteOf( page, 'n3', 'full' ) ).toHaveText( '(brak miejsc)' );
	await expect( noteOf( page, 'n2', 'pair' ) ).toBeHidden();
	await expect( dropped ).toBeHidden();

	// Tick n1, n2 (one seat left) and n4, then add a companion.
	await night( page, 'n1' ).check();
	await night( page, 'n2' ).check();
	await night( page, 'n4' ).check();
	await expect( night( page, 'n2' ) ).toBeChecked();

	await companion.check();

	// n2 (one seat) and n4 (companion cap 0) close for a pair; their ticks are cleared with a note.
	await expect( night( page, 'n2' ) ).toBeDisabled();
	await expect( night( page, 'n2' ) ).not.toBeChecked();
	await expect( noteOf( page, 'n2', 'pair' ) ).toBeVisible();
	await expect( noteOf( page, 'n2', 'pair' ) ).toHaveText( '(brak miejsc dla dwóch osób)' );
	await expect( noteOf( page, 'n2', 'full' ) ).toBeHidden();
	await expect( night( page, 'n4' ) ).toBeDisabled();
	await expect( night( page, 'n4' ) ).not.toBeChecked();
	await expect( noteOf( page, 'n4', 'pair' ) ).toBeVisible();
	await expect( dropped ).toBeVisible();

	// n1 has room for a pair and keeps its tick; n3 stays closed for the "no seats" reason.
	await expect( night( page, 'n1' ) ).toBeEnabled();
	await expect( night( page, 'n1' ) ).toBeChecked();
	await expect( night( page, 'n3' ) ).toBeDisabled();
	await expect( noteOf( page, 'n3', 'full' ) ).toBeVisible();
	await expect( noteOf( page, 'n3', 'pair' ) ).toBeHidden();

	// Unticking the companion reopens the nights closed only for a pair (the mirror's point).
	await companion.uncheck();
	await expect( night( page, 'n2' ) ).toBeEnabled();
	await expect( night( page, 'n4' ) ).toBeEnabled();
	await expect( noteOf( page, 'n2', 'pair' ) ).toBeHidden();
	await expect( noteOf( page, 'n4', 'pair' ) ).toBeHidden();
	// A cleared tick is not restored silently.
	await expect( night( page, 'n2' ) ).not.toBeChecked();
	// n3 has no seats whatever the companion state.
	await expect( night( page, 'n3' ) ).toBeDisabled();

	// Ticking an available night retires the stale "dropped" notice.
	await night( page, 'n2' ).check();
	await expect( dropped ).toBeHidden();
} );

test( 'a lone registrant ticks both nights and submits; the full night then renders closed', async ( { page } ) => {
	await page.goto( `http://localhost:8891/?page_id=${ submitPageId }` );

	await night( page, 's1' ).check();
	await night( page, 's2' ).check();
	await expect( night( page, 's1' ) ).toBeChecked();
	await expect( night( page, 's2' ) ).toBeChecked();

	await page.check( 'input[name="evreg_field[__type]"][value="uczestnik"]' );
	await page.fill( 'input[name="evreg_field[email]"]', 'nights-single@example.com' );
	// Wait out the antispam min-fill-time gate (see SubmitHandler::MIN_FILL_SECONDS).
	await page.waitForTimeout( 3500 );
	await page.locator( 'button.evreg-submit' ).click();
	await expect( page.locator( '.evreg-success' ) ).toBeVisible();

	// Next visitor: s2 had one seat and it is gone, so it renders closed.
	await page.goto( `http://localhost:8891/?page_id=${ submitPageId }` );
	await expect( night( page, 's2' ) ).toBeDisabled();
	await expect( noteOf( page, 's2', 'full' ) ).toBeVisible();
	await expect( night( page, 's1' ) ).toBeEnabled();

	// s1 has two seats left, so a pair still fits there.
	await page.locator( 'input[name="evreg_companion"]' ).check();
	await expect( night( page, 's1' ) ).toBeEnabled();
} );
