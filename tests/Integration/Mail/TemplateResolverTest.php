<?php

declare( strict_types=1 );

namespace EvReg\Tests\Integration\Mail;

use EvReg\Mail\DefaultTemplates;
use EvReg\Mail\TemplateResolver;
use EvReg\Persistence\MailTemplateRepository;
use WP_UnitTestCase;

final class TemplateResolverTest extends WP_UnitTestCase {

	private TemplateResolver $resolver;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		$this->resolver = new TemplateResolver( new MailTemplateRepository() );
		$this->event_id = self::factory()->post->create( array( 'post_type' => 'evreg_event' ) );
	}

	public function test_falls_back_to_default_template(): void {
		$template = $this->resolver->resolve( $this->event_id, DefaultTemplates::KEY_OPTIN );

		$this->assertSame( DefaultTemplates::get( DefaultTemplates::KEY_OPTIN ), $template );
		$this->assertStringContainsString( '{link_potwierdzenia}', $template['body'] );
	}

	public function test_event_override_wins_per_field(): void {
		update_post_meta(
			$this->event_id,
			MailTemplateRepository::META_KEY,
			wp_slash( (string) wp_json_encode( array( 'optin' => array( 'subject' => 'Własny temat', 'body' => '' ) ) ) )
		);

		$template = $this->resolver->resolve( $this->event_id, DefaultTemplates::KEY_OPTIN );

		$this->assertSame( 'Własny temat', $template['subject'] );
		$this->assertSame( DefaultTemplates::get( DefaultTemplates::KEY_OPTIN )['body'], $template['body'] );
	}

	public function test_variant_key_resolves_to_base_template(): void {
		update_post_meta(
			$this->event_id,
			MailTemplateRepository::META_KEY,
			wp_slash( (string) wp_json_encode( array( 'admin_new' => array( 'subject' => 'Nowe zgłoszenie' ) ) ) )
		);

		$template = $this->resolver->resolve( $this->event_id, 'admin_new:9e107d9d372bb6826bd81d3542a419d6' );

		$this->assertSame( 'Nowe zgłoszenie', $template['subject'] );
	}

	public function test_unknown_key_resolves_to_empty_template(): void {
		$this->assertSame( array( 'subject' => '', 'body' => '' ), $this->resolver->resolve( $this->event_id, 'nie_ma_takiego' ) );
	}

	public function test_base_key_strips_variant_suffix(): void {
		$this->assertSame( 'admin_new', TemplateResolver::baseKey( 'admin_new:abc' ) );
		$this->assertSame( 'optin', TemplateResolver::baseKey( 'optin' ) );
	}

	public function test_every_default_template_has_subject_and_body(): void {
		foreach ( DefaultTemplates::keys() as $key ) {
			$template = DefaultTemplates::get( $key );

			$this->assertNotSame( '', $template['subject'], $key );
			$this->assertNotSame( '', $template['body'], $key );
		}
	}
}
