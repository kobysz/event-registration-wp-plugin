<?php
/**
 * Fixture setup for the night-seats Playwright E2E spec.
 *
 * Run via `wp eval-file` (WP-CLI) inside the wp-env dev container. Creates two
 * `evreg_event` posts in the "nights" accommodation mode, each with its own page
 * embedding the form shortcode. Makes no reservations.
 *
 * State event nights: n1 (5 seats, open), n2 (1 seat: room for a lone registrant,
 * none for a pair), n3 (0 seats: closed), n4 (5 seats but companion_cap 0: closed
 * to pairs, open to a lone registrant).
 * Submit event nights: s1 (3 seats), s2 (1 seat).
 *
 * Prints a single line "<stateEventId> <statePageId> <submitEventId> <submitPageId>" to stdout.
 *
 * @package EvReg
 */

\EvReg\Persistence\Migrations::install();

/**
 * Creates an event in nights mode plus a page embedding its form.
 *
 * @param string           $title  Event title.
 * @param array<int,mixed> $nights Night definitions.
 * @return array{0:int,1:int}
 */
function evreg_e2e_nights_event( string $title, array $nights ): array {
	$event_id = wp_insert_post(
		array(
			'post_type'   => 'evreg_event',
			'post_status' => 'publish',
			'post_title'  => $title,
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
				'mode'              => 'nights',
				'nights'            => $nights,
				'allow_none'        => true,
				'companion_enabled' => true,
			),
			'settings'      => array( 'global_cap' => null, 'waitlist_enabled' => false ),
		)
	);

	$page_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $title . ' page',
			'post_content' => '[evreg_form event="' . (int) $event_id . '"]',
		),
		true
	);

	if ( is_wp_error( $page_id ) ) {
		fwrite( STDERR, 'Failed to create page: ' . $page_id->get_error_message() . "\n" );
		exit( 1 );
	}

	return array( (int) $event_id, (int) $page_id );
}

list( $state_event, $state_page ) = evreg_e2e_nights_event(
	'E2E Night Seats State',
	array(
		array( 'key' => 'n1', 'label' => 'Noc 1', 'price' => 50, 'seats' => 5 ),
		array( 'key' => 'n2', 'label' => 'Noc 2', 'price' => 50, 'seats' => 1 ),
		array( 'key' => 'n3', 'label' => 'Noc 3', 'price' => 50, 'seats' => 0 ),
		array( 'key' => 'n4', 'label' => 'Noc 4', 'price' => 50, 'seats' => 5, 'companion_cap' => 0 ),
	)
);

list( $submit_event, $submit_page ) = evreg_e2e_nights_event(
	'E2E Night Seats Submit',
	array(
		array( 'key' => 's1', 'label' => 'Doba 1', 'price' => 50, 'seats' => 3 ),
		array( 'key' => 's2', 'label' => 'Doba 2', 'price' => 50, 'seats' => 1 ),
	)
);

echo $state_event . ' ' . $state_page . ' ' . $submit_event . ' ' . $submit_page . "\n";
