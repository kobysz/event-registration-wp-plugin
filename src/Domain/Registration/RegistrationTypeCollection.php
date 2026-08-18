<?php

declare( strict_types=1 );

namespace EvReg\Domain\Registration;

use EvReg\Domain\Schema\SchemaException;

final class RegistrationTypeCollection {

	/**
	 * @param RegistrationType[] $types
	 */
	private function __construct( private readonly array $types ) {
	}

	/**
	 * @param array<int,array<string,mixed>> $list
	 */
	public static function fromArray( array $list ): self {
		$types = array();
		$keys  = array();

		foreach ( $list as $item ) {
			$type = RegistrationType::fromArray( (array) $item );

			if ( in_array( $type->key, $keys, true ) ) {
				throw new SchemaException( sprintf( 'Zduplikowany typ zgłoszenia: "%s".', $type->key ) );
			}

			$keys[]  = $type->key;
			$types[] = $type;
		}

		return new self( $types );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function toArray(): array {
		return array_map(
			static fn ( RegistrationType $type ): array => $type->toArray(),
			$this->types
		);
	}

	/**
	 * @return RegistrationType[]
	 */
	public function all(): array {
		return $this->types;
	}

	/**
	 * @return RegistrationType[]
	 */
	public function active(): array {
		return array_values(
			array_filter( $this->types, static fn ( RegistrationType $type ): bool => $type->active )
		);
	}

	public function get( string $key ): ?RegistrationType {
		foreach ( $this->types as $type ) {
			if ( $type->key === $key ) {
				return $type;
			}
		}

		return null;
	}

	public function has( string $key ): bool {
		return null !== $this->get( $key );
	}

	/**
	 * @return array<string,int|null>
	 */
	public function capacities(): array {
		$map = array();

		foreach ( $this->types as $type ) {
			$map[ $type->key ] = $type->capacity;
		}

		return $map;
	}
}
