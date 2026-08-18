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
	}
);

require $tests_dir . '/includes/bootstrap.php';
