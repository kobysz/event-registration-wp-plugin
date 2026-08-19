import {
	TEMPLATE_TYPES,
	PLACEHOLDERS_BY_TYPE,
	setField,
	normalizeForSave,
	mergeLoaded,
} from './mailTemplateOps';

describe( 'mailTemplateOps', () => {
	it( 'TEMPLATE_TYPES pokrywa pięć typów maili', () => {
		const keys = TEMPLATE_TYPES.map( ( t ) => t.key );
		expect( keys ).toEqual( [ 'optin', 'confirmed', 'waitlist', 'expired', 'admin_new' ] );
	} );

	it( 'PLACEHOLDERS_BY_TYPE ma wpis dla każdego typu', () => {
		TEMPLATE_TYPES.forEach( ( { key } ) => {
			expect( Array.isArray( PLACEHOLDERS_BY_TYPE[ key ] ) ).toBe( true );
			expect( PLACEHOLDERS_BY_TYPE[ key ].length ).toBeGreaterThan( 0 );
		} );
	} );

	it( 'setField ustawia pole i nie mutuje wejścia', () => {
		const before = { optin: { subject: 'A', body: '' } };
		const snapshot = JSON.stringify( before );
		const after = setField( before, 'optin', 'body', 'Nowa treść' );

		expect( after.optin.body ).toBe( 'Nowa treść' );
		expect( after.optin.subject ).toBe( 'A' );
		expect( JSON.stringify( before ) ).toBe( snapshot );
	} );

	it( 'setField tworzy brakujący typ', () => {
		const after = setField( {}, 'confirmed', 'subject', 'X' );
		expect( after.confirmed ).toEqual( { subject: 'X', body: '' } );
	} );

	it( 'normalizeForSave zrzuca puste pola i puste typy', () => {
		const result = normalizeForSave( {
			optin: { subject: 'Temat', body: '' },
			confirmed: { subject: '', body: '' },
			waitlist: { subject: '', body: 'Treść' },
		} );

		expect( result ).toEqual( {
			optin: { subject: 'Temat' },
			waitlist: { body: 'Treść' },
		} );
	} );

	it( 'normalizeForSave przycina białe znaki przy ocenie pustości', () => {
		const result = normalizeForSave( { optin: { subject: '   ', body: 'x' } } );
		expect( result.optin ).toEqual( { body: 'x' } );
	} );

	it( 'mergeLoaded uzupełnia brakujące pola pustym stringiem dla każdego typu', () => {
		const merged = mergeLoaded( { optin: { subject: 'Zapisany temat' } } );

		expect( merged.optin ).toEqual( { subject: 'Zapisany temat', body: '' } );
		expect( merged.confirmed ).toEqual( { subject: '', body: '' } );
		expect( Object.keys( merged ) ).toHaveLength( TEMPLATE_TYPES.length );
	} );

	it( 'mergeLoaded znosi wartości spoza pól subject/body', () => {
		const merged = mergeLoaded( { optin: { subject: 'S', body: 'B', extra: 'X' } } );
		expect( merged.optin ).toEqual( { subject: 'S', body: 'B' } );
	} );
} );
