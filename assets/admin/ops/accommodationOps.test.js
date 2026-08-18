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
} );
