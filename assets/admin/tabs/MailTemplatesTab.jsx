import { TextControl, TextareaControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { TEMPLATE_TYPES, PLACEHOLDERS_BY_TYPE, setField } from '../ops/mailTemplateOps';

export default function MailTemplatesTab( { templates, defaults, update } ) {
	const setTemplates = update( 'mailTemplates' );
	const values = templates || {};
	const fallback = defaults || {};

	return (
		<div className="evreg-mail-templates-tab">
			<p className="evreg-mail-templates-tab__hint">
				{ __(
					'Puste pole = użyty zostanie tekst domyślny (widoczny jako podpowiedź).',
					'event-registration'
				) }
			</p>
			{ TEMPLATE_TYPES.map( ( { key, label } ) => {
				const entry = values[ key ] || { subject: '', body: '' };
				const def = fallback[ key ] || { subject: '', body: '' };

				return (
					<fieldset className="evreg-mail-template" key={ key }>
						<legend>{ label }</legend>
						<TextControl
							label={ __( 'Temat', 'event-registration' ) }
							value={ entry.subject || '' }
							placeholder={ def.subject }
							onChange={ ( value ) => setTemplates( setField( values, key, 'subject', value ) ) }
						/>
						<TextareaControl
							label={ __( 'Treść', 'event-registration' ) }
							value={ entry.body || '' }
							placeholder={ def.body }
							rows={ 10 }
							onChange={ ( value ) => setTemplates( setField( values, key, 'body', value ) ) }
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
