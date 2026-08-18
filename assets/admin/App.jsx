import { __ } from '@wordpress/i18n';

export default function App( { eventId } ) {
	return (
		<div className="evreg-admin">
			<p>{ __( 'Konfiguracja wydarzenia', 'event-registration' ) } #{ eventId }</p>
		</div>
	);
}
