<?php
/**
 * Stan dostępności wydarzenia jako całości.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Capacity;

enum EventAvailabilityState: string {
	case Open     = 'open';
	case Waitlist = 'waitlist';
	case Closed   = 'closed';
}
