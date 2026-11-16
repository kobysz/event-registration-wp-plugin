<?php
/**
 * Fixture setup for the accommodation-availability Playwright E2E spec.
 *
 * Run via `wp eval-file` (WP-CLI) inside the wp-env dev container. Creates an
 * `evreg_event` whose accommodation inventory has two slots of one seat each,
 * reserves one of them through ReservationService (so the slot is genuinely
 * exhausted in the DB), and publishes a page embedding the form shortcode.
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
		'post_title'  => 'E2E Accommodation Availability',
	),
	true
);

if ( is_wp_error( $event_id ) ) {
	fwrite( STDERR, 'Failed to create evreg_event: ' . $event_id->get_error_message() . "\n" );
	exit( 1 );
}

$accommodation = array(
	'packages'          => array( array( 'key' => 'n18', 'label' => 'Noc 18/19' ) ),
	'rooms'             => array(
		array( 'key' => 'single', 'label' => 'Jednoosobowy' ),
		array( 'key' => 'double', 'label' => 'Dwuosobowy' ),
	),
	'inventory'         => array(
		array( 'package' => 'n18', 'room' => 'single', 'capacity' => 1, 'price' => 100.0 ),
		array( 'package' => 'n18', 'room' => 'double', 'capacity' => 1, 'price' => 180.0 ),
	),
	'allow_none'        => true,
	'companion_enabled' => true,
);

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
		'accommodation' => $accommodation,
		'settings'      => array( 'global_cap' => null, 'waitlist_enabled' => true ),
	)
);

// Zajmij jedyne miejsce w slocie n18|single — przez serwis, żeby obłożenie liczyło się
// tak samo jak w produkcji (wiersz zgłoszenia + booking z seats).
( new \EvReg\Services\ReservationService(
	new \EvReg\Persistence\RegistrationRepository(),
	new \EvReg\Persistence\EventConfigRepository()
) )->reserve(
	(int) $event_id,
	new \EvReg\Services\ReservationRequest(
		'e2e-zajete@example.com',
		'Jan',
		'uczestnik',
		array( 'email' => 'e2e-zajete@example.com' ),
		new \EvReg\Domain\Accommodation\AccommodationSelection( 'n18', 'single' )
	)
);

$page_id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Zapisy z noclegiem',
		'post_content' => '[evreg_form event="' . (int) $event_id . '"]',
	),
	true
);

if ( is_wp_error( $page_id ) ) {
	fwrite( STDERR, 'Failed to create page: ' . $page_id->get_error_message() . "\n" );
	exit( 1 );
}

echo (int) $event_id . ' ' . (int) $page_id . "\n";
