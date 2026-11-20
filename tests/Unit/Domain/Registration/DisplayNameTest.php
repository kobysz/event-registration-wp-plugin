<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Registration;

use EvReg\Domain\Registration\DisplayName;
use PHPUnit\Framework\TestCase;

final class DisplayNameTest extends TestCase {

	public function test_joins_given_name_and_surname(): void {
		$answers = array(
			'imie'     => 'Jan',
			'nazwisko' => 'Kowalski',
		);

		$this->assertSame( 'Jan Kowalski', DisplayName::compose( $answers, 'Jan' ) );
	}

	/**
	 * Kolejność bierze się z klasyfikacji, nie z kolejności pól — zmiana układu
	 * formularza nie może dać „Kowalski Jan”.
	 */
	public function test_surname_never_precedes_given_name(): void {
		$answers = array(
			'nazwisko' => 'Kowalski',
			'imie'     => 'Jan',
		);

		$this->assertSame( 'Jan Kowalski', DisplayName::compose( $answers, 'Jan' ) );
	}

	public function test_matches_english_keys(): void {
		$answers = array(
			'first_name' => 'Jane',
			'last_name'  => 'Doe',
		);

		$this->assertSame( 'Jane Doe', DisplayName::compose( $answers, 'Jane' ) );
	}

	public function test_matches_suffixed_keys(): void {
		$answers = array(
			'imie_uczestnika'     => 'Jan',
			'nazwisko_uczestnika' => 'Kowalski',
		);

		$this->assertSame( 'Jan Kowalski', DisplayName::compose( $answers, 'Jan' ) );
	}

	/**
	 * Dopasowanie idzie po PIERWSZYM członie klucza, więc pole o nazwie firmy
	 * nie dokleja się do nazwy zgłaszającego się.
	 */
	public function test_ignores_keys_whose_first_token_is_not_a_name_part(): void {
		$answers = array(
			'imie'         => 'Jan',
			'company_name' => 'ACME',
			'nazwa_firmy'  => 'ACME',
		);

		$this->assertSame( 'Jan', DisplayName::compose( $answers, 'Jan' ) );
	}

	public function test_falls_back_to_stored_name_when_nothing_matches(): void {
		$answers = array(
			'uczestnik' => 'Jan Kowalski',
			'__type'    => 'std',
		);

		$this->assertSame( 'Jan Kowalski', DisplayName::compose( $answers, 'Jan Kowalski' ) );
	}

	public function test_falls_back_on_empty_answers(): void {
		$this->assertSame( 'Jan', DisplayName::compose( array(), 'Jan' ) );
	}

	/**
	 * Imię i nazwisko osoby towarzyszącej siedzi w odpowiedziach pod kontrolką
	 * evreg_companion_name — nie wolno go wziąć za nazwę zgłaszającego się.
	 */
	public function test_ignores_companion_name_control(): void {
		$answers = array(
			'imie'                 => 'Jan',
			'nazwisko'             => 'Kowalski',
			'evreg_companion'      => true,
			'evreg_companion_name' => 'Anna Nowak',
		);

		$this->assertSame( 'Jan Kowalski', DisplayName::compose( $answers, 'Jan' ) );
	}

	public function test_skips_empty_and_non_scalar_values(): void {
		$answers = array(
			'imie'     => 'Jan',
			'nazwisko' => '   ',
			'name'     => array( 'a', 'b' ),
		);

		$this->assertSame( 'Jan', DisplayName::compose( $answers, 'x' ) );
	}

	public function test_trims_and_collapses_whitespace(): void {
		$answers = array(
			'imie'     => '  Jan ',
			'nazwisko' => ' Kowalski  ',
		);

		$this->assertSame( 'Jan Kowalski', DisplayName::compose( $answers, 'x' ) );
	}

	/**
	 * Formularz z polem `name` ORAZ `imie` o tej samej treści nie może dać „Jan Jan”.
	 */
	public function test_deduplicates_identical_parts(): void {
		$answers = array(
			'name'     => 'Jan',
			'imie'     => 'jan',
			'nazwisko' => 'Kowalski',
		);

		$this->assertSame( 'Jan Kowalski', DisplayName::compose( $answers, 'x' ) );
	}
}
