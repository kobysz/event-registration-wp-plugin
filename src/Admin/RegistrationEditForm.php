<?php
/**
 * Renderuje formularz edycji odpowiedzi zgłoszenia w adminie.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Admin;

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

		$out  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
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
			'required'              => __( 'To pole jest wymagane.', 'event-registration' ),
			'invalid_email'         => __( 'Nieprawidłowy adres e-mail.', 'event-registration' ),
			'invalid_tel'           => __( 'Nieprawidłowy numer telefonu.', 'event-registration' ),
			'invalid_number'        => __( 'Nieprawidłowa liczba.', 'event-registration' ),
			'invalid_date'          => __( 'Nieprawidłowa data.', 'event-registration' ),
			'not_in_options'        => __( 'Wybór spoza dostępnych opcji.', 'event-registration' ),
			'too_long'              => __( 'Wpis jest za długi.', 'event-registration' ),
			'invalid_accommodation' => __( 'Nieprawidłowy wybór noclegu.', 'event-registration' ),
			'roommate_not_allowed'  => __( 'Współlokator niedozwolony dla tego pokoju.', 'event-registration' ),
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
		$control = '<td>' . self::renderControl( $field, $value ) . '</td>';
		return '<tr>' . $label . $control . '</tr>';
	}

	/**
	 * Renderuje kontrolkę wejściową pola odpowiednią do jego typu.
	 *
	 * @param Field $field Pole formularza.
	 * @param mixed $value Wartość do odtworzenia w kontrolce.
	 */
	private static function renderControl( Field $field, $value ): string {
		$name = esc_attr( $field->key );
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
		$name = esc_attr( $field->key );

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

		$name = esc_attr( $field->key );
		$opts = '<option value=""' . selected( '', $current, false ) . '>' . esc_html__( 'Bez noclegu', 'event-registration' ) . '</option>';

		foreach ( $packages as $pkg ) {
			foreach ( $rooms as $room ) {
				$slot = (string) ( $pkg['key'] ?? '' ) . '|' . (string) ( $room['key'] ?? '' );
				if ( ! isset( $available[ $slot ] ) ) {
					continue;
				}
				$label = (string) ( $pkg['label'] ?? '' ) . ' — ' . (string) ( $room['label'] ?? '' );
				$opts .= '<option value="' . esc_attr( $slot ) . '"' . selected( $slot, $current, false ) . '>' . esc_html( $label ) . '</option>';
			}
		}

		$roommate = is_array( $value ) ? (string) ( $value['roommate'] ?? '' ) : '';

		$out  = '<select name="' . $name . '[slot]">' . $opts . '</select> ';
		$out .= '<input type="text" name="' . $name . '[roommate]" placeholder="' . esc_attr__( 'Współlokator', 'event-registration' ) . '" value="' . esc_attr( $roommate ) . '" class="regular-text" />';

		return $out;
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
