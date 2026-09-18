import {
	availableOperators,
	triggerOptions,
	eligibleTriggers,
	operatorNeedsValue,
} from './conditionOps';

describe( 'conditionOps', () => {
	it( 'availableOperators dla pola wyboru daje wszystkie', () => {
		const ops = availableOperators( { key: 't', type: 'radio' } ).map( ( o ) => o.value );
		expect( ops ).toEqual( [ 'equals', 'not_equals', 'in', 'not_in', 'empty', 'not_empty' ] );
	} );

	it( 'availableOperators dla pola nie-wyboru daje tylko empty/not_empty', () => {
		const ops = availableOperators( { key: 't', type: 'text' } ).map( ( o ) => o.value );
		expect( ops ).toEqual( [ 'empty', 'not_empty' ] );
	} );

	it( 'triggerOptions dla __type bierze z types', () => {
		const opts = triggerOptions(
			{ key: '__type', type: 'radio', options: [] },
			[ { key: 'pacjent', label: 'Pacjent' }, { key: 'prelegent', label: 'Prelegent' } ]
		);
		expect( opts ).toEqual( [
			{ value: 'pacjent', label: 'Pacjent' },
			{ value: 'prelegent', label: 'Prelegent' },
		] );
	} );

	it( 'triggerOptions dla zwykłego pola wyboru bierze z options', () => {
		const opts = triggerOptions( { key: 'roz', type: 'select', options: [ { value: 's', label: 'S' } ] }, [] );
		expect( opts ).toEqual( [ { value: 's', label: 'S' } ] );
	} );

	it( 'eligibleTriggers pomija siebie oraz heading/paragraph/accommodation', () => {
		const fields = [
			{ key: '__type', type: 'radio', label: 'Typ' },
			{ key: 'naglowek', type: 'heading', label: 'H' },
			{ key: 'nocleg', type: 'accommodation', label: 'N' },
			{ key: 'pwz', type: 'text', label: 'PWZ' },
		];
		const keys = eligibleTriggers( fields, 'pwz' ).map( ( f ) => f.key );
		expect( keys ).toEqual( [ '__type' ] );
	} );

	it( 'operatorNeedsValue rozróżnia empty/not_empty od reszty', () => {
		expect( operatorNeedsValue( 'equals' ) ).toBe( true );
		expect( operatorNeedsValue( 'empty' ) ).toBe( false );
		expect( operatorNeedsValue( 'not_empty' ) ).toBe( false );
	} );
} );
