<?php
/**
 * Renderuje formularz edycji odpowiedzi zgłoszenia w adminie.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Admin;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Domain\Accommodation\NightsConfig;
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
	 * @param NightsConfig|null         $nights    Konfiguracja trybu „doby” rozstrzygnięta u wejścia
	 *                                             ({@see \EvReg\Frontend\NightsResolver}); null = tryb
	 *                                             klasyczny. Renderer sam NightsConfig::fromArray nie woła.
	 */
	public static function renderEdit( FormSchema $schema, array $answers, int $reg_id, ?array $errors = null, ?array $submitted = null, ?NightsConfig $nights = null ): string {
		$source = null !== $submitted ? $submitted : $answers;

		$head  = '<input type="hidden" name="action" value="evreg_edit_registration" />';
		$head .= '<input type="hidden" name="registration" value="' . esc_attr( (string) $reg_id ) . '" />';
		$head .= wp_nonce_field( 'evreg_edit_' . $reg_id, '_wpnonce', true, false );

		return self::renderFrame( $schema, $source, $errors, $nights, $head, '', esc_html__( 'Zapisz zmiany', 'event-registration' ) );
	}

	/**
	 * Renderuje pusty formularz ręcznego dodania zgłoszenia (POST → admin-post.php).
	 *
	 * Te same wiersze co edycja, inna rama: nonce zakresu eventu, ukryte ID wydarzenia,
	 * przełącznik wysyłki potwierdzenia (domyślnie wyłączony) i własny przycisk. Przy błędzie
	 * walidacji wartości wracają z odrzuconego POST-a (`$submitted`), w tym stan przełącznika.
	 *
	 * @param FormSchema                $schema    Złożony schemat formularza eventu.
	 * @param int                       $event_id  ID eventu, do którego dodajemy zgłoszenie.
	 * @param array<string,string>|null $errors    Błędy walidacji: klucz pola → kod błędu.
	 * @param array<string,mixed>|null  $submitted Wartości z odrzuconego POST (prefill przy błędzie).
	 * @param NightsConfig|null         $nights    Konfiguracja trybu „doby” rozstrzygnięta u wejścia; null = klasyczny.
	 */
	public static function renderAdd( FormSchema $schema, int $event_id, ?array $errors = null, ?array $submitted = null, ?NightsConfig $nights = null ): string {
		$source = null !== $submitted ? $submitted : array();

		$head  = '<input type="hidden" name="action" value="' . esc_attr( RegistrationsScreen::ACTION_ADD ) . '" />';
		$head .= '<input type="hidden" name="event_id" value="' . esc_attr( (string) $event_id ) . '" />';
		$head .= wp_nonce_field( 'evreg_add_' . $event_id, '_wpnonce', true, false );

		// Domyślnie wyłączony: wpis ręczny bywa przepisaniem telefonu, a mail nie powinien
		// wyjść bez decyzji organizatora. Etykieta mówi tylko o uczestniku — przy wyłączonym
		// przełączniku nie idzie nic do nikogo, więc nie obiecujemy kopii dla organizatorów.
		$notify_on = ! empty( $source['evreg_notify'] );
		$foot      = '<p><label><input type="checkbox" name="evreg_notify" value="1"' . ( $notify_on ? ' checked' : '' ) . ' /> '
			. esc_html__( 'Wyślij uczestnikowi e-mail z potwierdzeniem', 'event-registration' ) . '</label><br />'
			. '<span class="description">' . esc_html__( 'Bez zaznaczenia nie zostanie wysłany żaden e-mail.', 'event-registration' ) . '</span></p>';

		return self::renderFrame( $schema, $source, $errors, $nights, $head, $foot, esc_html__( 'Dodaj zgłoszenie', 'event-registration' ) );
	}

	/**
	 * Wspólna rama formularza: otwarcie `<form>`, błędy, tabela wierszy, stopka i przycisk.
	 *
	 * Jedyna kopia pętli wierszy — edycja i dodawanie różnią się wyłącznie tym, co wchodzi
	 * w `$head` (action, ukryte pola, nonce), `$foot` (kontrolki przed przyciskiem) i etykietą.
	 *
	 * @param FormSchema                $schema Złożony schemat formularza.
	 * @param array<string,mixed>       $source Wartości do odtworzenia w kontrolkach.
	 * @param array<string,string>|null $errors Błędy walidacji.
	 * @param NightsConfig|null         $nights Konfiguracja trybu „doby” albo null.
	 * @param string                    $head   Gotowy HTML: ukryte pola i nonce (już escapowany przez wołającego).
	 * @param string                    $foot   Gotowy HTML kontrolek przed przyciskiem (już escapowany).
	 * @param string                    $button Etykieta przycisku (już escapowana).
	 */
	private static function renderFrame( FormSchema $schema, array $source, ?array $errors, ?NightsConfig $nights, string $head, string $foot, string $button ): string {
		// Klasa evreg-form — reuse publicznego assets/public/form.js (evreg-public, wp_enqueue_script
		// w RegistrationView) do przełączania widoczności pola imienia companiona.
		$out  = '<form class="evreg-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$out .= $head;
		$out .= self::renderErrors( $schema, $errors, $nights );

		$out .= '<table class="form-table"><tbody>';
		foreach ( $schema->allFields() as $field ) {
			if ( ! $field->type->isInput() ) {
				continue;
			}
			$out .= self::renderRow( $field, $source[ $field->key ] ?? '', $nights );
			if ( FieldType::Accommodation === $field->type ) {
				$out .= self::renderNightsWishRow( $source, $nights );
				$out .= self::renderCompanionRow( $field, $source, $errors, $nights );
			}
		}
		$out .= '</tbody></table>';
		$out .= $foot;
		$out .= '<p><button type="submit" class="button button-primary">' . $button . '</button></p>';
		$out .= '</form>';

		return $out;
	}

	/**
	 * Renderuje blok błędów walidacji, jeśli są — etykieta pola + przetłumaczony komunikat.
	 *
	 * @param FormSchema                $schema Złożony schemat formularza (źródło etykiet pól).
	 * @param array<string,string>|null $errors Błędy walidacji: klucz pola → kod błędu.
	 * @param NightsConfig|null         $nights Konfiguracja trybu „doby” (etykieta doby w `night_full:<klucz>`).
	 */
	private static function renderErrors( FormSchema $schema, ?array $errors, ?NightsConfig $nights = null ): string {
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
			$label   = $labels[ $field_key ] ?? $field_key;
			$message = self::fieldErrorMessage( (string) $code, $nights );
			$items  .= '<li>' . esc_html( $label ) . ': ' . esc_html( $message ) . '</li>';
		}

		return '<div class="notice notice-error"><ul>' . $items . '</ul></div>';
	}

	/**
	 * Komunikat błędu pola: kod `night_full:<doba>` dostaje etykietę doby, resztę rozstrzyga errorMessage().
	 *
	 * Lustro {@see \EvReg\Frontend\FormRenderer::fieldErrorMessage()}. Bez konfiguracji
	 * dobowej nie ma skąd wziąć etykiety — kod spada na komunikat ogólny.
	 *
	 * @param string            $code   Kod błędu.
	 * @param NightsConfig|null $nights Konfiguracja trybu „doby” albo null.
	 */
	private static function fieldErrorMessage( string $code, ?NightsConfig $nights ): string {
		if ( null !== $nights && str_starts_with( $code, 'night_full:' ) ) {
			$label = $nights->nightLabel( substr( $code, strlen( 'night_full:' ) ) );

			/* translators: %s: etykieta doby noclegowej. */
			return sprintf( __( 'Doba „%s” nie ma już wolnych miejsc dla tego zgłoszenia. Odznacz ją albo wybierz inne doby.', 'event-registration' ), $label );
		}

		return self::errorMessage( $code );
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
			// Kod odmowy rezerwacji, nie walidacji pola: wybór BYŁ poprawny, tylko miejsce
			// zniknęło. FormRenderer ma go od początku; tu brakowało, bo ekran edycji mówi
			// o tym przez PRG i RegistrationNotice, a ekran dodawania re-renderuje inline.
			// Treść jak publiczna, w trybie bezosobowym — organizator czyta o kimś innym
			// (ten sam zabieg co w fieldErrorMessage() dla night_full).
			'accommodation_full'      => __( 'Wybrany nocleg nie ma już wolnych miejsc dla tego zgłoszenia. Wybierz inny albo „Bez noclegu".', 'event-registration' ),
			'roommate_not_allowed'    => __( 'Współlokator niedozwolony dla tego pokoju.', 'event-registration' ),
			'companion_name_required' => __( 'Podaj imię i nazwisko osoby towarzyszącej.', 'event-registration' ),
			'companion_required'      => __( 'Ten pokój jest dwuosobowy. Zaznacz osobę towarzyszącą albo wybierz pokój jednoosobowy.', 'event-registration' ),
			'companion_not_allowed'   => __( 'Ten pokój jest jednoosobowy. Odznacz osobę towarzyszącą albo wybierz pokój dwuosobowy.', 'event-registration' ),
		);

		return $map[ $code ] ?? __( 'Nieprawidłowa wartość.', 'event-registration' );
	}

	/**
	 * Renderuje jeden wiersz tabeli formularza (etykieta + kontrolka).
	 *
	 * @param Field             $field  Pole formularza.
	 * @param mixed             $value  Wartość do odtworzenia w kontrolce.
	 * @param NightsConfig|null $nights Konfiguracja trybu „doby” albo null (klasyczny).
	 */
	private static function renderRow( Field $field, $value, ?NightsConfig $nights = null ): string {
		$label   = '<th scope="row"><label>' . esc_html( $field->label ) . '</label></th>';
		$desc    = '' !== $field->description ? '<p class="description">' . wp_kses_post( $field->description ) . '</p>' : '';
		$control = '<td>' . self::renderControl( $field, $value, $nights ) . $desc . '</td>';
		return '<tr>' . $label . $control . '</tr>';
	}

	/**
	 * Renderuje kontrolkę wejściową pola odpowiednią do jego typu.
	 *
	 * @param Field             $field  Pole formularza.
	 * @param mixed             $value  Wartość do odtworzenia w kontrolce.
	 * @param NightsConfig|null $nights Konfiguracja trybu „doby” albo null (klasyczny).
	 */
	private static function renderControl( Field $field, $value, ?NightsConfig $nights = null ): string {
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
				// Jedno rozgałęzienie po trybie, na kontrolce: gałąź dobowa NIE buduje
				// AccommodationConfig (rzuca na niespójnym inwentarzu, a event w trybie
				// „doby” może nieść zapomniane dane klasyczne).
				return null !== $nights
					? self::renderNights( $field, $nights, $value )
					: self::renderAccommodation( $field, $value );
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

		// Zapisane odpowiedzi bywają w starym kształcie (nazwy właściwości obiektu
		// selekcji), więc czytamy je przez normalizator — inaczej prefill gubi wybór
		// noclegu i zapis edycji kasuje rezerwację.
		$stored  = is_array( $value ) ? AccommodationSelection::normalizeArray( $value ) : array();
		$current = is_array( $value ) ? ( $stored['package'] . '|' . $stored['room'] ) : '';

		// Namespace pod evreg_field[...] — spójnie z FormRenderer i wspólną ekstrakcją
		// SubmissionAssembler (klucze pól nie kolidują z query-vars WordPressa).
		// Pokoje z włączonym polem współlokatora (flaga per pokój) — tylko dla nich
		// renderujemy i pokazujemy pole „Współlokator".
		$roommateRooms = array();
		$accommodation = AccommodationConfig::fromArray( $field->config );
		// Pole nie ma sensu w pokoju dwuosobowym (tak jak w formularzu publicznym):
		// drugie łóżko zajmuje imiennie wskazana osoba towarzysząca.
		foreach ( $rooms as $room ) {
			$room_type = $accommodation->room( (string) ( $room['key'] ?? '' ) );
			if ( ! empty( $room['roommate_field'] ) && 2 !== ( null === $room_type ? 0 : $room_type->occupancy ) ) {
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
			$roommateVal = is_array( $value ) ? $stored['roommate'] : '';
			$out        .= '<input type="text" data-evreg-roommate-input name="' . $name . '[roommate]" placeholder="' . esc_attr__( 'Współlokator', 'event-registration' ) . '" value="' . esc_attr( $roommateVal ) . '" class="regular-text"' . $hidden . ' />';
		}

		$out .= '</div>';

		return $out;
	}

	/**
	 * Renderuje wybór dób jako checkboxy (tryb „doby”).
	 *
	 * Nazwy siedzą pod `evreg_field[<klucz>][nights][]` — dokładnie tam, gdzie czyta je
	 * `SubmissionAssembler::extractAnswers()`, i tak samo jak w formularzu publicznym.
	 * Kontrolka MUSI się domykać w obie strony: co wyrenderowane, to wraca. Dlatego —
	 * inaczej niż publicznie — żadna doba nie jest tu `disabled`: miejsca zajęte przez
	 * TO zgłoszenie wliczają się do zajętości, więc doba, którą ono trzyma, wyglądałaby
	 * na pełną, a wyłączony checkbox nie wysyła wartości. Zapis bez zmian skasowałby
	 * wtedy nocleg po cichu. Bramka i tak rozstrzyga pod blokadą w `editAnswers()`
	 * (z wykluczeniem tego zgłoszenia) i odmawia po nazwie doby.
	 *
	 * @param Field        $field  Pole typu accommodation.
	 * @param NightsConfig $config Konfiguracja trybu „doby”.
	 * @param mixed        $value  Wartość do odtworzenia (lista kluczy albo `['nights' => [...]]`).
	 */
	private static function renderNights( Field $field, NightsConfig $config, $value ): string {
		$selected = self::nightKeys( $value );
		$name     = 'evreg_field[' . esc_attr( $field->key ) . '][nights][]';
		$base     = 'evreg-admin-' . esc_attr( $field->key ) . '-night-';

		$out = '<div class="evreg-accommodation evreg-nights">';

		// Wiersz to <div class="evreg-choice evreg-night"> z inputem i <label for> w środku —
		// ten sam kształt co w formularzu publicznym. Div jest blokowy sam z siebie, więc
		// układ nie wisi ani na stylu inline, ani na publicznym arkuszu (ekran edycji
		// wciąga tylko skrypt evreg-public, nie styl): renderer emituje wyłącznie klasy-haki.
		foreach ( $config->nights() as $index => $night ) {
			$id = $base . $index;

			$out .= '<div class="evreg-choice evreg-night">'
				. '<input type="checkbox" id="' . esc_attr( $id ) . '" name="' . $name . '" value="' . esc_attr( $night->key ) . '"'
				. checked( in_array( $night->key, $selected, true ), true, false ) . ' /> '
				. '<label for="' . esc_attr( $id ) . '">' . esc_html( $night->label )
				. ' <span class="description">'
				. esc_html(
					sprintf(
						/* translators: %s: cena doby za jedno miejsce. */
						__( '(%s za miejsce)', 'event-registration' ),
						number_format_i18n( $night->price, 2 )
					)
				)
				. '</span></label></div>';
		}

		if ( array() === $config->nights() ) {
			$out .= '<p class="description">' . esc_html__( 'To wydarzenie nie ma skonfigurowanych dób.', 'event-registration' ) . '</p>';
		}

		return $out . '</div>';
	}

	/**
	 * Renderuje zastrzeżenie pod kontrolką dób, gdy zaznaczenie jest ŻYCZENIEM, a nie
	 * przyznanym noclegiem (zgłoszenie bez zapisanych wierszy dób: lista rezerwowa,
	 * awans bez przyznania, odmowa przy rezerwacji).
	 *
	 * Lustro zastrzeżenia „(nocleg nieprzyznany)" z detalu zgłoszenia — bez niego operator
	 * widziałby ptaszki identyczne jak przy przyznanym noclegu i czytał je jako zajęte miejsca.
	 *
	 * Fakt podróżuje w `$source` pod zarezerwowanym kluczem `evreg_nights_granted` — tym samym
	 * wzorem co `evreg_companion` (klucz spoza schematu dokładany przez `RegistrationsScreen`).
	 * To WYŁĄCZNIE podpowiedź renderu, nie kontrolka: na ścieżce re-renderu błędu prefill
	 * pochodzi z `values()`/`rawAnswers()`, gdzie tego klucza nie ma, więc zastrzeżenie
	 * nie wychodzi — i słusznie, bo ptaszki są wtedy tym, co operator właśnie zaznaczył.
	 *
	 * @param array<string,mixed> $source Źródło prefill (submitted, w innym wypadku answers).
	 * @param NightsConfig|null   $nights Konfiguracja trybu „doby” albo null (klasyczny).
	 */
	private static function renderNightsWishRow( array $source, ?NightsConfig $nights ): string {
		if ( null === $nights || ! array_key_exists( 'evreg_nights_granted', $source ) || ! empty( $source['evreg_nights_granted'] ) ) {
			return '';
		}

		return '<tr><td colspan="2"><p class="description evreg-nights-wish">'
			. esc_html__( 'Nocleg nie jest przyznany — zaznaczone doby to życzenie zapisane w zgłoszeniu, nie zajęte miejsca.', 'event-registration' )
			. '</p></td></tr>';
	}

	/**
	 * Normalizuje zapisany wybór dób do listy kluczy.
	 *
	 * Przyjmuje OBA kształty, w jakich wybór dociera do renderera: płaską listę kluczy
	 * (tak `ReservationService::nightAnswers()` zapisuje go w JSON-ie odpowiedzi i tak
	 * zwraca go walidator w `values()`) oraz `['nights' => [...]]` (surowe odpowiedzi
	 * z odrzuconego POST-a). Rozjazd między kształtem zapisu i odczytu kosztował już
	 * ten projekt skasowaną rezerwację (B15 na ścieżce klasycznej) — tutaj oba czyta
	 * jedna funkcja.
	 *
	 * @param mixed $value Zapisany albo przesłany wybór dób.
	 * @return array<int,string>
	 */
	private static function nightKeys( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$raw = is_array( $value['nights'] ?? null ) ? $value['nights'] : $value;

		return array_values( array_map( 'strval', array_filter( $raw, 'is_scalar' ) ) );
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
	 * @param NightsConfig|null         $nights Konfiguracja trybu „doby” albo null (klasyczny).
	 */
	private static function renderCompanionRow( Field $field, array $source, ?array $errors, ?NightsConfig $nights = null ): string {
		// Jedno rozgałęzienie po trybie: „doby” czyta flagę z NightsConfig i NIE buduje
		// AccommodationConfig (ta rzuca na niespójnym inwentarzu) — ten sam wzór co
		// w SubmissionAssembler::extractCompanion() i FormRenderer.
		$enabled = null !== $nights
			? $nights->companionEnabled()
			: AccommodationConfig::fromArray( $field->config )->companionEnabled();

		if ( ! $enabled ) {
			return '';
		}

		$companion_on   = ! empty( $source['evreg_companion'] );
		$companion_name = self::scalar( $source['evreg_companion_name'] ?? '' );
		$error          = null !== $errors ? ( $errors['evreg_companion'] ?? null ) : null;

		$checkbox = '<input type="checkbox" id="evreg_companion" name="evreg_companion" value="1"'
			. checked( $companion_on, true, false ) . ' data-evreg-companion />';

		// `required` tylko razem z widocznością — ukrytej kontrolki przeglądarka nie
		// sfokusuje i blokuje zapis bez komunikatu (jak w FormRenderer::renderCompanion).
		$hidden     = $companion_on ? '' : ' hidden';
		$required   = $companion_on ? ' required' : '';
		$name_class = 'regular-text' . ( null !== $error ? ' evreg-field-error' : '' );
		$name_input = '<br /><input type="text" class="' . $name_class . '" data-evreg-companion-input name="evreg_companion_name" placeholder="'
			. esc_attr__( 'Imię i nazwisko osoby towarzyszącej', 'event-registration' ) . '" value="' . esc_attr( $companion_name ) . '"' . $required . $hidden . ' />';

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
