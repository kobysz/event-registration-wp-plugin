import { __ } from '@wordpress/i18n';

export const TYPE_FIELD_KEY = '__type';

export const FIELD_TYPES = [
	{ value: 'text', label: __( 'Tekst', 'event-registration' ) },
	{ value: 'email', label: __( 'E-mail', 'event-registration' ) },
	{ value: 'tel', label: __( 'Telefon', 'event-registration' ) },
	{ value: 'textarea', label: __( 'Pole wielolinijkowe', 'event-registration' ) },
	{ value: 'number', label: __( 'Liczba', 'event-registration' ) },
	{ value: 'date', label: __( 'Data', 'event-registration' ) },
	{ value: 'select', label: __( 'Lista rozwijana', 'event-registration' ) },
	{ value: 'radio', label: __( 'Wybór pojedynczy', 'event-registration' ) },
	{ value: 'checkbox', label: __( 'Zgoda (pojedynczy checkbox)', 'event-registration' ) },
	{ value: 'checkbox-group', label: __( 'Wybór wielokrotny', 'event-registration' ) },
	{ value: 'heading', label: __( 'Nagłówek', 'event-registration' ) },
	{ value: 'paragraph', label: __( 'Akapit', 'event-registration' ) },
	{ value: 'accommodation', label: __( 'Nocleg', 'event-registration' ) },
];

export function isSpecial( key ) {
	return TYPE_FIELD_KEY === key;
}

export function isAccommodation( type ) {
	return 'accommodation' === type;
}
