<?php
/**
 * Stan zgłoszenia w cyklu życia rezerwacji.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Registration;

/**
 * Stan zgłoszenia w cyklu życia rezerwacji.
 */
enum RegistrationStatus: string {
	case Pending   = 'pending';
	case Confirmed = 'confirmed';
	case Waitlist  = 'waitlist';
	case Cancelled = 'cancelled';

	/**
	 * Czy ten status blokuje miejsce (liczy się do zajętości).
	 */
	public function occupiesSeat(): bool {
		return in_array( $this, array( self::Pending, self::Confirmed ), true );
	}

	/**
	 * Wartości statusów blokujących miejsce — do zapytań COUNT.
	 *
	 * @return string[]
	 */
	public static function occupyingValues(): array {
		return array_values(
			array_map(
				static fn ( self $status ): string => $status->value,
				array_filter( self::cases(), static fn ( self $status ): bool => $status->occupiesSeat() )
			)
		);
	}
}
