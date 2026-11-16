<?php
/**
 * Renderuje FormSchema do publicznego HTML formularza.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Frontend;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Accommodation\SlotAvailability;
use EvReg\Domain\Conditions\Condition;
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
	 * Tworzy renderer. Bez dostępności (null) wszystkie sloty noclegowe renderują się aktywne.
	 *
	 * @param SlotAvailability|null $availability Dostępność miejsc w slotach albo null.
	 */
	public function __construct( private readonly ?SlotAvailability $availability = null ) {
	}

	/**
	 * Ile miejsc zajmie to zgłoszenie w slocie noclegowym: 2 przy osobie towarzyszącej.
	 *
	 * @param SubmitResult|null $result Wynik poprzedniej próby submisji.
	 */
	private function seatsNeeded( ?SubmitResult $result ): int {
		return null !== $result && ! empty( $result->submittedValue( 'evreg_companion' ) ) ? 2 : 1;
	}

	/**
	 * Renderuje kompletny formularz. $result niesie błędy i wpisane wartości do re-renderu.
	 *
	 * @param FormSchema        $schema   Schemat formularza do wyrenderowania.
	 * @param int               $event_id ID posta eventu.
	 * @param SubmitResult|null $result   Wynik poprzedniej próby submisji (do re-renderu błędów).
	 */
	public function render( FormSchema $schema, int $event_id, ?SubmitResult $result = null ): string {
		// Jawny action = kanoniczny permalink bieżącej strony. Bez niego formularz
		// POST-uje na dokładny URL dokumentu; przy strukturze %postname%/ URL bez
		// końcowego slasha nie jest canonical-redirectowany na POST → WordPress
		// zwraca 404, a handler submisji nie przetwarza żądania. Kierowanie na
		// kanoniczny permalink usuwa ten rozjazd (fallback: bieżący URL, gdy brak).
		$action = esc_url( (string) get_permalink() );
		$out    = '' !== $action
			? '<form class="evreg-form" method="post" action="' . $action . '">'
			: '<form class="evreg-form" method="post">';
		$out   .= wp_nonce_field( 'evreg_submit_' . $event_id, 'evreg-nonce', true, false );
		$out   .= '<input type="hidden" name="evreg_event" value="' . esc_attr( (string) $event_id ) . '">';
		$out   .= '<input type="hidden" name="evreg_submit" value="1">';
		$out   .= '<input type="hidden" name="evreg_ts" value="' . esc_attr( (string) time() ) . '">';
		$out   .= '<div class="evreg-hp" aria-hidden="true" style="position:absolute;left:-9999px;">'
			. '<label>' . esc_html__( 'Zostaw puste', 'event-registration' ) . ' <input type="text" name="evreg_hp" value="" tabindex="-1" autocomplete="off"></label></div>';

		foreach ( $schema->sections() as $section ) {
			$out .= $this->renderSection( $section, $result );
		}

		$out .= '<button type="submit" class="evreg-submit btn btn-primary">' . esc_html__( 'Wyślij zgłoszenie', 'event-registration' ) . '</button>';
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
		$out  = '<fieldset class="evreg-section mb-4"' . $this->conditionAttrs( $section->condition ) . '>';
		$out .= '<legend class="fs-5">' . esc_html( $section->title ) . '</legend>';

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
	 * Serializuje warunek widoczności do atrybutów data-evreg-when-*.
	 * Wspólne dla sekcji i pól. Operatory bez wartości (empty/not_empty)
	 * pomijają data-evreg-when-value.
	 *
	 * @param Condition|null $condition Warunek albo null.
	 */
	private function conditionAttrs( ?Condition $condition ): string {
		if ( null === $condition ) {
			return '';
		}

		$attrs = ' data-evreg-when-field="' . esc_attr( $condition->field ) . '"'
			. ' data-evreg-when-operator="' . esc_attr( $condition->operator->value ) . '"';

		if ( $condition->operator->needsValue() ) {
			$value  = wp_json_encode( $condition->value );
			$attrs .= ' data-evreg-when-value="' . esc_attr( false !== $value ? $value : '' ) . '"';
		}

		return $attrs;
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

		$invalid = null !== $error;
		$seats   = $this->seatsNeeded( $result );
		$class   = 'evreg-field evreg-field-' . esc_attr( $field->type->value ) . ' mb-3';
		if ( $invalid ) {
			$class .= ' evreg-field-error';
		}

		if ( FieldType::Checkbox === $field->type ) {
			// Pojedynczy checkbox zgody: BS5 form-check — input (form-check-input)
			// przed etykietą (form-check-label), obok siebie, nie blok nad polem.
			$out  = '<div class="' . $class . ' form-check"' . $this->conditionAttrs( $field->condition ) . '>';
			$out .= $this->renderControl( $field, $value, $invalid, $seats );
			$out .= ' <label class="form-check-label" for="evreg-' . esc_attr( $field->key ) . '">' . esc_html( $field->label );
			if ( $field->required ) {
				$out .= ' <span class="evreg-required">*</span>';
			}
			$out .= '</label>';
		} else {
			$out  = '<div class="' . $class . '"' . $this->conditionAttrs( $field->condition ) . '>';
			$out .= '<label class="evreg-label form-label" for="evreg-' . esc_attr( $field->key ) . '">' . esc_html( $field->label );
			if ( $field->required ) {
				$out .= ' <span class="evreg-required">*</span>';
			}
			$out .= '</label>';
			$out .= $this->renderControl( $field, $value, $invalid, $seats );
		}

		if ( '' !== $field->description ) {
			$out .= '<p class="evreg-field-desc form-text">' . wp_kses_post( $field->description ) . '</p>';
		}

		if ( $invalid ) {
			$out .= '<div class="evreg-error-msg invalid-feedback d-block">' . esc_html( $this->errorMessage( (string) $error ) ) . '</div>';
		}

		$out .= '</div>';

		if ( FieldType::Accommodation === $field->type ) {
			$out .= $this->renderCompanion( $field, $result );
		}

		return $out;
	}

	/**
	 * Renderuje checkbox „Osoba towarzysząca" + pole jej imienia i nazwiska, gdy event ma
	 * włączoną tę opcję w konfiguracji noclegów. Kontrolki evreg_companion/evreg_companion_name
	 * NIE są polami schematu — nie idą pod evreg_field[...] namespace (nie kolidują z niczym
	 * wśród publicznych query-vars WordPressa i nie muszą, bo nie są tam używane).
	 *
	 * @param Field             $field  Pole typu accommodation (niesie config noclegów).
	 * @param SubmitResult|null $result Wynik poprzedniej próby submisji (do re-renderu).
	 */
	private function renderCompanion( Field $field, ?SubmitResult $result ): string {
		$config = AccommodationConfig::fromArray( $field->config );
		if ( ! $config->companionEnabled() ) {
			return '';
		}

		$companion_on   = null !== $result && ! empty( $result->submittedValue( 'evreg_companion' ) );
		$companion_name = null === $result ? '' : $this->scalarValue( $result->submittedValue( 'evreg_companion_name' ) );
		$error          = null === $result ? null : ( $result->errors()['evreg_companion'] ?? null );

		$out = '<div class="evreg-companion form-check mt-2">'
			. '<input class="form-check-input" type="checkbox" id="evreg_companion" name="evreg_companion" value="1"' . checked( $companion_on, true, false ) . ' data-evreg-companion>'
			. ' <label class="form-check-label" for="evreg_companion">' . esc_html__( 'Osoba towarzysząca', 'event-registration' ) . '</label>'
			. '</div>';

		$hidden     = $companion_on ? '' : ' hidden';
		$name_class = 'form-control mt-2' . ( null !== $error ? ' is-invalid' : '' );
		$out       .= '<input class="' . $name_class . '" type="text" data-evreg-companion-input name="evreg_companion_name" placeholder="' . esc_attr__( 'Imię i nazwisko osoby towarzyszącej', 'event-registration' ) . '" value="' . esc_attr( $companion_name ) . '"' . $hidden . '>';

		if ( null !== $error ) {
			$out .= '<div class="evreg-error-msg invalid-feedback d-block">' . esc_html( $this->errorMessage( (string) $error ) ) . '</div>';
		}

		return $out;
	}

	/**
	 * Renderuje kontrolkę wejściową pola odpowiednią do jego typu.
	 *
	 * @param Field $field   Pole formularza.
	 * @param mixed $value   Wpisana wartość do odtworzenia (lub null).
	 * @param bool  $invalid Czy pole ma błąd walidacji (dodaje is-invalid).
	 * @param int   $seats   Ile miejsc zajmie zgłoszenie w slocie noclegu (2 z osobą towarzyszącą).
	 */
	private function renderControl( Field $field, mixed $value, bool $invalid = false, int $seats = 1 ): string {
		// Namespace pól pod evreg_field[...], by nazwy pól NIE kolidowały z publicznymi
		// query-vars WordPressa (name/page/p/s/cat/author/...). WP czyta te vary także
		// z $_POST przy POST-to-self → surowe name="name" korumpuje główne zapytanie (404).
		$name = 'evreg_field[' . esc_attr( $field->key ) . ']';
		$id   = 'evreg-' . esc_attr( $field->key );
		$req  = $field->required ? ' required' : '';
		// Klasy Bootstrap 5. is-invalid tylko przy błędzie; wpina się w BS5 walidację.
		$inv     = $invalid ? ' is-invalid' : '';
		$control = 'form-control' . $inv;
		$select  = 'form-select' . $inv;
		$check   = 'form-check-input' . $inv;

		switch ( $field->type ) {
			case FieldType::Textarea:
				return '<textarea class="' . $control . '" id="' . $id . '" name="' . $name . '"' . $req . '>' . esc_textarea( $this->scalarValue( $value ) ) . '</textarea>';

			case FieldType::Select:
				$opts = '';
				foreach ( $field->options as $option ) {
					$opts .= '<option value="' . esc_attr( $option->value ) . '"' . selected( $this->scalarValue( $value ), $option->value, false ) . '>' . esc_html( $option->label ) . '</option>';
				}
				$select_html = '<select class="' . $select . '" id="' . $id . '" name="' . $name . '"' . $req . '><option value="">—</option>' . $opts . '</select>';

				// Opisów opcji nie da się umieścić w <option>, więc lecą blokiem pod kontrolką.
				foreach ( $field->options as $option ) {
					$select_html .= $this->optionDescription( $field->key, 'equals', $option );
				}

				return $select_html;

			case FieldType::Radio:
				return $this->renderChoices( $field->options, $name, 'radio', $value, $invalid, $field->key, 'equals' );

			case FieldType::CheckboxGroup:
				return $this->renderChoices( $field->options, $name . '[]', 'checkbox', $value, $invalid, $field->key, 'in' );

			case FieldType::Checkbox:
				return '<input class="' . $check . '" type="checkbox" id="' . $id . '" name="' . $name . '" value="1"' . checked( '1', $this->scalarValue( $value ), false ) . $req . '>';

			case FieldType::Accommodation:
				return $this->renderAccommodation( $field, $value, $seats );

			case FieldType::Number:
				return '<input class="' . $control . '" type="number" id="' . $id . '" name="' . $name . '" value="' . esc_attr( $this->scalarValue( $value ) ) . '"' . $req . '>';

			case FieldType::Date:
				return '<input class="' . $control . '" type="date" id="' . $id . '" name="' . $name . '" value="' . esc_attr( $this->scalarValue( $value ) ) . '"' . $req . '>';

			case FieldType::Email:
				return '<input class="' . $control . '" type="email" id="' . $id . '" name="' . $name . '" value="' . esc_attr( $this->scalarValue( $value ) ) . '"' . $req . '>';

			case FieldType::Tel:
				return '<input class="' . $control . '" type="tel" id="' . $id . '" name="' . $name . '" value="' . esc_attr( $this->scalarValue( $value ) ) . '"' . $req . '>';

			case FieldType::Hidden:
				return '<input type="hidden" name="' . $name . '" value="' . esc_attr( $this->scalarValue( $value ) ) . '">';

			default: // Text i pozostałe.
				return '<input class="' . $control . '" type="text" id="' . $id . '" name="' . $name . '" value="' . esc_attr( $this->scalarValue( $value ) ) . '"' . $req . '>';
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
	 * @param bool     $invalid Czy pole ma błąd walidacji (dodaje is-invalid).
	 * @param string   $field_key Klucz pola — steruje widocznością opisów opcji.
	 * @param string   $operator  Operator warunku opisu: `equals` albo `in`.
	 */
	private function renderChoices( array $options, string $name, string $type, mixed $value, bool $invalid = false, string $field_key = '', string $operator = 'equals' ): string {
		$selected    = is_array( $value ) ? array_map( 'strval', $value ) : array( (string) $value );
		$input_class = 'form-check-input' . ( $invalid ? ' is-invalid' : '' );
		$base        = 'evreg-opt-' . preg_replace( '/[^a-z0-9]+/i', '-', $name );
		$out         = '<div class="evreg-choices">';
		$index       = 0;
		foreach ( $options as $option ) {
			$oid     = $base . '-' . $index++;
			$checked = in_array( $option->value, $selected, true ) ? ' checked' : '';
			$out    .= '<div class="evreg-choice form-check">'
				. '<input class="' . $input_class . '" type="' . esc_attr( $type ) . '" id="' . esc_attr( $oid ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $option->value ) . '"' . $checked . '>'
				. ' <label class="form-check-label" for="' . esc_attr( $oid ) . '">' . esc_html( $option->label ) . '</label>'
				. $this->optionDescription( $field_key, $operator, $option )
				. '</div>';
		}
		$out .= '</div>';

		return $out;
	}

	/**
	 * Renderuje opis opcji widoczny dopiero po jej wybraniu.
	 *
	 * Warunek nie jest konfigurowany przez autora — wynika wprost z opcji, więc
	 * atrybuty `data-evreg-when-*` składamy tu z klucza pola i wartości opcji.
	 * Przełączanie obsługuje już `assets/public/form.js` (ten sam silnik co warunki
	 * pól i sekcji), dlatego element startuje jako `hidden`.
	 *
	 * Treść przechodzi przez wp_kses_post(): autor (admin z capem edycji wydarzeń)
	 * może wstawić link czy pogrubienie, ale skrypty i atrybuty zdarzeń odpadają.
	 *
	 * @param string $field_key Klucz pola, którego wybór steruje widocznością.
	 * @param string $operator  Operator warunku: `equals` (radio/lista) lub `in` (wielokrotny).
	 * @param Option $option    Opcja niosąca opis.
	 */
	private function optionDescription( string $field_key, string $operator, Option $option ): string {
		if ( '' === $option->description || '' === $field_key ) {
			return '';
		}

		$encoded = wp_json_encode( $option->value );

		return '<p class="evreg-option-desc form-text"'
			. ' data-evreg-when-field="' . esc_attr( $field_key ) . '"'
			. ' data-evreg-when-operator="' . esc_attr( $operator ) . '"'
			. ' data-evreg-when-value="' . esc_attr( false !== $encoded ? $encoded : '' ) . '"'
			. ' hidden>' . wp_kses_post( $option->description ) . '</p>';
	}

	/**
	 * Renderuje kontrolkę wyboru noclegu (pakiet × pokój + współlokator).
	 *
	 * @param Field $field Pole typu accommodation.
	 * @param mixed $value Wpisana wartość do odtworzenia (lub null).
	 * @param int   $seats Ile miejsc zajmie zgłoszenie w slocie (2 z osobą towarzyszącą).
	 */
	private function renderAccommodation( Field $field, mixed $value, int $seats = 1 ): string {
		$config    = $field->config;
		$packages  = is_array( $config['packages'] ?? null ) ? $config['packages'] : array();
		$rooms     = is_array( $config['rooms'] ?? null ) ? $config['rooms'] : array();
		$inventory = is_array( $config['inventory'] ?? null ) ? $config['inventory'] : array();
		$available = array();
		foreach ( $inventory as $item ) {
			$available[ (string) ( $item['package'] ?? '' ) . '|' . (string) ( $item['room'] ?? '' ) ] = $item;
		}

		$current = is_array( $value ) ? ( (string) ( $value['package'] ?? '' ) . '|' . (string) ( $value['room'] ?? '' ) ) : '';

		// Pokoje z włączonym polem współlokatora — tylko dla nich renderujemy
		// (i pokazujemy) pole „Preferowana osoba w pokoju". Flaga jest per pokój.
		$roommateRooms = array();
		foreach ( $rooms as $room ) {
			if ( ! empty( $room['roommate_field'] ) ) {
				$roommateRooms[ (string) ( $room['key'] ?? '' ) ] = true;
			}
		}

		// Najpierw zbieramy wiersze z dostępnością, dopiero potem renderujemy: stan
		// „wybrany slot jest niedostępny" musi być znany, zanim wypiszemy pierwsze radio.
		$rows = array();
		foreach ( $packages as $pkg ) {
			foreach ( $rooms as $room ) {
				$slot = (string) ( $pkg['key'] ?? '' ) . '|' . (string) ( $room['key'] ?? '' );
				if ( ! isset( $available[ $slot ] ) ) {
					continue;
				}
				$rows[] = array(
					'slot'     => $slot,
					'label'    => (string) ( $pkg['label'] ?? '' ) . ' — ' . (string) ( $room['label'] ?? '' ),
					'roommate' => isset( $roommateRooms[ (string) ( $room['key'] ?? '' ) ] ),
					'left'     => null === $this->availability ? null : $this->availability->left( $slot ),
					'closed'   => null !== $this->availability && $this->availability->isFull( $slot, $seats ),
				);
			}
		}

		// Namespace pól pod evreg_field[...], by nazwy pól NIE kolidowały z publicznymi
		// query-vars WordPressa (name/page/p/s/cat/author/...). WP czyta te vary także
		// z $_POST przy POST-to-self → surowe name="name" korumpuje główne zapytanie (404).
		$name  = 'evreg_field[' . esc_attr( $field->key ) . ']';
		$base  = 'evreg-' . esc_attr( $field->key );
		$index = 0;
		$out   = '<div class="evreg-accommodation">';

		$none_id = $base . '-slot-' . $index++;
		$out    .= '<div class="evreg-choice form-check">'
			. '<input class="form-check-input" type="radio" id="' . $none_id . '" name="' . $name . '[slot]" value=""' . checked( '', $current, false ) . '>'
			. ' <label class="form-check-label" for="' . $none_id . '">' . esc_html__( 'Bez noclegu', 'event-registration' ) . '</label>'
			. '</div>';

		foreach ( $rows as $row ) {
			$slot_id = $base . '-slot-' . $index++;
			$note    = '';
			if ( $row['closed'] ) {
				$note = ' ' . ( null !== $row['left'] && $row['left'] < 1
					? esc_html__( '(brak miejsc)', 'event-registration' )
					: esc_html__( '(za mało miejsc)', 'event-registration' ) );
			}
			$seats_attr = null === $row['left'] ? '' : ' data-evreg-seats-left="' . esc_attr( (string) $row['left'] ) . '"';
			// Niedostępny slot NIGDY nie renderuje się jako wybrany: `disabled` nie wysyła
			// wartości, więc zaznaczony-i-wyłączony radio dałby po submicie pusty [slot]
			// = „Bez noclegu" bez wiedzy zgłaszającego się (cichy downgrade).
			$checked  = ! $row['closed'] && $row['slot'] === $current;
			$roommate = $row['roommate'] ? ' data-evreg-roommate="1"' : '';
			$out     .= '<div class="evreg-choice form-check' . ( $row['closed'] ? ' is-full' : '' ) . '">'
				. '<input class="form-check-input" type="radio" id="' . $slot_id . '" name="' . $name . '[slot]" value="' . esc_attr( $row['slot'] ) . '"' . checked( $checked, true, false ) . ( $row['closed'] ? ' disabled' : '' ) . $seats_attr . $roommate . '>'
				. ' <label class="form-check-label" for="' . $slot_id . '">' . esc_html( $row['label'] ) . $note . '</label>'
				. '</div>';
		}

		// Pole współlokatora renderujemy tylko gdy JAKIKOLWIEK pokój je włącza.
		// Widoczność startową wyznacza wybrany pokój (poprawne bez JS przy
		// re-renderze błędu); form.js przełącza ją reaktywnie przy zmianie pokoju.
		if ( array() !== $roommateRooms ) {
			$separator   = strpos( $current, '|' );
			$currentRoom = ( '' !== $current && false !== $separator ) ? substr( $current, $separator + 1 ) : '';
			$hidden      = isset( $roommateRooms[ $currentRoom ] ) ? '' : ' hidden';
			$roommateVal = is_array( $value ) ? (string) ( $value['roommate'] ?? '' ) : '';
			$out        .= '<input class="form-control mt-2" type="text" data-evreg-roommate-input name="' . $name . '[roommate]" placeholder="' . esc_attr__( 'Preferowana osoba w pokoju', 'event-registration' ) . '" value="' . esc_attr( $roommateVal ) . '"' . $hidden . '>';
		}

		$out .= '</div>';

		return $out;
	}

	/**
	 * Tłumaczy kod błędu walidacji na komunikat czytelny dla użytkownika.
	 *
	 * @param string $code Kod błędu.
	 */
	private function errorMessage( string $code ): string {
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
}
