<?php

declare( strict_types=1 );

namespace EvReg\Tests\Unit\Domain\Conditions;

use EvReg\Domain\Conditions\Condition;
use EvReg\Domain\Conditions\ConditionEngine;
use EvReg\Domain\Conditions\Operator;
use PHPUnit\Framework\TestCase;

final class ConditionEngineTest extends TestCase {

	private ConditionEngine $engine;

	protected function setUp(): void {
		$this->engine = new ConditionEngine();
	}

	public function test_null_condition_is_always_met(): void {
		$this->assertTrue( $this->engine->isMet( null, array() ) );
	}

	public function test_equals_matches_scalar(): void {
		$condition = new Condition( '__type', Operator::Equals, 'uczestnik' );

		$this->assertTrue( $this->engine->isMet( $condition, array( '__type' => 'uczestnik' ) ) );
		$this->assertFalse( $this->engine->isMet( $condition, array( '__type' => 'wykladowca' ) ) );
	}

	public function test_not_equals_is_true_for_missing_answer(): void {
		$condition = new Condition( '__type', Operator::NotEquals, 'uczestnik' );

		$this->assertTrue( $this->engine->isMet( $condition, array() ) );
	}

	public function test_in_matches_any_listed_value(): void {
		$condition = new Condition( '__type', Operator::In, array( 'uczestnik', 'wykladowca' ) );

		$this->assertTrue( $this->engine->isMet( $condition, array( '__type' => 'wykladowca' ) ) );
		$this->assertFalse( $this->engine->isMet( $condition, array( '__type' => 'online' ) ) );
	}

	public function test_not_in_negates_in(): void {
		$condition = new Condition( '__type', Operator::NotIn, array( 'online' ) );

		$this->assertTrue( $this->engine->isMet( $condition, array( '__type' => 'uczestnik' ) ) );
		$this->assertFalse( $this->engine->isMet( $condition, array( '__type' => 'online' ) ) );
	}

	public function test_in_matches_intersection_for_multi_value_answers(): void {
		$condition = new Condition( 'atrakcje', Operator::In, array( 'kolacja' ) );

		$this->assertTrue(
			$this->engine->isMet( $condition, array( 'atrakcje' => array( 'zwiedzanie', 'kolacja' ) ) )
		);
		$this->assertFalse(
			$this->engine->isMet( $condition, array( 'atrakcje' => array( 'zwiedzanie' ) ) )
		);
	}

	public function test_equals_on_multi_value_requires_exactly_one_match(): void {
		$condition = new Condition( 'atrakcje', Operator::Equals, 'kolacja' );

		$this->assertTrue( $this->engine->isMet( $condition, array( 'atrakcje' => array( 'kolacja' ) ) ) );
		$this->assertFalse(
			$this->engine->isMet( $condition, array( 'atrakcje' => array( 'kolacja', 'zwiedzanie' ) ) )
		);
	}

	public function test_empty_and_not_empty(): void {
		$empty     = new Condition( 'uwagi', Operator::IsEmpty, null );
		$not_empty = new Condition( 'uwagi', Operator::IsNotEmpty, null );

		$this->assertTrue( $this->engine->isMet( $empty, array() ) );
		$this->assertTrue( $this->engine->isMet( $empty, array( 'uwagi' => '' ) ) );
		$this->assertTrue( $this->engine->isMet( $empty, array( 'uwagi' => array() ) ) );
		$this->assertFalse( $this->engine->isMet( $empty, array( 'uwagi' => 'tekst' ) ) );

		$this->assertTrue( $this->engine->isMet( $not_empty, array( 'uwagi' => 'tekst' ) ) );
		$this->assertFalse( $this->engine->isMet( $not_empty, array() ) );
	}
}
