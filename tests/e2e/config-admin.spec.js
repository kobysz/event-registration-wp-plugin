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

	// Utwórz nowy event — zapisz szkic, by uzyskać realne ID posta
	// (konfiguracja REST wymaga istniejącego posta; eventId=0 na
	// post-new.php nie przechodzi sprawdzenia uprawnień edit_post).
	await page.goto( '/wp-admin/post-new.php?post_type=evreg_event' );
	await page.fill( '#title', 'Event E2E' );
	await page.getByRole( 'button', { name: 'Save Draft' } ).click();
	await page.waitForURL( /post\.php\?post=\d+&action=edit/ );

	// Otwórz ekran edycji utrwalonego eventu — aplikacja React montuje
	// się pod tytułem.
	const root = page.locator( '#evreg-admin-root' );
	await expect( root ).toBeVisible();
	await page.waitForSelector( '#evreg-admin-root .evreg-admin' );

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
	await page.waitForSelector( '#evreg-admin-root .evreg-admin' );
	await page.getByRole( 'tab', { name: 'Typy zgłoszenia' } ).click();
	await expect( page.getByLabel( 'Klucz' ).first() ).toHaveValue( 'uczestnik' );
} );
