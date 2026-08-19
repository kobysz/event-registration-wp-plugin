<?php
/**
 * Fixture setup for the public-form Playwright E2E spec.
 *
 * Run via `wp eval-file` (WP-CLI) inside the wp-env dev container. Not a
 * plugin file — lives under tests/e2e/ and is not autoloaded/committed
 * into the plugin's runtime code path.
 *
 * Creates an `evreg_event` post with a Model-2 config (schema/types/settings
 * persisted through the plugin's own EventConfigRepository::save(), so the
 * meta shape is guaranteed to match what EventFormLoader/SchemaAssembler
 * expect), plus a `page` embedding `[evreg_form event="<id>"]`.
 *
 * Prints a single line "<eventId> <pageId>" to stdout for the spec to parse.
 *
 * @package EvReg
 */

$event_id = wp_insert_post(
	array(
		'post_type'   => 'evreg_event',
		'post_status' => 'publish',
		'post_title'  => 'E2E Event',
	),
	true
);

if ( is_wp_error( $event_id ) ) {
	fwrite( STDERR, 'Failed to create evreg_event: ' . $event_id->get_error_message() . "\n" );
	exit( 1 );
}

$schema = array(
	'version'  => 1,
	'sections' => array(
		array(
			'key'    => 'dane',
			'title'  => 'Dane',
			'fields' => array(
				array(
					'key'   => '__type',
					'type'  => 'radio',
					'label' => 'Typ',
				),
				array(
					'key'      => 'imie',
					'type'     => 'text',
					'label'    => 'Imie',
					'required' => true,
				),
				array(
					'key'      => 'email',
					'type'     => 'email',
					'label'    => 'E-mail',
					'required' => true,
				),
			),
		),
	),
);

$types = array(
	array(
		'key'   => 'uczestnik',
		'label' => 'Uczestnik',
		'price' => 0,
	),
);

$settings = array(
	'global_cap'       => null,
	'waitlist_enabled' => true,
);

( new \EvReg\Persistence\EventConfigRepository() )->save(
	(int) $event_id,
	array(
		'schema'   => $schema,
		'types'    => $types,
		'settings' => $settings,
	)
);

$page_id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Zapisy',
		'post_content' => '[evreg_form event="' . (int) $event_id . '"]',
	),
	true
);

if ( is_wp_error( $page_id ) ) {
	fwrite( STDERR, 'Failed to create page: ' . $page_id->get_error_message() . "\n" );
	exit( 1 );
}

echo (int) $event_id . ' ' . (int) $page_id . "\n";
