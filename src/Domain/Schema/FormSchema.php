<?php

declare( strict_types=1 );

namespace EvReg\Domain\Schema;

final class FormSchema {

	public const CURRENT_VERSION = 1;

	public const TYPE_FIELD_KEY = '__type';

	/**
	 * @param Section[] $sections
	 */
	private function __construct(
		private readonly array $sections,
		private readonly int $version = self::CURRENT_VERSION
	) {
	}

	public static function empty(): self {
		return new self( array() );
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function fromArray( array $data ): self {
		$sections = array();

		foreach ( (array) ( $data['sections'] ?? array() ) as $section ) {
			$sections[] = Section::fromArray( (array) $section );
		}

		return new self( $sections, (int) ( $data['version'] ?? self::CURRENT_VERSION ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'version'  => $this->version,
			'sections' => array_map(
				static fn ( Section $section ): array => $section->toArray(),
				$this->sections
			),
		);
	}

	/**
	 * @return Section[]
	 */
	public function sections(): array {
		return $this->sections;
	}

	/**
	 * @return Field[]
	 */
	public function allFields(): array {
		$fields = array();

		foreach ( $this->sections as $section ) {
			foreach ( $section->fields as $field ) {
				$fields[] = $field;
			}
		}

		return $fields;
	}

	public function findField( string $key ): ?Field {
		foreach ( $this->allFields() as $field ) {
			if ( $field->key === $key ) {
				return $field;
			}
		}

		return null;
	}

	public function sectionOf( string $fieldKey ): ?Section {
		foreach ( $this->sections as $section ) {
			foreach ( $section->fields as $field ) {
				if ( $field->key === $fieldKey ) {
					return $section;
				}
			}
		}

		return null;
	}
}
