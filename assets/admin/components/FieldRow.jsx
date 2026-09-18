import { forwardRef } from '@wordpress/element';
import { Button, TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { isSpecial, isAccommodation, isChoice, FIELD_TYPES } from '../ops/fieldTypes';
import { addOption, updateOption, removeOption } from '../ops/schemaOps';
import ConditionEditor from './ConditionEditor';

function typeLabel( type ) {
	const found = FIELD_TYPES.find( ( t ) => t.value === type );
	return found ? found.label : type;
}

/**
 * Prezentacyjna karta pojedynczego pola formularza.
 *
 * Bez logiki DnD — dostaje gotowe propsy uchwytu z SortableFieldRow. Pole
 * przypięte (__type) renderowane jest z `pinned`, bez uchwytu i przycisku usuń.
 */
const FieldRow = forwardRef( function FieldRow(
	{
		field,
		onChange,
		onRemove,
		pinned = false,
		isDragging = false,
		handleProps = null,
		handleRef = null,
		style = null,
		triggers = [],
		types = [],
		onConditionChange = () => {},
		...rest
	},
	ref
) {
	const special = isSpecial( field.key );
	const showRequired =
		! isAccommodation( field.type ) &&
		field.type !== 'heading' &&
		field.type !== 'paragraph';
	const showOptions = isChoice( field.type ) && ! special;
	const options = field.options || [];

	const classes = [ 'evreg-field-row' ];
	if ( pinned ) {
		classes.push( 'is-pinned' );
	}
	if ( isDragging ) {
		classes.push( 'is-dragging' );
	}

	return (
		<div ref={ ref } className={ classes.join( ' ' ) } style={ style || undefined } { ...rest }>
			<div className="evreg-field-row__grip">
				{ pinned ? (
					<span
						className="evreg-field-row__handle is-locked"
						aria-hidden="true"
						title={ __( 'Pole przypięte — zawsze na górze', 'event-registration' ) }
					>
						⚲
					</span>
				) : (
					<button
						type="button"
						ref={ handleRef }
						className="evreg-field-row__handle"
						aria-label={ __( 'Przeciągnij, aby zmienić kolejność', 'event-registration' ) }
						{ ...( handleProps || {} ) }
					>
						⠿
					</button>
				) }
			</div>

			<div className="evreg-field-row__body">
				<div className="evreg-field-row__head">
					<span className="evreg-field-row__badge">{ typeLabel( field.type ) }</span>
					<span className="evreg-field-row__key">{ field.key }</span>
				</div>

				<TextControl
					label={ __( 'Etykieta', 'event-registration' ) }
					value={ field.label || '' }
					onChange={ ( label ) => onChange( { label } ) }
				/>

				{ showRequired && (
					<ToggleControl
						label={ __( 'Wymagane', 'event-registration' ) }
						checked={ !! field.required }
						onChange={ ( required ) => onChange( { required } ) }
					/>
				) }

				{ showOptions && (
					<div className="evreg-field-options">
						<span className="evreg-field-options__title">
							{ __( 'Opcje', 'event-registration' ) }
						</span>
						{ options.map( ( opt, i ) => (
							<div className="evreg-field-options__row" key={ i }>
								<TextControl
									label={ __( 'Wartość', 'event-registration' ) }
									value={ opt.value || '' }
									onChange={ ( value ) =>
										onChange( { options: updateOption( options, i, { value } ) } )
									}
								/>
								<TextControl
									label={ __( 'Etykieta', 'event-registration' ) }
									value={ opt.label || '' }
									onChange={ ( label ) =>
										onChange( { options: updateOption( options, i, { label } ) } )
									}
								/>
								<Button
									isDestructive
									variant="tertiary"
									aria-label={ __( 'Usuń opcję', 'event-registration' ) }
									onClick={ () => onChange( { options: removeOption( options, i ) } ) }
								>
									{ __( 'Usuń', 'event-registration' ) }
								</Button>
							</div>
						) ) }
						<Button
							variant="secondary"
							onClick={ () => onChange( { options: addOption( options ) } ) }
						>
							{ __( 'Dodaj opcję', 'event-registration' ) }
						</Button>
					</div>
				) }

				{ special && (
					<p className="evreg-field-row__note">
						{ __( 'Opcje pochodzą z zakładki Typy zgłoszenia.', 'event-registration' ) }
					</p>
				) }
				{ isAccommodation( field.type ) && (
					<p className="evreg-field-row__note">
						{ __( 'Inwentarz konfigurujesz w zakładce Noclegi.', 'event-registration' ) }
					</p>
				) }

				{ ! special && (
					<ConditionEditor
						condition={ field.condition || null }
						triggers={ triggers }
						types={ types }
						onChange={ ( condition ) => onConditionChange( field.key, condition ) }
					/>
				) }
			</div>

			{ ! special && (
				<div className="evreg-field-row__actions">
					<Button isDestructive variant="tertiary" onClick={ onRemove }>
						{ __( 'Usuń', 'event-registration' ) }
					</Button>
				</div>
			) }
		</div>
	);
} );

export default FieldRow;
