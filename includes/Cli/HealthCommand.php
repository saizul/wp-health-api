<?php
/**
 * WP-CLI: wp health.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\WP\Cli;

use Saizul\HealthApi\Prometheus\ReportExporter;
use Saizul\HealthApi\Status;
use Saizul\HealthApi\WP\Rest\Controller;
use Saizul\HealthApi\WP\Services;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the health checks from the command line.
 */
final class HealthCommand {

	/**
	 * Services.
	 *
	 * @var Services
	 */
	private $services;

	/**
	 * Constructor.
	 *
	 * @param Services $services Services.
	 */
	public function __construct( Services $services ) {
		$this->services = $services;
	}

	/**
	 * Runs the health checks and prints the results.
	 *
	 * Exits with 0 when everything is ok, 1 on a warning and 2 on a
	 * critical result (the Nagios plugin convention), so the command can be
	 * used in shell scripts and cron jobs.
	 *
	 * ## OPTIONS
	 *
	 * [--checks=<ids>]
	 * : Comma-separated check ids, e.g. database,cron.
	 *
	 * [--fresh]
	 * : Run the checks now instead of using the short report cache.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp health check
	 *     wp health check --checks=database,cron --fresh
	 *     wp health check --format=json
	 *
	 * @param string[]             $args Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 */
	public function check( $args, $assoc_args ): void {
		$checks = Controller::sanitize_check_list( (string) ( $assoc_args['checks'] ?? '' ) );
		$report = $this->services->report( isset( $assoc_args['fresh'] ), '' === $checks ? null : explode( ',', $checks ) );
		$format = (string) ( $assoc_args['format'] ?? 'table' );

		if ( 'json' === $format ) {
			WP_CLI::line( (string) wp_json_encode( $report->to_array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		} else {
			$rows = array();
			foreach ( $report->results() as $id => $result ) {
				$rows[] = array(
					'check'       => $id,
					'status'      => $result->skipped ? 'skipped' : $result->status,
					'message'     => $result->message,
					'duration_ms' => round( $result->duration * 1000, 2 ),
				);
			}
			WP_CLI\Utils\format_items( $format, $rows, array( 'check', 'status', 'message', 'duration_ms' ) );
			if ( 'table' === $format ) {
				WP_CLI::line( sprintf( 'Overall: %s%s', $report->status(), $report->cached ? ' (cached)' : '' ) );
			}
		}

		$code = Status::severity( $report->status() );
		if ( $code > 0 ) {
			WP_CLI::halt( $code );
		}
	}

	/**
	 * Prints the metrics in the Prometheus text format.
	 *
	 * Useful with the node_exporter textfile collector:
	 * `wp health metrics > /var/lib/node_exporter/wordpress.prom`.
	 *
	 * ## OPTIONS
	 *
	 * [--checks=<ids>]
	 * : Comma-separated check ids.
	 *
	 * [--fresh]
	 * : Run the checks now instead of using the short report cache.
	 *
	 * @param string[]             $args Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 */
	public function metrics( $args, $assoc_args ): void {
		$checks = Controller::sanitize_check_list( (string) ( $assoc_args['checks'] ?? '' ) );
		$report = $this->services->report( isset( $assoc_args['fresh'] ), '' === $checks ? null : explode( ',', $checks ) );
		$labels = apply_filters( 'wp_health_api_metric_labels', array() );
		WP_CLI::out( ReportExporter::render( $report, $this->services->info(), is_array( $labels ) ? $labels : array() ) );
	}
}
