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
	 */
	public function assemble( FormSchema $schema, array $post ): AssembledSubmission {
		$answers   = $this->extractAnswers( $schema, $post );
		$validator = new Validator( new VisibilityResolver( new ConditionEngine() ), new FieldValidatorRegistry() );
		$result    = $validator->validate( $schema, $answers );

		$accommodationField = $this->accommodationField( $schema );
		$companion          = false;
		$companionName      = '';

		if ( null !== $accommodationField ) {
			[ $companion, $companionName ] = $this->extractCompanion( $accommodationField, $post );

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

		$values  = $result->values();
		$email   = $this->extractEmail( $schema, $values );
		$request = new ReservationRequest(
			$email,
			$this->extractName( $schema, $values, $email ),
			(string) ( $values[ FormSchema::TYPE_FIELD_KEY ] ?? '' ),
			$values,
			$this->extractSelection( $schema, $values ),
			CurrentLanguage::get(),
			$companion,
			$companionName
		);

		return AssembledSubmission::valid( $values, $request );
	}

	/**
	 * Wyodrębnia flagę i imię osoby towarzyszącej z posta. Honorowane TYLKO gdy event
	 * ma włączoną opcję companion_enabled w konfiguracji noclegów — klientowi się nie
	 * ufa: gdy wyłączone, POST evreg_companion jest ignorowany (companion=false).
	 *
	 * @param Field               $accommodationField Pole typu accommodation (niesie config noclegów).
	 * @param array<string,mixed> $post                Surowe $_POST (już wp_unslash przez wywołującego).
	 * @return array{0:bool,1:string}
	 */
	private function extractCompanion( Field $accommodationField, array $post ): array {
		$config = AccommodationConfig::fromArray( $accommodationField->config );
		if ( ! $config->companionEnabled() ) {
			return array( false, '' );
		}

		$companion      = ! empty( $post['evreg_companion'] );
		$companion_name = sanitize_text_field( (string) ( $post['evreg_companion_name'] ?? '' ) );

		return array( $companion, $companion_name );
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
	 * @return array<string,mixed>
	 */
	private function extractAnswers( FormSchema $schema, array $post ): array {
		// Pola formularza są namespace'owane pod evreg_field[...] (FormRenderer /
		// RegistrationEditForm), by nie kolidować z publicznymi query-vars WordPressa.
		$fields  = is_array( $post['evreg_field'] ?? null ) ? $post['evreg_field'] : array();
		$answers = array();
		foreach ( $schema->allFields() as $field ) {
			if ( FieldType::Accommodation === $field->type ) {
				$raw                    = is_array( $fields[ $field->key ] ?? null ) ? $fields[ $field->key ] : array();
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
	 */
	private function extractSelection( FormSchema $schema, array $values ): ?AccommodationSelection {
		foreach ( $schema->allFields() as $field ) {
			if ( FieldType::Accommodation !== $field->type ) {
				continue;
			}
			$sel = $values[ $field->key ] ?? null;
			if ( $sel instanceof AccommodationSelection ) {
				return $sel;
			}
			return null;
		}

		return null;
	}
}
