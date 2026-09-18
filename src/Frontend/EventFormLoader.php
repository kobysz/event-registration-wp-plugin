<?php
/**
 * Ładuje i składa kompletną FormSchema eventu do renderowania i walidacji.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Frontend;

use EvReg\Domain\Schema\ContentTranslator;
use EvReg\Domain\Schema\FormSchema;
use EvReg\Domain\Schema\SchemaAssembler;
use EvReg\Domain\Schema\SchemaException;
use EvReg\Persistence\EventConfigRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Ładuje i składa kompletną FormSchema eventu do renderowania i walidacji.
 */
final class EventFormLoader {

	/**
	 * Składacz konfiguracji w kompletną FormSchema.
	 *
	 * @var SchemaAssembler
	 */
	private SchemaAssembler $assembler;

	/**
	 * Tworzy loader z wstrzykniętym repozytorium konfiguracji.
	 *
	 * @param EventConfigRepository $config Repozytorium konfiguracji eventu.
	 */
	public function __construct( private readonly EventConfigRepository $config ) {
		$this->assembler = new SchemaAssembler();
	}

	/**
	 * Zwraca złożoną schemę eventu lub null, gdy brak/niepoprawna.
	 *
	 * @param int $event_id ID posta eventu.
	 */
	public function load( int $event_id ): ?FormSchema {
		$config = $this->config->get( $event_id );
		$schema = is_array( $config['schema'] ) ? $config['schema'] : array();

		if ( array() === $schema || empty( $schema['sections'] ) ) {
			return null;
		}

		$types              = is_array( $config['types'] ) ? $config['types'] : array();
		[ $schema, $types ] = ( new ContentTranslator() )->apply(
			$schema,
			$types,
			$this->config->getI18n( $event_id ),
			CurrentLanguage::get()
		);

		try {
			return $this->assembler->assemble(
				$schema,
				$types,
				is_array( $config['accommodation'] ) ? $config['accommodation'] : array()
			);
		} catch ( SchemaException $e ) {
			return null;
		}
	}
}
