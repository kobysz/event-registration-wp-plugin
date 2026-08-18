<?php
/**
 * Wybór zakwaterowania dokonany przez zgłaszającego się.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Accommodation;

/**
 * Konkretny wybór pakietu i pokoju z odpowiedzi formularza.
 */
final class AccommodationSelection {

	/**
	 * Tworzy wybór zakwaterowania z kluczy pakietu i pokoju.
	 *
	 * @param string $packageKey   Klucz wybranego pakietu.
	 * @param string $roomKey      Klucz wybranego typu pokoju.
	 * @param string $roommatePref Opcjonalna preferencja współlokatora.
	 */
	public function __construct(
		public readonly string $packageKey,
		public readonly string $roomKey,
		public readonly string $roommatePref = ''
	) {
	}

	/**
	 * Zwraca null dla pustego wyboru.
	 *
	 * @param array<string,mixed> $data Surowe dane wyboru z odpowiedzi formularza.
	 */
	public static function fromArray( array $data ): ?self {
		$package = trim( (string) ( $data['package'] ?? '' ) );
		$room    = trim( (string) ( $data['room'] ?? '' ) );

		if ( '' === $package || '' === $room ) {
			return null;
		}

		return new self( $package, $room, trim( (string) ( $data['roommate'] ?? '' ) ) );
	}

	/**
	 * Zwraca klucz identyfikujący slot pakiet/pokój.
	 */
	public function slotKey(): string {
		return $this->packageKey . '|' . $this->roomKey;
	}

	/**
	 * Serializuje wybór do tablicy.
	 *
	 * @return array<string,string>
	 */
	public function toArray(): array {
		return array(
			'package'  => $this->packageKey,
			'room'     => $this->roomKey,
			'roommate' => $this->roommatePref,
		);
	}
}
