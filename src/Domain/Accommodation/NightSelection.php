<?php
/**
 * Znormalizowany zbiór wybranych dób.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Domain\Accommodation;

/**
 * Zbiór wybranych dób: unikalne klucze, w kolejności konfiguracji, bez dób
 * nieznanych konfiguracji.
 */
final class NightSelection implements \JsonSerializable {

	/**
	 * Tworzy zbiór z gotowej listy kluczy.
	 *
	 * @param string[] $keys Klucze dób.
	 */
	private function __construct( private readonly array $keys ) {
	}

	/**
	 * Normalizuje surowe klucze względem konfiguracji.
	 *
	 * @param array<int|string,mixed> $keys   Surowe klucze dób.
	 * @param NightsConfig            $config Konfiguracja trybu „doby”.
	 */
	public static function fromKeys( array $keys, NightsConfig $config ): self {
		$wanted = array_map( 'strval', array_values( array_filter( $keys, 'is_scalar' ) ) );
		$result = array();

		foreach ( $config->nights() as $night ) {
			if ( in_array( $night->key, $wanted, true ) ) {
				$result[] = $night->key;
			}
		}

		return new self( $result );
	}

	/**
	 * Zwraca klucze wybranych dób jako listę.
	 *
	 * @return string[]
	 */
	public function keys(): array {
		return array_values( $this->keys );
	}

	/**
	 * Czy nie wybrano żadnej doby.
	 */
	public function isEmpty(): bool {
		return array() === $this->keys;
	}

	/**
	 * Serializuje wybór jako PŁASKĄ LISTĘ kluczy — nigdy jako obiekt.
	 *
	 * Kształt jest tu, a nie tylko u wywołującego, żeby nie dało się go pomylić:
	 * bez tego instancja trafiona przez wp_json_encode zakodowałaby się jako `{}`
	 * (wszystkie właściwości są prywatne), czyli po cichu bez danych. Repo zapłaciło
	 * już za dokładnie ten błąd (B15 na ścieżce klasycznej).
	 *
	 * @return string[]
	 */
	public function jsonSerialize(): array {
		return $this->keys();
	}
}
