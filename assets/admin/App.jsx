import { useState, useEffect } from '@wordpress/element';
import { Button, TabPanel, Spinner, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { loadConfig, saveConfig } from './api';
import ValidationReport from './components/ValidationReport';
import FormTab from './tabs/FormTab';
import TypesTab from './tabs/TypesTab';
import AccommodationTab from './tabs/AccommodationTab';
import SettingsTab from './tabs/SettingsTab';
import { ensureTypeField, emptySchema } from './ops/schemaOps';

const EMPTY = { schema: {}, types: [], accommodation: {}, settings: {} };

export default function App( { eventId } ) {
	const [ config, setConfig ] = useState( EMPTY );
	const [ validation, setValidation ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
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
	}, [ eventId ] );

	const update = ( key ) => ( value ) =>
		setConfig( ( prev ) => ( { ...prev, [ key ]: value } ) );

	const onSave = () => {
		setSaving( true );
		setError( '' );
		saveConfig( eventId, config )
			.then( ( data ) => setValidation( data.validation || null ) )
			.catch( () => setError( __( 'Zapis nie powiódł się.', 'event-registration' ) ) )
			.finally( () => setSaving( false ) );
	};

	if ( loading ) {
		return <Spinner />;
	}

	const tabs = [
		{ name: 'form', title: __( 'Formularz', 'event-registration' ) },
		{ name: 'types', title: __( 'Typy zgłoszenia', 'event-registration' ) },
		{ name: 'accommodation', title: __( 'Noclegi', 'event-registration' ) },
		{ name: 'settings', title: __( 'Ustawienia', 'event-registration' ) },
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

function TabRouter( { name, config, update } ) {
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
	return null;
}
