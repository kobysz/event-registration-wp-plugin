<?php

declare( strict_types=1 );

namespace EvReg\Domain\Schema;

use EvReg\Domain\Conditions\ConditionEngine;

final class VisibilityResolver {

	public function __construct( private readonly ConditionEngine $engine ) {
	}

	/**
	 * @param array<string,mixed> $answers
	 */
	public function resolve( FormSchema $schema, array $answers ): VisibilitySet {
		$sections = array();
		$fields   = array();

		foreach ( $schema->sections() as $section ) {
			if ( ! $this->engine->isMet( $section->condition, $answers ) ) {
				continue;
			}

			$sections[] = $section->key;

			foreach ( $section->fields as $field ) {
				if ( ! $this->engine->isMet( $field->condition, $answers ) ) {
					continue;
				}

				$fields[] = $field;
			}
		}

		return new VisibilitySet( $sections, $fields );
	}
}
