import { sectionDroppableId, isSectionId, sectionKeyFromId, resolveDrop } from './dnd';
import { emptySchema, addSection, addField, ensureTypeField } from './schemaOps';

describe( 'dnd id helpers', () => {
	it( 'sectionDroppableId i sectionKeyFromId są odwracalne', () => {
		const id = sectionDroppableId( 'dane' );
		expect( isSectionId( id ) ).toBe( true );
		expect( sectionKeyFromId( id ) ).toBe( 'dane' );
	} );

	it( 'klucz pola nie jest rozpoznawany jako id sekcji', () => {
		expect( isSectionId( 'email' ) ).toBe( false );
	} );
} );

describe( 'resolveDrop', () => {
	const build = () => {
		let schema = ensureTypeField( addSection( emptySchema(), 'dane', 'Dane' ) );
		schema = addSection( schema, 'extra', 'Extra' );
		schema = addField( schema, 'dane', { key: 'a', type: 'text', label: 'A' } );
		schema = addField( schema, 'dane', { key: 'b', type: 'text', label: 'B' } );
		return schema;
	};

	it( 'drop na polu zwraca sekcję i indeks tego pola', () => {
		const schema = build();
		expect( resolveDrop( schema, 'b', 'a' ) ).toEqual( { toSectionKey: 'dane', toIndex: 1 } );
	} );

	it( 'drop na pustej sekcji celuje na jej koniec', () => {
		const schema = build();
		expect( resolveDrop( schema, 'a', sectionDroppableId( 'extra' ) ) ).toEqual( {
			toSectionKey: 'extra',
			toIndex: 0,
		} );
	} );

	it( 'brak over zwraca null', () => {
		const schema = build();
		expect( resolveDrop( schema, 'a', null ) ).toBeNull();
	} );

	it( 'drop na samym sobie zwraca null', () => {
		const schema = build();
		expect( resolveDrop( schema, 'a', 'a' ) ).toBeNull();
	} );

	it( 'nieznany over zwraca null', () => {
		const schema = build();
		expect( resolveDrop( schema, 'a', 'brak' ) ).toBeNull();
	} );
} );
