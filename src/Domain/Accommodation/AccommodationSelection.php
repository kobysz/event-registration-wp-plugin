<?php

declare( strict_types=1 );

namespace EvReg\Domain\Accommodation;

final class AccommodationSelection {

	public function __construct(
		public readonly string $packageKey,
		public readonly string $roomKey,
		public readonly string $roommatePref = ''
	) {
	}

	/**
	 * Zwraca null dla pustego wyboru.
	 *
	 * @param array<string,mixed> $data
	 */
	public static function fromArray( array $data ): ?self {
		$package = trim( (string) ( $data['package'] ?? '' ) );
		$room    = trim( (string) ( $data['room'] ?? '' ) );

		if ( '' === $package || '' === $room ) {
			return null;
		}

		return new self( $package, $room, trim( (string) ( $data['roommate'] ?? '' ) ) );
	}

	public function slotKey(): string {
		return $this->packageKey . '|' . $this->roomKey;
	}

	/**
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
