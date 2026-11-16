const { test, expect } = require( '@playwright/test' );
const { execSync } = require( 'child_process' );

test( 'companion checkbox filters the room list by occupancy', async ( { page } ) => {
	const out = execSync(
		'node scripts/wp-env.cjs run cli --env-cwd=wp-content/plugins/event-registration -- wp eval-file tests/e2e/setup-room-occupancy.php',
		{ encoding: 'utf8' }
	);
	const line = out.trim().split( '\n' ).pop().trim();
	const [ , pageId ] = line.split( /\s+/ );

	await page.goto( `http://localhost:8891/?page_id=${ pageId }` );

	const single = page.locator( 'input[name="evreg_field[nocleg][slot]"][value="n18|single"]' );
	const double = page.locator( 'input[name="evreg_field[nocleg][slot]"][value="n18|double"]' );
	const none = page.locator( 'input[name="evreg_field[nocleg][slot]"][value=""]' );
	const companion = page.locator( 'input[name="evreg_companion"]' );

	// Checkbox towarzyszącej musi być nad listą.
	await expect( companion ).toBeVisible();

	// Bez towarzyszącej: tylko jednoosobowy.
	await expect( single ).toBeVisible();
	await expect( double ).toBeHidden();
	await expect( double ).toBeDisabled();

	await single.check();
	await expect( single ).toBeChecked();

	// Zaznaczenie towarzyszącej: lista przeskakuje na dwuosobowy, a zaznaczenie
	// jednoosobowego przenosi się na „Bez noclegu" (pusta grupa radio wysyłałaby pusty slot).
	await companion.check();
	await expect( double ).toBeVisible();
	await expect( single ).toBeHidden();
	await expect( single ).not.toBeChecked();
	await expect( none ).toBeChecked();

	// Odznaczenie wraca do stanu wyjściowego.
	await companion.uncheck();
	await expect( single ).toBeVisible();
	await expect( double ).toBeHidden();
	await expect( single ).toBeEnabled();

	// Każdy pokój pasuje do którejś strony filtra — komunikatu o braku dopasowań nie ma.
	await expect( page.locator( '.evreg-accommodation-mismatch' ) ).toBeHidden();
} );

test( 'mismatch message follows the filter when every room is two-person', async ( { page } ) => {
	const out = execSync(
		'node scripts/wp-env.cjs run cli --env-cwd=wp-content/plugins/event-registration -- wp eval-file tests/e2e/setup-room-occupancy.php',
		{ encoding: 'utf8' }
	);
	const line = out.trim().split( '\n' ).pop().trim();
	const [ , , doublesPageId ] = line.split( /\s+/ );

	await page.goto( 'http://localhost:8891/?page_id=' + doublesPageId );

	const double = page.locator( 'input[name="evreg_field[nocleg][slot]"][value="n18|double"]' );
	const companion = page.locator( 'input[name="evreg_companion"]' );
	const mismatch = page.locator( '.evreg-accommodation-mismatch' );

	// Bez towarzyszącej żaden pokój nie pasuje: lista pusta i wyjaśnienie dlaczego.
	await expect( double ).toBeHidden();
	await expect( mismatch ).toBeVisible();

	// Zaznaczenie towarzyszącej przywraca pokój dwuosobowy i chowa wyjaśnienie.
	await companion.check();
	await expect( double ).toBeVisible();
	await expect( double ).toBeEnabled();
	await expect( mismatch ).toBeHidden();

	// Odznaczenie znów je pokazuje.
	await companion.uncheck();
	await expect( double ).toBeHidden();
	await expect( mismatch ).toBeVisible();
} );
