import { __ } from '@wordpress/i18n';
import { isChoice } from './fieldTypes';

export const OPERATORS = [
	{ value: 'equals', label: __( 'równe', 'event-registration' ), needsValue: true, choiceOnly: true },
	{ value: 'not_equals', label: __( 'różne od', 'event-registration' ), needsValue: true, choiceOnly: true },
	{ value: 'in', label: __( 'jest jedną z', 'event-registration' ), needsValue: true, choiceOnly: true },
	{ value: 'not_in', label: __( 'nie jest żadną z', 'event-registration' ), needsValue: true, choiceOnly: true },
	{ value: 'empty', label: __( 'puste', 'event-registration' ), needsValue: false, choiceOnly: false },
	{ value: 'not_empty', label: __( 'niepuste', 'event-registration' ), needsValue: false, choiceOnly: false },
];

export function operatorNeedsValue( operator ) {
	const found = OPERATORS.find( ( o ) => o.value === operator );
	return found ? found.needsValue : false;
}

export function availableOperators( triggerField ) {
	const choice = !! triggerField && isChoice( triggerField.type );
	return OPERATORS.filter( ( o ) => choice || ! o.choiceOnly ).map( ( o ) => ( {
		value: o.value,
		label: o.label,
	} ) );
}

export function triggerOptions( triggerField, types ) {
	if ( ! triggerField ) {
		return [];
	}
	if ( triggerField.key === '__type' ) {
		return ( types || [] ).map( ( t ) => ( { value: t.key, label: t.label || t.key } ) );
	}
	if ( isChoice( triggerField.type ) ) {
		return ( triggerField.options || [] ).map( ( o ) => ( { value: o.value, label: o.label || o.value } ) );
	}
	return [];
}

export function eligibleTriggers( fields, selfKey ) {
	const excluded = [ 'heading', 'paragraph', 'accommodation' ];
	return ( fields || [] ).filter(
		( f ) => f.key !== selfKey && ! excluded.includes( f.type )
	);
}
