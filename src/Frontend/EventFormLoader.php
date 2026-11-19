<?php
/**
 * Ładuje i składa kompletną FormSchema eventu do renderowania i walidacji.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Frontend;

use EvReg\Domain\Accommodation\NightsConfig;
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
		$parts = $this->translated( $event_id );

		if ( array() === $parts['schema'] || empty( $parts['schema']['sections'] ) ) {
			return null;
		}

		try {
			return $this->assembler->assemble(
				$parts['schema'],
				$parts['types'],
				$parts['accommodation']
			);
		} catch ( SchemaException $e ) {
			return null;
		}
	}

	/**
	 * Zwraca konfigurację trybu „doby” eventu albo null dla trybu klasycznego.
	 *
	 * Tryb jest właściwością EVENTU, nie schematu, więc odczyt nie zależy ani od tego, czy
	 * schemat się złoży, ani od tego, czy niesie pole noclegu — organizator może je usunąć
	 * z formularza, a zgłoszenia dalej trzymają wiersze dób ({@see NightsResolver}).
	 *
	 * Konfiguracja idzie przez overlay językowy, tą samą drogą co schemat: etykiety dób są
	 * tłumaczalne (B6), a formularz publiczny jest ich odbiorcą. Dlatego tryb rozstrzyga się
	 * TUTAJ, a nie ze świeżego odczytu surowego meta — ten zgubiłby tłumaczenie po cichu.
	 *
	 * Null NIGDY nie znaczy „niepoprawna” — ta rzuca, a wejścia (Shortcode, SubmitHandler,
	 * panel zgłoszeń) łapią i degradują do komunikatu.
	 *
	 * @param int $event_id ID posta eventu.
	 *
	 * @throws SchemaException Gdy konfiguracja dób jest niepoprawna.
	 */
	public function nights( int $event_id ): ?NightsConfig {
		return NightsResolver::forAccommodation( $this->translated( $event_id )['accommodation'] );
	}

	/**
	 * Zwraca ustawienia eventu z nałożonym overlayem komunikatów bieżącego języka.
	 *
	 * @param int $event_id ID posta eventu.
	 *
	 * @return array<string,mixed>
	 */
	public function settings( int $event_id ): array {
		$config   = $this->config->get( $event_id );
		$settings = is_array( $config['settings'] ) ? $config['settings'] : array();

		return ( new ContentTranslator() )->translateNotices( $settings, $this->config->getI18n( $event_id ), CurrentLanguage::get() );
	}

	/**
	 * Zwraca konfigurację eventu z nałożonym overlayem językowym — w kształcie, w jakim
	 * trafia do SchemaAssembler. Jedno miejsce, w którym powstaje przetłumaczona konfiguracja.
	 *
	 * @param int $event_id ID posta eventu.
	 *
	 * @return array{schema:array<string,mixed>,types:array<int,array<string,mixed>>,accommodation:array<string,mixed>}
	 */
	private function translated( int $event_id ): array {
		$config             = $this->config->get( $event_id );
		$schema             = is_array( $config['schema'] ) ? $config['schema'] : array();
		$types              = is_array( $config['types'] ) ? $config['types'] : array();
		$accommodation      = is_array( $config['accommodation'] ) ? $config['accommodation'] : array();
		$overlay            = $this->config->getI18n( $event_id );
		$lang               = CurrentLanguage::get();
		$translator         = new ContentTranslator();
		[ $schema, $types ] = $translator->apply( $schema, $types, $overlay, $lang );

		return array(
			'schema'        => $schema,
			'types'         => $types,
			'accommodation' => $translator->translateAccommodation( $accommodation, $overlay, $lang ),
		);
	}
}
