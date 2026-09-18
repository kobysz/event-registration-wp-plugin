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

const EMPTY = { schema: {}, types: [], accommodation: {}, settings: {} };

export default function App( { eventId } ) {
	const [ config, setConfig ] = useState( EMPTY );
	const [ validation, setValidation ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ templateDefaults, setTemplateDefaults ] = useState( {} );
	const [ templatesLoaded, setTemplatesLoaded ] = useState( false );
	const [ i18nLoaded, setI18nLoaded ] = useState( false );

	useEffect( () => {
		if ( ! eventId ) {
			setLoading( false );
			return;
		}

		loadConfig( eventId )
			.then( ( data ) => {
				setConfig( {
					schema: ensureTypeField(
						data.schema && data.schema.sections ? data.schema : emptySchema()
					),
					types: data.types || [],
					accommodation: data.accommodation || {},
					settings: data.settings || {},
				} );
				setValidation( data.validation || null );
			} )
			.catch( () => setError( __( 'Nie udało się wczytać konfiguracji.', 'event-registration' ) ) )
			.finally( () => setLoading( false ) );

		loadTemplates( eventId )
			.then( ( data ) => {
				setConfig( ( prev ) => ( { ...prev, mailTemplates: mergeLoaded( data.templates || {} ) } ) );
				setTemplateDefaults( data.defaults || {} );
				setTemplatesLoaded( true );
			} )
			.catch( () =>
				setError( __( 'Nie udało się wczytać szablonów maili.', 'event-registration' ) )
			);

		loadI18n( eventId )
			.then( ( data ) => {
				setConfig( ( prev ) => ( { ...prev, i18n: data || {} } ) );
				setI18nLoaded( true );
			} )
			.catch( () =>
				setError( __( 'Nie udało się wczytać tłumaczeń.', 'event-registration' ) )
			);
	}, [ eventId ] );

	const update = ( key ) => ( value ) =>
		setConfig( ( prev ) => ( { ...prev, [ key ]: value } ) );

	const onSave = () => {
		setSaving( true );
		setError( '' );

		// Never issue a templates/i18n save before its initial load has completed:
		// an unloaded (or failed-load) `config.mailTemplates`/`config.i18n` would
		// normalize to an empty set and the REST endpoints do a full replace,
		// wiping any previously saved overrides. Only save once we know we have
		// a real snapshot to normalize.
		const tasks = [ saveConfig( eventId, config ) ];
		const templatesIndex = templatesLoaded ? tasks.push(
			saveTemplates( eventId, normalizeForSave( config.mailTemplates || {} ) )
		) - 1 : -1;
		const i18nIndex = i18nLoaded ? tasks.push( saveI18n( eventId, config.i18n || {} ) ) - 1 : -1;

		Promise.allSettled( tasks )
			.then( ( results ) => {
				const cfg = results[ 0 ];
				const tpl = templatesIndex >= 0 ? results[ templatesIndex ] : null;
				const i18n = i18nIndex >= 0 ? results[ i18nIndex ] : null;

				if ( 'fulfilled' === cfg.status ) {
					setValidation( cfg.value.validation || null );
				}

				if ( tpl && 'fulfilled' === tpl.status ) {
					setConfig( ( prev ) => ( { ...prev, mailTemplates: mergeLoaded( tpl.value.templates || {} ) } ) );
					setTemplateDefaults( tpl.value.defaults || {} );
				}

				if ( i18n && 'fulfilled' === i18n.status ) {
					setConfig( ( prev ) => ( { ...prev, i18n: i18n.value || {} } ) );
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
					setError( `${ __( 'Nie zapisano:', 'event-registration' ) } ${ rejectedLabels.join( ', ' ) }.` );
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

function TabRouter( { name, config, update, templateDefaults } ) {
	if ( 'form' === name ) {
		return <FormTab config={ config } update={ update } />;
	}
	if ( 'types' === name ) {
		return <TypesTab config={ config } update={ update } />;
	}
	if ( 'accommodation' === name ) {
		return <AccommodationTab config={ config } update={ update } />;
	}
	if ( 'settings' === name ) {
		return <SettingsTab config={ config } update={ update } />;
	}
	if ( 'mail' === name ) {
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
		return <TranslationsTab config={ config } update={ update } />;
	}
	return null;
}
