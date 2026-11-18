import {
	emptyAccommodation,
	addPackage,
	removePackage,
	updatePackage,
	addRoom,
	removeRoom,
	updateRoom,
	setInventoryCell,
	getInventoryCell,
	setAllowNone,
	setCompanionEnabled,
	setCompanionNote,
	setCompanionCountsEvent,
	setMode,
	addNight,
	removeNight,
	setNightField,
	NIGHT_KEY_MAX,
	nightKeyError,
} from './accommodationOps';

describe( 'accommodationOps', () => {
	it( 'emptyAccommodation ma puste listy i allow_none domyślnie true', () => {
		expect( emptyAccommodation() ).toEqual( {
			packages: [],
			rooms: [],
			inventory: [],
			allow_none: true,
		} );
	} );

	it( 'addPackage dodaje pakiet', () => {
		const acc = addPackage( emptyAccommodation() );
		expect( acc.packages ).toHaveLength( 1 );
	} );

	it( 'updatePackage zmienia klucz i etykietę', () => {
		let acc = addPackage( emptyAccommodation() );
		const key = acc.packages[ 0 ].key;
		acc = updatePackage( acc, key, { key: 'n12', label: 'Noc 1–2' } );
		expect( acc.packages[ 0 ].key ).toBe( 'n12' );
	} );

	it( 'setInventoryCell tworzy wpis dla pary pakiet×pokój', () => {
		let acc = emptyAccommodation();
		acc = setInventoryCell( acc, 'n12', 'double', { capacity: 20, price: 180 } );
		expect( getInventoryCell( acc, 'n12', 'double' ) ).toMatchObject( { capacity: 20, price: 180 } );
	} );

	it( 'setInventoryCell aktualizuje istniejący wpis, nie duplikuje', () => {
		let acc = emptyAccommodation();
		acc = setInventoryCell( acc, 'n12', 'double', { capacity: 20, price: 180 } );
		acc = setInventoryCell( acc, 'n12', 'double', { capacity: 25 } );
		expect( acc.inventory ).toHaveLength( 1 );
		expect( getInventoryCell( acc, 'n12', 'double' ) ).toMatchObject( { capacity: 25, price: 180 } );
	} );

	it( 'removePackage usuwa pakiet i jego wpisy inwentarza', () => {
		let acc = emptyAccommodation();
		acc = setInventoryCell( acc, 'n12', 'double', { capacity: 20, price: 180 } );
		acc.packages = [ { key: 'n12', label: 'Noc 1–2' } ];
		acc = removePackage( acc, 'n12' );
		expect( acc.packages ).toHaveLength( 0 );
		expect( acc.inventory ).toHaveLength( 0 );
	} );

	it( 'updateRoom zmienia klucz pokoju i przenosi wpisy inwentarza', () => {
		let acc = emptyAccommodation();
		acc.rooms = [ { key: 'double', label: 'Dwuosobowy', roommate_field: false } ];
		acc = setInventoryCell( acc, 'n12', 'double', { capacity: 20, price: 180 } );

		acc = updateRoom( acc, 'double', { key: 'twin' } );

		expect( acc.rooms[ 0 ].key ).toBe( 'twin' );
		expect( getInventoryCell( acc, 'n12', 'double' ) ).toBeNull();
		expect( getInventoryCell( acc, 'n12', 'twin' ) ).toMatchObject( { capacity: 20, price: 180 } );
	} );

	it( 'updateRoom przenosi wpisy inwentarza także przy czyszczeniu klucza do pustego stringa', () => {
		let acc = emptyAccommodation();
		acc.rooms = [ { key: 'double', label: 'Dwuosobowy', roommate_field: false } ];
		acc = setInventoryCell( acc, 'n12', 'double', { capacity: 20, price: 180 } );

		acc = updateRoom( acc, 'double', { key: '' } );

		expect( acc.rooms[ 0 ].key ).toBe( '' );
		expect( getInventoryCell( acc, 'n12', 'double' ) ).toBeNull();
		expect( getInventoryCell( acc, 'n12', '' ) ).toMatchObject( { capacity: 20, price: 180 } );
	} );

	it( 'removeRoom usuwa pokój i jego wpisy inwentarza', () => {
		let acc = emptyAccommodation();
		acc = setInventoryCell( acc, 'n12', 'double', { capacity: 20, price: 180 } );
		acc.rooms = [ { key: 'double', label: 'Dwuosobowy', roommate_field: false } ];
		acc = removeRoom( acc, 'double' );
		expect( acc.rooms ).toHaveLength( 0 );
		expect( acc.inventory ).toHaveLength( 0 );
	} );

	it( 'setAllowNone przełącza flagę', () => {
		expect( setAllowNone( emptyAccommodation(), false ).allow_none ).toBe( false );
	} );

	it( 'nie mutuje wejścia', () => {
		const acc = emptyAccommodation();
		const before = JSON.stringify( acc );
		addPackage( acc );
		expect( JSON.stringify( acc ) ).toBe( before );
	} );

	it( 'setCompanionEnabled ustawia flagę i nie mutuje wejścia', () => {
		const acc = emptyAccommodation();
		const before = JSON.stringify( acc );
		const next = setCompanionEnabled( acc, true );
		expect( next.companion_enabled ).toBe( true );
		expect( JSON.stringify( acc ) ).toBe( before );
	} );

	it( 'setCompanionEnabled rzutuje wartość na bool', () => {
		expect( setCompanionEnabled( emptyAccommodation(), 1 ).companion_enabled ).toBe( true );
		expect( setCompanionEnabled( emptyAccommodation(), 0 ).companion_enabled ).toBe( false );
	} );

	it( 'setCompanionNote ustawia przypis i nie mutuje wejścia', () => {
		const acc = emptyAccommodation();
		const before = JSON.stringify( acc );
		const next = setCompanionNote( acc, 'Przypis.' );
		expect( next.companion_note ).toBe( 'Przypis.' );
		expect( JSON.stringify( acc ) ).toBe( before );
	} );

	it( 'setCompanionNote rzutuje brakującą wartość na pusty string', () => {
		expect( setCompanionNote( emptyAccommodation(), undefined ).companion_note ).toBe( '' );
	} );

	it( 'setCompanionCountsEvent ustawia flagę i nie mutuje wejścia', () => {
		const acc = emptyAccommodation();
		const before = JSON.stringify( acc );
		const next = setCompanionCountsEvent( acc, true );
		expect( next.companion_counts_event ).toBe( true );
		expect( JSON.stringify( acc ) ).toBe( before );
	} );

	it( 'setCompanionCountsEvent rzutuje wartość na bool', () => {
		expect( setCompanionCountsEvent( emptyAccommodation(), 1 ).companion_counts_event ).toBe( true );
		expect( setCompanionCountsEvent( emptyAccommodation(), 0 ).companion_counts_event ).toBe( false );
	} );

	it( 'addRoom daje pokój bez ustawionej liczby osób', () => {
		const acc = addRoom( { packages: [], rooms: [], inventory: [] } );

		expect( acc.rooms[ 0 ].occupancy ).toBe( 0 );
	} );

	it( 'updateRoom ustawia liczbę osób', () => {
		const acc = updateRoom(
			{ packages: [], rooms: [ { key: 'double', label: '2-os.', occupancy: 0 } ], inventory: [] },
			'double',
			{ occupancy: 2 }
		);

		expect( acc.rooms[ 0 ].occupancy ).toBe( 2 );
	} );
} );

describe( 'accommodationOps — nights mode', () => {
	const classic = () => ( {
		packages: [ { key: 'p1', label: 'P' } ],
		rooms: [ { key: 'r1', label: 'R' } ],
		inventory: [ { package: 'p1', room: 'r1', capacity: 3, price: 10 } ],
		allow_none: true,
	} );

	it( 'setMode przełącza tryb i NIE kasuje danych klasycznych', () => {
		const input = classic();
		const next = setMode( input, 'nights' );
		expect( next.mode ).toBe( 'nights' );
		expect( next.nights ).toEqual( [] );
		expect( next.packages ).toEqual( input.packages );
		expect( next.inventory ).toEqual( input.inventory );
		expect( input.mode ).toBeUndefined();
		expect( setMode( next, 'classic' ).mode ).toBe( 'classic' );
		expect( setMode( next, 'bogus' ).mode ).toBe( 'classic' );
	} );

	it( 'addNight dodaje dobę z domyślnymi wartościami i unikalnym kluczem', () => {
		const a = addNight( setMode( classic(), 'nights' ) );
		const b = addNight( a );
		expect( a.nights ).toHaveLength( 1 );
		expect( a.nights[ 0 ] ).toMatchObject( { label: '', price: 0, seats: 0, companion_cap: '' } );
		expect( b.nights[ 0 ].key ).not.toBe( b.nights[ 1 ].key );
		expect( a.nights ).toHaveLength( 1 );
	} );

	it( 'addNight nie powiela klucza już obecnego w liście', () => {
		const acc = { mode: 'nights', nights: [ { key: 'night_1', label: '', price: 0, seats: 0, companion_cap: '' } ] };
		const next = addNight( acc );
		expect( next.nights ).toHaveLength( 2 );
		expect( new Set( next.nights.map( ( n ) => n.key ) ).size ).toBe( 2 );
	} );

	it( 'removeNight kasuje dobę z listy i nie zostawia po niej śladu, nie tykając klasyki', () => {
		const acc = {
			...classic(),
			mode: 'nights',
			nights: [
				{ key: 'fri', label: 'Piątek', price: 1, seats: 2, companion_cap: '' },
				{ key: 'sat', label: 'Sobota', price: 1, seats: 2, companion_cap: 1 },
			],
		};
		const next = removeNight( acc, 'fri' );
		expect( next.nights.map( ( n ) => n.key ) ).toEqual( [ 'sat' ] );
		expect( next.nights.some( ( n ) => 'fri' === n.key ) ).toBe( false );
		expect( next.nights ).toEqual( [ acc.nights[ 1 ] ] );
		expect( next.packages ).toEqual( acc.packages );
		expect( next.inventory ).toEqual( acc.inventory );
		expect( acc.nights ).toHaveLength( 2 );
	} );

	it( 'setNightField przycina klucz do limitu kolumny night_key', () => {
		const acc = { mode: 'nights', nights: [ { key: 'a', label: '', price: 0, seats: 0, companion_cap: '' } ] };
		expect( NIGHT_KEY_MAX ).toBe( 64 );
		const next = setNightField( acc, 'a', 'key', 'x'.repeat( 70 ) );
		expect( next.nights[ 0 ].key ).toBe( 'x'.repeat( 64 ) );
	} );

	it( 'setNightField: cap złożony z białych znaków zostaje pusty, nigdy zerem', () => {
		const acc = { mode: 'nights', nights: [ { key: 'a', label: '', price: 0, seats: 0, companion_cap: 3 } ] };
		expect( setNightField( acc, 'a', 'companion_cap', '   ' ).nights[ 0 ].companion_cap ).toBe( '' );
		expect( setNightField( acc, 'a', 'companion_cap', '' ).nights[ 0 ].companion_cap ).toBe( '' );
		expect( setNightField( acc, 'a', 'companion_cap', ' 4 ' ).nights[ 0 ].companion_cap ).toBe( 4 );
	} );

	it( 'setNightField: cap 0 to realny limit zerowy, a nie brak limitu', () => {
		const acc = { mode: 'nights', nights: [ { key: 'a', label: '', price: 0, seats: 0, companion_cap: '' } ] };
		expect( setNightField( acc, 'a', 'companion_cap', '0' ).nights[ 0 ].companion_cap ).toBe( 0 );
	} );

	it( 'setNightField: miejsca i cena są liczbami nieujemnymi', () => {
		const acc = { mode: 'nights', nights: [ { key: 'a', label: '', price: 5, seats: 5, companion_cap: '' } ] };
		expect( setNightField( acc, 'a', 'seats', '' ).nights[ 0 ].seats ).toBe( 0 );
		expect( setNightField( acc, 'a', 'seats', '-3' ).nights[ 0 ].seats ).toBe( 0 );
		expect( setNightField( acc, 'a', 'seats', '12' ).nights[ 0 ].seats ).toBe( 12 );
		expect( setNightField( acc, 'a', 'price', '99.5' ).nights[ 0 ].price ).toBe( 99.5 );
		expect( setNightField( acc, 'a', 'label', 'Piątek' ).nights[ 0 ].label ).toBe( 'Piątek' );
		expect( acc.nights[ 0 ].seats ).toBe( 5 );
	} );

	describe( 'night key validity', () => {
		const two = () => ( {
			mode: 'nights',
			nights: [
				{ key: 'fri', label: 'F', price: 1, seats: 1, companion_cap: '' },
				{ key: 'sat', label: 'S', price: 2, seats: 2, companion_cap: '' },
			],
		} );

		it( 'a key colliding with another night is not committed', () => {
			const acc = two();
			const next = setNightField( acc, 'sat', 'key', 'fri' );
			expect( next.nights.map( ( n ) => n.key ) ).toEqual( [ 'fri', 'sat' ] );
			expect( next.nights ).toEqual( acc.nights );
			expect( nightKeyError( acc, 'sat', 'fri' ) ).toBe( 'duplicate' );
		} );

		it( 'an empty or whitespace-only key is not committed', () => {
			const acc = two();
			expect( setNightField( acc, 'sat', 'key', '' ).nights ).toEqual( acc.nights );
			expect( setNightField( acc, 'sat', 'key', '   ' ).nights ).toEqual( acc.nights );
			expect( nightKeyError( acc, 'sat', ' ' ) ).toBe( 'empty' );
		} );

		it( 'distinct keys, and re-saving a night own key, are unaffected', () => {
			const acc = two();
			const renamed = setNightField( acc, 'sat', 'key', 'sun' );
			expect( renamed.nights.map( ( n ) => n.key ) ).toEqual( [ 'fri', 'sun' ] );
			expect( renamed.nights[ 1 ].seats ).toBe( 2 );
			expect( setNightField( acc, 'sat', 'key', 'sat' ).nights ).toEqual( acc.nights );
			expect( nightKeyError( acc, 'sat', 'sat' ) ).toBeNull();
		} );
	} );
} );
