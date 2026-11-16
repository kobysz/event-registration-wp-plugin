<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Accommodation;

use EvReg\Domain\Accommodation\RoomType;
use EvReg\Domain\Schema\SchemaException;
use PHPUnit\Framework\TestCase;

final class RoomTypeOccupancyTest extends TestCase {

	public function test_occupancy_defaults_to_zero(): void {
		$room = RoomType::fromArray( array( 'key' => 'single', 'label' => '1-os.' ) );

		$this->assertSame( 0, $room->occupancy );
	}

	public function test_occupancy_round_trips(): void {
		$room = RoomType::fromArray( array( 'key' => 'double', 'label' => '2-os.', 'occupancy' => 2 ) );

		$this->assertSame( 2, $room->occupancy );
		$this->assertSame( 2, $room->toArray()['occupancy'] );
	}

	public function test_rejects_occupancy_above_two(): void {
		$this->expectException( SchemaException::class );

		RoomType::fromArray( array( 'key' => 'triple', 'label' => '3-os.', 'occupancy' => 3 ) );
	}

	public function test_rejects_negative_occupancy(): void {
		$this->expectException( SchemaException::class );

		RoomType::fromArray( array( 'key' => 'weird', 'label' => 'x', 'occupancy' => -1 ) );
	}

	public function test_seats_delegates_to_room_occupancy(): void {
		$unset  = RoomType::fromArray( array( 'key' => 'a', 'label' => 'a' ) );
		$double = RoomType::fromArray( array( 'key' => 'b', 'label' => 'b', 'occupancy' => 2 ) );

		$this->assertSame( 1, $unset->seats( false ) );
		$this->assertSame( 2, $unset->seats( true ) );
		$this->assertSame( 2, $double->seats( false ) );
	}

	/**
	 * @return array<string,array{0:int,1:bool,2:string|null}>
	 */
	public static function mismatchProvider(): array {
		return array(
			'nieustawione nigdy nie protestuje, solo'    => array( 0, false, null ),
			'nieustawione nigdy nie protestuje, z osobą' => array( 0, true, null ),
			'jednoosobowy solo jest OK'                  => array( 1, false, null ),
			'jednoosobowy z osobą jest błędem'           => array( 1, true, 'companion_not_allowed' ),
			'dwuosobowy z osobą jest OK'                 => array( 2, true, null ),
			'dwuosobowy solo jest błędem'                => array( 2, false, 'companion_required' ),
		);
	}

	/**
	 * @dataProvider mismatchProvider
	 *
	 * @param int         $occupancy Liczba osób w pokoju.
	 * @param bool        $companion Czy zgłoszenie ma osobę towarzyszącą.
	 * @param string|null $expected  Oczekiwany kod błędu albo null.
	 */
	public function test_companion_mismatch( int $occupancy, bool $companion, ?string $expected ): void {
		$room = RoomType::fromArray( array( 'key' => 'r', 'label' => 'r', 'occupancy' => $occupancy ) );

		$this->assertSame( $expected, $room->companionMismatch( $companion ) );
	}
}
