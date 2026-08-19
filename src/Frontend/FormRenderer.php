<?php
/**
 * Renderuje FormSchema do publicznego HTML formularza.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Frontend;

use EvReg\Domain\Schema\Field;
use EvReg\Domain\Schema\FieldType;
use EvReg\Domain\Schema\FormSchema;
use EvReg\Domain\Schema\Option;
use EvReg\Domain\Schema\Section;

defined( 'ABSPATH' ) || exit;

/**
 * Renderuje FormSchema do publicznego HTML formularza. Serwerowo, bez JS.
 */
final class FormRenderer {

	/**
	 * Renderuje kompletny formularz. $result niesie błędy i wpisane wartości do re-renderu.
	 *
	 * @param FormSchema        $schema   Schemat formularza do wyrenderowania.
	 * @param int               $event_id ID posta eventu.
	 * @param SubmitResult|null $result   Wynik poprzedniej próby submisji (do re-renderu błędów).
	 */
	public function render( FormSchema $schema, int $event_id, ?SubmitResult $result = null ): string {
		$out  = '<form class="evreg-form" method="post">';
		$out .= wp_nonce_field( 'evreg_submit_' . $event_id, 'evreg-nonce', true, false );
		$out .= '<input type="hidden" name="evreg_event" value="' . esc_attr( (string) $event_id ) . '">';
		$out .= '<input type="hidden" name="evreg_submit" value="1">';
		$out .= '<input type="hidden" name="evreg_ts" value="' . esc_attr( (string) time() ) . '">';
		$out .= '<div class="evreg-hp" aria-hidden="true" style="position:absolute;left:-9999px;">'
			. '<label>' . esc_html__( 'Zostaw puste', 'event-registration' ) . ' <input type="text" name="evreg_hp" value="" tabindex="-1" autocomplete="off"></label></div>';

		foreach ( $schema->sections() as $section ) {
			$out .= $this->renderSection( $section, $result );
		}

		$out .= '<button type="submit" class="evreg-submit">' . esc_html__( 'Wyślij zgłoszenie', 'event-registration' ) . '</button>';
		$out .= '</form>';

		return $out;
	}

	/**
	 * Renderuje pojedynczą sekcję wraz z jej polami.
	 *
	 * @param Section           $section Sekcja formularza.
	 * @param SubmitResult|null $result  Wynik poprzedniej próby submisji.
	 */
	private function renderSection( Section $section, ?SubmitResult $result ): string {
		$attrs = '';
		if ( null !== $section->condition ) {
			$condition_value = wp_json_encode( $section->condition->value );
			$attrs           = ' data-evreg-when-field="' . esc_attr( $section->condition->field ) . '"'
				. ' data-evreg-when-operator="' . esc_attr( $section->condition->operator->value ) . '"'
				. ' data-evreg-when-value="' . esc_attr( false !== $condition_value ? $condition_value : '' ) . '"';
		}

		$out  = '<fieldset class="evreg-section"' . $attrs . '>';
		$out .= '<legend>' . esc_html( $section->title ) . '</legend>';

		if ( '' !== $section->description ) {
			$out .= '<p class="evreg-section-desc">' . esc_html( $section->description ) . '</p>';
		}

		foreach ( $section->fields as $field ) {
			$out .= $this->renderField( $field, $result );
		}

		$out .= '</fieldset>';

		return $out;
	}

	/**
	 * Renderuje pojedyncze pole wraz z etykietą i ewentualnym komunikatem błędu.
	 *
	 * @param Field             $field  Pole formularza.
	 * @param SubmitResult|null $result Wynik poprzedniej próby submisji.
	 */
	private function renderField( Field $field, ?SubmitResult $result ): string {
		if ( FieldType::Heading === $field->type ) {
			return '<h3 class="evreg-heading">' . esc_html( $field->label ) . '</h3>';
		}
		if ( FieldType::Paragraph === $field->type ) {
			return '<p class="evreg-paragraph">' . esc_html( $field->label ) . '</p>';
		}

		$value = null === $result ? null : $result->submittedValue( $field->key );
		$error = null === $result ? null : ( $result->errors()[ $field->key ] ?? null );

		$class = 'evreg-field evreg-field-' . esc_attr( $field->type->value );
		if ( null !== $error ) {
			$class .= ' evreg-field-error';
		}

		$out  = '<div class="' . $class . '">';
		$out .= '<label class="evreg-label" for="evreg-' . esc_attr( $field->key ) . '">' . esc_html( $field->label );
		if ( $field->required ) {
			$out .= ' <span class="evreg-required">*</span>';
		}
		$out .= '</label>';
		$out .= $this->renderControl( $field, $value );

		if ( null !== $error ) {
			$out .= '<span class="evreg-error-msg">' . esc_html( $this->errorMessage( (string) $error ) ) . '</span>';
		}

		$out .= '</div>';

		return $out;
	}

	/**
	 * Renderuje kontrolkę wejściową pola odpowiednią do jego typu.
	 *
	 * @param Field $field Pole formularza.
	 * @param mixed $value Wpisana wartość do odtworzenia (lub null).
	 */
	private function renderControl( Field $field, mixed $value ): string {
		$name = esc_attr( $field->key );
		$id   = 'evreg-' . esc_attr( $field->key );
		$req  = $field->required ? ' required' : '';

		switch ( $field->type ) {
			case FieldType::Textarea:
				return '<textarea id="' . $id . '" name="' . $name . '"' . $req . '>' . esc_textarea( $this->scalarValue( $value ) ) . '</textarea>';

			case FieldType::Select:
				$opts = '';
				foreach ( $field->options as $option ) {
					$opts .= '<option value="' . esc_attr( $option->value ) . '"' . selected( $this->scalarValue( $value ), $option->value, false ) . '>' . esc_html( $option->label ) . '</option>';
				}
				return '<select id="' . $id . '" name="' . $name . '"' . $req . '><option value="">—</option>' . $opts . '</select>';

			case FieldType::Radio:
				return $this->renderChoices( $field->options, $name, 'radio', $value );

			case FieldType::CheckboxGroup:
				return $this->renderChoices( $field->options, $name . '[]', 'checkbox', $value );

			case FieldType::Checkbox:
				return '<input type="checkbox" id="' . $id . '" name="' . $name . '" value="1"' . checked( '1', $this->scalarValue( $value ), false ) . $req . '>';

			case FieldType::Accommodation:
				return $this->renderAccommodation( $field, $value );

			case FieldType::Number:
				return '<input type="number" id="' . $id . '" name="' . $name . '" value="' . esc_attr( $this->scalarValue( $value ) ) . '"' . $req . '>';

			case FieldType::Date:
				return '<input type="date" id="' . $id . '" name="' . $name . '" value="' . esc_attr( $this->scalarValue( $value ) ) . '"' . $req . '>';

			case FieldType::Email:
				return '<input type="email" id="' . $id . '" name="' . $name . '" value="' . esc_attr( $this->scalarValue( $value ) ) . '"' . $req . '>';

			case FieldType::Tel:
				return '<input type="tel" id="' . $id . '" name="' . $name . '" value="' . esc_attr( $this->scalarValue( $value ) ) . '"' . $req . '>';

			case FieldType::Hidden:
				return '<input type="hidden" name="' . $name . '" value="' . esc_attr( $this->scalarValue( $value ) ) . '">';

			default: // Text i pozostałe.
				return '<input type="text" id="' . $id . '" name="' . $name . '" value="' . esc_attr( $this->scalarValue( $value ) ) . '"' . $req . '>';
		}
	}

	/**
	 * Rzutuje wartość skalarnego pola na string; nie-skalar (np. tablica z ataku `field[]=x`) daje pusty string.
	 *
	 * @param mixed $value Wpisana wartość do odtworzenia.
	 */
	private function scalarValue( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Renderuje listę opcji wyboru (radio/checkbox).
	 *
	 * @param Option[] $options Lista dostępnych opcji.
	 * @param string   $name    Nazwa atrybutu name kontrolki.
	 * @param string   $type    Typ HTML kontrolki ("radio" lub "checkbox").
	 * @param mixed    $value   Wpisana wartość (lub wartości) do odtworzenia.
	 */
	private function renderChoices( array $options, string $name, string $type, mixed $value ): string {
		$selected = is_array( $value ) ? array_map( 'strval', $value ) : array( (string) $value );
		$out      = '<div class="evreg-choices">';
		foreach ( $options as $option ) {
			$checked = in_array( $option->value, $selected, true ) ? ' checked' : '';
			$out    .= '<label class="evreg-choice"><input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $option->value ) . '"' . $checked . '> ' . esc_html( $option->label ) . '</label>';
		}
		$out .= '</div>';

		return $out;
	}

	/**
	 * Renderuje kontrolkę wyboru noclegu (pakiet × pokój + współlokator).
	 *
	 * @param Field $field Pole typu accommodation.
	 * @param mixed $value Wpisana wartość do odtworzenia (lub null).
	 */
	private function renderAccommodation( Field $field, mixed $value ): string {
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
		$out  = '<div class="evreg-accommodation">';
		$out .= '<label class="evreg-choice"><input type="radio" name="' . $name . '[slot]" value=""' . checked( '', $current, false ) . '> ' . esc_html__( 'Bez noclegu', 'event-registration' ) . '</label>';

		foreach ( $packages as $pkg ) {
			foreach ( $rooms as $room ) {
				$slot = (string) ( $pkg['key'] ?? '' ) . '|' . (string) ( $room['key'] ?? '' );
				if ( ! isset( $available[ $slot ] ) ) {
					continue;
				}
				$label = (string) ( $pkg['label'] ?? '' ) . ' — ' . (string) ( $room['label'] ?? '' );
				$out  .= '<label class="evreg-choice"><input type="radio" name="' . $name . '[slot]" value="' . esc_attr( $slot ) . '"' . checked( $slot, $current, false ) . '> ' . esc_html( $label ) . '</label>';
			}
		}

		$roommate = is_array( $value ) ? (string) ( $value['roommate'] ?? '' ) : '';
		$out     .= '<input type="text" name="' . $name . '[roommate]" placeholder="' . esc_attr__( 'Preferowana osoba w pokoju', 'event-registration' ) . '" value="' . esc_attr( $roommate ) . '">';
		$out     .= '</div>';

		return $out;
	}

	/**
	 * Tłumaczy kod błędu walidacji na komunikat czytelny dla użytkownika.
	 *
	 * @param string $code Kod błędu.
	 */
	private function errorMessage( string $code ): string {
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
}
