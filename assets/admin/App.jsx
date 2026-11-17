import { useState, useEffect } from '@wordpress/element';
import { Button, TabPanel, Spinner, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { loadConfig, saveConfig, loadTemplates, saveTemplates, loadI18n, saveI18n } from './api';
import ValidationReport from './components/ValidationReport';
import FormTab from './tabs/FormTab';
import TypesTab from './tabs/TypesTab';
import AccommodationTab from './tabs/AccommodationTab';
import SettingsTab from './tabs/SettingsTab';
import MailTemplatesTab from './tabs/MailTemplatesTab';
import TranslationsTab from './tabs/TranslationsTab';
import { ensureTypeField, emptySchema } from './ops/schemaOps';
import { mergeLoaded, normalizeForSave } from './ops/mailTemplateOps';
import { EMPTY_CONFIG, mergeLoadedConfig, configPayload, saveTargets } from './ops/configStateOps';

export default function App( { eventId } ) {
	const [ config, setConfig ] = useState( EMPTY_CONFIG );
	const [ dirty, setDirty ] = useState( {} );
	const [ validation, setValidation ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ templateDefaults, setTemplateDefaults ] = useState( {} );
	// Read-only server facts (mode lock, seats per night). Kept out of `config`
	// so they are never sent back by a save.
	const [ stats, setStats ] = useState( {} );

	useEffect( () => {
		if ( ! eventId ) {
			setLoading( false );
			return;
		}

		// The three loads run in parallel and land in any order, so each one
		// merges its own keys into the state instead of replacing it. Replacing
		// here dropped an already loaded overlay (or set of mail templates) from
		// the state, and the next save wrote the empty value over the stored one.
		loadConfig( eventId )
			.then( ( data ) => {
				setConfig( ( prev ) =>
					mergeLoadedConfig( prev, {
						schema: ensureTypeField(
							data.schema && data.schema.sections ? data.schema : emptySchema()
						),
						types: data.types || [],
						accommodation: data.accommodation || {},
						settings: data.settings || {},
					} )
				);
				setValidation( data.validation || null );
				setStats( data.stats || {} );
			} )
			.catch( () => setError( __( 'Nie udało się wczytać konfiguracji.', 'event-registration' ) ) )
			.finally( () => setLoading( false ) );

		loadTemplates( eventId )
			.then( ( data ) => {
				setConfig( ( prev ) =>
					mergeLoadedConfig( prev, { mailTemplates: mergeLoaded( data.templates || {} ) } )
				);
				setTemplateDefaults( data.defaults || {} );
			} )
			.catch( () =>
				setError( __( 'Nie udało się wczytać szablonów maili.', 'event-registration' ) )
			);

		loadI18n( eventId )
			.then( ( data ) => {
				setConfig( ( prev ) => mergeLoadedConfig( prev, { i18n: data || {} } ) );
			} )
			.catch( () =>
				setError( __( 'Nie udało się wczytać tłumaczeń.', 'event-registration' ) )
			);
	}, [ eventId ] );

	// "Touched" is sticky for the lifetime of the screen: a part the user edited
	// once keeps being saved on every later save. Clearing it after a successful
	// save would drop an edit typed while that save was still in flight.
	const update = ( key ) => ( value ) => {
		setDirty( ( prev ) => ( prev[ key ] ? prev : { ...prev, [ key ]: true } ) );
		setConfig( ( prev ) => mergeLoadedConfig( prev, { [ key ]: value } ) );
	};

	const onSave = () => {
		setSaving( true );
		setError( '' );

		// The templates and i18n endpoints replace the stored value wholesale, so
		// each is written only when the state actually carries one (it was
		// loaded) and the user touched it. A part that never loaded, or that
		// nobody edited, is left alone instead of being overwritten with an
		// empty value.
		const targets = saveTargets( config, dirty );
		const tasks = [ saveConfig( eventId, configPayload( config ) ) ];
		const templatesIndex = targets.templates ? tasks.push(
			saveTemplates( eventId, normalizeForSave( config.mailTemplates ) )
		) - 1 : -1;
		const i18nIndex = targets.i18n ? tasks.push( saveI18n( eventId, config.i18n ) ) - 1 : -1;

		Promise.allSettled( tasks )
			.then( ( results ) => {
				const cfg = results[ 0 ];
				const tpl = templatesIndex >= 0 ? results[ templatesIndex ] : null;
				const i18n = i18nIndex >= 0 ? results[ i18nIndex ] : null;

				if ( 'fulfilled' === cfg.status ) {
					setValidation( cfg.value.validation || null );
					setStats( cfg.value.stats || {} );
				}

				if ( tpl && 'fulfilled' === tpl.status ) {
					setConfig( ( prev ) =>
						mergeLoadedConfig( prev, { mailTemplates: mergeLoaded( tpl.value.templates || {} ) } )
					);
					setTemplateDefaults( tpl.value.defaults || {} );
				}

				if ( i18n && 'fulfilled' === i18n.status ) {
					setConfig( ( prev ) => mergeLoadedConfig( prev, { i18n: i18n.value || {} } ) );
				}

				const rejectedLabels = [];
				if ( 'rejected' === cfg.status ) {
					rejectedLabels.push( __( 'konfiguracja', 'event-registration' ) );
				}
				if ( tpl && 'rejected' === tpl.status ) {
					rejectedLabels.push( __( 'szablony maili', 'event-registration' ) );
				}
				if ( i18n && 'rejected' === i18n.status ) {
					rejectedLabels.push( __( 'tłumaczenia', 'event-registration' ) );
				}
				if ( rejectedLabels.length ) {
					// The server states why it refused (e.g. the mode lock); show
					// that instead of a bare "not saved".
					const reason =
						'rejected' === cfg.status && cfg.reason && cfg.reason.message
							? ` ${ cfg.reason.message }`
							: '';
					setError( `${ __( 'Nie zapisano:', 'event-registration' ) } ${ rejectedLabels.join( ', ' ) }.${ reason }` );
				}
			} )
			.finally( () => setSaving( false ) );
	};

	if ( loading ) {
		return <Spinner />;
	}

	if ( ! eventId ) {
		return (
			<Notice status="info" isDismissible={ false }>
				{ __( 'Zapisz wydarzenie jako szkic, aby skonfigurować formularz.', 'event-registration' ) }
			</Notice>
		);
	}

	const tabs = [
		{ name: 'form', title: __( 'Formularz', 'event-registration' ) },
		{ name: 'types', title: __( 'Typy zgłoszenia', 'event-registration' ) },
		{ name: 'accommodation', title: __( 'Noclegi', 'event-registration' ) },
		{ name: 'settings', title: __( 'Ustawienia', 'event-registration' ) },
		{ name: 'mail', title: __( 'Szablony maili', 'event-registration' ) },
		{ name: 'i18n', title: __( 'Tłumaczenia', 'event-registration' ) },
	];

	return (
		<div className="evreg-admin">
			<ShortcodeBox eventId={ eventId } />
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			<ValidationReport validation={ validation } />
			<TabPanel tabs={ tabs }>
				{ ( tab ) => (
					<TabRouter
						name={ tab.name }
						config={ config }
						update={ update }
						templateDefaults={ templateDefaults }
						stats={ stats }
					/>
				) }
			</TabPanel>
			<div className="evreg-admin__actions">
				<Button variant="primary" onClick={ onSave } isBusy={ saving } disabled={ saving }>
					{ __( 'Zapisz', 'event-registration' ) }
				</Button>
			</div>
		</div>
	);
}

function ShortcodeBox( { eventId } ) {
	const [ copied, setCopied ] = useState( false );
	const shortcode = `[evreg_form event="${ eventId }"]`;

	const copy = () => {
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( shortcode ).then( () => {
				setCopied( true );
				setTimeout( () => setCopied( false ), 2000 );
			} );
		}
	};

	return (
		<Notice status="info" isDismissible={ false }>
			<strong>{ __( 'Umieść formularz na stronie', 'event-registration' ) }</strong>
			{ ' ' }
			{ __(
				'— wklej ten shortcode w treść dowolnej strony (albo dodaj blok „Event Registration”):',
				'event-registration'
			) }
			<div style={ { marginTop: '8px', display: 'flex', gap: '8px', alignItems: 'center' } }>
				<input
					type="text"
					readOnly
					value={ shortcode }
					onFocus={ ( e ) => e.target.select() }
					style={ { fontFamily: 'monospace', width: '320px', maxWidth: '100%' } }
					aria-label={ __( 'Shortcode formularza', 'event-registration' ) }
				/>
				<Button variant="secondary" onClick={ copy }>
					{ copied
						? __( 'Skopiowano', 'event-registration' )
						: __( 'Kopiuj', 'event-registration' ) }
				</Button>
			</div>
		</Notice>
	);
}

function TabRouter( { name, config, update, templateDefaults, stats } ) {
	if ( 'form' === name ) {
		return <FormTab config={ config } update={ update } />;
	}
	if ( 'types' === name ) {
		return <TypesTab config={ config } update={ update } />;
	}
	if ( 'accommodation' === name ) {
		return <AccommodationTab config={ config } update={ update } stats={ stats } />;
	}
	if ( 'settings' === name ) {
		return <SettingsTab config={ config } update={ update } />;
	}
	if ( 'mail' === name ) {
		if ( undefined === config.mailTemplates || undefined === config.i18n ) {
			return <NotLoadedNotice />;
		}
		return (
			<MailTemplatesTab
				templates={ config.mailTemplates }
				defaults={ templateDefaults }
				i18n={ config.i18n }
				update={ update }
			/>
		);
	}
	if ( 'i18n' === name ) {
		// Editing an overlay that never loaded would send a partial one to an
		// endpoint that replaces the stored value, so refuse instead.
		if ( undefined === config.i18n ) {
			return <NotLoadedNotice />;
		}
		return <TranslationsTab config={ config } update={ update } />;
	}
	return null;
}

function NotLoadedNotice() {
	return (
		<Notice status="warning" isDismissible={ false }>
			{ __(
				'Nie wczytano zapisanych danych tej zakładki. Odśwież stronę — edycja teraz nadpisałaby zapisane treści.',
				'event-registration'
			) }
		</Notice>
	);
}
