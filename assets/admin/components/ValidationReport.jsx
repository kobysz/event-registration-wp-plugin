import { __ } from '@wordpress/i18n';
import { messageForCode } from '../ops/validationMessages';

export default function ValidationReport( { validation } ) {
	if ( ! validation ) {
		return null;
	}

	if ( validation.valid ) {
		return (
			<div className="notice notice-success inline">
				<p>{ __( 'Konfiguracja jest poprawna.', 'event-registration' ) }</p>
			</div>
		);
	}

	return (
		<div className="notice notice-warning inline">
			<ul>
				{ ( validation.errors || [] ).map( ( error, index ) => (
					<li key={ index }>{ messageForCode( error.code, error.detail ) }</li>
				) ) }
			</ul>
		</div>
	);
}
