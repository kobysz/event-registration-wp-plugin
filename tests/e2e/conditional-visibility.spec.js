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

// B14: ukryta warunkowo kontrolka `required` nie może blokować wysyłki (przeglądarka nie
// sfokusuje ukrytej kontrolki i przerywa submit bez komunikatu), a po pokazaniu MUSI
// znów być wymagana — inaczej test przeszedłby po usunięciu `required` zewsząd.
test( 'a hidden required field does not block submit; once shown it does', async ( { page } ) => {
	const out = execSync(
		'node scripts/wp-env.cjs run cli --env-cwd=wp-content/plugins/event-registration -- wp eval-file tests/e2e/setup-conditional.php',
		{ encoding: 'utf8' }
	);
	const [ , pageId ] = out.trim().split( '\n' ).pop().trim().split( /\s+/ );
	const email = `cond-${ Date.now() }@example.com`;

	await page.goto( `http://localhost:8891/?page_id=${ pageId }` );

	const pwz = page.locator( 'input[name="evreg_field[pwz]"]' );
	const temat = page.locator( 'input[name="evreg_field[temat]"]' );
	const abstrakt = page.locator( 'input[name="evreg_field[abstrakt]"]' );
	const validity = ( loc ) => loc.evaluate( ( el ) => ( { required: el.required, valid: el.validity.valid } ) );

	// Pacjent: pwz, sekcja „Prelegent" i zagnieżdżony abstrakt są schowane i nie są wymagane.
	await page.check( 'input[name="evreg_field[__type]"][value="pacjent"]' );
	await expect( pwz ).toBeHidden();
	await expect( temat ).toBeHidden();
	expect( ( await validity( pwz ) ).required ).toBe( false );
	expect( ( await validity( temat ) ).required ).toBe( false );
	expect( ( await validity( abstrakt ) ).required ).toBe( false );

	// Prelegent: kontrolki wracają do wymaganych (round-trip), a formularz nie przejdzie
	// natywnej walidacji — z komunikatem na widocznym polu.
	await page.check( 'input[name="evreg_field[__type]"][value="prelegent"]' );
	await expect( pwz ).toBeVisible();
	expect( ( await validity( pwz ) ).required ).toBe( true );
	expect( ( await validity( temat ) ).required ).toBe( true );
	// Zagnieżdżony abstrakt nadal schowany własnym warunkiem (temat pusty) → nie wymagany,
	// mimo że kontener nadrzędny został właśnie pokazany.
	await expect( abstrakt ).toBeHidden();
	expect( ( await validity( abstrakt ) ).required ).toBe( false );
	const formValid = await page.locator( '.evreg-form' ).evaluate( ( f ) => f.checkValidity() );
	expect( formValid ).toBe( false );
	expect( await pwz.evaluate( ( el ) => el.validationMessage ) ).not.toBe( '' );

	// Wypełnienie tematu pokazuje abstrakt i znów czyni go wymaganym.
	await temat.fill( 'Temat referatu' );
	await expect( abstrakt ).toBeVisible();
	expect( ( await validity( abstrakt ) ).required ).toBe( true );

	// Wracamy na pacjenta: wszystko schowane → submit przechodzi mimo wymaganych pól w środku.
	await page.check( 'input[name="evreg_field[__type]"][value="pacjent"]' );
	await page.fill( 'input[name="evreg_field[email]"]', email );
	await page.waitForTimeout( 3500 ); // bramka antyspamowa MIN_FILL_SECONDS
	await page.locator( 'button.evreg-submit' ).click();
	await expect( page.locator( '.evreg-success' ) ).toBeVisible();
} );
