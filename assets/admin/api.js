import apiFetch from '@wordpress/api-fetch';

const base = ( eventId ) => `/evreg/v1/events/${ eventId }/config`;

export function loadConfig( eventId ) {
	return apiFetch( { path: base( eventId ) } );
}

export function saveConfig( eventId, config ) {
	return apiFetch( {
		path: base( eventId ),
		method: 'PUT',
		data: config,
	} );
}
