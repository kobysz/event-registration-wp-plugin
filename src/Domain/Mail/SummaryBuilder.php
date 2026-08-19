<?php
/**
 * Budowa tekstowego podsumowania odpowiedzi zgłoszenia.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Mail;

use EvReg\Domain\Schema\Field;
use EvReg\Domain\Schema\FieldType;
use EvReg\Domain\Schema\FormSchema;
use EvReg\Domain\Schema\VisibilityResolver;

/**
 * Budowa tekstowego podsumowania odpowiedzi zgłoszenia.
 *
 * Etykieta „tak” jest wstrzykiwana, bo domena nie tłumaczy stringów.
 */
final class SummaryBuilder {

	/**
	 * Tworzy builder.
	 *
	 * @param VisibilityResolver $resolver Rozstrzyganie widoczności pól.
	 * @param string             $yesLabel Etykieta dla zaznaczonego pola typu checkbox.
	 */
	public function __construct(
		private readonly VisibilityResolver $resolver,
		private readonly string $yesLabel
	) {
	}

	/**
	 * Buduje podsumowanie widocznych, niepustych odpowiedzi.
	 *
	 * @param FormSchema          $schema  Złożona schema formularza.
	 * @param array<string,mixed> $answers Odpowiedzi po walidacji.
	 */
	public function build( FormSchema $schema, array $answers ): string {
		$lines = array();

		foreach ( $this->resolver->resolve( $schema, $answers )->visibleFields() as $field ) {
			if ( ! $this->isReportable( $field ) ) {
				continue;
			}

			$value = $this->format( $field, $answers[ $field->key ] ?? null );

			if ( '' === $value ) {
				continue;
			}

			$lines[] = $field->label . ': ' . $value;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Czy pole w ogóle trafia do podsumowania.
	 *
	 * @param Field $field Pole schemy.
	 */
	private function isReportable( Field $field ): bool {
		if ( FormSchema::TYPE_FIELD_KEY === $field->key ) {
			return false;
		}

		if ( FieldType::Accommodation === $field->type ) {
			return false;
		}

		return $field->type->isInput();
	}

	/**
	 * Formatuje wartość odpowiedzi do jednej linii tekstu.
	 *
	 * @param Field $field Pole schemy.
	 * @param mixed $value Wartość odpowiedzi.
	 */
	private function format( Field $field, mixed $value ): string {
		if ( null === $value || false === $value ) {
			return '';
		}

		if ( true === $value ) {
			return $this->yesLabel;
		}

		if ( is_array( $value ) ) {
			$labels = array();

			foreach ( $value as $item ) {
				$label = $this->label( $field, (string) $item );

				if ( '' !== $label ) {
					$labels[] = $label;
				}
			}

			return implode( ', ', $labels );
		}

		if ( is_scalar( $value ) ) {
			return $this->label( $field, (string) $value );
		}

		return '';
	}

	/**
	 * Zamienia wartość na etykietę opcji, gdy pole ma opcje.
	 *
	 * @param Field  $field Pole schemy.
	 * @param string $value Surowa wartość odpowiedzi.
	 */
	private function label( Field $field, string $value ): string {
		if ( ! $field->type->hasOptions() ) {
			return trim( $value );
		}

		foreach ( $field->options as $option ) {
			if ( $option->value === $value ) {
				return $option->label;
			}
		}

		return trim( $value );
	}
}
