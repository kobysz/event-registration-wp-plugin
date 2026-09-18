const { test, expect } = require( '@playwright/test' );
const { execSync } = require( 'child_process' );

test( 'conditional field toggles with the trigger', async ( { page } ) => {
	// Fixture: creates an evreg_event whose `pwz` field is conditional on
	// `__type` equalling `prelegent` (Model-2 config via EventConfigRepository::save()),
	// plus a page embedding the [evreg_form] shortcode. See
	// tests/e2e/setup-conditional.php for details.
	const out = execSync(
		'node scripts/wp-env.cjs run cli --env-cwd=wp-content/plugins/event-registration -- wp eval-file tests/e2e/setup-conditional.php',
		{ encoding: 'utf8' }
	);
	const line = out.trim().split( '\n' ).pop().trim();
	const [ , pageId ] = line.split( /\s+/ );

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
