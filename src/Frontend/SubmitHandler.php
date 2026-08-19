<?php
/**
 * Obsługa submisji publicznego formularza.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Frontend;

use EvReg\Domain\Accommodation\AccommodationSelection;
use EvReg\Domain\Conditions\ConditionEngine;
use EvReg\Domain\Schema\FieldType;
use EvReg\Domain\Schema\FormSchema;
use EvReg\Domain\Schema\VisibilityResolver;
use EvReg\Domain\Validation\FieldValidatorRegistry;
use EvReg\Domain\Validation\Validator;
use EvReg\Services\ReservationRequest;
use EvReg\Services\ReservationService;

defined( 'ABSPATH' ) || exit;

/**
 * Obsługa submisji publicznego formularza (POST-to-self).
 */
final class SubmitHandler {

	private const MIN_FILL_SECONDS = 3;

	/**
	 * Tworzy handler submisji z wstrzykniętym loaderem schematu i serwisem rezerwacji.
	 *
	 * @param EventFormLoader    $loader       Loader złożonego schematu eventu.
	 * @param ReservationService $reservations Serwis rezerwacji miejsc.
	 */
	public function __construct(
		private readonly EventFormLoader $loader,
		private readonly ReservationService $reservations
	) {
	}

	/**
	 * Jądro submisji bez redirectu — testowalne.
	 *
	 * @param int                 $event_id ID posta eventu.
	 * @param array<string,mixed> $post     Surowe $_POST.
	 */
	public function process( int $event_id, array $post ): SubmitResult {
		if ( '' !== (string) ( $post['evreg_hp'] ?? '' ) ) {
			return SubmitResult::spam();
		}
		if ( time() - (int) ( $post['evreg_ts'] ?? 0 ) < self::MIN_FILL_SECONDS ) {
			return SubmitResult::spam();
		}

		$schema = $this->loader->load( $event_id );
		if ( null === $schema ) {
			return SubmitResult::configError();
		}

		$answers   = $this->extractAnswers( $schema, $post );
		$validator = new Validator( new VisibilityResolver( new ConditionEngine() ), new FieldValidatorRegistry() );
		$result    = $validator->validate( $schema, $answers );

		if ( ! $result->isValid() ) {
			return SubmitResult::invalid( $result->errors(), $answers );
		}

		$values = $result->values();

		$email = $this->extractEmail( $schema, $values );
		if ( '' === $email ) {
			return SubmitResult::configError();
		}

		$request = new ReservationRequest(
			$email,
			$this->extractName( $schema, $values, $email ),
			(string) ( $values[ FormSchema::TYPE_FIELD_KEY ] ?? '' ),
			$values,
			$this->extractSelection( $schema, $values )
		);

		$reservation = $this->reservations->reserve( $event_id, $request );

		return match ( $reservation->code ) {
			'reserved'   => SubmitResult::success( 'reserved' ),
			'waitlisted' => SubmitResult::success( 'waitlisted' ),
			'duplicate'  => SubmitResult::duplicate(),
			default      => SubmitResult::rejected(),
		};
	}

	/**
	 * Wyodrębnia surowe odpowiedzi z $_POST wg pól schematu.
	 *
	 * @param FormSchema          $schema Schemat formularza.
	 * @param array<string,mixed> $post   Surowe $_POST.
	 * @return array<string,mixed>
	 */
	private function extractAnswers( FormSchema $schema, array $post ): array {
		$answers = array();
		foreach ( $schema->allFields() as $field ) {
			if ( FieldType::Accommodation === $field->type ) {
				$raw                    = is_array( $post[ $field->key ] ?? null ) ? $post[ $field->key ] : array();
				$slot                   = (string) ( $raw['slot'] ?? '' );
				$parts                  = '' === $slot ? array( '', '' ) : explode( '|', $slot, 2 );
				$answers[ $field->key ] = array(
					'package'  => $parts[0] ?? '',
					'room'     => $parts[1] ?? '',
					'roommate' => (string) ( $raw['roommate'] ?? '' ),
				);
				continue;
			}
			if ( array_key_exists( $field->key, $post ) ) {
				$answers[ $field->key ] = $post[ $field->key ];
			}
		}

		return $answers;
	}

	/**
	 * Znajduje wartość pierwszego widocznego pola typu e-mail.
	 *
	 * @param FormSchema          $schema Schemat formularza.
	 * @param array<string,mixed> $values Znormalizowane wartości z Validatora.
	 */
	private function extractEmail( FormSchema $schema, array $values ): string {
		foreach ( $schema->allFields() as $field ) {
			if ( FieldType::Email === $field->type && isset( $values[ $field->key ] ) ) {
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
