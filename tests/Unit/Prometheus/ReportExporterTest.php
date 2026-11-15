<?php
declare(strict_types=1);

namespace Saizul\HealthApi\Tests\Unit\Prometheus;

use PHPUnit\Framework\TestCase;
use Saizul\HealthApi\CheckResult;
use Saizul\HealthApi\Prometheus\ReportExporter;
use Saizul\HealthApi\Report;
use Saizul\HealthApi\Sample;
use Saizul\HealthApi\Status;

final class ReportExporterTest extends TestCase {

	private function report(): Report {
		$database           = new CheckResult(
			'database',
			Status::OK,
			'fine',
			array(),
			array(
				Sample::gauge( 'database_up', true, 'Database up.' ),
				Sample::gauge( 'database_latency_seconds', 0.002, 'Latency.' ),
			)
		);
		$database->duration = 0.003;
		$updates            = new CheckResult(
			'updates',
			Status::WARN,
			'pending',
			array(),
			array(
				Sample::gauge( 'updates_pending', 2, 'Pending updates.', array( 'type' => 'plugin' ) ),
				Sample::gauge( 'updates_pending', 0, 'Pending updates.', array( 'type' => 'core' ) ),
				new Sample( 'bad-name', 1, 'Dropped.' ),
			)
		);
		$woo                = CheckResult::skipped( 'woocommerce', 'Not active.' );
		$report             = new Report( array( $database, $updates, $woo ), 1800000000 );
		$report->duration   = 0.0125;

		return $report;
	}

	public function test_full_exposition(): void {
		$expected = <<<'TXT'
# HELP wp_health_up Whether the health endpoint produced a report (always 1).
# TYPE wp_health_up gauge
wp_health_up 1
# HELP wp_health_status Overall status: 0 = ok, 1 = warn, 2 = critical.
# TYPE wp_health_status gauge
wp_health_status 1
# HELP wp_health_info Plugin and WordPress information (always 1).
# TYPE wp_health_info gauge
wp_health_info{plugin_version="0.1.0",wordpress_version="6.7.1"} 1
# HELP wp_health_report_timestamp_seconds Unix time the report was generated.
# TYPE wp_health_report_timestamp_seconds gauge
wp_health_report_timestamp_seconds 1800000000
# HELP wp_health_report_duration_seconds Time taken to run all checks in seconds.
# TYPE wp_health_report_duration_seconds gauge
wp_health_report_duration_seconds 0.0125
# HELP wp_health_report_cached Whether the report was served from the cache (1 = yes).
# TYPE wp_health_report_cached gauge
wp_health_report_cached 0
# HELP wp_health_check_skipped Whether a check did not apply on this site (1 = skipped).
# TYPE wp_health_check_skipped gauge
wp_health_check_skipped{check="database"} 0
wp_health_check_skipped{check="updates"} 0
wp_health_check_skipped{check="woocommerce"} 1
# HELP wp_health_check_status Check status: 0 = ok, 1 = warn, 2 = critical.
# TYPE wp_health_check_status gauge
wp_health_check_status{check="database"} 0
wp_health_check_status{check="updates"} 1
# HELP wp_health_check_duration_seconds Time taken by a check in seconds.
# TYPE wp_health_check_duration_seconds gauge
wp_health_check_duration_seconds{check="database"} 0.003
wp_health_check_duration_seconds{check="updates"} 0
wp_health_check_duration_seconds{check="woocommerce"} 0
# HELP wp_health_database_up Database up.
# TYPE wp_health_database_up gauge
wp_health_database_up 1
# HELP wp_health_database_latency_seconds Latency.
# TYPE wp_health_database_latency_seconds gauge
wp_health_database_latency_seconds 0.002
# HELP wp_health_updates_pending Pending updates.
# TYPE wp_health_updates_pending gauge
wp_health_updates_pending{type="plugin"} 2
wp_health_updates_pending{type="core"} 0

TXT;
		$this->assertSame(
			$expected,
			ReportExporter::render(
				$this->report(),
				array(
					'wordpress_version' => '6.7.1',
					'plugin_version'    => '0.1.0',
				)
			)
		);
	}

	public function test_constant_labels_are_added_everywhere(): void {
		$output = ReportExporter::render( $this->report(), array(), array( 'site' => 'shop' ) );
		$this->assertStringNotContainsString( 'wp_health_info', $output );
		foreach ( explode( "\n", trim( $output ) ) as $line ) {
			if ( '#' !== $line[0] ) {
				$this->assertStringContainsString( 'site="shop"', $line );
			}
		}
		$this->assertStringContainsString( 'wp_health_updates_pending{site="shop",type="plugin"} 2', $output );
	}

	public function test_cached_report_metrics_survive_the_cache(): void {
		$copy         = Report::from_array( $this->report()->to_array( true ) );
		$copy->cached = true;
		$output       = ReportExporter::render( $copy );
		$this->assertStringContainsString( "wp_health_report_cached 1\n", $output );
		$this->assertStringContainsString( "wp_health_database_latency_seconds 0.002\n", $output );
	}

	public function test_broken_custom_samples_are_dropped(): void {
		$custom = new CheckResult(
			'custom',
			Status::OK,
			'x',
			array(),
			array(
				Sample::gauge( 'custom_value', 1, 'Kept.', array( 'a' => '1' ) ),
				Sample::gauge( 'custom_value', 2, 'Same label set.', array( 'a' => '1' ) ),
				Sample::gauge( 'custom_other', 3, 'Bad label.', array( '__reserved' => 'x' ) ),
				new Sample( 'database_up', 1, 'Clashes with a built-in name and type.', array(), 'counter' ),
				new Sample( 'custom_weird', 1, 'Unknown type.', array(), 'meter' ),
				Sample::gauge( 'up', 5, 'Same name as the report metric, same label set.' ),
			)
		);
		$report = new Report( array( $custom ), 1800000000 );
		$output = ReportExporter::render( $report );

		$this->assertStringContainsString( "wp_health_custom_value{a=\"1\"} 1\n", $output );
		$this->assertStringNotContainsString( 'custom_value{a="1"} 2', $output );
		$this->assertStringNotContainsString( 'custom_other', $output );
		$this->assertStringNotContainsString( 'custom_weird', $output );
		$this->assertStringContainsString( "wp_health_up 1\n", $output );
		$this->assertStringNotContainsString( 'wp_health_up 5', $output );
	}
}
