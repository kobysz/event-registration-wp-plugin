import { useState } from '@wordpress/element';
import { Button, SelectControl, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import SectionEditor from '../components/SectionEditor';
import { FIELD_TYPES } from '../ops/fieldTypes';
import {
	emptySchema,
	ensureTypeField,
	addSection,
	addField,
	removeField,
	moveField,
	updateField,
} from '../ops/schemaOps';

export default function FormTab( { config, update } ) {
	const schema = ensureTypeField(
		config.schema && config.schema.sections ? config.schema : emptySchema()
	);
	const setSchema = update( 'schema' );

	const [ newSection, setNewSection ] = useState( '' );
	const [ fieldType, setFieldType ] = useState( 'text' );
	const [ fieldKey, setFieldKey ] = useState( '' );
	const targetSection = schema.sections[ 0 ] ? schema.sections[ 0 ].key : 'dane';

	const onAddSection = () => {
		if ( ! newSection ) {
			return;
		}
		setSchema( addSection( schema, newSection, newSection ) );
		setNewSection( '' );
	};

	const onAddField = () => {
		if ( ! fieldKey ) {
			return;
		}
		setSchema(
			addField( schema, targetSection, { key: fieldKey, type: fieldType, label: fieldKey } )
		);
		setFieldKey( '' );
	};

	return (
		<div className="evreg-form-tab">
			{ schema.sections.map( ( section ) => (
				<SectionEditor
					key={ section.key }
					section={ section }
					onFieldChange={ ( key, patch ) => setSchema( updateField( schema, key, patch ) ) }
					onFieldRemove={ ( key ) => setSchema( removeField( schema, key ) ) }
					onFieldMove={ ( key, dir ) => setSchema( moveField( schema, key, dir ) ) }
				/>
			) ) }

			<div className="evreg-form-tab__add-field">
				<TextControl
					label={ __( 'Klucz nowego pola', 'event-registration' ) }
					value={ fieldKey }
					onChange={ setFieldKey }
				/>
				<SelectControl
					label={ __( 'Typ', 'event-registration' ) }
					value={ fieldType }
					options={ FIELD_TYPES }
					onChange={ setFieldType }
				/>
				<Button variant="secondary" onClick={ onAddField }>
					{ __( 'Dodaj pole', 'event-registration' ) }
				</Button>
			</div>

			<div className="evreg-form-tab__add-section">
				<TextControl
					label={ __( 'Nowa sekcja', 'event-registration' ) }
					value={ newSection }
					onChange={ setNewSection }
				/>
				<Button variant="secondary" onClick={ onAddSection }>
					{ __( 'Dodaj sekcję', 'event-registration' ) }
				</Button>
			</div>
		</div>
	);
}
