<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Persistence;

use EvReg\Persistence\MailTemplateRepository;
use WP_UnitTestCase;

final class MailTemplateRepositoryTest extends WP_UnitTestCase {

	private MailTemplateRepository $repository;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		$this->repository = new MailTemplateRepository();
		$this->event_id   = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
	}

	public function test_returns_empty_array_when_meta_missing(): void {
		$this->assertSame( array(), $this->repository->get( $this->event_id ) );
	}

	public function test_reads_templates_stored_as_json_string(): void {
		update_post_meta(
			$this->event_id,
			MailTemplateRepository::META_KEY,
			wp_slash( (string) wp_json_encode( array( 'optin' => array( 'subject' => 'Temat', 'body' => "Linia 1\nLinia 2" ) ) ) )
		);

		$templates = $this->repository->get( $this->event_id );

		$this->assertSame( 'Temat', $templates['optin']['subject'] );
		$this->assertSame( "Linia 1\nLinia 2", $templates['optin']['body'] );
	}

	public function test_returns_empty_array_for_broken_json(): void {
		update_post_meta( $this->event_id, MailTemplateRepository::META_KEY, '{nie-json' );

		$this->assertSame( array(), $this->repository->get( $this->event_id ) );
	}

	public function test_reads_templates_stored_as_array(): void {
		update_post_meta( $this->event_id, MailTemplateRepository::META_KEY, array( 'optin' => array( 'subject' => 'Z tablicy' ) ) );

		$this->assertSame( 'Z tablicy', $this->repository->get( $this->event_id )['optin']['subject'] );
	}
}
