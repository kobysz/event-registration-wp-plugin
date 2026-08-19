<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Mail;

use EvReg\Domain\Mail\RetryPolicy;
use EvReg\Mail\Dispatcher;
use EvReg\Persistence\MailQueueRepository;
use EvReg\Persistence\Migrations;
use WP_Error;
use WP_UnitTestCase;

final class DispatcherTest extends WP_UnitTestCase {

	private Dispatcher $dispatcher;

	private MailQueueRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		Migrations::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Migrations::table( 'mail_queue' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$this->repository = new MailQueueRepository();
		$this->dispatcher = new Dispatcher( $this->repository, new RetryPolicy() );
	}

	protected function tearDown(): void {
		remove_all_filters( 'pre_wp_mail' );
		remove_all_filters( 'evreg_mail_batch_size' );
		parent::tearDown();
	}

	/**
	 * Podstawia wynik wp_mail bez dotykania SMTP.
	 *
	 * @param bool   $result  Wynik zwracany przez wp_mail.
	 * @param string $message Komunikat zgłaszany przez wp_mail_failed przy porażce.
	 */
	private function fake_mail( bool $result, string $message = '' ): void {
		add_filter(
			'pre_wp_mail',
			static function () use ( $result, $message ) {
				if ( ! $result && '' !== $message ) {
					do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', $message ) );
				}

				return $result;
			}
		);
	}

	/**
	 * Wstawia wiersz kolejki wymagalny od dawna i zwraca jego id.
	 *
	 * @param string $template_key Klucz szablonu (musi być unikalny w teście).
	 */
	private function queue_row( string $template_key = 'optin' ): int {
		global $wpdb;

		$this->repository->insert(
			array(
				'registration_id' => null,
				'event_id'        => 1,
				'template_key'    => $template_key,
				'recipient'       => 'jan@example.com',
				'subject'         => 'Temat',
				'body'            => 'Treść',
				'headers'         => '',
				'scheduled_at'    => '2020-01-01 00:00:00',
			)
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . Migrations::table( 'mail_queue' ) );
	}

	public function test_successful_send_marks_row_sent(): void {
		$this->fake_mail( true );
		$id = $this->queue_row();

		$this->dispatcher->run();

		$row = $this->repository->find( $id );

		$this->assertSame( MailQueueRepository::STATUS_SENT, $row['status'] );
		$this->assertNotNull( $row['sent_at'] );
		$this->assertSame( '1', (string) $row['attempts'] );
	}

	public function test_first_failure_reschedules_in_one_minute_with_error(): void {
		$this->fake_mail( false, 'SMTP timeout' );
		$id = $this->queue_row();

		$this->dispatcher->run();

		$row = $this->repository->find( $id );

		$this->assertSame( MailQueueRepository::STATUS_QUEUED, $row['status'] );
		$this->assertSame( 'SMTP timeout', $row['last_error'] );
		$this->assertSame( '1', (string) $row['attempts'] );

		$delay = strtotime( (string) $row['scheduled_at'] ) - time();
		$this->assertGreaterThan( 45, $delay );
		$this->assertLessThan( 75, $delay );
	}

	public function test_third_failure_marks_row_failed(): void {
		$this->fake_mail( false, 'SMTP down' );
		$id = $this->queue_row();

		$this->dispatcher->run();
		$this->repository->reschedule( $id, '2020-01-01 00:00:00', 'SMTP down' );
		$this->dispatcher->run();
		$this->repository->reschedule( $id, '2020-01-01 00:00:00', 'SMTP down' );
		$this->dispatcher->run();

		$row = $this->repository->find( $id );

		$this->assertSame( MailQueueRepository::STATUS_FAILED, $row['status'] );
		$this->assertSame( '3', (string) $row['attempts'] );
		$this->assertSame( 'SMTP down', $row['last_error'] );
	}

	public function test_failure_without_wp_error_records_generic_message(): void {
		$this->fake_mail( false );
		$id = $this->queue_row();

		$this->dispatcher->run();

		$this->assertNotSame( '', (string) $this->repository->find( $id )['last_error'] );
	}

	public function test_exception_from_wp_mail_is_treated_as_failure(): void {
		add_filter(
			'pre_wp_mail',
			static function (): bool {
				throw new \RuntimeException( 'Wtyczka SMTP wybuchła' );
			}
		);
		$id = $this->queue_row();

		$this->dispatcher->run();

		$row = $this->repository->find( $id );

		$this->assertSame( MailQueueRepository::STATUS_QUEUED, $row['status'] );
		$this->assertSame( 'Wtyczka SMTP wybuchła', $row['last_error'] );
	}

	public function test_stale_sending_row_is_recovered_and_sent(): void {
		$this->fake_mail( true );
		$id = $this->queue_row();
		$this->repository->claim( $id, gmdate( 'Y-m-d H:i:s', time() - 600 ) );

		$this->dispatcher->run();

		$this->assertSame( MailQueueRepository::STATUS_SENT, $this->repository->find( $id )['status'] );
	}

	public function test_recently_claimed_row_is_left_alone(): void {
		$this->fake_mail( true );
		$id = $this->queue_row();
		$this->repository->claim( $id, gmdate( 'Y-m-d H:i:s', time() - 10 ) );

		$this->dispatcher->run();

		$this->assertSame( MailQueueRepository::STATUS_SENDING, $this->repository->find( $id )['status'] );
	}

	public function test_batch_size_filter_limits_one_run(): void {
		$this->fake_mail( true );
		$first  = $this->queue_row( 'optin' );
		$second = $this->queue_row( 'confirmed' );

		add_filter( 'evreg_mail_batch_size', static fn (): int => 1 );

		$this->dispatcher->run();

		$statuses = array(
			$this->repository->find( $first )['status'],
			$this->repository->find( $second )['status'],
		);

		$this->assertContains( MailQueueRepository::STATUS_SENT, $statuses );
		$this->assertContains( MailQueueRepository::STATUS_QUEUED, $statuses );
	}

	public function test_run_without_due_rows_does_nothing(): void {
		$this->fake_mail( true );

		$this->dispatcher->run();

		$this->assertSame( array(), $this->repository->due( '2099-01-01 00:00:00', 10 ) );
	}
}
