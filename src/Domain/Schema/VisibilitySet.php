<?php

declare( strict_types=1 );

namespace EvReg\Domain\Schema;

final class VisibilitySet {

	/**
	 * @param string[] $visibleSections
	 * @param Field[]  $visibleFields
	 */
	public function __construct(
		private readonly array $visibleSections,
		private readonly array $visibleFields
	) {
	}

	public function isSectionVisible( string $key ): bool {
		return in_array( $key, $this->visibleSections, true );
	}

	public function isFieldVisible( string $key ): bool {
		foreach ( $this->visibleFields as $field ) {
			if ( $field->key === $key ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return Field[]
	 */
	public function visibleFields(): array {
		return $this->visibleFields;
	}
}
