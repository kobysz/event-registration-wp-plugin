import { __ } from '@wordpress/i18n';

// Jedyne miejsce z domyślnymi tekstami komunikatów o pełnym evencie — czytają je
// SettingsTab (placeholder) i i18nOps (baza wiersza tłumaczenia).
export const NOTICE_KEYS = [ 'full_waitlist', 'full_closed' ];

export function defaultNotice( key ) {
	const defaults = {
		full_waitlist: __(
			'Wszystkie miejsca są zajęte. Możesz się zapisać — trafisz na listę rezerwową i damy znać, gdy zwolni się miejsce.',
			'event-registration'
		),
		full_closed: __( 'Rejestracja zamknięta — brak wolnych miejsc.', 'event-registration' ),
	};
	return Object.prototype.hasOwnProperty.call( defaults, key ) ? defaults[ key ] : '';
}
