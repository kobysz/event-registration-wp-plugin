<?php
/**
 * Operatory dostępne w warunkach widoczności schematu.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Conditions;

/**
 * Wspierane operatory porównania odpowiedzi z oczekiwaną wartością.
 */
enum Operator: string {
	case Equals     = 'equals';
	case NotEquals  = 'not_equals';
	case In         = 'in';
	case NotIn      = 'not_in';
	case IsEmpty    = 'empty';
	case IsNotEmpty = 'not_empty';

	/** Czy operator porównuje z wartością. */
	public function needsValue(): bool {
		return ! in_array( $this, array( self::IsEmpty, self::IsNotEmpty ), true );
	}
}
