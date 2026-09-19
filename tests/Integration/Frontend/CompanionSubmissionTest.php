<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Frontend;

use EvReg\Domain\Schema\FormSchema;
use EvReg\Frontend\SubmissionAssembler;
use WP_UnitTestCase;

final class CompanionSubmissionTest extends WP_UnitTestCase {

	private SubmissionAssembler $assembler;

	protected function setUp(): void {
		parent::setUp();
		$this->assembler = new SubmissionAssembler();
	}

	private function schema( bool $companion_enabled ): FormSchema {
		return FormSchema::fromArray(
			array(
				'version'  => 1,
				'sections' => array(
					array(
						'key'    => 'dane',
						'title'  => 'Dane',
						'fields' => array(
							array(
								'key'     => '__type',
								'type'    => 'radio',
								'label'   => 'Typ',
								'options' => array( array( 'value' => 'std', 'label' => 'Standard' ) ),
							),
							array( 'key' => 'email', 'type' => 'email', 'label' => 'E-mail', 'required' => true ),
							array(
								'key'    => 'nocleg',
								'type'   => 'accommodation',
								'label'  => 'Nocleg',
								'config' => array(
									'packages'          => array( array( 'key' => 'std', 'label' => 'Standard' ) ),
									'rooms'             => array( array( 'key' => 'double', 'label' => 'Dwuosobowy' ) ),
									'inventory'         => array(
										array( 'package' => 'std', 'room' => 'double', 'capacity' => 10, 'price' => 0 ),
									),
									'allow_none'        => true,
									'companion_enabled' => $companion_enabled,
								),
							),
						),
					),
				),
			)
		);
	}

	public function test_companion_checked_with_name_threads_into_request(): void {
		$assembled = $this->assembler->assemble(
			$this->schema( true ),
			array(
				'evreg_field'          => array(
					'email'  => 'a@b.pl',
					'__type' => 'std',
				),
				'evreg_companion'      => '1',
				'evreg_companion_name' => 'Jan Kowalski',
			)
		);

		$this->assertTrue( $assembled->isValid() );
		$request = $assembled->request();
		$this->assertNotNull( $request );
		$this->assertTrue( $request->companion );
		$this->assertSame( 'Jan Kowalski', $request->companionName );
	}

	public function test_companion_checked_without_name_is_invalid(): void {
		$assembled = $this->assembler->assemble(
			$this->schema( true ),
			array(
				'evreg_field'     => array(
					'email'  => 'a@b.pl',
					'__type' => 'std',
				),
				'evreg_companion' => '1',
			)
		);

		$this->assertFalse( $assembled->isValid() );
		$this->assertNull( $assembled->request() );
		$this->assertSame( 'companion_name_required', $assembled->errors()['evreg_companion'] ?? null );
	}

	public function test_companion_checked_with_blank_name_is_invalid(): void {
		$assembled = $this->assembler->assemble(
			$this->schema( true ),
			array(
				'evreg_field'          => array(
					'email'  => 'a@b.pl',
					'__type' => 'std',
				),
				'evreg_companion'      => '1',
				'evreg_companion_name' => '   ',
			)
		);

		$this->assertFalse( $assembled->isValid() );
		$this->assertSame( 'companion_name_required', $assembled->errors()['evreg_companion'] ?? null );
	}

	public function test_companion_unchecked_is_false_on_request(): void {
		$assembled = $this->assembler->assemble(
			$this->schema( true ),
			array(
				'evreg_field' => array(
					'email'  => 'a@b.pl',
					'__type' => 'std',
				),
			)
		);

		$this->assertTrue( $assembled->isValid() );
		$request = $assembled->request();
		$this->assertNotNull( $request );
		$this->assertFalse( $request->companion );
		$this->assertSame( '', $request->companionName );
	}

	public function test_companion_post_ignored_when_disabled_for_event(): void {
		$assembled = $this->assembler->assemble(
			$this->schema( false ),
			array(
				'evreg_field'          => array(
					'email'  => 'a@b.pl',
					'__type' => 'std',
				),
				'evreg_companion'      => '1',
				'evreg_companion_name' => 'Jan Kowalski',
			)
		);

		$this->assertTrue( $assembled->isValid() );
		$request = $assembled->request();
		$this->assertNotNull( $request );
		$this->assertFalse( $request->companion );
		$this->assertSame( '', $request->companionName );
	}
}
