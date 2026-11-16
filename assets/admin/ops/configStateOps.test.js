import {
	EMPTY_CONFIG,
	mergeLoadedConfig,
	configPayload,
	saveTargets,
} from './configStateOps';

describe( 'mergeLoadedConfig', () => {
	it( 'keeps an already loaded overlay when the config response lands afterwards', () => {
		const afterI18n = mergeLoadedConfig( EMPTY_CONFIG, { i18n: { en: { fields: { imie: 'Name' } } } } );

		const afterConfig = mergeLoadedConfig( afterI18n, {
			schema: { sections: [] },
			types: [],
			accommodation: {},
			settings: {},
		} );

		expect( afterConfig.i18n ).toEqual( { en: { fields: { imie: 'Name' } } } );
	} );

	it( 'keeps already loaded mail templates when the config response lands afterwards', () => {
		const afterTemplates = mergeLoadedConfig( EMPTY_CONFIG, { mailTemplates: { reserved: { subject: 'Hi' } } } );

		const afterConfig = mergeLoadedConfig( afterTemplates, { schema: { sections: [] } } );

		expect( afterConfig.mailTemplates ).toEqual( { reserved: { subject: 'Hi' } } );
	} );

	it( 'keeps the config when the overlay response lands afterwards', () => {
		const afterConfig = mergeLoadedConfig( EMPTY_CONFIG, { schema: { sections: [ { key: 'dane' } ] } } );

		const afterI18n = mergeLoadedConfig( afterConfig, { i18n: { en: {} } } );

		expect( afterI18n.schema ).toEqual( { sections: [ { key: 'dane' } ] } );
	} );

	it( 'does not mutate the previous state', () => {
		const prev = { ...EMPTY_CONFIG, i18n: { en: {} } };

		mergeLoadedConfig( prev, { schema: { sections: [] } } );

		expect( prev ).toEqual( { ...EMPTY_CONFIG, i18n: { en: {} } } );
	} );
} );

describe( 'configPayload', () => {
	it( 'sends only the four config keys', () => {
		const payload = configPayload( {
			schema: { sections: [] },
			types: [ { key: 'a' } ],
			accommodation: { packages: [] },
			settings: { form_page_id: 7 },
			i18n: { en: {} },
			mailTemplates: { reserved: {} },
		} );

		expect( Object.keys( payload ).sort() ).toEqual( [
			'accommodation',
			'schema',
			'settings',
			'types',
		] );
	} );

	it( 'omits keys the state does not carry yet', () => {
		expect( configPayload( { schema: { sections: [] } } ) ).toEqual( { schema: { sections: [] } } );
	} );
} );

describe( 'saveTargets', () => {
	it( 'skips the overlay when nothing touched it, even though it is loaded', () => {
		const targets = saveTargets( { i18n: { en: { fields: {} } } }, {} );

		expect( targets.i18n ).toBe( false );
	} );

	it( 'skips the overlay when the state does not carry one, even if flagged dirty', () => {
		const targets = saveTargets( {}, { i18n: true } );

		expect( targets.i18n ).toBe( false );
	} );

	it( 'saves the overlay once it is both loaded and touched', () => {
		const targets = saveTargets( { i18n: { en: {} } }, { i18n: true } );

		expect( targets.i18n ).toBe( true );
	} );

	it( 'gates mail templates the same way', () => {
		expect( saveTargets( { mailTemplates: {} }, {} ).templates ).toBe( false );
		expect( saveTargets( {}, { mailTemplates: true } ).templates ).toBe( false );
		expect( saveTargets( { mailTemplates: {} }, { mailTemplates: true } ).templates ).toBe( true );
	} );
} );
