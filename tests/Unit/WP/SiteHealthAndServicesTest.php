<?php
declare(strict_types=1);

namespace Saizul\HealthApi\Tests\Unit\WP;

use Saizul\HealthApi\CheckResult;
use Saizul\HealthApi\Status;
use Saizul\HealthApi\Tests\Fakes\CallbackCheck;
use Saizul\HealthApi\Tests\WpTestCase;
use Saizul\HealthApi\WP\Admin\SettingsPage;
use Saizul\HealthApi\WP\Options;
use Saizul\HealthApi\WP\Plugin;
use Saizul\HealthApi\WP\SiteHealth;
use WpStubs;

final class SiteHealthAndServicesTest extends WpTestCase {

	public function test_site_health_tests_are_added_per_enabled_check(): void {
		$site_health = new SiteHealth( $this->services( array( 'checks' => array( 'ssl' => true, 'disk' => false ) ) ) );
		$tests       = $site_health->add_tests( array( 'direct' => array( 'core_test' => array() ) ) );

		$this->assertArrayHasKey( 'core_test', $tests['direct'] );
		$this->assertArrayHasKey( 'wp_health_api_database', $tests['direct'] );
		$this->assertArrayHasKey( 'wp_health_api_ssl', $tests['direct'] );
		$this->assertArrayNotHasKey( 'wp_health_api_disk', $tests['direct'] );
		$this->assertSame( 'Health API: Database', $tests['direct']['wp_health_api_database']['label'] );

		$result = call_user_func( $tests['direct']['wp_health_api_database']['test'] );
		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( 'wp_health_api_database', $result['test'] );
		$this->assertSame( 'Database is healthy', $result['label'] );
	}

	public function test_site_health_status_mapping(): void {
		$warn = SiteHealth::format( 'disk', new CheckResult( 'disk', Status::WARN, 'Low <space>' ) );
		$this->assertSame( 'recommended', $warn['status'] );
		$this->assertSame( 'orange', $warn['badge']['color'] );
		$this->assertStringContainsString( 'Low &lt;space&gt;', $warn['description'], 'Messages are escaped' );

		$critical = SiteHealth::format( 'database', new CheckResult( 'database', Status::CRITICAL, 'down' ) );
		$this->assertSame( 'critical', $critical['status'] );
		$this->assertSame( 'Database needs attention', $critical['label'] );

		$skipped = SiteHealth::format( 'woocommerce', CheckResult::skipped( 'woocommerce', 'Not active.' ) );
		$this->assertSame( 'good', $skipped['status'] );
		$this->assertStringContainsString( 'not applicable', $skipped['label'] );

		$this->assertSame( 'good', SiteHealth::format( 'custom_check', null )['status'] );
	}

	public function test_all_site_health_tests_share_one_report(): void {
		$site_health = new SiteHealth( $this->services( array() ) );
		$site_health->run_test( 'database' );
		$site_health->run_test( 'cron' );
		$site_health->run_test( 'php' );
		$this->assertSame( 1, $this->probes['database']->calls );
	}

	public function test_debug_information(): void {
		$this->token();
		$info = ( new SiteHealth( $this->services( array() ) ) )->debug_information( array() );
		$this->assertSame( 'Health API', $info['wp-health-api']['label'] );
		$this->assertSame( 'option', $info['wp-health-api']['fields']['token']['debug'] );
		$this->assertSame( 'https://shop.example.com/wp-json/health/v1/metrics', $info['wp-health-api']['fields']['metrics_url']['value'] );
		$this->assertStringNotContainsString( 'sha256', serialize( $info ) );
	}

	public function test_settings_are_read_from_the_option_and_filter(): void {
		WpStubs::$options[ Options::SETTINGS ] = array( 'cache_ttl' => '45', 'checks' => array( 'ssl' => '1' ) );
		add_filter(
			'wp_health_api_settings',
			static function ( array $settings ): array {
				$settings['checks']['disk'] = false;
				return $settings;
			}
		);
		$config = $this->services()->config();
		$this->assertSame( 45, $config->int( 'cache_ttl' ) );
		$this->assertTrue( $config->is_enabled( 'ssl' ) );
		$this->assertFalse( $config->is_enabled( 'disk' ) );
	}

	public function test_custom_checks_through_the_filter(): void {
		add_filter(
			'wp_health_api_checks',
			static function ( array $checks ): array {
				$checks[] = new CallbackCheck(
					'queue_depth',
					static function ( string $id ): CheckResult {
						return new CheckResult( $id, Status::WARN, 'Queue is long.' );
					}
				);
				$checks[] = new CallbackCheck(
					'database',
					static function ( string $id ): CheckResult {
						return new CheckResult( $id, Status::CRITICAL, 'Duplicate id is ignored.' );
					}
				);
				$checks[] = new CallbackCheck(
					'Bad Id',
					static function ( string $id ): CheckResult {
						return new CheckResult( $id, Status::CRITICAL, 'Invalid id is ignored.' );
					}
				);
				$checks[] = 'not a check';
				return $checks;
			}
		);
		$services = $this->services( array() );
		$report   = $services->report();
		$this->assertSame( Status::WARN, $report->status() );
		$this->assertSame( 'Queue is long.', $report->result( 'queue_depth' )->message );
		$this->assertSame( Status::OK, $report->result( 'database' )->status );
		$this->assertNull( $report->result( 'Bad Id' ) );

		$this->assertSame( array( 'queue_depth' ), array_keys( $services->report( true, array( 'queue_depth' ) )->results() ) );
	}

	public function test_report_action_and_flush(): void {
		$services = $this->services( array() );
		$services->report();
		$this->assertContains( 'wp_health_api_report', array_column( WpStubs::$actions, 0 ) );
		$services->flush();
		$services->report();
		$this->assertSame( 2, $this->probes['database']->calls );
	}

	public function test_settings_page_sanitize_reports_errors(): void {
		$page   = new SettingsPage( $this->services( array() ) );
		$output = $page->sanitize(
			array(
				'cache_ttl'    => '20',
				'status_codes' => array( 'critical' => '99' ),
			)
		);
		$this->assertSame( 20, $output['cache_ttl'] );
		$this->assertSame( 503, $output['status_codes']['critical'] );
		$this->assertCount( 1, WpStubs::$settings_errors );
		$this->assertStringContainsString( 'status_codes.critical', WpStubs::$settings_errors[0][2] );
		$this->assertSame( \Saizul\HealthApi\Settings\Defaults::settings(), $page->sanitize( 'junk' ), 'Junk input gives defaults' );
	}

	public function test_saving_settings_flushes_the_cache(): void {
		$services = $this->services( array() );
		( new SettingsPage( $services ) )->register( '/plugins/wp-health-api/wp-health-api.php' );
		$services->report();
		update_option( Options::SETTINGS, array( 'cache_ttl' => 30 ) );
		$services->report();
		$this->assertSame( 2, $this->probes['database']->calls );
	}

	public function test_action_links(): void {
		$links = ( new SettingsPage( $this->services( array() ) ) )->action_links( array( '<a>Deactivate</a>' ) );
		$this->assertStringContainsString( 'options-general.php?page=wp-health-api', $links[0] );
		$this->assertCount( 2, $links );
	}

	public function test_plugin_registers_hooks(): void {
		WpStubs::$can = true; // is_admin() stub.
		( new Plugin( '/plugins/wp-health-api/wp-health-api.php', $this->services( array() ) ) )->register();
		foreach ( array( 'init', 'rest_api_init', 'rest_post_dispatch', 'rest_pre_serve_request', 'site_status_tests', 'debug_information', 'admin_menu', 'admin_init', 'cron_schedules', 'deactivate_wp-health-api/wp-health-api.php' ) as $hook ) {
			$this->assertArrayHasKey( $hook, WpStubs::$filters, $hook );
		}
	}
}
