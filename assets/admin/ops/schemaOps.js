import { __ } from '@wordpress/i18n';
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
	next.sections = ( next.sections || [] ).filter( ( s ) => {
		if ( s.key !== sectionKey ) {
			return true;
		}
		// Only empty sections may be removed; a section with fields (including
		// the one pinning __type) is protected against accidental data loss.
		return ( s.fields || [] ).length > 0;
	} );
	return next;
}

export function moveSection( schema, sectionKey, direction ) {
	const next = clone( schema );
	const sections = next.sections || [];
	const index = sections.findIndex( ( s ) => s.key === sectionKey );

	if ( index === -1 ) {
		return next;
	}

	const target = 'up' === direction ? index - 1 : index + 1;

	if ( target < 0 || target >= sections.length ) {
		return next;
	}

	[ sections[ index ], sections[ target ] ] = [ sections[ target ], sections[ index ] ];

	return next;
}

export function renameSection( schema, sectionKey, title ) {
	const next = clone( schema );
	( next.sections || [] ).forEach( ( section ) => {
		if ( section.key === sectionKey ) {
			section.title = title;
		}
	} );
	return next;
}

export function addField( schema, sectionKey, field ) {
	const exists = ( schema.sections || [] )
		.flatMap( ( s ) => s.fields || [] )
		.some( ( f ) => f.key === field.key );

	if ( exists ) {
		return schema;
	}

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

/**
 * Przenosi pole do wskazanej sekcji na wskazaną pozycję (reorder DnD).
 *
 * Wszystkie dropy (w obrębie sekcji i między sekcjami) idą przez ten prymityw.
 * `toIndex` to docelowa pozycja w tablicy docelowej PO usunięciu źródła.
 * Pole __type jest przypięte na górze swojej sekcji: nie da się go ruszyć ani
 * wstawić czegokolwiek przed nie.
 */
export function moveFieldTo( schema, fieldKey, toSectionKey, toIndex ) {
	if ( fieldKey === TYPE_FIELD_KEY ) {
		return schema;
	}

	const next = clone( schema );
	const sections = next.sections || [];
	const target = sections.find( ( s ) => s.key === toSectionKey );

	if ( ! target ) {
		return schema;
	}

	let moved = null;
	sections.forEach( ( section ) => {
		const fields = section.fields || [];
		const index = fields.findIndex( ( f ) => f.key === fieldKey );
		if ( index !== -1 ) {
			[ moved ] = fields.splice( index, 1 );
		}
	} );

	if ( ! moved ) {
		return schema;
	}

	target.fields = target.fields || [];
	const pinned = target.fields.some( ( f ) => f.key === TYPE_FIELD_KEY ) ? 1 : 0;
	const clamped = Math.max( pinned, Math.min( toIndex, target.fields.length ) );
	target.fields.splice( clamped, 0, moved );

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

export function setFieldCondition( schema, fieldKey, condition ) {
	const next = clone( schema );
	( next.sections || [] ).forEach( ( section ) => {
		( section.fields || [] ).forEach( ( field ) => {
			if ( field.key === fieldKey ) {
				field.condition = condition;
			}
		} );
	} );
	return next;
}

export function clearFieldCondition( schema, fieldKey ) {
	return setFieldCondition( schema, fieldKey, null );
}

export function setSectionCondition( schema, sectionKey, condition ) {
	const next = clone( schema );
	( next.sections || [] ).forEach( ( section ) => {
		if ( section.key === sectionKey ) {
			section.condition = condition;
		}
	} );
	return next;
}

export function clearSectionCondition( schema, sectionKey ) {
	return setSectionCondition( schema, sectionKey, null );
}

export function addOption( options ) {
	return [ ...( options || [] ), { value: '', label: '' } ];
}

export function updateOption( options, index, patch ) {
	return ( options || [] ).map( ( opt, i ) => ( i === index ? { ...opt, ...patch } : opt ) );
}

export function removeOption( options, index ) {
	return ( options || [] ).filter( ( _, i ) => i !== index );
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
		next.sections = [
			{
				key: 'dane',
				title: __( 'Dane', 'event-registration' ),
				description: '',
				condition: null,
				fields: [],
			},
		];
	}

	next.sections[ 0 ].fields = next.sections[ 0 ].fields || [];
	next.sections[ 0 ].fields.unshift( {
		key: TYPE_FIELD_KEY,
		type: 'radio',
		label: __( 'Typ zgłoszenia', 'event-registration' ),
	} );

	return next;
}
