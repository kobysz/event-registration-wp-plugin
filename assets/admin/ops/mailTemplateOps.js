import { __ } from '@wordpress/i18n';

export const TEMPLATE_TYPES = [
	{ key: 'optin', label: __( 'Potwierdzenie zgłoszenia (opt-in)', 'event-registration' ) },
	{ key: 'confirmed', label: __( 'Zgłoszenie potwierdzone', 'event-registration' ) },
	{ key: 'waitlist', label: __( 'Lista rezerwowa', 'event-registration' ) },
	{ key: 'expired', label: __( 'Rezerwacja wygasła', 'event-registration' ) },
	{ key: 'admin_new', label: __( 'Powiadomienie organizatora', 'event-registration' ) },
];

const PARTICIPANT_PLACEHOLDERS = [
	'{imie}',
	'{email}',
	'{event}',
	'{typ}',
	'{nocleg}',
	'{link_potwierdzenia}',
	'{podsumowanie}',
];

export const PLACEHOLDERS_BY_TYPE = {
	optin: PARTICIPANT_PLACEHOLDERS,
	confirmed: PARTICIPANT_PLACEHOLDERS,
	waitlist: PARTICIPANT_PLACEHOLDERS,
	expired: PARTICIPANT_PLACEHOLDERS,
	admin_new: PARTICIPANT_PLACEHOLDERS,
};

export function setField( templates, type, field, value ) {
	const current = templates[ type ] || { subject: '', body: '' };
	return {
		...templates,
		[ type ]: { subject: '', body: '', ...current, [ field ]: value },
	};
}

export function normalizeForSave( templates ) {
	const result = {};

	TEMPLATE_TYPES.forEach( ( { key } ) => {
		const entry = templates[ key ] || {};
		const fields = {};

		[ 'subject', 'body' ].forEach( ( field ) => {
			const value = ( entry[ field ] || '' ).trim();
			if ( '' !== value ) {
				fields[ field ] = entry[ field ];
			}
		} );

		if ( 0 < Object.keys( fields ).length ) {
			result[ key ] = fields;
		}
	} );

	return result;
}

export function mergeLoaded( templates ) {
	const result = {};

	TEMPLATE_TYPES.forEach( ( { key } ) => {
		const entry = templates[ key ] || {};
		result[ key ] = {
			subject: 'string' === typeof entry.subject ? entry.subject : '',
			body: 'string' === typeof entry.body ? entry.body : '',
		};
	} );

	return result;
}
