import { createRoot } from '@wordpress/element';
import App from './App';

const container = document.getElementById( 'evreg-admin-root' );

if ( container ) {
	const eventId = window.evregAdmin ? parseInt( window.evregAdmin.eventId, 10 ) : 0;
	createRoot( container ).render( <App eventId={ eventId } /> );
}
