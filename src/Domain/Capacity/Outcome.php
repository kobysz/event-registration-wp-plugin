<?php
/**
 * Możliwe wyniki oceny pojemnościowej zgłoszenia.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Capacity;

enum Outcome: string {
	case Accepted   = 'accepted';
	case Waitlisted = 'waitlisted';
	case Rejected   = 'rejected';
}
