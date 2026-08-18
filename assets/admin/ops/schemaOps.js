import { TYPE_FIELD_KEY } from './fieldTypes';

const clone = ( value ) => JSON.parse( JSON.stringify( value ) );

export function emptySchema() {
	return { version: 1, sections: [] };
}

export function addSection( schema, key, title ) {
	const next = clone( schema );
	next.sections = next.sections || [];
	next.sections.push( { key, title, description: '', condition: null, fields: [] } );
	return next;
}

export function removeSection( schema, sectionKey ) {
	const next = clone( schema );
	next.sections = ( next.sections || [] ).filter( ( s ) => s.key !== sectionKey );
	return next;
}

export function addField( schema, sectionKey, field ) {
	const next = clone( schema );
	( next.sections || [] ).forEach( ( section ) => {
		if ( section.key === sectionKey ) {
			section.fields = section.fields || [];
			section.fields.push( field );
		}
	} );
	return next;
}

export function removeField( schema, fieldKey ) {
	if ( fieldKey === TYPE_FIELD_KEY ) {
		return schema;
	}

	const next = clone( schema );
	( next.sections || [] ).forEach( ( section ) => {
		section.fields = ( section.fields || [] ).filter( ( f ) => f.key !== fieldKey );
	} );
	return next;
}

export function moveField( schema, fieldKey, direction ) {
	const next = clone( schema );
	( next.sections || [] ).forEach( ( section ) => {
		const fields = section.fields || [];
		const index = fields.findIndex( ( f ) => f.key === fieldKey );

		if ( index === -1 ) {
			return;
		}

		const target = 'up' === direction ? index - 1 : index + 1;

		if ( target < 0 || target >= fields.length ) {
			return;
		}

		[ fields[ index ], fields[ target ] ] = [ fields[ target ], fields[ index ] ];
	} );
	return next;
}

export function updateField( schema, fieldKey, patch ) {
	const next = clone( schema );
	( next.sections || [] ).forEach( ( section ) => {
		section.fields = ( section.fields || [] ).map( ( f ) =>
			f.key === fieldKey ? { ...f, ...patch } : f
		);
	} );
	return next;
}

export function ensureTypeField( schema ) {
	const hasType = ( schema.sections || [] )
		.flatMap( ( s ) => s.fields || [] )
		.some( ( f ) => f.key === TYPE_FIELD_KEY );

	if ( hasType ) {
		return schema;
	}

	const next = clone( schema );

	if ( ! next.sections || next.sections.length === 0 ) {
		next.sections = [ { key: 'dane', title: 'Dane', description: '', condition: null, fields: [] } ];
	}

	next.sections[ 0 ].fields = next.sections[ 0 ].fields || [];
	next.sections[ 0 ].fields.unshift( {
		key: TYPE_FIELD_KEY,
		type: 'radio',
		label: 'Typ zgłoszenia',
	} );

	return next;
}
