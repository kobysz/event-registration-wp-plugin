<?php

declare( strict_types=1 );

namespace EvReg\Domain\Validation;

final class FieldOutcome {

	private function __construct(
		public readonly mixed $value,
		public readonly ?string $errorCode
	) {
	}

	public static function valid( mixed $value ): self {
		return new self( $value, null );
	}

	public static function error( string $code ): self {
		return new self( null, $code );
	}

	public function isValid(): bool {
		return null === $this->errorCode;
	}
}
