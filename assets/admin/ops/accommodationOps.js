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
	if ( 'key' in patch && patch.key !== key ) {
		next.inventory = next.inventory.map( ( i ) =>
			i.package === key ? { ...i, package: patch.key } : i
		);
	}
	return next;
}

export function addRoom( acc ) {
	const next = ensure( acc );
	next.rooms.push( { key: nextKey( 'room' ), label: '', roommate_field: false, occupancy: 0 } );
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
	if ( 'key' in patch && patch.key !== key ) {
		next.inventory = next.inventory.map( ( i ) =>
			i.room === key ? { ...i, room: patch.key } : i
		);
	}
	return next;
}

export function getInventoryCell( acc, packageKey, roomKey ) {
	const list = ( acc && acc.inventory ) || [];
	const found = list.find( ( i ) => i.package === packageKey && i.room === roomKey );
	return found ? { ...found } : null;
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

export function setCompanionEnabled( acc, value ) {
	const next = ensure( acc );
	next.companion_enabled = !! value;
	return next;
}

export function setCompanionNote( acc, value ) {
	const next = ensure( acc );
	next.companion_note = String( value ?? '' );
	return next;
}

export function setCompanionCountsEvent( acc, value ) {
	const next = ensure( acc );
	next.companion_counts_event = !! value;
	return next;
}

// ---------------------------------------------------------------------------
// Nights mode. The classic keys (packages, rooms, inventory) are left alone so
// switching back is not destructive; these ops only touch `mode` and `nights`.
// ---------------------------------------------------------------------------

/** `night_key` is varchar(64) in the database; the domain does not bound it, so the editor does. */
export const NIGHT_KEY_MAX = 64;

function ensureNights( acc ) {
	const next = ensure( acc );
	next.nights = next.nights || [];
	return next;
}

export function setMode( acc, mode ) {
	const next = ensureNights( acc );
	next.mode = 'nights' === mode ? 'nights' : 'classic';
	return next;
}

export function addNight( acc ) {
	const next = ensureNights( acc );
	const taken = new Set( next.nights.map( ( n ) => n.key ) );
	let key = nextKey( 'night' );
	while ( taken.has( key ) ) {
		key = nextKey( 'night' );
	}
	next.nights.push( { key, label: '', price: 0, seats: 0, companion_cap: '' } );
	return next;
}

export function removeNight( acc, key ) {
	const next = ensureNights( acc );
	next.nights = next.nights.filter( ( n ) => n.key !== key );
	return next;
}

function nonNegative( parse, raw ) {
	const value = parse( String( raw ).trim() );
	return Number.isFinite( value ) && value > 0 ? value : 0;
}

/**
 * Normalises one night field. seats 0 = closed night; companion_cap '' = no
 * limit while a numeric 0 = no pairs — the two conventions stay distinct, and
 * a whitespace-only cap must never become 0.
 */
function normalizeNightValue( field, value ) {
	if ( 'key' === field ) {
		return String( value ).slice( 0, NIGHT_KEY_MAX );
	}
	if ( 'seats' === field ) {
		return nonNegative( ( v ) => parseInt( v, 10 ), value );
	}
	if ( 'price' === field ) {
		return nonNegative( parseFloat, value );
	}
	if ( 'companion_cap' === field ) {
		const trimmed = String( value ).trim();
		if ( '' === trimmed ) {
			return '';
		}
		const parsed = parseInt( trimmed, 10 );
		return Number.isFinite( parsed ) ? Math.max( 0, parsed ) : '';
	}
	return value;
}

/**
 * Why `candidate` cannot become the key of night `key`: 'empty' (blank after
 * trimming) or 'duplicate' (another night already has it); null when fine.
 * A duplicate key makes the public form degrade to "registration unavailable",
 * so such a key must never reach the saved config.
 */
export function nightKeyError( acc, key, candidate ) {
	const candidateKey = String( candidate ).slice( 0, NIGHT_KEY_MAX );
	if ( '' === candidateKey.trim() ) {
		return 'empty';
	}
	const nights = ( acc && acc.nights ) || [];
	if ( nights.some( ( n ) => n.key !== key && n.key === candidateKey ) ) {
		return 'duplicate';
	}
	return null;
}

export function setNightField( acc, key, field, value ) {
	const next = ensureNights( acc );
	if ( 'key' === field && null !== nightKeyError( next, key, value ) ) {
		// Rejected: the config keeps the last valid key.
		return next;
	}
	next.nights = next.nights.map( ( n ) =>
		n.key === key ? { ...n, [ field ]: normalizeNightValue( field, value ) } : n
	);
	return next;
}
