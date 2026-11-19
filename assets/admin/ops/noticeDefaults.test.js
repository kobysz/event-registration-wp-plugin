import { NOTICE_KEYS, defaultNotice } from './noticeDefaults';

describe( 'defaultNotice', () => {
	afterEach( () => {
		delete window.evregAdmin;
	} );

	it( 'returns the text the server localized for a known key', () => {
		window.evregAdmin = {
			noticeDefaults: { full_waitlist: 'z serwera', full_closed: 'zamknięte' },
		};

		expect( defaultNotice( 'full_waitlist' ) ).toBe( 'z serwera' );
		expect( defaultNotice( 'full_closed' ) ).toBe( 'zamknięte' );
	} );

	it( 'returns an empty string for an unknown key', () => {
		window.evregAdmin = { noticeDefaults: { full_closed: 'zamknięte' } };

		expect( defaultNotice( 'nope' ) ).toBe( '' );
	} );

	// Panel montuje się też wtedy, gdy payload nie dojechał — placeholder ma wtedy
	// zniknąć, nie wywalić zakładki.
	it( 'survives a missing payload', () => {
		expect( defaultNotice( 'full_waitlist' ) ).toBe( '' );

		window.evregAdmin = {};
		expect( defaultNotice( 'full_waitlist' ) ).toBe( '' );
	} );

	it( 'keeps both notice keys', () => {
		expect( NOTICE_KEYS ).toEqual( [ 'full_waitlist', 'full_closed' ] );
	} );
} );
