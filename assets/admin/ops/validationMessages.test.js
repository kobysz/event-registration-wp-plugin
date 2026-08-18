import { messageForCode } from './validationMessages';

describe( 'messageForCode', () => {
	it( 'zwraca komunikat PL dla znanego kodu', () => {
		expect( messageForCode( 'schema_invalid', 'x' ) ).toContain( 'Konfiguracja' );
	} );

	it( 'dołącza detail dla schema_invalid', () => {
		expect( messageForCode( 'schema_invalid', 'brak pola __type' ) ).toContain( 'brak pola __type' );
	} );

	it( 'dla nieznanego kodu zwraca detail lub kod', () => {
		expect( messageForCode( 'nieznany', 'szczegół' ) ).toBe( 'szczegół' );
		expect( messageForCode( 'nieznany', '' ) ).toBe( 'nieznany' );
	} );
} );
