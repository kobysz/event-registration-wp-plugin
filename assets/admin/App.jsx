import { useState, useEffect } from '@wordpress/element';
import { Button, TabPanel, Spinner, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { loadConfig, saveConfig } from './api';
import ValidationReport from './components/ValidationReport';

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
					schema: data.schema || {},
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

function TabRouter( { name } ) {
	// Zakładki podłączane w kolejnych taskach (3–6). Na razie placeholder,
	// żeby shell działał end-to-end.
	return <p>{ name }</p>;
}
