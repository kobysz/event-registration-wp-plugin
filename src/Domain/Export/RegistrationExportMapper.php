<?php
/**
 * Mapowanie danych rejestracji do kolumn i wartości CSV.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Export;

use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Schema\FieldType;
use EvReg\Domain\Schema\FormSchema;

/**
 * Mapuje schemat formularza na kolumny eksportu i dane rejestracji na wartości komórek.
 */
final class RegistrationExportMapper {
	/**
	 * Uporządkowane kolumny odpowiedzi: pola input (pomija Heading/Paragraph), nagłówek = label pola.
	 * Pomija także pola __type i accommodation (mają dedykowane kolumny w eksporcie).
	 *
	 * @param FormSchema $schema Schemat formularza.
	 * @return array<int,array{key:string,label:string}>
	 */
	public function answerColumns( FormSchema $schema ): array {
		$cols = array();
		foreach ( $schema->allFields() as $field ) {
			if ( ! $field->type->isInput() ) {
				continue;
			}
			// Pomija __type i accommodation — mają dedykowane kolumny.
			if ( FormSchema::TYPE_FIELD_KEY === $field->key || FieldType::Accommodation === $field->type ) {
				continue;
			}
			$cols[] = array(
				'key'   => $field->key,
				'label' => '' !== $field->shortLabel ? $field->shortLabel : $field->label,
			);
		}
		return $cols;
	}

	/**
	 * Wartości komórek odpowiedzi w kolejności answerColumns; multi-value → implode(', '); brak/null → ''.
	 *
	 * @param array<string,mixed> $data   Odebrane odpowiedzi (data JSON zdekodowane).
	 * @param FormSchema          $schema Schemat formularza.
	 * @return array<int,string>
	 */
	public function answerCells( array $data, FormSchema $schema ): array {
		$cells = array();
		foreach ( $this->answerColumns( $schema ) as $col ) {
			$value = $data[ $col['key'] ] ?? null;
			if ( is_array( $value ) ) {
				$cells[] = implode( ', ', array_map( 'strval', $value ) );
			} elseif ( null === $value ) {
				$cells[] = '';
			} else {
				$cells[] = (string) $value;
			}
		}
		return $cells;
	}

	/**
	 * Labele noclegu z wiersza bookingu (fallback surowy klucz gdy nieznany).
	 *
	 * @param array<string,mixed>|null $booking Wiersz evreg_accommodation_bookings albo null.
	 * @param AccommodationConfig      $config  Konfiguracja zakwaterowania.
	 * @return array{package:string,room:string,roommate:string}
	 */
	public function accommodationCells( ?array $booking, AccommodationConfig $config ): array {
		if ( null === $booking ) {
			return array(
				'package'  => '',
				'room'     => '',
				'roommate' => '',
			);
		}
		$package_key   = (string) ( $booking['package_key'] ?? '' );
		$room_key      = (string) ( $booking['room_type_key'] ?? '' );
		$package_label = (string) ( $booking['package_label'] ?? '' );
		$room_label    = (string) ( $booking['room_label'] ?? '' );

		// Kolejność: snapshot z chwili rezerwacji → aktualna konfiguracja → surowy klucz.
		// Snapshot utrzymuje czytelność historii, gdy pakiet/pokój zmieni klucz albo zniknie.
		return array(
			'package'  => '' !== $package_label ? $package_label : $config->packageLabel( $package_key ),
			'room'     => '' !== $room_label ? $room_label : $config->roomLabel( $room_key ),
			'roommate' => (string) ( $booking['roommate_pref'] ?? '' ),
		);
	}
}
