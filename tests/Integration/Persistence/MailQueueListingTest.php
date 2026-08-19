<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Persistence;

use EvReg\Persistence\MailQueueRepository;
use EvReg\Persistence\Migrations;
use WP_UnitTestCase;

final class MailQueueListingTest extends WP_UnitTestCase {

	private MailQueueRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( 'mail_queue' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->repository = new MailQueueRepository();
	}

	/**
	 * @param array<string,mixed> $overrides
	 */
	private function seed( array $overrides = array() ): void {
		$row = array_merge(
			array(
				'registration_id' => null,
				'event_id'        => 1,
				'template_key'    => 'optin',
				'recipient'       => 'jan@example.com',
				'subject'         => 'Temat',
				'body'            => 'Treść',
				'headers'         => '',
				'scheduled_at'    => '2026-08-19 10:00:00',
			),
			$overrides
		);
		$this->repository->insert( $row );

		if ( isset( $overrides['status'] ) && MailQueueRepository::STATUS_QUEUED !== $overrides['status'] ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$id = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . Migrations::table( 'mail_queue' ) );
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				Migrations::table( 'mail_queue' ),
				array( 'status' => (string) $overrides['status'] ),
				array( 'id' => $id ),
				array( '%s' ),
				array( '%d' )
			);
		}
	}

	public function test_paginate_returns_all_without_filters_newest_first(): void {
		$this->seed( array( 'template_key' => 'a' ) );
		$this->seed( array( 'template_key' => 'b' ) );

		$rows = $this->repository->paginate( array(), 20, 0 );

		$this->assertCount( 2, $rows );
		$this->assertSame( 'b', $rows[0]['template_key'] );
		$this->assertSame( 'a', $rows[1]['template_key'] );
	}

	public function test_paginate_filters_by_status(): void {
		$this->seed( array( 'template_key' => 'q' ) );
		$this->seed( array( 'template_key' => 'f', 'status' => MailQueueRepository::STATUS_FAILED ) );

		$rows = $this->repository->paginate( array( 'status' => MailQueueRepository::STATUS_FAILED ), 20, 0 );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'f', $rows[0]['template_key'] );
	}

	public function test_paginate_filters_by_event(): void {
		$this->seed( array( 'event_id' => 1 ) );
		$this->seed( array( 'event_id' => 2, 'template_key' => 'e2' ) );

		$rows = $this->repository->paginate( array( 'event_id' => 2 ), 20, 0 );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'e2', $rows[0]['template_key'] );
	}

	public function test_paginate_combines_status_and_event(): void {
		$this->seed( array( 'event_id' => 1, 'status' => MailQueueRepository::STATUS_FAILED, 'template_key' => 'hit' ) );
		$this->seed( array( 'event_id' => 2, 'status' => MailQueueRepository::STATUS_FAILED ) );
		$this->seed( array( 'event_id' => 1, 'template_key' => 'queued' ) );

		$rows = $this->repository->paginate( array( 'event_id' => 1, 'status' => MailQueueRepository::STATUS_FAILED ), 20, 0 );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'hit', $rows[0]['template_key'] );
	}

	public function test_paginate_respects_limit_and_offset(): void {
		$this->seed( array( 'template_key' => 'a' ) );
		$this->seed( array( 'template_key' => 'b' ) );
		$this->seed( array( 'template_key' => 'c' ) );

		$page1 = $this->repository->paginate( array(), 2, 0 );
		$page2 = $this->repository->paginate( array(), 2, 2 );

		$this->assertCount( 2, $page1 );
		$this->assertCount( 1, $page2 );
		$this->assertSame( 'a', $page2[0]['template_key'] );
	}

	public function test_paginate_ignores_unknown_status(): void {
		$this->seed( array( 'template_key' => 'a' ) );

		$rows = $this->repository->paginate( array( 'status' => 'nonsense' ), 20, 0 );

		$this->assertCount( 1, $rows );
	}

	public function test_count_by_filter_matches_paginate(): void {
		$this->seed( array( 'status' => MailQueueRepository::STATUS_FAILED ) );
		$this->seed();
		$this->seed( array( 'status' => MailQueueRepository::STATUS_FAILED ) );

		$this->assertSame( 3, $this->repository->countByFilter( array() ) );
		$this->assertSame( 2, $this->repository->countByFilter( array( 'status' => MailQueueRepository::STATUS_FAILED ) ) );
	}

	public function test_distinct_event_ids(): void {
		$this->seed( array( 'event_id' => 5 ) );
		$this->seed( array( 'event_id' => 5, 'template_key' => 'b' ) );
		$this->seed( array( 'event_id' => 9 ) );

		$ids = $this->repository->distinctEventIds();

		sort( $ids );
		$this->assertSame( array( 5, 9 ), $ids );
	}
}
