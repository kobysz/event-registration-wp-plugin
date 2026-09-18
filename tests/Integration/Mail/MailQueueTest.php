<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Mail;

use EvReg\Domain\Mail\Placeholders;
use EvReg\Domain\Mail\TemplateRenderer;
use EvReg\Mail\DefaultTemplates;
use EvReg\Mail\MailQueue;
use EvReg\Mail\TemplateResolver;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\MailQueueRepository;
use EvReg\Persistence\MailTemplateRepository;
use EvReg\Persistence\Migrations;
use WP_UnitTestCase;

final class MailQueueTest extends WP_UnitTestCase {

	private MailQueue $mail_queue;

	private MailQueueRepository $repository;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( 'mail_queue' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$this->repository = new MailQueueRepository();
		$this->mail_queue = new MailQueue(
			$this->repository,
			new TemplateResolver( new MailTemplateRepository(), new EventConfigRepository() ),
			new TemplateRenderer()
		);
		$this->event_id   = self::factory()->post->create(
			array(
				'post_type'  => 'evreg_event',
				'post_title' => 'Zjazd 2026',
			)
		);

		wp_clear_scheduled_hook( MailQueue::DISPATCH_HOOK );
	}

	private function values(): Placeholders {
		return new Placeholders(
			array(
				'imie'               => 'Jan',
				'email'              => 'jan@example.com',
				'event'              => 'Zjazd 2026',
				'typ'                => 'Uczestnik',
				'nocleg'             => '',
				'link_potwierdzenia' => 'https://example.org/?evreg_confirm=abc',
				'podsumowanie'       => 'Imię i nazwisko: Jan',
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function only_row(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( 'SELECT * FROM ' . Migrations::table( 'mail_queue' ), ARRAY_A );

		$this->assertCount( 1, $rows );

		return $rows[0];
	}

	public function test_enqueue_stores_rendered_snapshot(): void {
		$this->assertTrue(
			$this->mail_queue->enqueue( DefaultTemplates::KEY_OPTIN, $this->event_id, 7, 'jan@example.com', $this->values() )
		);

		$row = $this->only_row();

		$this->assertStringContainsString( 'Zjazd 2026', $row['subject'] );
		$this->assertStringContainsString( 'https://example.org/?evreg_confirm=abc', $row['body'] );
		$this->assertStringContainsString( 'Imię i nazwisko: Jan', $row['body'] );
		$this->assertStringNotContainsString( '{imie}', $row['body'] );
		$this->assertSame( MailQueueRepository::STATUS_QUEUED, $row['status'] );
	}

	public function test_enqueue_is_idempotent_per_registration_and_template(): void {
		$this->assertTrue( $this->mail_queue->enqueue( DefaultTemplates::KEY_OPTIN, $this->event_id, 7, 'jan@example.com', $this->values() ) );
		$this->assertFalse( $this->mail_queue->enqueue( DefaultTemplates::KEY_OPTIN, $this->event_id, 7, 'jan@example.com', $this->values() ) );

		$this->only_row();
	}

	public function test_enqueue_stores_headers_as_json(): void {
		$this->mail_queue->enqueue(
			DefaultTemplates::KEY_ADMIN_NEW,
			$this->event_id,
			7,
			'organizator@example.com',
			$this->values(),
			array( 'Reply-To: jan@example.com' )
		);

		$this->assertSame( array( 'Reply-To: jan@example.com' ), json_decode( (string) $this->only_row()['headers'], true ) );
	}

	public function test_enqueue_rejects_invalid_recipient(): void {
		$this->assertFalse( $this->mail_queue->enqueue( DefaultTemplates::KEY_OPTIN, $this->event_id, 7, 'nie-adres', $this->values() ) );
		$this->assertSame( array(), $this->repository->due( '2099-01-01 00:00:00', 10 ) );
	}

	public function test_enqueue_rejects_unknown_template(): void {
		$this->assertFalse( $this->mail_queue->enqueue( 'nie_ma_takiego', $this->event_id, 7, 'jan@example.com', $this->values() ) );
		$this->assertSame( array(), $this->repository->due( '2099-01-01 00:00:00', 10 ) );
	}

	public function test_immediate_enqueue_schedules_single_dispatch(): void {
		$this->mail_queue->enqueue( DefaultTemplates::KEY_OPTIN, $this->event_id, 7, 'jan@example.com', $this->values(), array(), true );

		$this->assertNotFalse( wp_next_scheduled( MailQueue::DISPATCH_HOOK ) );
	}

	public function test_non_immediate_enqueue_does_not_schedule(): void {
		$this->mail_queue->enqueue( DefaultTemplates::KEY_ADMIN_NEW, $this->event_id, 7, 'organizator@example.com', $this->values() );

		$this->assertFalse( wp_next_scheduled( MailQueue::DISPATCH_HOOK ) );
	}
}
