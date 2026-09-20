import { TextControl, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import {
	translatableItems,
	getTranslation,
	getOptionTranslation,
	setTranslation,
	setOptionTranslation,
	getAccommodationTranslation,
	setAccommodationTranslation,
	getShortLabelTranslation,
	setShortLabelTranslation,
} from '../ops/i18nOps';

export default function TranslationsTab( { config, update } ) {
	const languages = ( window.evregAdmin && window.evregAdmin.languages ) || [];

	if ( ! languages.length ) {
		return (
			<Notice status="info" isDismissible={ false }>
				{ __( 'Włącz Polylang i dodaj języki, aby tłumaczyć treść.', 'event-registration' ) }
			</Notice>
		);
	}

	const setOverlay = update( 'i18n' );
	const overlay = config.i18n || {};
	const items = translatableItems( config.schema || {}, config.types || [], config.accommodation || {} );

	return (
		<div className="evreg-i18n-tab">
			<table className="evreg-i18n-tab__table">
				<thead>
					<tr>
						<th>{ __( 'Oryginał', 'event-registration' ) }</th>
						{ languages.map( ( lang ) => (
							<th key={ lang.value }>{ lang.label }</th>
						) ) }
					</tr>
				</thead>
				<tbody>
					{ items.map( ( item ) => (
						<tr key={ `${ item.kind }:${ item.accKind || '' }:${ item.key }` }>
							<td className="evreg-i18n-tab__base">{ item.base }</td>
							{ languages.map( ( lang ) => {
								let value;
								if ( 'option' === item.kind ) {
									value = getOptionTranslation( overlay, lang.value, item.fieldKey, item.optionValue );
								} else if ( 'accommodation' === item.kind ) {
									value = getAccommodationTranslation( overlay, lang.value, item.accKind, item.key );
								} else if ( 'shortLabel' === item.kind ) {
									value = getShortLabelTranslation( overlay, lang.value, item.key );
								} else {
									value = getTranslation( overlay, lang.value, item.kind, item.key );
								}

								const onChange = ( next ) => {
									if ( 'option' === item.kind ) {
										setOverlay(
											setOptionTranslation(
												overlay,
												lang.value,
												item.fieldKey,
												item.optionValue,
												next
											)
										);
										return;
									}
									if ( 'accommodation' === item.kind ) {
										setOverlay(
											setAccommodationTranslation(
												overlay,
												lang.value,
												item.accKind,
												item.key,
												next
											)
										);
										return;
									}
									if ( 'shortLabel' === item.kind ) {
										setOverlay(
											setShortLabelTranslation( overlay, lang.value, item.key, next )
										);
										return;
									}
									setOverlay(
										setTranslation( overlay, lang.value, item.kind, item.key, next )
									);
								};

								return (
									<td key={ lang.value }>
										<TextControl
											label={ `${ item.base } (${ lang.label })` }
											hideLabelFromVision
											value={ value }
											onChange={ onChange }
										/>
									</td>
								);
							} ) }
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}
