<?php
/**
 * Fixture setup for the room-occupancy Playwright E2E spec.
 *
 * Run via `wp eval-file` (WP-CLI) inside the wp-env dev container. Creates an
 * `evreg_event` with one single-occupancy and one double-occupancy room, both
 * with plenty of seats, plus a page embedding the form shortcode. Makes no
 * reservations: this spec exercises the companion filter, not availability.
 *
 * Prints a single line "<eventId> <pageId>" to stdout for the spec to parse.
 *
 * @package EvReg
 */

\EvReg\Persistence\Migrations::install();

$event_id = wp_insert_post(
	array(
		'post_type'   => 'evreg_event',
		'post_status' => 'publish',
		'post_title'  => 'E2E Room Occupancy',
	),
	true
);

if ( is_wp_error( $event_id ) ) {
	fwrite( STDERR, 'Failed to create evreg_event: ' . $event_id->get_error_message() . "\n" );
	exit( 1 );
}

( new \EvReg\Persistence\EventConfigRepository() )->save(
	(int) $event_id,
	array(
		'schema'        => array(
			'version'  => 1,
			'sections' => array(
				array(
					'key'    => 'dane',
					'title'  => 'Dane',
					'fields' => array(
						array( 'key' => '__type', 'type' => 'radio', 'label' => 'Typ' ),
						array( 'key' => 'email', 'type' => 'email', 'label' => 'E-mail', 'required' => true ),
						array( 'key' => 'nocleg', 'type' => 'accommodation', 'label' => 'Nocleg' ),
					),
				),
			),
		),
		'types'         => array( array( 'key' => 'uczestnik', 'label' => 'Uczestnik', 'price' => 0 ) ),
		'accommodation' => array(
			'packages'          => array( array( 'key' => 'n18', 'label' => 'Noc 18/19' ) ),
			'rooms'             => array(
				array( 'key' => 'single', 'label' => 'Jednoosobowy', 'occupancy' => 1 ),
				array( 'key' => 'double', 'label' => 'Dwuosobowy', 'occupancy' => 2 ),
			),
			'inventory'         => array(
				array( 'package' => 'n18', 'room' => 'single', 'capacity' => 10, 'price' => 100.0 ),
				array( 'package' => 'n18', 'room' => 'double', 'capacity' => 10, 'price' => 180.0 ),
			),
			'allow_none'        => true,
			'companion_enabled' => true,
		),
		'settings'      => array( 'global_cap' => null, 'waitlist_enabled' => true ),
	)
);

$page_id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Zapisy z liczbą osób',
		'post_content' => '[evreg_form event="' . (int) $event_id . '"]',
	),
	true
);

if ( is_wp_error( $page_id ) ) {
	fwrite( STDERR, 'Failed to create page: ' . $page_id->get_error_message() . "\n" );
	exit( 1 );
}

echo (int) $event_id . ' ' . (int) $page_id . "\n";
