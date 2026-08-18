<?php

declare( strict_types=1 );

namespace EvReg\Domain\Conditions;

final class ConditionEngine {

	/**
	 * @param array<string,mixed> $answers
	 */
	public function isMet( ?Condition $condition, array $answers ): bool {
		if ( null === $condition ) {
			return true;
		}

		$answer = $answers[ $condition->field ] ?? '';

		return match ( $condition->operator ) {
			Operator::Equals     => $this->equals( $answer, $condition->value ),
			Operator::NotEquals  => ! $this->equals( $answer, $condition->value ),
			Operator::In         => $this->intersects( $answer, $condition->value ),
			Operator::NotIn      => ! $this->intersects( $answer, $condition->value ),
			Operator::IsEmpty    => $this->isEmpty( $answer ),
			Operator::IsNotEmpty => ! $this->isEmpty( $answer ),
		};
	}

	private function equals( mixed $answer, array|string|null $expected ): bool {
		$expected_list = $this->toList( $expected );
		$answer_list   = $this->toList( $answer );

		return $answer_list === $expected_list;
	}

	private function intersects( mixed $answer, array|string|null $expected ): bool {
		$expected_list = $this->toList( $expected );
		$answer_list   = $this->toList( $answer );

		return array() !== array_intersect( $answer_list, $expected_list );
	}

	private function isEmpty( mixed $answer ): bool {
		if ( is_array( $answer ) ) {
			return array() === $answer;
		}

		return null === $answer || '' === (string) $answer;
	}

	/**
	 * @return string[]
	 */
	private function toList( mixed $value ): array {
		if ( null === $value ) {
			return array();
		}

		if ( is_array( $value ) ) {
			return array_values( array_map( static fn ( $item ): string => (string) $item, $value ) );
		}

		if ( '' === (string) $value ) {
			return array();
		}

		return array( (string) $value );
	}
}
