import { Button, TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { isSpecial, isAccommodation } from '../ops/fieldTypes';

export default function FieldRow( { field, onChange, onRemove, onMove } ) {
	const special = isSpecial( field.key );

	return (
		<div className="evreg-field-row">
			<strong>{ field.type }</strong>
			<TextControl
				label={ __( 'Etykieta', 'event-registration' ) }
				value={ field.label || '' }
				onChange={ ( label ) => onChange( { label } ) }
			/>
			{ ! isAccommodation( field.type ) && field.type !== 'heading' && field.type !== 'paragraph' && (
				<ToggleControl
					label={ __( 'Wymagane', 'event-registration' ) }
					checked={ !! field.required }
					onChange={ ( required ) => onChange( { required } ) }
				/>
			) }
			{ special && (
				<p className="description">
					{ __( 'Opcje pochodzą z zakładki Typy zgłoszenia.', 'event-registration' ) }
				</p>
			) }
			{ isAccommodation( field.type ) && (
				<p className="description">
					{ __( 'Inwentarz konfigurujesz w zakładce Noclegi.', 'event-registration' ) }
				</p>
			) }
			<div className="evreg-field-row__actions">
				<Button onClick={ () => onMove( 'up' ) }>↑</Button>
				<Button onClick={ () => onMove( 'down' ) }>↓</Button>
				{ ! special && (
					<Button isDestructive onClick={ onRemove }>
						{ __( 'Usuń', 'event-registration' ) }
					</Button>
				) }
			</div>
		</div>
	);
}
