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

const templatesBase = ( eventId ) => `/evreg/v1/events/${ eventId }/mail-templates`;

export function loadTemplates( eventId ) {
	return apiFetch( { path: templatesBase( eventId ) } );
}

export function saveTemplates( eventId, templates ) {
	return apiFetch( {
		path: templatesBase( eventId ),
		method: 'POST',
		data: { templates },
	} );
}

const i18nBase = ( eventId ) => `/evreg/v1/events/${ eventId }/i18n`;

export function loadI18n( eventId ) {
	return apiFetch( { path: i18nBase( eventId ) } );
}

export function saveI18n( eventId, overlay ) {
	return apiFetch( {
		path: i18nBase( eventId ),
		method: 'POST',
		data: overlay,
	} );
}
