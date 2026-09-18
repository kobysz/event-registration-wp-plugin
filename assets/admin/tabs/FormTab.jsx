import { useState } from '@wordpress/element';
import { Button, SelectControl, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import {
	DndContext,
	closestCenter,
	PointerSensor,
	KeyboardSensor,
	useSensor,
	useSensors,
} from '@dnd-kit/core';
import { sortableKeyboardCoordinates } from '@dnd-kit/sortable';
import SectionEditor from '../components/SectionEditor';
import { FIELD_TYPES } from '../ops/fieldTypes';
import {
	emptySchema,
	ensureTypeField,
	addSection,
	removeSection,
	renameSection,
	moveSection,
	addField,
	removeField,
	moveFieldTo,
	updateField,
	setFieldCondition,
	setSectionCondition,
} from '../ops/schemaOps';
import { resolveDrop } from '../ops/dnd';

export default function FormTab( { config, update } ) {
	const schema = ensureTypeField(
		config.schema && config.schema.sections ? config.schema : emptySchema()
	);
	const setSchema = update( 'schema' );

	const [ newSection, setNewSection ] = useState( '' );
	const [ fieldType, setFieldType ] = useState( 'text' );
	const [ fieldKey, setFieldKey ] = useState( '' );
	const firstSection = schema.sections[ 0 ] ? schema.sections[ 0 ].key : 'dane';
	const [ targetSection, setTargetSection ] = useState( firstSection );

	const sectionOptions = schema.sections.map( ( s ) => ( {
		value: s.key,
		label: s.title || s.key,
	} ) );
	const activeTarget = schema.sections.some( ( s ) => s.key === targetSection )
		? targetSection
		: firstSection;
	const allFields = schema.sections.flatMap( ( s ) => s.fields || [] );

	const sensors = useSensors(
		useSensor( PointerSensor, { activationConstraint: { distance: 5 } } ),
		useSensor( KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates } )
	);

	const onDragEnd = ( { active, over } ) => {
		const drop = resolveDrop( schema, active.id, over ? over.id : null );
		if ( drop ) {
			setSchema( moveFieldTo( schema, active.id, drop.toSectionKey, drop.toIndex ) );
		}
	};

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
			addField( schema, activeTarget, { key: fieldKey, type: fieldType, label: fieldKey } )
		);
		setFieldKey( '' );
	};

	return (
		<div className="evreg-form-tab">
			<DndContext sensors={ sensors } collisionDetection={ closestCenter } onDragEnd={ onDragEnd }>
				{ schema.sections.map( ( section, index ) => (
					<SectionEditor
						key={ section.key }
						section={ section }
						canMoveUp={ index > 0 }
						canMoveDown={ index < schema.sections.length - 1 }
						onMoveSection={ ( key, dir ) => setSchema( moveSection( schema, key, dir ) ) }
						onFieldChange={ ( key, patch ) => setSchema( updateField( schema, key, patch ) ) }
						onFieldRemove={ ( key ) => setSchema( removeField( schema, key ) ) }
						onRenameSection={ ( key, title ) => setSchema( renameSection( schema, key, title ) ) }
						onRemoveSection={ ( key ) => setSchema( removeSection( schema, key ) ) }
						allFields={ allFields }
						types={ config.types || [] }
						onFieldConditionChange={ ( key, condition ) =>
							setSchema( setFieldCondition( schema, key, condition ) )
						}
						onSectionConditionChange={ ( key, condition ) =>
							setSchema( setSectionCondition( schema, key, condition ) )
						}
					/>
				) ) }
			</DndContext>

			<div className="evreg-form-tab__toolbar">
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
					<SelectControl
						label={ __( 'Sekcja', 'event-registration' ) }
						value={ activeTarget }
						options={ sectionOptions }
						onChange={ setTargetSection }
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
		</div>
	);
}
