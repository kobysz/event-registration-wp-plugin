import { addType, removeType, updateType } from './typesOps';

describe( 'typesOps', () => {
	it( 'addType dodaje typ z domyślnymi wartościami', () => {
		const types = addType( [] );
		expect( types ).toHaveLength( 1 );
		expect( types[ 0 ] ).toMatchObject( { key: '', label: '', price: 0, capacity: null, active: true } );
	} );

	it( 'removeType usuwa po indeksie', () => {
		const types = addType( addType( [] ) );
		expect( removeType( types, 0 ) ).toHaveLength( 1 );
	} );

	it( 'updateType nadpisuje pola wskazanego typu', () => {
		let types = addType( [] );
		types = updateType( types, 0, { key: 'uczestnik', price: 450 } );
		expect( types[ 0 ].key ).toBe( 'uczestnik' );
		expect( types[ 0 ].price ).toBe( 450 );
	} );

	it( 'nie mutuje wejścia', () => {
		const types = addType( [] );
		const before = JSON.stringify( types );
		updateType( types, 0, { key: 'x' } );
		expect( JSON.stringify( types ) ).toBe( before );
	} );
} );
