<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * src/Domain musi pozostać wolne od WordPressa — inaczej testy jednostkowe przestaną działać bez WP.
 */
final class DomainPurityTest extends TestCase {

	private const FORBIDDEN = array(
		'$wpdb',
		'add_action(',
		'add_filter(',
		'apply_filters(',
		'do_action(',
		'get_option(',
		'update_option(',
		'wp_mail(',
		'sanitize_text_field(',
		'esc_html(',
		'__(',
		'ABSPATH',
	);

	public function test_domain_contains_no_wordpress_calls(): void {
		$root = dirname( __DIR__, 3 ) . '/src/Domain';

		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );

		$violations = array();

		foreach ( $files as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}

			$contents = (string) file_get_contents( $file->getPathname() );

			foreach ( self::FORBIDDEN as $needle ) {
				if ( str_contains( $contents, $needle ) ) {
					$violations[] = $file->getFilename() . ' → ' . $needle;
				}
			}
		}

		$this->assertSame( array(), $violations, "Wywołania WordPressa w src/Domain:\n" . implode( "\n", $violations ) );
	}
}
