const { test, expect } = require( '@playwright/test' );
const { execSync } = require( 'child_process' );

test( 'exhausted slots are disabled and the companion toggle closes one-seat slots', async ( { page } ) => {
	// Fixture: event z dwoma slotami po 1 miejscu; n18|single już zajęty.
	// Zobacz tests/e2e/setup-accommodation-availability.php.
	const out = execSync(
		'node scripts/wp-env.cjs run cli --env-cwd=wp-content/plugins/event-registration -- wp eval-file tests/e2e/setup-accommodation-availability.php',
		{ encoding: 'utf8' }
	);
	const line = out.trim().split( '\n' ).pop().trim();
	const [ , pageId ] = line.split( /\s+/ );

	await page.goto( `http://localhost:8891/?page_id=${ pageId }` );

	const single = page.locator( 'input[name="evreg_field[nocleg][slot]"][value="n18|single"]' );
	const double = page.locator( 'input[name="evreg_field[nocleg][slot]"][value="n18|double"]' );

	// Zajęty slot: wyłączony serwerowo, z dopiskiem.
	await expect( single ).toBeDisabled();
	await expect( page.locator( '.evreg-choice.is-full' ) ).toContainText( '(brak miejsc)' );

	// Slot z jednym wolnym miejscem: dostępny dla jednej osoby.
	await expect( double ).toBeEnabled();
	await double.check();
	await expect( double ).toBeChecked();

	// Osoba towarzysząca potrzebuje dwóch miejsc → slot się wyłącza, a zaznaczenie
	// przechodzi na „Bez noclegu" (pusta grupa radio wysyłałaby pusty [slot]).
	await page.locator( 'input[name="evreg_companion"]' ).check();
	await expect( double ).toBeDisabled();
	await expect( double ).not.toBeChecked();
	await expect( page.locator( 'input[name="evreg_field[nocleg][slot]"][value=""]' ) ).toBeChecked();
} );
