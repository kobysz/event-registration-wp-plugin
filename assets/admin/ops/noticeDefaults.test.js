import { defaultNotice } from './noticeDefaults';

describe( 'defaultNotice', () => {
	it( 'returns distinct texts for known keys', () => {
		expect( defaultNotice( 'full_waitlist' ) ).toMatch( /rezerwow/ );
		expect( defaultNotice( 'full_closed' ) ).toMatch( /zamknięta/ );
	} );

	it( 'returns an empty string for an unknown key', () => {
		expect( defaultNotice( 'nope' ) ).toBe( '' );
	} );
} );
