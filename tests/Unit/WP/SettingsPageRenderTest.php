<?php
declare(strict_types=1);

namespace Saizul\HealthApi\Tests\Unit\WP;

use Saizul\HealthApi\Tests\WpTestCase;
use Saizul\HealthApi\WP\Admin\SettingsPage;
use Saizul\HealthApi\WP\Options;
use Saizul\HealthApi\WP\Probe\WpObjectCacheProbe;
use Saizul\HealthApi\WP\Probe\WpUpdatesProbe;
use WpStubs;

final class SettingsPageRenderTest extends WpTestCase {

	private function render( SettingsPage $page ): string {
		ob_start();
		$page->render();
		return (string) ob_get_clean();
	}

	public function test_render_requires_the_capability(): void {
		$this->assertSame( '', $this->render( new SettingsPage( $this->services( array() ) ) ) );
	}

	public function test_render(): void {
		WpStubs::$can = true;
		$html         = $this->render( new SettingsPage( $this->services( array() ) ) );

		$this->assertStringContainsString( '<h1>Health API</h1>', $html );
		$this->assertStringContainsString( 'https://shop.example.com/wp-json/health/v1/metrics', $html );
		$this->assertStringContainsString( 'Authorization: Bearer $HEALTH_TOKEN', $html );
		$this->assertStringContainsString( 'name="wp_health_api_settings[thresholds][database.latency_ms][warn]" value="250"', $html );
		$this->assertStringContainsString( 'name="wp_health_api_settings[thresholds][woocommerce.orders_last_hour][warn]" value=""', $html );
		$this->assertStringContainsString( 'name="wp_health_api_settings[checks][ssl]" value="0"', $html, 'Hidden fallback for unchecked boxes' );
		$this->assertStringContainsString( 'No token is configured', $html );
		$this->assertStringContainsString( 'value="wp_health_api_rotate"', $html );
		$this->assertStringNotContainsString( 'value="wp_health_api_revoke"', $html );
		$this->assertStringContainsString( 'Overall: <strong>OK</strong>', $html );
		$this->assertSame( substr_count( $html, '<tr' ), substr_count( $html, '</tr>' ) );
	}

	public function test_new_token_is_shown_once(): void {
		WpStubs::$can = true;
		$this->token();
		WpStubs::$transients[ SettingsPage::NEW_TOKEN . '7' ] = 'shown-once-token-value-123';
		$_GET['token'] = 'new';
		$page          = new SettingsPage( $this->services( array() ) );

		$first = $this->render( $page );
		$this->assertStringContainsString( 'value="shown-once-token-value-123"', $first );
		$this->assertStringContainsString( 'value="wp_health_api_revoke"', $first );
		$this->assertArrayNotHasKey( SettingsPage::NEW_TOKEN . '7', WpStubs::$transients );
		$this->assertStringNotContainsString( 'shown-once-token-value-123', $this->render( $page ) );
		unset( $_GET['token'] );
	}

	public function test_register_hooks(): void {
		$page = new SettingsPage( $this->services( array() ) );
		$page->register( '/plugins/wp-health-api/wp-health-api.php' );
		do_action( 'admin_menu' );
		do_action( 'admin_init' );
		$this->assertSame( 'manage_options', WpStubs::$routes['admin:wp-health-api'][1] );
		$this->assertSame( array( $page, 'sanitize' ), WpStubs::$routes[ 'setting:' . Options::SETTINGS ]['sanitize_callback'] );
		$this->assertArrayHasKey( 'admin_post_wp_health_api_rotate', WpStubs::$filters );
	}

	public function test_object_cache_probe_round_trip(): void {
		$probe = new WpObjectCacheProbe();
		$this->assertSame( 'none', $probe->backend() );
		$this->assertFalse( $probe->is_persistent() );
		$trip = $probe->round_trip();
		$this->assertTrue( $trip['ok'] );
		$this->assertIsFloat( $trip['latency'] );
		$this->assertSame( array(), WpStubs::$cache, 'The probe cleans up' );
	}

	public function test_updates_probe_reads_site_transients(): void {
		WpStubs::$site_transients['update_plugins'] = (object) array( 'response' => array() );
		$probe                                      = new WpUpdatesProbe();
		$this->assertFalse( $probe->core() );
		$this->assertIsObject( $probe->plugins() );
		$this->assertSame( '6.7.1', $probe->wordpress_version() );
	}
}
