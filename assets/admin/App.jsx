import { useState, useEffect } from '@wordpress/element';
import { Button, TabPanel, Spinner, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { loadConfig, saveConfig, loadTemplates, saveTemplates } from './api';
import ValidationReport from './components/ValidationReport';
import FormTab from './tabs/FormTab';
import TypesTab from './tabs/TypesTab';
import AccommodationTab from './tabs/AccommodationTab';
import SettingsTab from './tabs/SettingsTab';
import MailTemplatesTab from './tabs/MailTemplatesTab';
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
	}, [ eventId ] );

	const update = ( key ) => ( value ) =>
		setConfig( ( prev ) => ( { ...prev, [ key ]: value } ) );

	const onSave = () => {
		setSaving( true );
		setError( '' );

		// Never issue a templates save before the initial load has completed: an
		// unloaded (or failed-load) `config.mailTemplates` would normalize to an
		// empty set and the REST endpoint does a full replace, wiping any
		// previously saved overrides. Only save templates once we know we have
		// a real snapshot to normalize.
		const tasks = [ saveConfig( eventId, config ) ];
		if ( templatesLoaded ) {
			tasks.push( saveTemplates( eventId, normalizeForSave( config.mailTemplates || {} ) ) );
		}

		Promise.allSettled( tasks )
			.then( ( [ cfg, tpl ] ) => {
				if ( 'fulfilled' === cfg.status ) {
					setValidation( cfg.value.validation || null );
				}

				if ( ! templatesLoaded ) {
					if ( 'rejected' === cfg.status ) {
						setError( __( 'Zapis nie powiódł się.', 'event-registration' ) );
					}
					return;
				}

				if ( 'fulfilled' === tpl.status ) {
					setConfig( ( prev ) => ( { ...prev, mailTemplates: mergeLoaded( tpl.value.templates || {} ) } ) );
					setTemplateDefaults( tpl.value.defaults || {} );
				}
				if ( 'rejected' === cfg.status && 'rejected' === tpl.status ) {
					setError( __( 'Zapis nie powiódł się.', 'event-registration' ) );
				} else if ( 'rejected' === cfg.status ) {
					setError( __( 'Konfiguracja nie zapisana; szablony zapisane.', 'event-registration' ) );
				} else if ( 'rejected' === tpl.status ) {
					setError( __( 'Szablony nie zapisane; konfiguracja zapisana.', 'event-registration' ) );
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
	];

	return (
		<div className="evreg-admin">
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
				update={ update }
			/>
		);
	}
	return null;
}
