import { TextControl, ToggleControl, SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function SettingsTab( { config, update } ) {
	const settings = config.settings || {};
	const setSettings = update( 'settings' );
	const set = ( key ) => ( value ) => setSettings( { ...settings, [ key ]: value } );

	const emails = Array.isArray( settings.notify_emails )
		? settings.notify_emails.join( ', ' )
		: '';

	return (
		<div className="evreg-settings-tab">
			<TextControl
				type="date"
				label={ __( 'Data rozpoczęcia', 'event-registration' ) }
				value={ settings.start_date || '' }
				onChange={ set( 'start_date' ) }
			/>
			<TextControl
				type="date"
				label={ __( 'Data zakończenia', 'event-registration' ) }
				value={ settings.end_date || '' }
				onChange={ set( 'end_date' ) }
			/>
			<TextControl
				type="number"
				label={ __( 'Limit miejsc (puste = brak)', 'event-registration' ) }
				value={ settings.global_cap ?? '' }
				onChange={ ( value ) =>
					setSettings( {
						...settings,
						global_cap: '' === value ? null : parseInt( value, 10 ),
					} )
				}
			/>
			<ToggleControl
				label={ __( 'Lista rezerwowa włączona', 'event-registration' ) }
				checked={ settings.waitlist_enabled !== false }
				onChange={ set( 'waitlist_enabled' ) }
			/>
			<TextControl
				type="date"
				label={ __( 'Otwarcie zapisów', 'event-registration' ) }
				value={ settings.registration_opens || '' }
				onChange={ set( 'registration_opens' ) }
			/>
			<TextControl
				type="date"
				label={ __( 'Zamknięcie zapisów', 'event-registration' ) }
				value={ settings.registration_closes || '' }
				onChange={ set( 'registration_closes' ) }
			/>
			<TextControl
				label={ __( 'E-maile organizatora (oddzielone przecinkami)', 'event-registration' ) }
				value={ emails }
				onChange={ ( value ) =>
					setSettings( {
						...settings,
						notify_emails: value
							.split( ',' )
							.map( ( item ) => item.trim() )
							.filter( Boolean ),
					} )
				}
			/>
			<SelectControl
				label={ __( 'Strona z formularzem (link potwierdzenia)', 'event-registration' ) }
				value={ String( settings.form_page_id || 0 ) }
				options={ [
					{ value: '0', label: __( '— użyj strony wydarzenia —', 'event-registration' ) },
					...( ( window.evregAdmin && window.evregAdmin.pages ) || [] ).map( ( page ) => ( {
						value: String( page.value ),
						label: page.label,
					} ) ),
				] }
				onChange={ ( value ) =>
					setSettings( { ...settings, form_page_id: parseInt( value, 10 ) } )
				}
			/>
		</div>
	);
}
