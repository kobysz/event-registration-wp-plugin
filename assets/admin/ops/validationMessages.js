import { __, sprintf } from '@wordpress/i18n';

const KNOWN = {
	schema_invalid: ( detail ) =>
		sprintf(
			/* translators: %s: szczegół błędu walidacji z serwera. */
			__( 'Konfiguracja formularza jest niepoprawna: %s', 'event-registration' ),
			detail
		),
};

export function messageForCode( code, detail ) {
	if ( KNOWN[ code ] ) {
		return KNOWN[ code ]( detail );
	}

	return detail || code;
}
