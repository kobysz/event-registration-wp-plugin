<?php
/**
 * Renderuje formularz edycji odpowiedzi zgłoszenia w adminie.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Admin;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Schema\Field;
use EvReg\Domain\Schema\FieldType;
use EvReg\Domain\Schema\FormSchema;

defined( 'ABSPATH' ) || exit;

/**
 * Renderuje `<form>` edycji odpowiedzi zgłoszenia (POST → admin-post.php).
 */
final class RegistrationEditForm {

	/**
	 * Renderuje formularz edycji odpowiedzi zgłoszenia (POST → admin-post.php).
	 *
	 * @param FormSchema                $schema    Złożony schemat formularza eventu.
	 * @param array<string,mixed>       $answers   Bieżące odpowiedzi zgłoszenia (prefill domyślny).
	 * @param int                       $reg_id    ID zgłoszenia.
	 * @param array<string,string>|null $errors    Błędy walidacji: klucz pola → kod błędu (kształt {@see \EvReg\Domain\Validation\ValidationResult::errors()}).
	 * @param array<string,mixed>|null  $submitted Wartości z odrzuconego POST (prefill przy błędzie).
	 */
	public static function render( FormSchema $schema, array $answers, int $reg_id, ?array $errors = null, ?array $submitted = null ): string {
		$source = null !== $submitted ? $submitted : $answers;
		$nonce  = wp_nonce_field( 'evreg_edit_' . $reg_id, '_wpnonce', true, false );

		// Klasa evreg-form — reuse publicznego assets/public/form.js (evreg-public, wp_enqueue_script
		// w RegistrationsScreen::render_edit) do przełączania widoczności pola imienia companiona.
		$out  = '<form class="evreg-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$out .= '<input type="hidden" name="action" value="evreg_edit_registration" />';
		$out .= '<input type="hidden" name="registration" value="' . esc_attr( (string) $reg_id ) . '" />';
		$out .= $nonce;
		$out .= self::renderErrors( $schema, $errors );

		$out .= '<table class="form-table"><tbody>';
		foreach ( $schema->allFields() as $field ) {
			if ( ! $field->type->isInput() ) {
				continue;
			}
			$out .= self::renderRow( $field, $source[ $field->key ] ?? '' );
			if ( FieldType::Accommodation === $field->type ) {
				$out .= self::renderCompanionRow( $field, $source, $errors );
			}
		}
		$out .= '</tbody></table>';
		$out .= '<p><button type="submit" class="button button-primary">' . esc_html__( 'Zapisz zmiany', 'event-registration' ) . '</button></p>';
		$out .= '</form>';

		return $out;
	}

	/**
	 * Renderuje blok błędów walidacji, jeśli są — etykieta pola + przetłumaczony komunikat.
	 *
	 * @param FormSchema                $schema Złożony schemat formularza (źródło etykiet pól).
	 * @param array<string,string>|null $errors Błędy walidacji: klucz pola → kod błędu.
	 */
	private static function renderErrors( FormSchema $schema, ?array $errors ): string {
		if ( empty( $errors ) ) {
			return '';
		}

		$labels = array();
		foreach ( $schema->allFields() as $field ) {
			$labels[ $field->key ] = $field->label;
		}
		// Kontrolka companion NIE jest polem schematu (patrz renderCompanionRow) — dokładamy
		// etykietę ręcznie, inaczej lista błędów pokazałaby surowy klucz evreg_companion.
		$labels['evreg_companion'] = __( 'Osoba towarzysząca', 'event-registration' );

		$items = '';
		foreach ( $errors as $field_key => $code ) {
			$label  = $labels[ $field_key ] ?? $field_key;
			$items .= '<li>' . esc_html( $label ) . ': ' . esc_html( self::errorMessage( (string) $code ) ) . '</li>';
		}

		return '<div class="notice notice-error"><ul>' . $items . '</ul></div>';
	}

	/**
	 * Tłumaczy kod błędu walidacji na komunikat czytelny dla użytkownika.
	 *
	 * Mapa skopiowana 1:1 z {@see \EvReg\Frontend\FormRenderer::errorMessage()} — te same
	 * kody, te same komunikaty, ten sam text domain. Formularz publiczny i ekran edycji
	 * w adminie muszą pokazywać identyczną treść dla tych samych kodów walidacji.
	 *
	 * @param string $code Kod błędu.
	 */
	private static function errorMessage( string $code ): string {
		$map = array(
			'required'                => __( 'To pole jest wymagane.', 'event-registration' ),
			'invalid_email'           => __( 'Nieprawidłowy adres e-mail.', 'event-registration' ),
			'invalid_tel'             => __( 'Nieprawidłowy numer telefonu.', 'event-registration' ),
			'invalid_number'          => __( 'Nieprawidłowa liczba.', 'event-registration' ),
			'invalid_date'            => __( 'Nieprawidłowa data.', 'event-registration' ),
			'not_in_options'          => __( 'Wybór spoza dostępnych opcji.', 'event-registration' ),
			'too_long'                => __( 'Wpis jest za długi.', 'event-registration' ),
			'invalid_accommodation'   => __( 'Nieprawidłowy wybór noclegu.', 'event-registration' ),
			'roommate_not_allowed'    => __( 'Współlokator niedozwolony dla tego pokoju.', 'event-registration' ),
			'companion_name_required' => __( 'Podaj imię i nazwisko osoby towarzyszącej.', 'event-registration' ),
		);

		return $map[ $code ] ?? __( 'Nieprawidłowa wartość.', 'event-registration' );
	}

	/**
	 * Renderuje jeden wiersz tabeli formularza (etykieta + kontrolka).
	 *
	 * @param Field $field Pole formularza.
	 * @param mixed $value Wartość do odtworzenia w kontrolce.
	 */
	private static function renderRow( Field $field, $value ): string {
		$label   = '<th scope="row"><label>' . esc_html( $field->label ) . '</label></th>';
		$desc    = '' !== $field->description ? '<p class="description">' . esc_html( $field->description ) . '</p>' : '';
		$control = '<td>' . self::renderControl( $field, $value ) . $desc . '</td>';
		return '<tr>' . $label . $control . '</tr>';
	}

	/**
	 * Renderuje kontrolkę wejściową pola odpowiednią do jego typu.
	 *
	 * @param Field $field Pole formularza.
	 * @param mixed $value Wartość do odtworzenia w kontrolce.
	 */
	private static function renderControl( Field $field, $value ): string {
		// Namespace pod evreg_field[...] — spójnie z FormRenderer i wspólną ekstrakcją
		// SubmissionAssembler (klucze pól nie kolidują z query-vars WordPressa).
		$name = 'evreg_field[' . esc_attr( $field->key ) . ']';
		switch ( $field->type ) {
			case FieldType::Textarea:
				return '<textarea name="' . $name . '" rows="4" class="large-text">' . esc_textarea( self::scalar( $value ) ) . '</textarea>';
			case FieldType::Select:
			case FieldType::Radio:
			case FieldType::Checkbox:
			case FieldType::CheckboxGroup:
				return self::renderChoices( $field, $value );
			case FieldType::Accommodation:
				return self::renderAccommodation( $field, $value );
			case FieldType::Hidden:
				return '<input type="hidden" name="' . $name . '" value="' . esc_attr( self::scalar( $value ) ) . '" />';
			default: // Text, Email, Tel, Number, Date.
				$input_type = esc_attr( $field->type->value );
				return '<input type="' . $input_type . '" name="' . $name . '" value="' . esc_attr( self::scalar( $value ) ) . '" class="regular-text" />';
		}
	}

	/**
	 * Renderuje kontrolki wyboru: select/radio/checkbox/checkbox-group.
	 *
	 * @param Field $field Pole formularza (typu select/radio/checkbox/checkbox-group).
	 * @param mixed $value Wartość (lub wartości) do odtworzenia.
	 */
	private static function renderChoices( Field $field, $value ): string {
		// Namespace pod evreg_field[...] — spójnie z FormRenderer i wspólną ekstrakcją
		// SubmissionAssembler (klucze pól nie kolidują z query-vars WordPressa).
		$name = 'evreg_field[' . esc_attr( $field->key ) . ']';

		if ( FieldType::Checkbox === $field->type ) {
			return '<input type="checkbox" name="' . $name . '" value="1"' . checked( '1', self::scalar( $value ), false ) . ' />';
		}

		if ( FieldType::Select === $field->type ) {
			$current = self::scalar( $value );
			$opts    = '<option value="">' . esc_html__( '— brak —', 'event-registration' ) . '</option>';
			foreach ( $field->options as $option ) {
				$opts .= '<option value="' . esc_attr( $option->value ) . '"' . selected( $current, $option->value, false ) . '>' . esc_html( $option->label ) . '</option>';
			}
			return '<select name="' . $name . '">' . $opts . '</select>';
		}

		$is_group   = FieldType::CheckboxGroup === $field->type;
		$type       = $is_group ? 'checkbox' : 'radio';
		$field_name = $is_group ? $name . '[]' : $name;
		$selected   = is_array( $value ) ? array_map( 'strval', $value ) : array( (string) $value );

		$out = '<div class="evreg-admin-choices">';
		foreach ( $field->options as $option ) {
			$is_checked = in_array( $option->value, $selected, true ) ? ' checked' : '';
			$out       .= '<label style="display:block;"><input type="' . esc_attr( $type ) . '" name="' . $field_name . '" value="' . esc_attr( $option->value ) . '"' . $is_checked . ' /> ' . esc_html( $option->label ) . '</label>';
		}
		$out .= '</div>';

		return $out;
	}

	/**
	 * Renderuje kontrolkę wyboru noclegu (pakiet × pokój + współlokator).
	 *
	 * @param Field $field Pole typu accommodation.
	 * @param mixed $value Wartość do odtworzenia (kształt {package,room,roommate}).
	 */
	private static function renderAccommodation( Field $field, $value ): string {
		$config    = $field->config;
		$packages  = is_array( $config['packages'] ?? null ) ? $config['packages'] : array();
		$rooms     = is_array( $config['rooms'] ?? null ) ? $config['rooms'] : array();
		$inventory = is_array( $config['inventory'] ?? null ) ? $config['inventory'] : array();

		$available = array();
		foreach ( $inventory as $item ) {
			$available[ (string) ( $item['package'] ?? '' ) . '|' . (string) ( $item['room'] ?? '' ) ] = $item;
		}

		$current = is_array( $value ) ? ( (string) ( $value['package'] ?? '' ) . '|' . (string) ( $value['room'] ?? '' ) ) : '';

		// Namespace pod evreg_field[...] — spójnie z FormRenderer i wspólną ekstrakcją
		// SubmissionAssembler (klucze pól nie kolidują z query-vars WordPressa).
		// Pokoje z włączonym polem współlokatora (flaga per pokój) — tylko dla nich
		// renderujemy i pokazujemy pole „Współlokator".
		$roommateRooms = array();
		foreach ( $rooms as $room ) {
			if ( ! empty( $room['roommate_field'] ) ) {
				$roommateRooms[ (string) ( $room['key'] ?? '' ) ] = true;
			}
		}

		$name = 'evreg_field[' . esc_attr( $field->key ) . ']';
		$opts = '<option value=""' . selected( '', $current, false ) . '>' . esc_html__( 'Bez noclegu', 'event-registration' ) . '</option>';

		foreach ( $packages as $pkg ) {
			foreach ( $rooms as $room ) {
				$slot = (string) ( $pkg['key'] ?? '' ) . '|' . (string) ( $room['key'] ?? '' );
				if ( ! isset( $available[ $slot ] ) ) {
					continue;
				}
				$label    = (string) ( $pkg['label'] ?? '' ) . ' — ' . (string) ( $room['label'] ?? '' );
				$roommate = isset( $roommateRooms[ (string) ( $room['key'] ?? '' ) ] ) ? ' data-evreg-roommate="1"' : '';
				$opts    .= '<option value="' . esc_attr( $slot ) . '"' . selected( $slot, $current, false ) . $roommate . '>' . esc_html( $label ) . '</option>';
			}
		}

		// Wrapper .evreg-accommodation pozwala reużyć form.js (evreg-public) do
		// przełączania widoczności pola współlokatora przy zmianie pokoju.
		$out = '<div class="evreg-accommodation"><select name="' . $name . '[slot]">' . $opts . '</select> ';

		if ( array() !== $roommateRooms ) {
			$separator   = strpos( $current, '|' );
			$currentRoom = ( '' !== $current && false !== $separator ) ? substr( $current, $separator + 1 ) : '';
			$hidden      = isset( $roommateRooms[ $currentRoom ] ) ? '' : ' hidden';
			$roommateVal = is_array( $value ) ? (string) ( $value['roommate'] ?? '' ) : '';
			$out        .= '<input type="text" data-evreg-roommate-input name="' . $name . '[roommate]" placeholder="' . esc_attr__( 'Współlokator', 'event-registration' ) . '" value="' . esc_attr( $roommateVal ) . '" class="regular-text"' . $hidden . ' />';
		}

		$out .= '</div>';

		return $out;
	}

	/**
	 * Renderuje wiersz checkbox + pole imienia „Osoba towarzysząca", gdy event ma włączoną
	 * tę opcję w konfiguracji noclegów (mirror {@see \EvReg\Frontend\FormRenderer::renderCompanion()}).
	 * Kontrolki evreg_companion/evreg_companion_name NIE są polami schematu — bez namespace
	 * evreg_field[...], zgodnie z publicznym formularzem (SubmissionAssembler czyta je wprost
	 * z $_POST). Klasa .evreg-form na <form> (patrz render()) daje reuse publicznego
	 * assets/public/form.js (applyCompanion) do przełączania widoczności pola imienia.
	 *
	 * @param Field                     $field  Pole typu accommodation (niesie config noclegów).
	 * @param array<string,mixed>       $source Źródło prefill (submitted, w innym wypadku answers).
	 * @param array<string,string>|null $errors Błędy walidacji: klucz pola => kod błędu.
	 */
	private static function renderCompanionRow( Field $field, array $source, ?array $errors ): string {
		$config = AccommodationConfig::fromArray( $field->config );
		if ( ! $config->companionEnabled() ) {
			return '';
		}

		$companion_on   = ! empty( $source['evreg_companion'] );
		$companion_name = self::scalar( $source['evreg_companion_name'] ?? '' );
		$error          = null !== $errors ? ( $errors['evreg_companion'] ?? null ) : null;

		$checkbox = '<input type="checkbox" id="evreg_companion" name="evreg_companion" value="1"'
			. checked( $companion_on, true, false ) . ' data-evreg-companion />';

		$hidden     = $companion_on ? '' : ' hidden';
		$name_class = 'regular-text' . ( null !== $error ? ' evreg-field-error' : '' );
		$name_input = '<br /><input type="text" class="' . $name_class . '" data-evreg-companion-input name="evreg_companion_name" placeholder="'
			. esc_attr__( 'Imię i nazwisko osoby towarzyszącej', 'event-registration' ) . '" value="' . esc_attr( $companion_name ) . '"' . $hidden . ' />';

		$error_html = null !== $error ? '<p class="description">' . esc_html( self::errorMessage( (string) $error ) ) . '</p>' : '';

		$label_cell = '<th scope="row"><label for="evreg_companion">' . esc_html__( 'Osoba towarzysząca', 'event-registration' ) . '</label></th>';
		$value_cell = '<td>' . $checkbox . $name_input . $error_html . '</td>';

		return '<tr>' . $label_cell . $value_cell . '</tr>';
	}

	/**
	 * Rzutuje wartość na string skalarny (tablice łączy przecinkiem).
	 *
	 * @param mixed $value Wartość do zrzutowania.
	 */
	private static function scalar( $value ): string {
		if ( is_array( $value ) ) {
			return implode( ', ', array_map( 'strval', $value ) );
		}
		return (string) $value;
	}
}
