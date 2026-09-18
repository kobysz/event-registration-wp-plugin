<?php
/**
 * Bootstrap dla testów integracyjnych — ładuje suite testowy WordPressa.
 */

declare( strict_types=1 );

$tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/wordpress-phpunit';

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once $tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/event-registration.php';
		// Testy asertują polskie stringi źródłowe. Od kiedy shipujemy katalog
		// en_US, WP na domyślnym locale en_US tłumaczyłby je na angielski.
		// Wymuszamy pl_PL (brak pl_PL.mo → zwracany jest polski msgid = źródło).
		// I18nTest sam przełącza się na en_US przez switch_to_locale.
		add_filter( 'locale', static fn (): string => 'pl_PL' );
	}
);

require $tests_dir . '/includes/bootstrap.php';
