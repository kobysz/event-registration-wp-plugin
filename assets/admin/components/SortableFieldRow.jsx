import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import FieldRow from './FieldRow';

/**
 * Owija FieldRow w logikę @dnd-kit (uchwyt aktywujący + transform).
 * Używane tylko dla pól przeciągalnych — __type renderuje się bez tego wrappera.
 */
export default function SortableFieldRow( {
	field,
	onChange,
	onRemove,
	triggers,
	types,
	onConditionChange,
} ) {
	const {
		attributes,
		listeners,
		setNodeRef,
		setActivatorNodeRef,
		transform,
		transition,
		isDragging,
	} = useSortable( { id: field.key } );

	const style = {
		transform: CSS.Translate.toString( transform ),
		transition,
		opacity: isDragging ? 0.4 : 1,
	};

	return (
		<FieldRow
			ref={ setNodeRef }
			field={ field }
			onChange={ onChange }
			onRemove={ onRemove }
			isDragging={ isDragging }
			handleRef={ setActivatorNodeRef }
			handleProps={ { ...attributes, ...listeners } }
			style={ style }
			triggers={ triggers }
			types={ types }
			onConditionChange={ onConditionChange }
		/>
	);
}
