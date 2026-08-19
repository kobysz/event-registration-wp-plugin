const { test, expect } = require( '@playwright/test' );
const { execSync } = require( 'child_process' );

const EMAIL = 'e2e@example.com';

function wp( args ) {
	const cmd = 'node scripts/wp-env.cjs run cli --env-cwd=wp-content/plugins/event-registration -- wp ' + args;
	return execSync( cmd, { encoding: 'utf8' } );
}

let pagePath;

test.beforeAll( () => {
	// Fixture: creates an evreg_event with a Model-2 config (schema/types/settings)
	// persisted via EventConfigRepository::save(), plus a page embedding the
	// [evreg_form] shortcode. See tests/e2e/setup-public-form.php for details.
	const output = wp( 'eval-file tests/e2e/setup-public-form.php' );
	const line = output.trim().split( '\n' ).pop().trim();
	const [ , pageId ] = line.split( /\s+/ );

	pagePath = '/?page_id=' + pageId;
} );

test( 'participant fills the form, submits, and confirms via token link', async ( { page } ) => {
	await page.goto( pagePath );
	await expect( page.locator( '.evreg-form' ) ).toBeVisible();

	await page.check( 'input[name="__type"][value="uczestnik"]' );
	await page.fill( 'input[name="imie"]', 'Jan Testowy' );
	await page.fill( 'input[name="email"]', EMAIL );

	// Wait out the antispam min-fill-time gate (>= 3s, see SubmitHandler::MIN_FILL_SECONDS).
	await page.waitForTimeout( 3500 );
	await page.getByRole( 'button', { name: 'Wyślij zgłoszenie' } ).click();

	await expect( page.locator( '.evreg-success' ) ).toBeVisible();

	// Fetch the pending registration's token directly from the DB (dev env prefix is "wp_").
	const tokenOutput = wp(
		`db query "SELECT token FROM wp_evreg_registrations WHERE email=\'${ EMAIL }\' ORDER BY id DESC LIMIT 1" --skip-column-names`
	);
	const token = tokenOutput.trim().split( '\n' ).pop().trim();
	expect( token ).toMatch( /^[0-9a-f]{32}$/ );

	await page.goto( '/?evreg_confirm=' + token );
	await expect( page ).toHaveURL( /evreg_confirmed=confirmed/ );
} );
