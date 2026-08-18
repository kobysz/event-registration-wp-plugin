const clone = ( value ) => JSON.parse( JSON.stringify( value ) );

let counter = 0;
const nextKey = ( prefix ) => `${ prefix }_${ ++counter }`;

export function emptyAccommodation() {
	return { packages: [], rooms: [], inventory: [], allow_none: true };
}

function ensure( acc ) {
	const next = clone( acc || {} );
	next.packages = next.packages || [];
	next.rooms = next.rooms || [];
	next.inventory = next.inventory || [];
	if ( typeof next.allow_none === 'undefined' ) {
		next.allow_none = true;
	}
	return next;
}

export function addPackage( acc ) {
	const next = ensure( acc );
	next.packages.push( { key: nextKey( 'pkg' ), label: '' } );
	return next;
}

export function removePackage( acc, key ) {
	const next = ensure( acc );
	next.packages = next.packages.filter( ( p ) => p.key !== key );
	next.inventory = next.inventory.filter( ( i ) => i.package !== key );
	return next;
}

export function updatePackage( acc, key, patch ) {
	const next = ensure( acc );
	next.packages = next.packages.map( ( p ) => ( p.key === key ? { ...p, ...patch } : p ) );
	if ( patch.key && patch.key !== key ) {
		next.inventory = next.inventory.map( ( i ) =>
			i.package === key ? { ...i, package: patch.key } : i
		);
	}
	return next;
}

export function addRoom( acc ) {
	const next = ensure( acc );
	next.rooms.push( { key: nextKey( 'room' ), label: '', roommate_field: false } );
	return next;
}

export function removeRoom( acc, key ) {
	const next = ensure( acc );
	next.rooms = next.rooms.filter( ( r ) => r.key !== key );
	next.inventory = next.inventory.filter( ( i ) => i.room !== key );
	return next;
}

export function updateRoom( acc, key, patch ) {
	const next = ensure( acc );
	next.rooms = next.rooms.map( ( r ) => ( r.key === key ? { ...r, ...patch } : r ) );
	if ( patch.key && patch.key !== key ) {
		next.inventory = next.inventory.map( ( i ) =>
			i.room === key ? { ...i, room: patch.key } : i
		);
	}
	return next;
}

export function getInventoryCell( acc, packageKey, roomKey ) {
	const list = ( acc && acc.inventory ) || [];
	return list.find( ( i ) => i.package === packageKey && i.room === roomKey ) || null;
}

export function setInventoryCell( acc, packageKey, roomKey, patch ) {
	const next = ensure( acc );
	const index = next.inventory.findIndex(
		( i ) => i.package === packageKey && i.room === roomKey
	);

	if ( index === -1 ) {
		next.inventory.push( {
			package: packageKey,
			room: roomKey,
			capacity: 0,
			price: 0,
			...patch,
		} );
	} else {
		next.inventory[ index ] = { ...next.inventory[ index ], ...patch };
	}

	return next;
}

export function setAllowNone( acc, value ) {
	const next = ensure( acc );
	next.allow_none = !! value;
	return next;
}
