<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Accommodation;

use EvReg\Domain\Accommodation\RoomOccupancy;
use PHPUnit\Framework\TestCase;

final class RoomOccupancyTest extends TestCase {

	/**
	 * @return array<string,array{0:int,1:bool,2:int}>
	 */
	public static function seatsProvider(): array {
		return array(
			'nieustawione, solo'       => array( 0, false, 1 ),
			'nieustawione, z osobą'    => array( 0, true, 2 ),
			'jednoosobowy, solo'       => array( 1, false, 1 ),
			'jednoosobowy, z osobą'    => array( 1, true, 1 ),
			'dwuosobowy, solo'         => array( 2, false, 2 ),
			'dwuosobowy, z osobą'      => array( 2, true, 2 ),
		);
	}

	/**
	 * @dataProvider seatsProvider
	 *
	 * @param int  $occupancy Liczba osób w pokoju.
	 * @param bool $companion Czy zgłoszenie ma osobę towarzyszącą.
	 * @param int  $expected  Oczekiwana liczba miejsc.
	 */
	public function test_seats( int $occupancy, bool $companion, int $expected ): void {
		$this->assertSame( $expected, RoomOccupancy::seats( $occupancy, $companion ) );
	}
}
