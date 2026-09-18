<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration;

use EvReg\Plugin;
use WP_UnitTestCase;

final class I18nTest extends WP_UnitTestCase {

	protected function tearDown(): void {
		unload_textdomain( 'event-registration' );
		restore_current_locale();
		parent::tearDown();
	}

	public function test_php_strings_translate_to_english_under_en_us(): void {
		switch_to_locale( 'en_US' );
		// Reloadable unload: the plugin's own bootstrap (e.g. CPT labels calling
		// __()) may already have just-in-time loaded this domain before this test
		// runs. A default (non-reloadable) unload_textdomain() latches the domain
		// into $l10n_unloaded, which permanently blocks WP's just-in-time
		// (re)loading for the rest of the process — see
		// wp-includes/l10n.php _load_textdomain_just_in_time(). Passing
		// reloadable=true drops the cached translations without setting that
		// latch, so the load_plugin_textdomain() call below can actually take
		// effect the next time __() is called for this domain.
		unload_textdomain( 'event-registration', true );
		load_plugin_textdomain(
			'event-registration',
			false,
			dirname( plugin_basename( Plugin::plugin_file() ) ) . '/languages'
		);

		$this->assertSame(
			'Submit registration',
			__( 'Wyślij zgłoszenie', 'event-registration' )
		);
		$this->assertSame(
			'No accommodation',
			__( 'Bez noclegu', 'event-registration' )
		);
	}

	public function test_polish_source_returned_without_catalog(): void {
		// Locale default in tests is en_US; without our textdomain loaded the
		// msgid (Polish source) is returned verbatim — proves the assertion above
		// is really exercising the loaded .mo, not a coincidence.
		//
		// Unlike the test above, this uses the default non-reloadable unload: it
		// must latch the domain as unloaded so WP's just-in-time loading
		// mechanism does NOT silently reload the catalog behind our back when
		// __() is called below.
		unload_textdomain( 'event-registration' );
		$this->assertSame(
			'Wyślij zgłoszenie',
			__( 'Wyślij zgłoszenie', 'event-registration' )
		);
	}
}
