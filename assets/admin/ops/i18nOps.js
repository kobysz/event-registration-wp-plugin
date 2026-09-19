function langBucket( overlay, lang ) {
	const base = overlay[ lang ] || {};
	return {
		sections: base.sections || {},
		fields: base.fields || {},
		types: base.types || {},
		options: base.options || {},
		mail: base.mail || {},
		accommodation: base.accommodation || {},
	};
}

const KIND_BUCKET = { section: 'sections', field: 'fields', type: 'types' };

export function translatableItems( schema, types, accommodation ) {
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
	const acc = accommodation || {};
	( acc.packages || [] ).forEach( ( pkg ) => {
		items.push( { kind: 'accommodation', accKind: 'packages', key: pkg.key, base: pkg.label || pkg.key } );
	} );
	( acc.rooms || [] ).forEach( ( room ) => {
		items.push( { kind: 'accommodation', accKind: 'rooms', key: room.key, base: room.label || room.key } );
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

export function getAccommodationTranslation( overlay, lang, accKind, key ) {
	const bucket = ( ( overlay[ lang ] || {} ).accommodation || {} )[ accKind ] || {};
	return bucket[ key ] ? String( bucket[ key ] ) : '';
}

export function setAccommodationTranslation( overlay, lang, accKind, key, value ) {
	const current = langBucket( overlay, lang );
	return {
		...overlay,
		[ lang ]: {
			...current,
			accommodation: {
				...current.accommodation,
				[ accKind ]: { ...( current.accommodation[ accKind ] || {} ), [ key ]: value },
			},
		},
	};
}

export function getMailTranslation( overlay, lang, templateKey, field ) {
	const mail = ( ( overlay[ lang ] || {} ).mail || {} )[ templateKey ] || {};
	return mail[ field ] ? String( mail[ field ] ) : '';
}

export function setMailTranslation( overlay, lang, templateKey, field, value ) {
	const langEntry = overlay[ lang ] || {};
	const mail = langEntry.mail || {};
	const tpl = mail[ templateKey ] || {};
	return {
		...overlay,
		[ lang ]: {
			...langEntry,
			mail: {
				...mail,
				[ templateKey ]: { ...tpl, [ field ]: value },
			},
		},
	};
}
