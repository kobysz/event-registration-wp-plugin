import { ToggleControl, SelectControl, CheckboxControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import {
	availableOperators,
	triggerOptions,
	operatorNeedsValue,
} from '../ops/conditionOps';

export default function ConditionEditor( { condition, triggers, types, onChange } ) {
	const enabled = !! condition;

	const triggerField = condition
		? triggers.find( ( t ) => t.key === condition.field ) || null
		: null;
	const operators = availableOperators( triggerField );
	const options = triggerOptions( triggerField, types );
	const selected = Array.isArray( condition?.value )
		? condition.value
		: condition?.value
		? [ condition.value ]
		: [];

	const onToggle = ( on ) => {
		if ( ! on ) {
			onChange( null );
			return;
		}
		const first = triggers[ 0 ] || null;
		onChange( { field: first ? first.key : '', operator: 'not_empty' } );
	};

	const onTrigger = ( field ) => {
		// Reset operator/value gdy zmienia się trigger (opcje mogą zniknąć).
		onChange( { field, operator: 'not_empty' } );
	};

	const onOperator = ( operator ) => {
		const next = { field: condition.field, operator };
		if ( operatorNeedsValue( operator ) ) {
			next.value = 'in' === operator || 'not_in' === operator ? [] : '';
		}
		onChange( next );
	};

	const onSingleValue = ( value ) => onChange( { ...condition, value } );

	const onMultiValue = ( optionValue, checked ) => {
		const set = new Set( selected );
		if ( checked ) {
			set.add( optionValue );
		} else {
			set.delete( optionValue );
		}
		onChange( { ...condition, value: Array.from( set ) } );
	};

	return (
		<div className="evreg-condition">
			<ToggleControl
				label={ __( 'Pokaż warunkowo', 'event-registration' ) }
				checked={ enabled }
				onChange={ onToggle }
			/>

			{ enabled && (
				<div className="evreg-condition__body">
					<SelectControl
						label={ __( 'Gdy pole', 'event-registration' ) }
						value={ condition.field }
						options={ triggers.map( ( t ) => ( { value: t.key, label: t.label || t.key } ) ) }
						onChange={ onTrigger }
					/>
					<SelectControl
						label={ __( 'Operator', 'event-registration' ) }
						value={ condition.operator }
						options={ operators }
						onChange={ onOperator }
					/>

					{ operatorNeedsValue( condition.operator ) &&
						( 'in' === condition.operator || 'not_in' === condition.operator ) && (
							<div className="evreg-condition__values">
								{ options.map( ( o ) => (
									<CheckboxControl
										key={ o.value }
										label={ o.label }
										checked={ selected.includes( o.value ) }
										onChange={ ( checked ) => onMultiValue( o.value, checked ) }
									/>
								) ) }
							</div>
						) }

					{ operatorNeedsValue( condition.operator ) &&
						'in' !== condition.operator &&
						'not_in' !== condition.operator && (
							<SelectControl
								label={ __( 'Wartość', 'event-registration' ) }
								value={ Array.isArray( condition.value ) ? '' : condition.value || '' }
								options={ [ { value: '', label: __( '— wybierz —', 'event-registration' ) }, ...options ] }
								onChange={ onSingleValue }
							/>
						) }
				</div>
			) }
		</div>
	);
}
