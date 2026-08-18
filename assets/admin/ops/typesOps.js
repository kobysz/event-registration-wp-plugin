export function addType( types ) {
	return [ ...( types || [] ), { key: '', label: '', price: 0, capacity: null, active: true } ];
}

export function removeType( types, index ) {
	return ( types || [] ).filter( ( _, i ) => i !== index );
}

export function updateType( types, index, patch ) {
	return ( types || [] ).map( ( type, i ) => ( i === index ? { ...type, ...patch } : type ) );
}
