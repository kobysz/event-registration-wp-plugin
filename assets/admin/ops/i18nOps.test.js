import {
	translatableItems,
	getTranslation,
	getOptionTranslation,
	setTranslation,
	setOptionTranslation,
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
