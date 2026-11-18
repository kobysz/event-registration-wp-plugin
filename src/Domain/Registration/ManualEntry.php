<?php
/**
 * Znacznik zgłoszenia wprowadzonego ręcznie przez organizatora.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Registration;

/**
 * Opisuje, że zgłoszenie dodaje organizator w adminie (np. po rozmowie telefonicznej),
 * a nie uczestnik przez formularz publiczny.
 */
final class ManualEntry {

	/**
	 * Tworzy znacznik wpisu ręcznego.
	 *
	 * @param bool $notify Czy uczestnik ma dostać maile cyklu życia (domyślnie nie).
	 */
	public function __construct( public bool $notify = false ) {
	}
}
