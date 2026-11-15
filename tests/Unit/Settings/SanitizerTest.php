<?php
declare(strict_types=1);

namespace Saizul\HealthApi\Tests\Unit\Settings;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Saizul\HealthApi\Settings\Config;
use Saizul\HealthApi\Settings\Defaults;
use Saizul\HealthApi\Settings\Sanitizer;
use Saizul\HealthApi\Status;
use Saizul\HealthApi\Threshold;

final class SanitizerTest extends TestCase {

	public function test_empty_input_gives_defaults(): void {
		$this->assertSame( Defaults::settings(), Sanitizer::sanitize( array() ) );
	}

	public function test_every_default_threshold_is_valid(): void {
		$config = new Config();
		foreach ( array_keys( Defaults::thresholds() ) as $key ) {
			$this->assertInstanceOf( Threshold::class, $config->threshold( $key ) );
		}
	}

	public function test_unknown_keys_are_dropped(): void {
		$output = Sanitizer::sanitize(
			array(
				'evil'       => 'x',
				'checks'     => array( 'made_up' => '1' ),
				'thresholds' => array( 'made.up' => array( 'warn' => 1 ) ),
			)
		);
		$this->assertArrayNotHasKey( 'evil', $output );
		$this->assertArrayNotHasKey( 'made_up', $output['checks'] );
		$this->assertArrayNotHasKey( 'made.up', $output['thresholds'] );
	}

	public function test_checks_from_a_form(): void {
		$output = Sanitizer::sanitize(
			array(
				'checks' => array(
					'ssl'      => '1',
					'database' => '0',
					'disk'     => 'yes',
					'php'      => '',
				),
			)
		);
		$this->assertTrue( $output['checks']['ssl'] );
		$this->assertFalse( $output['checks']['database'] );
		$this->assertTrue( $output['checks']['disk'] );
		$this->assertFalse( $output['checks']['php'] );
		$this->assertTrue( $output['checks']['cron'], 'Untouched checks keep their default' );
	}

	public function test_thresholds(): void {
		$errors = array();
		$output = Sanitizer::sanitize(
			array(
				'thresholds' => array(
					'database.latency_ms' => array( 'warn' => ' 100 ', 'critical' => '' ),
					'disk.free_percent'   => array( 'warn' => '5', 'critical' => '10' ), // Wrong order for "below".
					'cron.overdue_events' => array( 'warn' => 'many', 'critical' => '5' ),
					'ssl.days_left'       => 'not an array',
				),
			),
			$errors
		);
		$this->assertSame( array( 'warn' => 100.0, 'critical' => null ), $output['thresholds']['database.latency_ms'] );
		$this->assertSame( array( 'warn' => 10.0, 'critical' => 5.0 ), $output['thresholds']['disk.free_percent'], 'Invalid pair keeps the default' );
		$this->assertSame( array( 'warn' => 10.0, 'critical' => 50.0 ), $output['thresholds']['cron.overdue_events'] );
		$this->assertCount( 2, $errors );
		$this->assertStringStartsWith( 'cron.overdue_events:', $errors[0] );
		$this->assertStringStartsWith( 'disk.free_percent:', $errors[1] );
	}

	public function test_status_codes(): void {
		$errors = array();
		$output = Sanitizer::sanitize(
			array(
				'status_codes' => array(
					'ok'       => '204',
					'warn'     => '599',
					'critical' => '700',
				),
			),
			$errors
		);
		$this->assertSame( array( 'ok' => 204, 'warn' => 599, 'critical' => 503 ), $output['status_codes'] );
		$this->assertSame( array( 'status_codes.critical: must be between 200 and 599.' ), $errors );
	}

	public function test_integers_are_clamped(): void {
		$output = Sanitizer::sanitize(
			array(
				'cache_ttl'         => '9999',
				'rate_limit_window' => '0',
				'cron_grace'        => '-5',
				'ssl_cache_hours'   => 'abc',
			)
		);
		$this->assertSame( 300, $output['cache_ttl'] );
		$this->assertSame( 1, $output['rate_limit_window'] );
		$this->assertSame( 0, $output['cron_grace'] );
		$this->assertSame( 12, $output['ssl_cache_hours'] );
	}

	public function test_php_versions(): void {
		$errors = array();
		$output = Sanitizer::sanitize( array( 'php_warn_below' => '8.2', 'php_critical_below' => '8.0.0' ), $errors );
		$this->assertSame( '8.2', $output['php_warn_below'] );
		$this->assertSame( '8.0.0', $output['php_critical_below'] );
		$this->assertSame( array(), $errors );

		$output = Sanitizer::sanitize( array( 'php_warn_below' => 'latest' ), $errors );
		$this->assertSame( '8.1', $output['php_warn_below'] );
		$this->assertCount( 1, $errors );

		$errors = array();
		$output = Sanitizer::sanitize( array( 'php_warn_below' => '7.0', 'php_critical_below' => '8.0' ), $errors );
		$this->assertSame( '8.1', $output['php_warn_below'] );
		$this->assertSame( '7.4', $output['php_critical_below'] );
		$this->assertCount( 1, $errors );

		$output = Sanitizer::sanitize( array( 'php_warn_below' => '', 'php_critical_below' => '' ) );
		$this->assertSame( '', $output['php_warn_below'] );
	}

	public function test_enumerations(): void {
		$output = Sanitizer::sanitize(
			array(
				'maintenance_status'   => 'critical',
				'public_status'        => 'overall',
				'trusted_proxy_header' => 'http_x_forwarded_for',
			)
		);
		$this->assertSame( 'critical', $output['maintenance_status'] );
		$this->assertSame( 'overall', $output['public_status'] );
		$this->assertSame( 'HTTP_X_FORWARDED_FOR', $output['trusted_proxy_header'] );

		$errors = array();
		$output = Sanitizer::sanitize(
			array(
				'maintenance_status'   => 'panic',
				'public_status'        => 'everything',
				'trusted_proxy_header' => 'HTTP_AUTHORIZATION',
			),
			$errors
		);
		$this->assertSame( 'warn', $output['maintenance_status'] );
		$this->assertSame( 'alive', $output['public_status'] );
		$this->assertSame( '', $output['trusted_proxy_header'] );
		$this->assertCount( 1, $errors );
	}

	public function test_sanitising_twice_is_stable(): void {
		$once = Sanitizer::sanitize( array( 'cache_ttl' => '30', 'checks' => array( 'ssl' => true ) ) );
		$this->assertSame( $once, Sanitizer::sanitize( $once ) );
	}

	public function test_config_accessors(): void {
		$config = new Config( array( 'checks' => array( 'ssl' => true, 'disk' => false ), 'status_codes' => array( 'warn' => '503' ) ) );
		$this->assertTrue( $config->is_enabled( 'ssl' ) );
		$this->assertFalse( $config->is_enabled( 'disk' ) );
		$this->assertFalse( $config->is_enabled( 'nope' ) );
		$this->assertNotContains( 'disk', $config->enabled_checks() );
		$this->assertSame( 'database', $config->enabled_checks()[0] );
		$this->assertSame( 503, $config->status_code( Status::WARN ) );
		$this->assertSame( 200, $config->status_code( Status::OK ) );
		$this->assertSame( 10, $config->int( 'cache_ttl' ) );
		$this->assertTrue( $config->bool( 'require_opcache' ) );
		$this->assertSame( 'alive', $config->string( 'public_status' ) );
		$this->assertSame( Threshold::BELOW, $config->threshold( 'disk.free_percent' )->direction() );

		$this->expectException( InvalidArgumentException::class );
		$config->threshold( 'nope' );
	}
}
