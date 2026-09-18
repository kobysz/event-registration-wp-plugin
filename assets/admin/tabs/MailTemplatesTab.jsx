import { useState } from '@wordpress/element';
import { TextControl, TextareaControl, SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { TEMPLATE_TYPES, PLACEHOLDERS_BY_TYPE, setField } from '../ops/mailTemplateOps';
import { getMailTranslation, setMailTranslation } from '../ops/i18nOps';

export default function MailTemplatesTab( { templates, defaults, i18n, update } ) {
	const [ lang, setLang ] = useState( '' );
	const isBase = '' === lang;

	const setTemplates = update( 'mailTemplates' );
	const setOverlay = update( 'i18n' );
	const values = templates || {};
	const fallback = defaults || {};
	const overlay = i18n || {};
	const languages = ( window.evregAdmin && window.evregAdmin.languages ) || [];

	const langOptions = [
		{ value: '', label: __( 'Podstawowy', 'event-registration' ) },
		...languages,
	];

	return (
		<div className="evreg-mail-templates-tab">
			<p className="evreg-mail-templates-tab__hint">
				{ __(
					'Puste pole = użyty zostanie tekst domyślny (widoczny jako podpowiedź).',
					'event-registration'
				) }
			</p>
			{ !! languages.length && (
				<SelectControl
					label={ __( 'Język', 'event-registration' ) }
					value={ lang }
					options={ langOptions }
					onChange={ setLang }
				/>
			) }
			{ TEMPLATE_TYPES.map( ( { key, label } ) => {
				const entry = values[ key ] || { subject: '', body: '' };
				const def = fallback[ key ] || { subject: '', body: '' };
				const baseSubject = entry.subject || def.subject;
				const baseBody = entry.body || def.body;

				const subjectValue = isBase
					? entry.subject || ''
					: getMailTranslation( overlay, lang, key, 'subject' );
				const bodyValue = isBase
					? entry.body || ''
					: getMailTranslation( overlay, lang, key, 'body' );

				const onSubjectChange = ( value ) => {
					if ( isBase ) {
						setTemplates( setField( values, key, 'subject', value ) );
						return;
					}
					setOverlay( setMailTranslation( overlay, lang, key, 'subject', value ) );
				};

				const onBodyChange = ( value ) => {
					if ( isBase ) {
						setTemplates( setField( values, key, 'body', value ) );
						return;
					}
					setOverlay( setMailTranslation( overlay, lang, key, 'body', value ) );
				};

				return (
					<fieldset className="evreg-mail-template" key={ key }>
						<legend>{ label }</legend>
						<TextControl
							label={ __( 'Temat', 'event-registration' ) }
							value={ subjectValue }
							placeholder={ isBase ? def.subject : baseSubject }
							onChange={ onSubjectChange }
						/>
						<TextareaControl
							label={ __( 'Treść', 'event-registration' ) }
							value={ bodyValue }
							placeholder={ isBase ? def.body : baseBody }
							rows={ 10 }
							onChange={ onBodyChange }
						/>
						<p className="evreg-mail-template__placeholders">
							{ __( 'Dostępne pola:', 'event-registration' ) }{ ' ' }
							<code>{ PLACEHOLDERS_BY_TYPE[ key ].join( ' ' ) }</code>
						</p>
					</fieldset>
				);
			} ) }
		</div>
	);
}
