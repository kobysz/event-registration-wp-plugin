import {
	translatableItems,
	getTranslation,
	getOptionTranslation,
	setTranslation,
	setOptionTranslation,
	getMailTranslation,
	setMailTranslation,
	getAccommodationTranslation,
	setAccommodationTranslation,
} from './i18nOps';

const schema = {
	version: 1,
	sections: [
		{
			key: 'dane',
			title: 'Dane',
			fields: [
				{ key: '__type', type: 'radio', label: 'Typ zgłoszenia' },
				{ key: 'imie', type: 'text', label: 'Imię' },
				{ key: 'rozmiar', type: 'select', label: 'Rozmiar', options: [ { value: 's', label: 'Mały' } ] },
			],
		},
	],
};
const types = [ { key: 'pacjent', label: 'Pacjent' } ];

describe( 'i18nOps', () => {
	it( 'translatableItems enumeruje sekcje, pola, opcje i typy', () => {
		const items = translatableItems( schema, types );
		expect( items ).toEqual( [
			{ kind: 'section', key: 'dane', base: 'Dane' },
			{ kind: 'field', key: '__type', base: 'Typ zgłoszenia' },
			{ kind: 'field', key: 'imie', base: 'Imię' },
			{ kind: 'field', key: 'rozmiar', base: 'Rozmiar' },
			{ kind: 'option', key: 'rozmiar:s', base: 'Mały', fieldKey: 'rozmiar', optionValue: 's' },
			{ kind: 'type', key: 'pacjent', base: 'Pacjent' },
		] );
	} );

	it( 'setTranslation/getTranslation dla pola', () => {
		let overlay = {};
		overlay = setTranslation( overlay, 'en', 'field', 'imie', 'First name' );
		expect( getTranslation( overlay, 'en', 'field', 'imie' ) ).toBe( 'First name' );
		expect( overlay.en.fields.imie ).toBe( 'First name' );
	} );

	it( 'setOptionTranslation/getOptionTranslation', () => {
		let overlay = {};
		overlay = setOptionTranslation( overlay, 'en', 'rozmiar', 's', 'Small' );
		expect( getOptionTranslation( overlay, 'en', 'rozmiar', 's' ) ).toBe( 'Small' );
		expect( overlay.en.options.rozmiar.s ).toBe( 'Small' );
	} );

	it( 'get* zwraca pusty string gdy brak', () => {
		expect( getTranslation( {}, 'en', 'field', 'x' ) ).toBe( '' );
		expect( getOptionTranslation( {}, 'en', 'f', 'o' ) ).toBe( '' );
	} );

	it( 'ops nie mutują wejścia', () => {
		const overlay = { en: { fields: { imie: 'X' } } };
		const before = JSON.stringify( overlay );
		setTranslation( overlay, 'en', 'field', 'imie', 'Y' );
		setOptionTranslation( overlay, 'en', 'rozmiar', 's', 'Z' );
		expect( JSON.stringify( overlay ) ).toBe( before );
	} );
} );

describe( 'i18nOps mail bucket', () => {
	it( 'setMailTranslation/getMailTranslation dla subject i body', () => {
		let overlay = {};
		overlay = setMailTranslation( overlay, 'en', 'optin', 'subject', 'Confirm your registration' );
		overlay = setMailTranslation( overlay, 'en', 'optin', 'body', 'Click the link.' );
		expect( getMailTranslation( overlay, 'en', 'optin', 'subject' ) ).toBe( 'Confirm your registration' );
		expect( getMailTranslation( overlay, 'en', 'optin', 'body' ) ).toBe( 'Click the link.' );
		expect( overlay.en.mail.optin.subject ).toBe( 'Confirm your registration' );
	} );

	it( 'getMailTranslation zwraca pusty string gdy brak', () => {
		expect( getMailTranslation( {}, 'en', 'optin', 'subject' ) ).toBe( '' );
	} );

	it( 'mail ops nie mutują wejścia', () => {
		const overlay = { en: { mail: { optin: { subject: 'X' } } } };
		const before = JSON.stringify( overlay );
		setMailTranslation( overlay, 'en', 'optin', 'body', 'Y' );
		expect( JSON.stringify( overlay ) ).toBe( before );
	} );

	it( 'setTranslation po setMailTranslation zachowuje bucket mail (regresja utraty danych)', () => {
		let overlay = {};
		overlay = setMailTranslation( overlay, 'en', 'optin', 'subject', 'Hello' );
		overlay = setTranslation( overlay, 'en', 'field', 'imie', 'Name' );
		expect( overlay.en.mail.optin.subject ).toBe( 'Hello' );
		expect( overlay.en.fields.imie ).toBe( 'Name' );
	} );

	it( 'setOptionTranslation po setMailTranslation zachowuje bucket mail (regresja utraty danych)', () => {
		let overlay = {};
		overlay = setMailTranslation( overlay, 'en', 'optin', 'subject', 'Hello' );
		overlay = setOptionTranslation( overlay, 'en', 'rozmiar', 's', 'Small' );
		expect( overlay.en.mail.optin.subject ).toBe( 'Hello' );
		expect( overlay.en.options.rozmiar.s ).toBe( 'Small' );
	} );

	it( 'setMailTranslation po setTranslation/setOptionTranslation zachowuje sections/fields/options', () => {
		let overlay = {};
		overlay = setTranslation( overlay, 'en', 'section', 'dane', 'Data' );
		overlay = setTranslation( overlay, 'en', 'field', 'imie', 'Name' );
		overlay = setOptionTranslation( overlay, 'en', 'rozmiar', 's', 'Small' );
		overlay = setMailTranslation( overlay, 'en', 'optin', 'subject', 'Hello' );
		expect( overlay.en.sections.dane ).toBe( 'Data' );
		expect( overlay.en.fields.imie ).toBe( 'Name' );
		expect( overlay.en.options.rozmiar.s ).toBe( 'Small' );
		expect( overlay.en.mail.optin.subject ).toBe( 'Hello' );
	} );
} );

describe( 'i18nOps accommodation bucket', () => {
	const accommodation = {
		packages: [ { key: 'n12', label: 'Noc 1–2' }, { key: 'n23', label: 'Noc 2–3' } ],
		rooms: [ { key: 'double', label: 'Pokój 2-osobowy' } ],
		inventory: [ { package: 'n12', room: 'double', capacity: 5, price: 180 } ],
	};

	it( 'translatableItems dopisuje wiersze pakietów i pokojów', () => {
		const items = translatableItems( schema, types, accommodation );
		expect( items ).toContainEqual( { kind: 'accommodation', accKind: 'packages', key: 'n12', base: 'Noc 1–2' } );
		expect( items ).toContainEqual( { kind: 'accommodation', accKind: 'packages', key: 'n23', base: 'Noc 2–3' } );
		expect( items ).toContainEqual( { kind: 'accommodation', accKind: 'rooms', key: 'double', base: 'Pokój 2-osobowy' } );
	} );

	it( 'translatableItems bez noclegu nie dopisuje wierszy (kompatybilność)', () => {
		expect( translatableItems( schema, types ) ).toHaveLength( 6 );
	} );

	it( 'set/getAccommodationTranslation dla pakietu i pokoju', () => {
		let overlay = {};
		overlay = setAccommodationTranslation( overlay, 'en', 'packages', 'n12', 'Night 1–2' );
		overlay = setAccommodationTranslation( overlay, 'en', 'rooms', 'double', 'Double room' );
		expect( getAccommodationTranslation( overlay, 'en', 'packages', 'n12' ) ).toBe( 'Night 1–2' );
		expect( getAccommodationTranslation( overlay, 'en', 'rooms', 'double' ) ).toBe( 'Double room' );
		expect( overlay.en.accommodation.packages.n12 ).toBe( 'Night 1–2' );
	} );

	it( 'getAccommodationTranslation zwraca pusty string gdy brak', () => {
		expect( getAccommodationTranslation( {}, 'en', 'packages', 'x' ) ).toBe( '' );
	} );

	it( 'accommodation ops nie mutują wejścia', () => {
		const overlay = { en: { accommodation: { packages: { n12: 'X' } } } };
		const before = JSON.stringify( overlay );
		setAccommodationTranslation( overlay, 'en', 'rooms', 'double', 'Y' );
		expect( JSON.stringify( overlay ) ).toBe( before );
	} );

	it( 'setTranslation po setAccommodationTranslation zachowuje bucket accommodation (regresja utraty danych)', () => {
		let overlay = {};
		overlay = setAccommodationTranslation( overlay, 'en', 'packages', 'n12', 'Night 1–2' );
		overlay = setTranslation( overlay, 'en', 'field', 'imie', 'Name' );
		expect( overlay.en.accommodation.packages.n12 ).toBe( 'Night 1–2' );
		expect( overlay.en.fields.imie ).toBe( 'Name' );
	} );

	it( 'setMailTranslation po setAccommodationTranslation zachowuje bucket accommodation', () => {
		let overlay = {};
		overlay = setAccommodationTranslation( overlay, 'en', 'packages', 'n12', 'Night 1–2' );
		overlay = setMailTranslation( overlay, 'en', 'optin', 'subject', 'Hello' );
		expect( overlay.en.accommodation.packages.n12 ).toBe( 'Night 1–2' );
		expect( overlay.en.mail.optin.subject ).toBe( 'Hello' );
	} );
} );
