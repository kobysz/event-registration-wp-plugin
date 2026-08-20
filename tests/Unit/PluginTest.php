<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit;

use EvReg\Plugin;
use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase {

	public function test_autoloader_resolves_plugin_class(): void {
		$this->assertTrue( class_exists( Plugin::class ) );
	}
}
