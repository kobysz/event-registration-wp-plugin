import { Button, TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { addType, removeType, updateType } from '../ops/typesOps';

export default function TypesTab( { config, update } ) {
	const types = config.types || [];
	const setTypes = update( 'types' );

	return (
		<div className="evreg-types-tab">
			{ types.map( ( type, index ) => (
				<div className="evreg-type-row" key={ index }>
					<TextControl
						label={ __( 'Klucz', 'event-registration' ) }
						value={ type.key || '' }
						onChange={ ( key ) => setTypes( updateType( types, index, { key } ) ) }
					/>
					<TextControl
						label={ __( 'Nazwa', 'event-registration' ) }
						value={ type.label || '' }
						onChange={ ( label ) => setTypes( updateType( types, index, { label } ) ) }
					/>
					<TextControl
						type="number"
						label={ __( 'Cena', 'event-registration' ) }
						value={ type.price ?? 0 }
						onChange={ ( price ) =>
							setTypes( updateType( types, index, { price: parseFloat( price ) || 0 } ) )
						}
					/>
					<TextControl
						type="number"
						label={ __( 'Limit (puste = brak)', 'event-registration' ) }
						value={ type.capacity ?? '' }
						onChange={ ( capacity ) =>
							setTypes(
								updateType( types, index, {
									capacity: '' === capacity ? null : parseInt( capacity, 10 ),
								} )
							)
						}
					/>
					<ToggleControl
						label={ __( 'Aktywny', 'event-registration' ) }
						checked={ type.active !== false }
						onChange={ ( active ) => setTypes( updateType( types, index, { active } ) ) }
					/>
					<Button isDestructive onClick={ () => setTypes( removeType( types, index ) ) }>
						{ __( 'Usuń', 'event-registration' ) }
					</Button>
				</div>
			) ) }
			<Button variant="secondary" onClick={ () => setTypes( addType( types ) ) }>
				{ __( 'Dodaj typ', 'event-registration' ) }
			</Button>
		</div>
	);
}
