import {
	emptySchema,
	addSection,
	addField,
	removeField,
	moveField,
	updateField,
	ensureTypeField,
} from './schemaOps';

describe( 'schemaOps', () => {
	it( 'emptySchema ma wersję i pustą listę sekcji', () => {
		expect( emptySchema() ).toEqual( { version: 1, sections: [] } );
	} );

	it( 'ensureTypeField wstawia __type gdy brak', () => {
		const schema = ensureTypeField( addSection( emptySchema(), 'dane', 'Dane' ) );
		const keys = schema.sections[ 0 ].fields.map( ( f ) => f.key );
		expect( keys ).toContain( '__type' );
	} );

	it( 'ensureTypeField nie duplikuje __type', () => {
		let schema = ensureTypeField( addSection( emptySchema(), 'dane', 'Dane' ) );
		schema = ensureTypeField( schema );
		const count = schema.sections
			.flatMap( ( s ) => s.fields )
			.filter( ( f ) => f.key === '__type' ).length;
		expect( count ).toBe( 1 );
	} );

	it( 'addField dodaje pole do wskazanej sekcji', () => {
		let schema = addSection( emptySchema(), 'dane', 'Dane' );
		schema = addField( schema, 'dane', { key: 'email', type: 'email', label: 'E-mail' } );
		expect( schema.sections[ 0 ].fields ).toHaveLength( 1 );
		expect( schema.sections[ 0 ].fields[ 0 ].key ).toBe( 'email' );
	} );

	it( 'removeField usuwa pole po kluczu', () => {
		let schema = addField( addSection( emptySchema(), 'dane', 'Dane' ), 'dane', {
			key: 'email',
			type: 'email',
			label: 'E-mail',
		} );
		schema = removeField( schema, 'email' );
		expect( schema.sections[ 0 ].fields ).toHaveLength( 0 );
	} );

	it( 'removeField nie usuwa __type', () => {
		let schema = ensureTypeField( addSection( emptySchema(), 'dane', 'Dane' ) );
		schema = removeField( schema, '__type' );
		const keys = schema.sections.flatMap( ( s ) => s.fields ).map( ( f ) => f.key );
		expect( keys ).toContain( '__type' );
	} );

	it( 'moveField przesuwa pole w górę', () => {
		let schema = addSection( emptySchema(), 'dane', 'Dane' );
		schema = addField( schema, 'dane', { key: 'a', type: 'text', label: 'A' } );
		schema = addField( schema, 'dane', { key: 'b', type: 'text', label: 'B' } );
		schema = moveField( schema, 'b', 'up' );
		expect( schema.sections[ 0 ].fields.map( ( f ) => f.key ) ).toEqual( [ 'b', 'a' ] );
	} );

	it( 'updateField nadpisuje właściwości pola', () => {
		let schema = addField( addSection( emptySchema(), 'dane', 'Dane' ), 'dane', {
			key: 'email',
			type: 'email',
			label: 'E-mail',
		} );
		schema = updateField( schema, 'email', { required: true, label: 'Adres e-mail' } );
		const field = schema.sections[ 0 ].fields[ 0 ];
		expect( field.required ).toBe( true );
		expect( field.label ).toBe( 'Adres e-mail' );
	} );

	it( 'nie mutuje argumentu wejściowego', () => {
		const schema = addSection( emptySchema(), 'dane', 'Dane' );
		const before = JSON.stringify( schema );
		addField( schema, 'dane', { key: 'x', type: 'text', label: 'X' } );
		expect( JSON.stringify( schema ) ).toBe( before );
	} );
} );
