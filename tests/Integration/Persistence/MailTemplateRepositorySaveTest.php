<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Persistence;

use EvReg\Persistence\MailTemplateRepository;
use WP_UnitTestCase;

final class MailTemplateRepositorySaveTest extends WP_UnitTestCase {

	private MailTemplateRepository $repository;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		$this->repository = new MailTemplateRepository();
		$this->event_id   = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
	}

	public function test_save_then_get_round_trips(): void {
		$this->repository->save(
			$this->event_id,
			array( 'optin' => array( 'subject' => 'Temat', 'body' => "Linia 1\nLinia 2" ) )
		);

		$templates = $this->repository->get( $this->event_id );

		$this->assertSame( 'Temat', $templates['optin']['subject'] );
		$this->assertSame( "Linia 1\nLinia 2", $templates['optin']['body'] );
	}

	public function test_save_preserves_non_ascii_and_newlines(): void {
		$body = "Cześć Michał,\n\npozdrawiamy – zespół.";
		$this->repository->save( $this->event_id, array( 'confirmed' => array( 'subject' => 'Zażółć', 'body' => $body ) ) );

		$templates = $this->repository->get( $this->event_id );

		$this->assertSame( 'Zażółć', $templates['confirmed']['subject'] );
		$this->assertSame( $body, $templates['confirmed']['body'] );
	}

	public function test_save_drops_empty_fields_and_types(): void {
		$this->repository->save(
			$this->event_id,
			array(
				'optin'     => array( 'subject' => 'Tylko temat', 'body' => '' ),
				'confirmed' => array( 'subject' => '', 'body' => '' ),
			)
		);

		$templates = $this->repository->get( $this->event_id );

		$this->assertSame( array( 'subject' => 'Tylko temat' ), $templates['optin'] );
		$this->assertArrayNotHasKey( 'confirmed', $templates );
	}

	public function test_save_empty_result_deletes_meta(): void {
		$this->repository->save( $this->event_id, array( 'optin' => array( 'subject' => 'X', 'body' => '' ) ) );
		$this->repository->save( $this->event_id, array( 'optin' => array( 'subject' => '', 'body' => '' ) ) );

		$this->assertSame( array(), $this->repository->get( $this->event_id ) );
		$this->assertSame( '', get_post_meta( $this->event_id, MailTemplateRepository::META_KEY, true ) );
	}

	public function test_save_ignores_non_string_fields(): void {
		// Celowo brudne wejście — save() musi odsiać nie-łańcuchowe pola i nie-łańcuchowe klucze.
		// (PHPStan skanuje tylko src/, nie tests/ — brak potrzeby ignore.)
		$this->repository->save(
			$this->event_id,
			array( 'optin' => array( 'subject' => 'OK', 'body' => 123 ), 5 => array( 'subject' => 'zły klucz' ) )
		);

		$templates = $this->repository->get( $this->event_id );

		$this->assertSame( array( 'subject' => 'OK' ), $templates['optin'] );
		$this->assertArrayNotHasKey( 5, $templates );
	}
}
