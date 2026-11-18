<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration;

use WP_UnitTestCase;

/**
 * Wtyczka NIE wysyła żadnego katalogu tłumaczeń — źródłem stringów jest polski
 * (msgid = polski literał), a `languages/` niesie wyłącznie `.pot` dla ewentualnego
 * tłumacza. Katalog en_US został usunięty świadomie (backlog B38): był nieaktualny
 * od miesięcy, więc anglojęzyczny odwiedzający i tak widział mieszankę polskiego
 * i nieaktualnego angielskiego.
 *
 * Dlatego ten test pilnuje dziś JEDNEJ rzeczy: że brak katalogu degraduje się do
 * źródła, a nie do pustego stringa. Gdyby kiedyś doszedł katalog jakiegoś locale,
 * drugi test — że `load_plugin_textdomain` faktycznie go podnosi — wraca tutaj.
 */
final class I18nTest extends WP_UnitTestCase {

	protected function tearDown(): void {
		unload_textdomain( 'event-registration' );
		restore_current_locale();
		parent::tearDown();
	}

	public function test_source_polish_is_returned_without_any_catalog(): void {
		// Domyślny unload (nie-reloadable) JEST tu zamierzony: zatrzaskuje domenę
		// jako wyładowaną, więc mechanizm just-in-time WordPressa nie podniesie
		// katalogu za naszymi plecami przy wywołaniu __() niżej.
		unload_textdomain( 'event-registration' );

		$this->assertSame(
			'Wyślij zgłoszenie',
			__( 'Wyślij zgłoszenie', 'event-registration' )
		);
		$this->assertSame(
			'Bez noclegu',
			__( 'Bez noclegu', 'event-registration' )
		);
	}

	public function test_english_locale_also_falls_back_to_source(): void {
		switch_to_locale( 'en_US' );
		unload_textdomain( 'event-registration', true );

		$this->assertSame(
			'Wyślij zgłoszenie',
			__( 'Wyślij zgłoszenie', 'event-registration' )
		);
	}
}
