import FieldRow from './FieldRow';

export default function SectionEditor( { section, onFieldChange, onFieldRemove, onFieldMove } ) {
	return (
		<div className="evreg-section">
			<h3>{ section.title || section.key }</h3>
			{ ( section.fields || [] ).map( ( field ) => (
				<FieldRow
					key={ field.key }
					field={ field }
					onChange={ ( patch ) => onFieldChange( field.key, patch ) }
					onRemove={ () => onFieldRemove( field.key ) }
					onMove={ ( dir ) => onFieldMove( field.key, dir ) }
				/>
			) ) }
		</div>
	);
}
