<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Mail;

use EvReg\Mail\DefaultTemplates;
use EvReg\Mail\TemplateResolver;
use EvReg\Persistence\EventConfigRepository;
use EvReg\Persistence\MailTemplateRepository;
use WP_UnitTestCase;

final class TemplateResolverLangTest extends WP_UnitTestCase {

	private TemplateResolver $resolver;

	private EventConfigRepository $config;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		$this->config   = new EventConfigRepository();
		$this->resolver = new TemplateResolver( new MailTemplateRepository(), $this->config );
		$this->event_id = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
	}

	public function test_empty_lang_preserves_base_default_behavior(): void {
		$template = $this->resolver->resolve( $this->event_id, DefaultTemplates::KEY_OPTIN, '' );

		$this->assertSame( DefaultTemplates::get( DefaultTemplates::KEY_OPTIN ), $template );
	}

	public function test_empty_lang_preserves_base_override_behavior(): void {
		update_post_meta(
			$this->event_id,
			MailTemplateRepository::META_KEY,
			wp_slash(
				(string) wp_json_encode(
					array(
						'optin' => array(
							'subject' => 'Własny temat',
							'body'    => 'Własna treść',
						),
					)
				)
			)
		);

		$template = $this->resolver->resolve( $this->event_id, DefaultTemplates::KEY_OPTIN, '' );

		$this->assertSame( 'Własny temat', $template['subject'] );
		$this->assertSame( 'Własna treść', $template['body'] );
	}

	public function test_lang_overlay_wins_over_base_and_default(): void {
		$this->config->saveI18n(
			$this->event_id,
			array(
				'en' => array(
					'mail' => array(
						DefaultTemplates::KEY_OPTIN => array(
							'subject' => 'EN subject',
							'body'    => 'EN body',
						),
					),
				),
			)
		);

		$template = $this->resolver->resolve( $this->event_id, DefaultTemplates::KEY_OPTIN, 'en' );

		$this->assertSame( 'EN subject', $template['subject'] );
		$this->assertSame( 'EN body', $template['body'] );
	}

	public function test_lang_overlay_falls_back_per_field_to_base_override(): void {
		update_post_meta(
			$this->event_id,
			MailTemplateRepository::META_KEY,
			wp_slash(
				(string) wp_json_encode(
					array(
						'optin' => array(
							'subject' => 'Własny temat',
							'body'    => 'Własna treść',
						),
					)
				)
			)
		);
		$this->config->saveI18n(
			$this->event_id,
			array(
				'en' => array(
					'mail' => array(
						DefaultTemplates::KEY_OPTIN => array(
							'subject' => 'EN subject',
						),
					),
				),
			)
		);

		$template = $this->resolver->resolve( $this->event_id, DefaultTemplates::KEY_OPTIN, 'en' );

		$this->assertSame( 'EN subject', $template['subject'] );
		$this->assertSame( 'Własna treść', $template['body'] );
	}

	public function test_lang_overlay_falls_back_per_field_to_default_when_no_base_override(): void {
		$this->config->saveI18n(
			$this->event_id,
			array(
				'en' => array(
					'mail' => array(
						DefaultTemplates::KEY_OPTIN => array(
							'subject' => 'EN subject',
						),
					),
				),
			)
		);

		$template = $this->resolver->resolve( $this->event_id, DefaultTemplates::KEY_OPTIN, 'en' );

		$this->assertSame( 'EN subject', $template['subject'] );
		$this->assertSame( DefaultTemplates::get( DefaultTemplates::KEY_OPTIN )['body'], $template['body'] );
	}

	public function test_unknown_lang_falls_back_to_base_default(): void {
		$this->config->saveI18n(
			$this->event_id,
			array(
				'en' => array(
					'mail' => array(
						DefaultTemplates::KEY_OPTIN => array(
							'subject' => 'EN subject',
							'body'    => 'EN body',
						),
					),
				),
			)
		);

		$template = $this->resolver->resolve( $this->event_id, DefaultTemplates::KEY_OPTIN, 'de' );

		$this->assertSame( DefaultTemplates::get( DefaultTemplates::KEY_OPTIN ), $template );
	}
}
