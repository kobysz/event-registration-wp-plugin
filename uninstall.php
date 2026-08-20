<?php
/**
 * Odinstalowanie Event Registration — czyszczenie stanu za bramką.
 *
 * @package EvReg
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

if ( class_exists( \EvReg\Persistence\Uninstaller::class ) ) {
	\EvReg\Persistence\Uninstaller::run();
}
