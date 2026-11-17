<?php
/**
 * Renderuje FormSchema do publicznego HTML formularza.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Frontend;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Accommodation\NightsConfig;
use EvReg\Domain\Accommodation\RoomOccupancy;
use EvReg\Domain\Accommodation\SlotAvailability;
use EvReg\Domain\Capacity\NightOccupancy;
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
	 * @param SlotAvailability|null $availability   Dostępność miejsc w slotach albo null.
	 * @param NightOccupancy|null   $nightOccupancy Zajętość dób (tryb „doby”); null = wszystkie doby wolne.
	 * @param NightsConfig|null     $nights         Konfiguracja trybu „doby” rozstrzygnięta u wejścia
	 *                                              (NightsResolver); null = tryb klasyczny. Nigdy „niepoprawna” —
	 *                                              ta zatrzymuje się u wejścia, renderer sam fromArray nie woła.
	 */
	public function __construct(
		private readonly ?SlotAvailability $availability = null,
		private readonly ?NightOccupancy $nightOccupancy = null,
		private readonly ?NightsConfig $nights = null
	) {
	}

	/**
	 * Czy odtwarzana submisja miała zaznaczoną osobę towarzyszącą.
	 *
	 * @param SubmitResult|null $result Wynik poprzedniej próby submisji.
	 */
	private function companionSubmitted( ?SubmitResult $result ): bool {
		return null !== $result && ! empty( $result->submittedValue( 'evreg_companion' ) );
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

		$invalid   = null !== $error;
		$companion = $this->companionSubmitted( $result );
		$class     = 'evreg-field evreg-field-' . esc_attr( $field->type->value ) . ' mb-3';
		if ( $invalid ) {
			$class .= ' evreg-field-error';
		}

		// Checkbox towarzyszącej idzie NAD listę noclegów: steruje tym, które pokoje
		// są w ogóle pokazane, więc musi być widoczny przed wyborem.
		$before = FieldType::Accommodation === $field->type ? $this->renderCompanion( $field, $result ) : '';

		if ( FieldType::Checkbox === $field->type ) {
			// Pojedynczy checkbox zgody: BS5 form-check — input (form-check-input)
			// przed etykietą (form-check-label), obok siebie, nie blok nad polem.
			$out  = $before . '<div class="' . $class . ' form-check"' . $this->conditionAttrs( $field->condition ) . '>';
			$out .= $this->renderControl( $field, $value, $invalid, $companion );
			$out .= ' <label class="form-check-label" for="evreg-' . esc_attr( $field->key ) . '">' . esc_html( $field->label );
			if ( $field->required ) {
				$out .= ' <span class="evreg-required">*</span>';
			}
			$out .= '</label>';
		} else {
			$out  = $before . '<div class="' . $class . '"' . $this->conditionAttrs( $field->condition ) . '>';
			$out .= '<label class="evreg-label form-label" for="evreg-' . esc_attr( $field->key ) . '">' . esc_html( $field->label );
			if ( $field->required ) {
				$out .= ' <span class="evreg-required">*</span>';
			}
			$out .= '</label>';
			$out .= $this->renderControl( $field, $value, $invalid, $companion );
		}

		if ( '' !== $field->description ) {
			$out .= '<p class="evreg-field-desc form-text">' . wp_kses_post( $field->description ) . '</p>';
		}

		if ( $invalid ) {
			$out .= '<div class="evreg-error-msg invalid-feedback d-block">' . esc_html( $this->fieldErrorMessage( $field, (string) $error ) ) . '</div>';
		}

		$out .= '</div>';

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
		// Jedno rozgałęzienie po trybie: „doby” czyta flagę z NightsConfig, bez AccommodationConfig.
		$enabled = null !== $this->nights
			? $this->nights->companionEnabled()
			: AccommodationConfig::fromArray( $field->config )->companionEnabled();
		if ( ! $enabled ) {
			return '';
		}

		$companion_on   = null !== $result && ! empty( $result->submittedValue( 'evreg_companion' ) );
		$companion_name = null === $result ? '' : $this->scalarValue( $result->submittedValue( 'evreg_companion_name' ) );
		$error          = null === $result ? null : ( $result->errors()['evreg_companion'] ?? null );

		$out = '<div class="evreg-companion-block mt-2 mb-3">'
			. '<div class="evreg-companion form-check">'
			. '<input class="form-check-input" type="checkbox" id="evreg_companion" name="evreg_companion" value="1"' . checked( $companion_on, true, false ) . ' data-evreg-companion>'
			. ' <label class="form-check-label" for="evreg_companion">' . esc_html__( 'Osoba towarzysząca', 'event-registration' ) . '</label>'
			. '</div>';

		// `required` TYLKO razem z widocznością (serwer tu, form.js przy przełączeniu
		// checkboxa): przeglądarka nie potrafi sfokusować ukrytej kontrolki, więc
		// ukryte-i-wymagane pole blokuje wysyłkę bez żadnego komunikatu dla człowieka.
		$hidden     = $companion_on ? '' : ' hidden';
		$required   = $companion_on ? ' required' : '';
		$name_class = 'form-control' . ( null !== $error ? ' is-invalid' : '' );
		$out       .= '<label class="evreg-label form-label mt-2" for="evreg_companion_name" data-evreg-companion-label' . $hidden . '>'
			. esc_html__( 'Imię i nazwisko osoby towarzyszącej', 'event-registration' )
			. ' <span class="evreg-required">*</span></label>';
		$out       .= '<input class="' . $name_class . '" type="text" id="evreg_companion_name" data-evreg-companion-input name="evreg_companion_name" value="' . esc_attr( $companion_name ) . '"' . $required . $hidden . '>';

		if ( null !== $error ) {
			$out .= '<div class="evreg-error-msg invalid-feedback d-block">' . esc_html( $this->errorMessage( (string) $error ) ) . '</div>';
		}

		return $out . '</div>';
	}

	/**
	 * Renderuje kontrolkę wejściową pola odpowiednią do jego typu.
	 *
	 * @param Field $field   Pole formularza.
	 * @param mixed $value   Wpisana wartość do odtworzenia (lub null).
	 * @param bool  $invalid Czy pole ma błąd walidacji (dodaje is-invalid).
	 * @param bool  $companion Czy odtwarzana submisja ma osobę towarzyszącą.
	 */
	private function renderControl( Field $field, mixed $value, bool $invalid = false, bool $companion = false ): string {
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
				return $this->renderAccommodation( $field, $value, $companion );

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
	 * @param bool  $companion Czy odtwarzana submisja ma osobę towarzyszącą.
	 */
	private function renderAccommodation( Field $field, mixed $value, bool $companion = false ): string {
		if ( null !== $this->nights ) {
			return $this->renderNights( $field, $this->nights, $value, $companion );
		}

		$config = $field->config;
		// Liczbę osób w pokoju czytamy przez AccommodationConfig, nie z surowej tablicy:
		// fromArray() zeruje occupancy, gdy event ma wyłączoną osobę towarzyszącą, więc
		// tylko tak renderer widzi to samo co pojemność, cena i walidacja. Surowy odczyt
		// chowałby pokój, który cała reszta systemu uznaje za dostępny.
		$accommodation = AccommodationConfig::fromArray( $field->config );
		$packages      = is_array( $config['packages'] ?? null ) ? $config['packages'] : array();
		$rooms         = is_array( $config['rooms'] ?? null ) ? $config['rooms'] : array();
		$inventory     = is_array( $config['inventory'] ?? null ) ? $config['inventory'] : array();
		$available     = array();
		foreach ( $inventory as $item ) {
			$available[ (string) ( $item['package'] ?? '' ) . '|' . (string) ( $item['room'] ?? '' ) ] = $item;
		}

		$current = is_array( $value ) ? ( (string) ( $value['package'] ?? '' ) . '|' . (string) ( $value['room'] ?? '' ) ) : '';

		// Pusta tablica odpowiedzi daje „|" (puste package i room połączone separatorem) —
		// to NIE jest wybrany slot, lecz brak wyboru. Bez tej normalizacji radio
		// „Bez noclegu" nie zaznaczyłoby się przy re-renderze pustej selekcji.
		if ( '|' === $current ) {
			$current = '';
		}

		// Klientowi się nie ufa: gdy event nie pozwala na osobę towarzyszącą,
		// odtworzony POST z evreg_companion nie może filtrować listy ani zamykać
		// slotów (ten sam wzór co SubmissionAssembler::extractCompanion).
		if ( empty( $config['companion_enabled'] ) ) {
			$companion = false;
		}

		// Pokoje z włączonym polem współlokatora — tylko dla nich renderujemy
		// (i pokazujemy) pole „Preferowana osoba w pokoju". Flaga jest per pokój.
		// Pole nie ma sensu w pokoju dwuosobowym: drugie łóżko zajmuje imiennie
		// wskazana osoba towarzysząca.
		$roommateRooms = array();
		foreach ( $rooms as $room ) {
			$room_type = $accommodation->room( (string) ( $room['key'] ?? '' ) );
			if ( ! empty( $room['roommate_field'] ) && 2 !== ( null === $room_type ? 0 : $room_type->occupancy ) ) {
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
				$room_type = $accommodation->room( (string) ( $room['key'] ?? '' ) );
				$occupancy = null === $room_type ? 0 : $room_type->occupancy;
				$rowSeats  = RoomOccupancy::seats( $occupancy, $companion );
				$rows[]    = array(
					'slot'      => $slot,
					'label'     => (string) ( $pkg['label'] ?? '' ) . ' — ' . (string) ( $room['label'] ?? '' ),
					'roommate'  => isset( $roommateRooms[ (string) ( $room['key'] ?? '' ) ] ),
					'occupancy' => $occupancy,
					'left'      => null === $this->availability ? null : $this->availability->left( $slot ),
					'closed'    => null !== $this->availability && $this->availability->isFull( $slot, $rowSeats ),
					'mismatch'  => null !== $room_type && null !== $room_type->companionMismatch( $companion ),
				);
			}
		}

		// Wybór z poprzedniej próby, który stał się niedostępny (albo zniknął z inwentarza),
		// PRZENOSIMY na „Bez noclegu" i ogłaszamy. Pusta grupa radio nie wysyła klucza
		// [slot], a AccommodationValidator przy allow_none tego nie kwestionuje — czyli
		// dokładnie ten cichy downgrade, który ta zmiana likwiduje.
		// Wiersz niepasujący do stanu checkboxa (`mismatch`) liczy się jak niedostępny.
		$dropped   = false;
		$any_open  = false;
		$any_match = false;
		foreach ( $rows as $row ) {
			if ( ! $row['mismatch'] ) {
				$any_match = true;
			}
			if ( ! $row['closed'] && ! $row['mismatch'] ) {
				$any_open = true;
			}
		}
		if ( '' !== $current ) {
			$still_open = false;
			foreach ( $rows as $row ) {
				if ( $row['slot'] === $current && ! $row['closed'] && ! $row['mismatch'] ) {
					$still_open = true;
				}
			}
			if ( ! $still_open ) {
				$dropped = true;
				$current = '';
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
				// Osobny <span>, by form.js mógł ukryć notkę, gdy slot znów się mieści.
				$note = ' <span class="evreg-seats-note">' . ( null !== $row['left'] && $row['left'] < 1
					? esc_html__( '(brak miejsc)', 'event-registration' )
					: esc_html__( '(za mało miejsc)', 'event-registration' ) ) . '</span>';
			}
			$seats_attr = null === $row['left'] ? '' : ' data-evreg-seats-left="' . esc_attr( (string) $row['left'] ) . '"';
			$occ_attr   = ' data-evreg-occupancy="' . esc_attr( (string) $row['occupancy'] ) . '"';
			// Niedostępny ALBO niepasujący slot NIGDY nie renderuje się jako wybrany.
			// `disabled` nie wysyła wartości, więc zaznaczony-i-wyłączony radio dałby
			// pusty [slot] = „Bez noclegu" bez wiedzy zgłaszającego się (cichy downgrade).
			// `hidden` chowa, ale NADAL wysyła wartość, więc wiersz niepasujący dostaje
			// też `disabled` — inaczej schowany-a-zaznaczony radio trafiłby do $_POST.
			$off      = $row['closed'] || $row['mismatch'];
			$checked  = ! $off && $row['slot'] === $current;
			$roommate = $row['roommate'] ? ' data-evreg-roommate="1"' : '';
			$out     .= '<div class="evreg-choice form-check' . ( $row['closed'] ? ' is-full' : '' ) . '"' . ( $row['mismatch'] ? ' hidden' : '' ) . '>'
				. '<input class="form-check-input" type="radio" id="' . $slot_id . '" name="' . $name . '[slot]" value="' . esc_attr( $row['slot'] ) . '"' . checked( $checked, true, false ) . ( $off ? ' disabled' : '' ) . ( $row['mismatch'] ? ' hidden' : '' ) . $seats_attr . $occ_attr . $roommate . '>'
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

		// Oba komunikaty renderujemy ZAWSZE (gdy są wiersze), z atrybutem `hidden` gdy
		// nie dotyczą stanu startowego — form.js przełącza je reaktywnie, bez własnych
		// tłumaczonych tekstów.
		if ( array() !== $rows ) {
			$out .= '<p class="evreg-accommodation-dropped"' . ( $dropped ? '' : ' hidden' ) . '>'
				. esc_html__( 'Twój wcześniejszy wybór noclegu jest już niedostępny.', 'event-registration' )
				. '</p>';

			$out .= '<p class="evreg-accommodation-mismatch"' . ( $any_match ? ' hidden' : '' ) . '>'
				. esc_html__( 'Dla tego wyboru nie ma pasujących pokojów — zmień zaznaczenie osoby towarzyszącej.', 'event-registration' )
				. '</p>';
		}

		// Bez „Bez noclegu" i bez ani jednego otwartego slotu nie ma czego wybrać —
		// powiedz to wprost (tylko gdy powodem są wyczerpane miejsca, nie niepasujące
		// pokoje — to osobny komunikat powyżej), zamiast pokazywać listę samych wyszarzonych pozycji.
		if ( ! $any_open && $any_match && array() !== $rows && ! (bool) ( $config['allow_none'] ?? true ) ) {
			$out .= '<p class="evreg-accommodation-empty">'
				. esc_html__( 'Brak wolnych miejsc noclegowych.', 'event-registration' )
				. '</p>';
		}

		$out .= '</div>';

		return $out;
	}

	/**
	 * Renderuje wybór dób jako checkboxy (tryb „doby”).
	 *
	 * Nazwy pól siedzą pod `evreg_field[<klucz>][nights][]` (namespace jest obowiązkowy —
	 * patrz renderControl). Doba bez miejsc jest `disabled`; doba z miejscem, ale bez miejsca
	 * dla pary, jest `disabled` TYLKO przy zaznaczonej osobie towarzyszącej — odznaczenie
	 * jej otwiera dobę ponownie, co robi form.js (applyNightState). Zaznaczenie z doby, która
	 * jest wyłączona, zdejmujemy jawnie i ogłaszamy: `disabled` nie wysyła wartości, więc
	 * zaznaczony-i-wyłączony checkbox po submicie po cichu znikałby z wyboru.
	 *
	 * @param Field        $field     Pole typu accommodation.
	 * @param NightsConfig $config    Konfiguracja trybu „doby”.
	 * @param mixed        $value     Surowa odpowiedź do odtworzenia (`['nights' => [...]]`) lub null.
	 * @param bool         $companion Czy odtwarzana submisja ma osobę towarzyszącą.
	 */
	private function renderNights( Field $field, NightsConfig $config, mixed $value, bool $companion ): string {
		// Klientowi się nie ufa: bez włączonej osoby towarzyszącej POST evreg_companion nie
		// zamyka żadnej doby (ten sam wzór co w ścieżce klasycznej i w SubmissionAssembler).
		if ( ! $config->companionEnabled() ) {
			$companion = false;
		}

		$occupancy = $this->nightOccupancy ?? new NightOccupancy( array(), array() );
		$raw       = is_array( $value ) && is_array( $value['nights'] ?? null ) ? $value['nights'] : array();
		$selected  = array_map( 'strval', array_values( array_filter( $raw, 'is_scalar' ) ) );

		$name    = 'evreg_field[' . esc_attr( $field->key ) . '][nights][]';
		$base    = 'evreg-' . esc_attr( $field->key ) . '-night-';
		$out     = '<div class="evreg-accommodation evreg-nights">';
		$dropped = false;
		$any_on  = false;

		foreach ( $config->nights() as $index => $night ) {
			$left       = max( 0, $night->seats - $occupancy->seatsFor( $night->key ) );
			$pairs_left = null === $night->companionCap ? null : max( 0, $night->companionCap - $occupancy->pairsFor( $night->key ) );
			$full       = $left < 1;
			$no_pair    = $companion && ! $full && ( $left < 2 || ( null !== $pairs_left && $pairs_left < 1 ) );
			$off        = $full || $no_pair;
			$ticked     = in_array( $night->key, $selected, true );
			$id         = $base . $index;

			if ( ! $off ) {
				$any_on = true;
			}
			// Zaznaczenie z wyłączonej doby zdejmujemy (nigdy checked + disabled).
			if ( $off && $ticked ) {
				$dropped = true;
			}

			$out .= '<div class="evreg-choice evreg-night form-check' . ( $off ? ' is-full' : '' ) . '">'
				. '<input class="form-check-input" type="checkbox" id="' . esc_attr( $id ) . '" name="' . $name . '" value="' . esc_attr( $night->key ) . '"'
				. checked( $ticked && ! $off, true, false )
				. ' data-evreg-night-seats-left="' . esc_attr( (string) $left ) . '"'
				. ' data-evreg-night-pairs-left="' . esc_attr( null === $pairs_left ? '' : (string) $pairs_left ) . '"'
				. ( $off ? ' disabled' : '' ) . '>'
				. ' <label class="form-check-label" for="' . esc_attr( $id ) . '">' . esc_html( $night->label )
				// Obie notki istnieją zawsze (jedna schowana): form.js przełącza je reaktywnie
				// i nie ma własnych tłumaczonych tekstów.
				. ' <span class="evreg-seats-note" data-evreg-night-note="full"' . ( $full ? '' : ' hidden' ) . '>' . esc_html__( '(brak miejsc)', 'event-registration' ) . '</span>'
				. ' <span class="evreg-seats-note" data-evreg-night-note="pair"' . ( $no_pair ? '' : ' hidden' ) . '>' . esc_html__( '(brak miejsc dla dwóch osób)', 'event-registration' ) . '</span>'
				. '</label>'
				. '</div>';
		}

		if ( array() !== $config->nights() ) {
			$out .= '<p class="evreg-nights-dropped"' . ( $dropped ? '' : ' hidden' ) . '>'
				. esc_html__( 'Zaznaczona doba jest już niedostępna i została odznaczona.', 'event-registration' )
				. '</p>';

			if ( ! $any_on && ! $config->allowsNone() ) {
				$out .= '<p class="evreg-accommodation-empty">'
					. esc_html__( 'Brak wolnych miejsc noclegowych.', 'event-registration' )
					. '</p>';
			}
		}

		return $out . '</div>';
	}

	/**
	 * Komunikat błędu pola: kody niosące kontekst pola (`night_full:<doba>`) dostają jego
	 * etykietę, resztę rozstrzyga errorMessage().
	 *
	 * @param Field  $field Pole, którego dotyczy błąd.
	 * @param string $code  Kod błędu.
	 */
	private function fieldErrorMessage( Field $field, string $code ): string {
		if ( null !== $this->nights && str_starts_with( $code, 'night_full:' ) && FieldType::Accommodation === $field->type ) {
			$label = $this->nights->nightLabel( substr( $code, strlen( 'night_full:' ) ) );

			/* translators: %s: etykieta doby noclegowej. */
			return sprintf( __( 'Doba „%s” nie ma już wolnych miejsc dla Twojego zgłoszenia. Odznacz ją albo wybierz inne doby.', 'event-registration' ), $label );
		}

		return $this->errorMessage( $code );
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
			'companion_required'      => __( 'Ten pokój jest dwuosobowy. Zaznacz osobę towarzyszącą albo wybierz pokój jednoosobowy.', 'event-registration' ),
			'companion_not_allowed'   => __( 'Ten pokój jest jednoosobowy. Odznacz osobę towarzyszącą albo wybierz pokój dwuosobowy.', 'event-registration' ),
			'accommodation_full'      => __( 'Wybrany nocleg nie ma już wolnych miejsc dla Twojego zgłoszenia. Wybierz inny albo „Bez noclegu".', 'event-registration' ),
		);

		return $map[ $code ] ?? __( 'Nieprawidłowa wartość.', 'event-registration' );
	}
}
