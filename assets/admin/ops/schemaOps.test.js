import {
	emptySchema,
	addSection,
	removeSection,
	renameSection,
	addField,
	removeField,
	moveField,
	moveFieldTo,
	updateField,
	ensureTypeField,
	addOption,
	updateOption,
	removeOption,
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

	it( 'ensureTypeField z pustej schemy daje kompletną, poprawną schemę (to zapisuje App przy wczytaniu)', () => {
		const schema = ensureTypeField( emptySchema() );
		expect( schema.version ).toBe( 1 );
		const keys = schema.sections.flatMap( ( s ) => s.fields ).map( ( f ) => f.key );
		expect( keys ).toContain( '__type' );
	} );

	it( 'moveField przesuwa pole w dół', () => {
		let schema = addSection( emptySchema(), 'dane', 'Dane' );
		schema = addField( schema, 'dane', { key: 'a', type: 'text', label: 'A' } );
		schema = addField( schema, 'dane', { key: 'b', type: 'text', label: 'B' } );
		schema = moveField( schema, 'a', 'down' );
		expect( schema.sections[ 0 ].fields.map( ( f ) => f.key ) ).toEqual( [ 'b', 'a' ] );
	} );

	it( 'moveField w górę na pierwszym polu nic nie zmienia', () => {
		let schema = addSection( emptySchema(), 'dane', 'Dane' );
		schema = addField( schema, 'dane', { key: 'a', type: 'text', label: 'A' } );
		schema = addField( schema, 'dane', { key: 'b', type: 'text', label: 'B' } );
		schema = moveField( schema, 'a', 'up' );
		expect( schema.sections[ 0 ].fields.map( ( f ) => f.key ) ).toEqual( [ 'a', 'b' ] );
	} );

	it( 'moveField w dół na ostatnim polu nic nie zmienia', () => {
		let schema = addSection( emptySchema(), 'dane', 'Dane' );
		schema = addField( schema, 'dane', { key: 'a', type: 'text', label: 'A' } );
		schema = addField( schema, 'dane', { key: 'b', type: 'text', label: 'B' } );
		schema = moveField( schema, 'b', 'down' );
		expect( schema.sections[ 0 ].fields.map( ( f ) => f.key ) ).toEqual( [ 'a', 'b' ] );
	} );

	it( 'addField ignoruje duplikat klucza w dowolnej sekcji', () => {
		let schema = addSection( emptySchema(), 'dane', 'Dane' );
		schema = addSection( schema, 'extra', 'Extra' );
		schema = addField( schema, 'dane', { key: 'email', type: 'email', label: 'E-mail' } );
		schema = addField( schema, 'extra', { key: 'email', type: 'text', label: 'Duplikat' } );
		const allEmail = schema.sections
			.flatMap( ( s ) => s.fields )
			.filter( ( f ) => f.key === 'email' );
		expect( allEmail ).toHaveLength( 1 );
		expect( schema.sections[ 1 ].fields ).toHaveLength( 0 );
	} );

	it( 'renameSection zmienia tytuł wskazanej sekcji', () => {
		let schema = addSection( emptySchema(), 'dane', 'Dane' );
		schema = renameSection( schema, 'dane', 'Uczestnik' );
		expect( schema.sections[ 0 ].title ).toBe( 'Uczestnik' );
	} );

	it( 'renameSection na nieznanej sekcji nic nie zmienia', () => {
		const schema = addSection( emptySchema(), 'dane', 'Dane' );
		const before = JSON.stringify( schema );
		expect( JSON.stringify( renameSection( schema, 'brak', 'X' ) ) ).toBe( before );
	} );

	it( 'removeSection usuwa pustą sekcję', () => {
		let schema = addSection( emptySchema(), 'dane', 'Dane' );
		schema = addSection( schema, 'extra', 'Extra' );
		schema = removeSection( schema, 'extra' );
		expect( schema.sections.map( ( s ) => s.key ) ).toEqual( [ 'dane' ] );
	} );

	it( 'removeSection nie usuwa niepustej sekcji', () => {
		let schema = addSection( emptySchema(), 'dane', 'Dane' );
		schema = addField( schema, 'dane', { key: 'a', type: 'text', label: 'A' } );
		schema = removeSection( schema, 'dane' );
		expect( schema.sections.map( ( s ) => s.key ) ).toEqual( [ 'dane' ] );
	} );

	it( 'moveFieldTo przesuwa pole na wskazaną pozycję w tej samej sekcji', () => {
		let schema = ensureTypeField( addSection( emptySchema(), 'dane', 'Dane' ) );
		schema = addField( schema, 'dane', { key: 'a', type: 'text', label: 'A' } );
		schema = addField( schema, 'dane', { key: 'b', type: 'text', label: 'B' } );
		schema = addField( schema, 'dane', { key: 'c', type: 'text', label: 'C' } );
		schema = moveFieldTo( schema, 'c', 'dane', 1 );
		expect( schema.sections[ 0 ].fields.map( ( f ) => f.key ) ).toEqual( [
			'__type',
			'c',
			'a',
			'b',
		] );
	} );

	it( 'moveFieldTo przenosi pole między sekcjami', () => {
		let schema = ensureTypeField( addSection( emptySchema(), 'dane', 'Dane' ) );
		schema = addSection( schema, 'extra', 'Extra' );
		schema = addField( schema, 'dane', { key: 'a', type: 'text', label: 'A' } );
		schema = addField( schema, 'extra', { key: 'b', type: 'text', label: 'B' } );
		schema = moveFieldTo( schema, 'a', 'extra', 1 );
		expect( schema.sections[ 0 ].fields.map( ( f ) => f.key ) ).toEqual( [ '__type' ] );
		expect( schema.sections[ 1 ].fields.map( ( f ) => f.key ) ).toEqual( [ 'b', 'a' ] );
	} );

	it( 'moveFieldTo nie rusza pola __type', () => {
		let schema = ensureTypeField( addSection( emptySchema(), 'dane', 'Dane' ) );
		schema = addSection( schema, 'extra', 'Extra' );
		schema = moveFieldTo( schema, '__type', 'extra', 0 );
		expect( schema.sections[ 0 ].fields[ 0 ].key ).toBe( '__type' );
		expect( schema.sections[ 1 ].fields ).toHaveLength( 0 );
	} );

	it( 'moveFieldTo nie pozwala wstawić pola przed __type', () => {
		let schema = ensureTypeField( addSection( emptySchema(), 'dane', 'Dane' ) );
		schema = addField( schema, 'dane', { key: 'a', type: 'text', label: 'A' } );
		schema = moveFieldTo( schema, 'a', 'dane', 0 );
		expect( schema.sections[ 0 ].fields.map( ( f ) => f.key ) ).toEqual( [ '__type', 'a' ] );
	} );

	it( 'moveFieldTo na nieznanej sekcji docelowej nic nie zmienia', () => {
		let schema = ensureTypeField( addSection( emptySchema(), 'dane', 'Dane' ) );
		schema = addField( schema, 'dane', { key: 'a', type: 'text', label: 'A' } );
		const before = JSON.stringify( schema );
		expect( JSON.stringify( moveFieldTo( schema, 'a', 'brak', 0 ) ) ).toBe( before );
	} );

	it( 'moveFieldTo nie mutuje argumentu wejściowego', () => {
		let schema = ensureTypeField( addSection( emptySchema(), 'dane', 'Dane' ) );
		schema = addField( schema, 'dane', { key: 'a', type: 'text', label: 'A' } );
		const before = JSON.stringify( schema );
		moveFieldTo( schema, 'a', 'dane', 1 );
		expect( JSON.stringify( schema ) ).toBe( before );
	} );

	it( 'addOption dopisuje pustą opcję', () => {
		expect( addOption( [] ) ).toEqual( [ { value: '', label: '' } ] );
		expect( addOption( [ { value: 'a', label: 'A' } ] ) ).toEqual( [
			{ value: 'a', label: 'A' },
			{ value: '', label: '' },
		] );
	} );

	it( 'updateOption scala łatkę w opcję o danym indeksie', () => {
		const opts = [ { value: 'a', label: 'A' }, { value: 'b', label: 'B' } ];
		expect( updateOption( opts, 1, { value: 'x' } ) ).toEqual( [
			{ value: 'a', label: 'A' },
			{ value: 'x', label: 'B' },
		] );
	} );

	it( 'removeOption usuwa opcję o danym indeksie', () => {
		const opts = [ { value: 'a', label: 'A' }, { value: 'b', label: 'B' } ];
		expect( removeOption( opts, 0 ) ).toEqual( [ { value: 'b', label: 'B' } ] );
	} );

	it( 'ops opcji nie mutują wejścia', () => {
		const opts = [ { value: 'a', label: 'A' } ];
		const before = JSON.stringify( opts );
		addOption( opts );
		updateOption( opts, 0, { label: 'Z' } );
		removeOption( opts, 0 );
		expect( JSON.stringify( opts ) ).toBe( before );
	} );
} );
