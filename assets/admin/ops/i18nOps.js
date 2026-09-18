function langBucket( overlay, lang ) {
	const base = overlay[ lang ] || {};
	return {
		sections: base.sections || {},
		fields: base.fields || {},
		types: base.types || {},
		options: base.options || {},
	};
}

const KIND_BUCKET = { section: 'sections', field: 'fields', type: 'types' };

export function translatableItems( schema, types ) {
	const items = [];
	( schema.sections || [] ).forEach( ( section ) => {
		items.push( { kind: 'section', key: section.key, base: section.title || section.key } );
		( section.fields || [] ).forEach( ( field ) => {
			items.push( { kind: 'field', key: field.key, base: field.label || field.key } );
		} );
		( section.fields || [] ).forEach( ( field ) => {
			if ( field.key === '__type' ) {
				return;
			}
			( field.options || [] ).forEach( ( opt ) => {
				items.push( {
					kind: 'option',
					key: `${ field.key }:${ opt.value }`,
					base: opt.label || opt.value,
					fieldKey: field.key,
					optionValue: opt.value,
				} );
			} );
		} );
	} );
	( types || [] ).forEach( ( type ) => {
		items.push( { kind: 'type', key: type.key, base: type.label || type.key } );
	} );
	return items;
}

export function getTranslation( overlay, lang, kind, key ) {
	const bucket = langBucket( overlay, lang )[ KIND_BUCKET[ kind ] ];
	return bucket && bucket[ key ] ? String( bucket[ key ] ) : '';
}

export function getOptionTranslation( overlay, lang, fieldKey, optionValue ) {
	const opts = langBucket( overlay, lang ).options[ fieldKey ] || {};
	return opts[ optionValue ] ? String( opts[ optionValue ] ) : '';
}

export function setTranslation( overlay, lang, kind, key, value ) {
	const bucketName = KIND_BUCKET[ kind ];
	const current = langBucket( overlay, lang );
	return {
		...overlay,
		[ lang ]: {
			...current,
			[ bucketName ]: { ...current[ bucketName ], [ key ]: value },
		},
	};
}

export function setOptionTranslation( overlay, lang, fieldKey, optionValue, value ) {
	const current = langBucket( overlay, lang );
	return {
		...overlay,
		[ lang ]: {
			...current,
			options: {
				...current.options,
				[ fieldKey ]: { ...( current.options[ fieldKey ] || {} ), [ optionValue ]: value },
			},
		},
	};
}
