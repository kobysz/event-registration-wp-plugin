<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Frontend;

use EvReg\Domain\Schema\FormSchema;
use EvReg\Frontend\EventFormLoader;
use EvReg\Frontend\SubmissionAssembler;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Services\ReservationRequest;
use WP_UnitTestCase;

final class SubmissionAssemblerTest extends WP_UnitTestCase {

	private SubmissionAssembler $assembler;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();

		$this->event_id = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
		( new EventConfigRepository() )->save(
			$this->event_id,
			array(
				'schema' => array(
					'version'  => 1,
					'sections' => array(
						array(
							'key'    => 'dane',
							'title'  => 'Dane',
							'fields' => array(
								array( 'key' => '__type', 'type' => 'radio', 'label' => 'Typ' ),
								array( 'key' => 'name', 'type' => 'text', 'label' => 'Imię', 'required' => true ),
								array( 'key' => 'email', 'type' => 'email', 'label' => 'E-mail', 'required' => true ),
							),
						),
					),
				),
				'types'         => array( array( 'key' => 'std', 'label' => 'Standard', 'price' => 0.0, 'capacity' => 5 ) ),
				'accommodation' => array( 'packages' => array(), 'rooms' => array(), 'inventory' => array() ),
				'settings'      => array( 'global_cap' => null, 'waitlist_enabled' => true ),
			)
		);

		$this->assembler = new SubmissionAssembler();
	}

	public function test_assemble_returns_reservation_request_for_valid_answers(): void {
		$schema = ( new EventFormLoader( new EventConfigRepository() ) )->load( $this->event_id );
		$this->assertNotNull( $schema );

		$assembled = $this->assembler->assemble(
			$schema,
			array(
				'evreg_field' => array(
					'email'  => 'a@b.pl',
					'name'   => 'Jan',
					'__type' => 'std',
				),
			)
		);

		$this->assertTrue( $assembled->isValid() );
		$this->assertSame( array(), $assembled->errors() );

		$request = $assembled->request();
		$this->assertInstanceOf( ReservationRequest::class, $request );
		$this->assertSame( 'a@b.pl', $request->email );
		$this->assertSame( 'Jan', $request->name );
		$this->assertSame( 'std', $request->typeKey );
	}

	public function test_assemble_returns_invalid_when_required_field_missing(): void {
		$schema = ( new EventFormLoader( new EventConfigRepository() ) )->load( $this->event_id );
		$this->assertNotNull( $schema );

		$fields = array(
			'email'  => '',
			'name'   => 'Jan',
			'__type' => 'std',
		);

		$assembled = $this->assembler->assemble( $schema, array( 'evreg_field' => $fields ) );

		$this->assertFalse( $assembled->isValid() );
		$this->assertNull( $assembled->request() );
		$this->assertEqualsCanonicalizing( $fields, $assembled->values() );
	}

	public function test_blank_required_email_is_an_error_unless_email_optional(): void {
		$schema = ( new EventFormLoader( new EventConfigRepository() ) )->load( $this->event_id );
		$this->assertNotNull( $schema );

		$post = array(
			'evreg_field' => array(
				'email'  => '',
				'name'   => 'Jan',
				'__type' => 'std',
			),
		);

		$strict = $this->assembler->assemble( $schema, $post );
		$this->assertFalse( $strict->isValid() );
		$this->assertArrayHasKey( 'email', $strict->errors() );

		$relaxed = $this->assembler->assemble( $schema, $post, null, true );
		$this->assertTrue( $relaxed->isValid() );
		$this->assertNotNull( $relaxed->request() );
		$this->assertSame( '', $relaxed->request()->email );
	}

	public function test_malformed_email_is_still_an_error_when_email_optional(): void {
		$schema = ( new EventFormLoader( new EventConfigRepository() ) )->load( $this->event_id );
		$this->assertNotNull( $schema );

		$assembled = $this->assembler->assemble(
			$schema,
			array(
				'evreg_field' => array(
					'email'  => 'nie-adres',
					'name'   => 'Jan',
					'__type' => 'std',
				),
			),
			null,
			true
		);

		$this->assertFalse( $assembled->isValid() );
		$this->assertArrayHasKey( 'email', $assembled->errors() );
		$this->assertNotSame( 'required', $assembled->errors()['email'] );
	}

	public function test_email_field_key_picks_first_email_field_even_when_blank(): void {
		$schema = FormSchema::fromArray(
			array(
				'version'  => 1,
				'sections' => array(
					array(
						'key'    => 'dane',
						'title'  => 'Dane',
						'fields' => array(
							array( 'key' => 'mail_a', 'type' => 'email', 'label' => 'A' ),
							array( 'key' => 'mail_b', 'type' => 'email', 'label' => 'B' ),
						),
					),
				),
			)
		);

		$this->assertSame( 'mail_a', $this->assembler->emailFieldKey( $schema ) );
	}

	public function test_email_field_key_is_empty_without_email_field(): void {
		$schema = FormSchema::fromArray(
			array(
				'version'  => 1,
				'sections' => array(
					array(
						'key'    => 'dane',
						'title'  => 'Dane',
						'fields' => array( array( 'key' => 'name', 'type' => 'text', 'label' => 'Imię' ) ),
					),
				),
			)
		);

		$this->assertSame( '', $this->assembler->emailFieldKey( $schema ) );
	}
}
