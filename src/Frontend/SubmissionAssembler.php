<?php
/**
 * Wspólny pipeline walidacji + ekstrakcji submisji formularza.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Frontend;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Domain\Accommodation\NightSelection;
use EvReg\Domain\Accommodation\NightsConfig;
use EvReg\Domain\Conditions\ConditionEngine;
use EvReg\Domain\Schema\Field;
use EvReg\Domain\Schema\FieldType;
use EvReg\Domain\Schema\FormSchema;
use EvReg\Domain\Schema\VisibilityResolver;
use EvReg\Domain\Validation\FieldValidatorRegistry;
use EvReg\Domain\Validation\Validator;
use EvReg\Services\ReservationRequest;

defined( 'ABSPATH' ) || exit;

/**
 * Waliduje surowe odpowiedzi względem schematu i buduje `ReservationRequest`.
 *
 * Współdzielone przez publiczny formularz (`SubmitHandler`) i przyszły ekran
 * edycji zgłoszenia w adminie — jedyne źródło prawdy dla ekstrakcji.
 */
final class SubmissionAssembler {

	/**
	 * Waliduje surowe posty względem schematu i buduje ReservationRequest.
	 *
	 * Nie robi guardu pustego e-maila — to pozostaje po stronie wywołującego.
	 *
	 * @param FormSchema          $schema Złożony schemat formularza.
	 * @param array<string,mixed> $post   Surowe $_POST.
	 * @param NightsConfig|null   $nights Konfiguracja trybu „doby” rozstrzygnięta u wejścia
	 *                                    (NightsResolver); null = tryb klasyczny. Asembler sam
	 *                                    NightsConfig::fromArray nie woła.
	 * @param bool                $emailOptional Czy pole e-mail zwolnić z `required` (wpis ręczny
	 *                                           organizatora — rozmówca przez telefon często nie ma adresu).
	 *                                           Poprawność podanego adresu nadal jest sprawdzana.
	 */
	public function assemble( FormSchema $schema, array $post, ?NightsConfig $nights = null, bool $emailOptional = false ): AssembledSubmission {
		$answers   = $this->extractAnswers( $schema, $post, $nights );
		$validator = new Validator( new VisibilityResolver( new ConditionEngine() ), new FieldValidatorRegistry() );
		$email_key = $emailOptional ? $this->emailFieldKey( $schema ) : '';
		$optional  = '' === $email_key ? array() : array( $email_key );
		$result    = $validator->validate( $schema, $answers, $optional );

		$accommodationField = $this->accommodationField( $schema );
		$companion          = false;
		$companionName      = '';

		if ( null !== $accommodationField ) {
			[ $companion, $companionName ] = $this->extractCompanion( $accommodationField, $post, $nights );

			// Kontrolki evreg_companion/evreg_companion_name NIE są polami schematu — nie idą
			// pod evreg_field[...], więc dokładamy je do $answers ręcznie, by re-render błędu
			// (FormRenderer) miał z czego odtworzyć stan checkboxa i wpisane imię. Dokładamy je
			// tylko gdy schemat w ogóle ma pole noclegu (spójnie z FormRenderer, który
			// checkbox rysuje wyłącznie obok tego pola) — bez tego $values pojedynczego
			// formularza bez noclegu dostawałoby martwe klucze.
			$answers['evreg_companion']      = $companion;
			$answers['evreg_companion_name'] = $companionName;
		}

		$errors = $result->errors();
		if ( $companion && '' === trim( $companionName ) ) {
			$errors['evreg_companion'] = 'companion_name_required';
		}

		if ( array() !== $errors ) {
			return AssembledSubmission::invalid( $errors, $answers );
		}

		$values   = $result->values();
		$email    = $this->extractEmail( $schema, $values );
		$supplied = $this->accommodationSupplied( $schema, $values );
		$request  = new ReservationRequest(
			$email,
			$this->extractName( $schema, $values, $email ),
			(string) ( $values[ FormSchema::TYPE_FIELD_KEY ] ?? '' ),
			$values,
			$this->extractSelection( $schema, $values, null !== $nights ),
			CurrentLanguage::get(),
			$companion,
			$companionName,
			$this->extractNights( $schema, $values, $nights, $supplied ),
			$supplied
		);

		return AssembledSubmission::valid( $values, $request, $answers );
	}

	/**
	 * Wyodrębnia flagę i imię osoby towarzyszącej z posta. Honorowane TYLKO gdy event
	 * ma włączoną opcję companion_enabled w konfiguracji noclegów — klientowi się nie
	 * ufa: gdy wyłączone, POST evreg_companion jest ignorowany (companion=false).
	 *
	 * @param Field               $accommodationField Pole typu accommodation (niesie config noclegów).
	 * @param array<string,mixed> $post                Surowe $_POST (już wp_unslash przez wywołującego).
	 * @param NightsConfig|null   $nights              Konfiguracja trybu „doby” albo null (klasyczny).
	 * @return array{0:bool,1:string}
	 */
	private function extractCompanion( Field $accommodationField, array $post, ?NightsConfig $nights ): array {
		// Jedno rozgałęzienie po trybie: „doby” czyta flagę z NightsConfig i NIE buduje
		// AccommodationConfig (rzuca na niespójnym inwentarzu, a stare dane klasyczne mogą
		// zostać w konfiguracji eventu w trybie „doby”).
		$enabled = null !== $nights
			? $nights->companionEnabled()
			: AccommodationConfig::fromArray( $accommodationField->config )->companionEnabled();
		if ( ! $enabled ) {
			return array( false, '' );
		}

		$companion      = ! empty( $post['evreg_companion'] );
		$companion_name = sanitize_text_field( (string) ( $post['evreg_companion_name'] ?? '' ) );

		return array( $companion, $companion_name );
	}

	/**
	 * Czy ta submisja w ogóle PODAŁA odpowiedź na pole noclegu — JEDYNY dom tej reguły.
	 *
	 * Validator zwraca wartości wyłącznie pól WIDOCZNYCH (`VisibilityResolver`), a formularze
	 * (publiczny i ekran edycji w adminie) renderują WSZYSTKIE pola schematu i POSTują je
	 * nawet schowane. Pole noclegu ukryte warunkiem (B1) wypada więc z wyniku walidacji, choć
	 * jego kontrolka przyszła w POŚCIE — i to jest inny fakt niż „nic nie wybrano”. Odpowiedź
	 * udzieloną od nieudzielonej odróżnia OBECNOŚĆ klucza w wartościach: widoczne pole trafia
	 * tam zawsze, choćby z wartością null (pusty wybór).
	 *
	 * Bez tego rozróżnienia edycja zmieniająca odpowiedź, od której zależy warunek pola
	 * noclegu (typowo typ zgłoszenia), czytała się jak świadoma rezygnacja i niszczyła
	 * nocleg — booking/wiersze dób, zajęte miejsca i cenę — pod komunikatem „zapisano” (B21).
	 *
	 * Schemat bez pola noclegu daje false („nie podano”) — nie ma tam odpowiedzi do udzielenia,
	 * a zastane zgłoszenia z noclegiem nie mogą tracić wierszy przez samo usunięcie pola
	 * z formularza.
	 *
	 * @param FormSchema          $schema Schemat formularza.
	 * @param array<string,mixed> $values Znormalizowane wartości z Validatora (tylko widoczne pola).
	 */
	private function accommodationSupplied( FormSchema $schema, array $values ): bool {
		foreach ( $schema->allFields() as $field ) {
			if ( FieldType::Accommodation === $field->type ) {
				return array_key_exists( $field->key, $values );
			}
		}

		return false;
	}

	/**
	 * Znajduje pole zakwaterowania schematu, jeśli obecne.
	 *
	 * @param FormSchema $schema Złożony schemat formularza.
	 */
	private function accommodationField( FormSchema $schema ): ?Field {
		foreach ( $schema->allFields() as $field ) {
			if ( FieldType::Accommodation === $field->type ) {
				return $field;
			}
		}

		return null;
	}

	/**
	 * Wyodrębnia surowe odpowiedzi z $_POST wg pól schematu.
	 *
	 * @param FormSchema          $schema Schemat formularza.
	 * @param array<string,mixed> $post   Surowe $_POST.
	 * @param NightsConfig|null   $nights Konfiguracja trybu „doby” albo null (klasyczny).
	 * @return array<string,mixed>
	 */
	private function extractAnswers( FormSchema $schema, array $post, ?NightsConfig $nights ): array {
		// Pola formularza są namespace'owane pod evreg_field[...] (FormRenderer /
		// RegistrationEditForm), by nie kolidować z publicznymi query-vars WordPressa.
		$fields  = is_array( $post['evreg_field'] ?? null ) ? $post['evreg_field'] : array();
		$answers = array();
		foreach ( $schema->allFields() as $field ) {
			if ( FieldType::Accommodation === $field->type ) {
				$raw = is_array( $fields[ $field->key ] ?? null ) ? $fields[ $field->key ] : array();
				if ( null !== $nights ) {
					// Tryb „doby”: surowa lista kluczy z checkboxów, nic z pól klasycznych.
					$keys                   = is_array( $raw['nights'] ?? null ) ? array_values( array_filter( $raw['nights'], 'is_scalar' ) ) : array();
					$answers[ $field->key ] = array( 'nights' => array_map( 'strval', $keys ) );
					continue;
				}
				$slot                   = (string) ( $raw['slot'] ?? '' );
				$parts                  = '' === $slot ? array( '', '' ) : explode( '|', $slot, 2 );
				$answers[ $field->key ] = array(
					'package'  => $parts[0] ?? '',
					'room'     => $parts[1] ?? '',
					'roommate' => (string) ( $raw['roommate'] ?? '' ),
				);
				continue;
			}
			if ( array_key_exists( $field->key, $fields ) ) {
				$answers[ $field->key ] = $fields[ $field->key ];
			}
		}

		return $answers;
	}

	/**
	 * Klucz pierwszego pola typu e-mail w kolejności schematu, NIEZALEŻNIE od wartości.
	 *
	 * Celowo inna reguła niż prywatne `extractEmail()` (pierwsze NIEPUSTE pole e-mail z wartości):
	 * ta metoda służy właśnie przypadkowi pustego pola, więc nie może na wartość patrzeć.
	 * Nie unifikować. Zwraca '' gdy schemat nie ma pola e-mail.
	 *
	 * @param FormSchema $schema Schemat formularza.
	 */
	public function emailFieldKey( FormSchema $schema ): string {
		foreach ( $schema->allFields() as $field ) {
			if ( FieldType::Email === $field->type ) {
				return $field->key;
			}
		}

		return '';
	}

	/**
	 * Znajduje wartość pierwszego widocznego, niepustego pola typu e-mail.
	 *
	 * @param FormSchema          $schema Schemat formularza.
	 * @param array<string,mixed> $values Znormalizowane wartości z Validatora.
	 */
	private function extractEmail( FormSchema $schema, array $values ): string {
		foreach ( $schema->allFields() as $field ) {
			if ( FieldType::Email === $field->type && isset( $values[ $field->key ] ) && '' !== (string) $values[ $field->key ] ) {
				return (string) $values[ $field->key ];
			}
		}

		return '';
	}

	/**
	 * Wyznacza nazwę zgłaszającego się: pole name/imie, inaczej pierwsze pole tekstowe, inaczej fallback.
	 *
	 * @param FormSchema          $schema   Schemat formularza.
	 * @param array<string,mixed> $values   Znormalizowane wartości z Validatora.
	 * @param string              $fallback Wartość zastępcza, gdy brak nazwy (np. e-mail).
	 */
	private function extractName( FormSchema $schema, array $values, string $fallback ): string {
		foreach ( array( 'name', 'imie' ) as $key ) {
			if ( isset( $values[ $key ] ) && '' !== (string) $values[ $key ] ) {
				return (string) $values[ $key ];
			}
		}
		foreach ( $schema->allFields() as $field ) {
			if ( FieldType::Text === $field->type && isset( $values[ $field->key ] ) && '' !== (string) $values[ $field->key ] ) {
				return (string) $values[ $field->key ];
			}
		}

		return $fallback;
	}

	/**
	 * Zwraca gotowy obiekt wyboru zakwaterowania znormalizowany przez AccommodationValidator, jeśli obecny.
	 *
	 * @param FormSchema          $schema Schemat formularza.
	 * @param array<string,mixed> $values Znormalizowane wartości z Validatora.
	 * @param bool                $nightsMode Czy pole jest w trybie „doby” (wtedy wybór niesie nights, nie selekcja).
	 */
	private function extractSelection( FormSchema $schema, array $values, bool $nightsMode ): ?AccommodationSelection {
		foreach ( $schema->allFields() as $field ) {
			if ( FieldType::Accommodation !== $field->type ) {
				continue;
			}
			// W trybie „doby” wybór niesie ReservationRequest::$nights, nie selekcja klasyczna.
			if ( $nightsMode ) {
				return null;
			}
			$sel = $values[ $field->key ] ?? null;
			if ( $sel instanceof AccommodationSelection ) {
				return $sel;
			}
			return null;
		}

		return null;
	}

	/**
	 * Zwraca wybór dób w trybie „doby”; poza tym trybem null.
	 *
	 * Gdy pole jest w trybie „doby” i odpowiedź na nie PODANO, wynik to prawdziwy
	 * NightSelection, także pusty — pusty zbiór to jawna rezygnacja z noclegu. Gdy odpowiedzi
	 * nie podano (pole ukryte warunkiem, więc Validator jego wartości nie zwrócił), wynikiem
	 * jest null: dla serwisu „nie podano”, co ścieżka edycji czyta jako „nie zmieniaj
	 * noclegu”, a `reserveNights` jako „bez noclegu” (nowe zgłoszenie nie ma czego stracić).
	 *
	 * @param FormSchema          $schema   Schemat formularza.
	 * @param array<string,mixed> $values   Znormalizowane wartości z Validatora.
	 * @param NightsConfig|null   $config   Konfiguracja trybu „doby” albo null (klasyczny).
	 * @param bool                $supplied Czy odpowiedź na pole noclegu w ogóle podano.
	 */
	private function extractNights( FormSchema $schema, array $values, ?NightsConfig $config, bool $supplied ): ?NightSelection {
		if ( null === $config || ! $supplied ) {
			return null;
		}

		foreach ( $schema->allFields() as $field ) {
			if ( FieldType::Accommodation !== $field->type ) {
				continue;
			}

			$keys = $values[ $field->key ] ?? array();

			return NightSelection::fromKeys( is_array( $keys ) ? $keys : array(), $config );
		}

		return null;
	}
}
