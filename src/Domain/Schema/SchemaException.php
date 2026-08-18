<?php
/**
 * Wyjątek zgłaszany przy naruszeniu integralności schematu formularza.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Schema;

/**
 * Zgłaszany, gdy dane schematu są niespójne lub niekompletne.
 */
final class SchemaException extends \InvalidArgumentException {
}
