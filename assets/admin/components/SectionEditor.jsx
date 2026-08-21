import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useDroppable } from '@dnd-kit/core';
import { SortableContext, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { TYPE_FIELD_KEY } from '../ops/fieldTypes';
import { sectionDroppableId } from '../ops/dnd';
import FieldRow from './FieldRow';
import SortableFieldRow from './SortableFieldRow';

export default function SectionEditor( {
	section,
	canMoveUp,
	canMoveDown,
	onMoveSection,
	onFieldChange,
	onFieldRemove,
	onRenameSection,
	onRemoveSection,
} ) {
	const [ collapsed, setCollapsed ] = useState( false );

	const fields = section.fields || [];
	const pinned = fields.find( ( f ) => f.key === TYPE_FIELD_KEY );
	const draggable = fields.filter( ( f ) => f.key !== TYPE_FIELD_KEY );
	const isEmpty = fields.length === 0;

	const { setNodeRef, isOver } = useDroppable( { id: sectionDroppableId( section.key ) } );

	const classes = [ 'evreg-section' ];
	if ( collapsed ) {
		classes.push( 'is-collapsed' );
	}
	if ( isOver ) {
		classes.push( 'is-drop-target' );
	}

	return (
		<div className={ classes.join( ' ' ) }>
			<div className="evreg-section__header">
				<button
					type="button"
					className="evreg-section__toggle"
					aria-expanded={ ! collapsed }
					onClick={ () => setCollapsed( ( c ) => ! c ) }
				>
					{ collapsed ? '▸' : '▾' }
				</button>
				<input
					type="text"
					className="evreg-section__title"
					value={ section.title || '' }
					placeholder={ section.key }
					aria-label={ __( 'Nazwa sekcji', 'event-registration' ) }
					onChange={ ( e ) => onRenameSection( section.key, e.target.value ) }
				/>
				<span className="evreg-section__count">
					{ fields.length }
				</span>
				<Button
					className="evreg-section__move"
					aria-label={ __( 'Przesuń sekcję w górę', 'event-registration' ) }
					disabled={ ! canMoveUp }
					onClick={ () => onMoveSection( section.key, 'up' ) }
				>
					↑
				</Button>
				<Button
					className="evreg-section__move"
					aria-label={ __( 'Przesuń sekcję w dół', 'event-registration' ) }
					disabled={ ! canMoveDown }
					onClick={ () => onMoveSection( section.key, 'down' ) }
				>
					↓
				</Button>
				{ isEmpty && (
					<Button
						isDestructive
						variant="tertiary"
						onClick={ () => onRemoveSection( section.key ) }
					>
						{ __( 'Usuń sekcję', 'event-registration' ) }
					</Button>
				) }
			</div>

			{ ! collapsed && (
				<div ref={ setNodeRef } className="evreg-section__fields">
					{ pinned && (
						<FieldRow
							pinned
							field={ pinned }
							onChange={ ( patch ) => onFieldChange( pinned.key, patch ) }
							onRemove={ () => {} }
						/>
					) }

					<SortableContext
						items={ draggable.map( ( f ) => f.key ) }
						strategy={ verticalListSortingStrategy }
					>
						{ draggable.map( ( field ) => (
							<SortableFieldRow
								key={ field.key }
								field={ field }
								onChange={ ( patch ) => onFieldChange( field.key, patch ) }
								onRemove={ () => onFieldRemove( field.key ) }
							/>
						) ) }
					</SortableContext>

					{ isEmpty && (
						<p className="evreg-section__empty">
							{ __( 'Brak pól — przeciągnij pole tutaj albo dodaj nowe niżej.', 'event-registration' ) }
						</p>
					) }
				</div>
			) }
		</div>
	);
}
