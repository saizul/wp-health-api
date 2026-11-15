<?php
/**
 * Report to Prometheus metrics.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Prometheus;

use InvalidArgumentException;
use Saizul\HealthApi\Report;
use Saizul\HealthApi\Status;

/**
 * Turns a report into metric families: report-level metrics, one status
 * and duration series per check, and every sample the checks produced.
 * All names get the `wp_health_` prefix.
 */
final class ReportExporter {

	const PREFIX = 'wp_health_';

	/**
	 * Build families.
	 *
	 * @param Report               $report Report.
	 * @param array<string,string> $info Labels for the wp_health_info metric.
	 * @param array<string,string> $const_labels Labels added to every sample (e.g. site).
	 * @return MetricFamily[]
	 */
	public static function to_families( Report $report, array $info = array(), array $const_labels = array() ): array {
		$families = array();
		$get      = static function ( string $name, string $help, string $type = 'gauge' ) use ( &$families ): MetricFamily {
			$name = self::PREFIX . $name;
			if ( ! isset( $families[ $name ] ) ) {
				$families[ $name ] = new MetricFamily( $name, $type, $help );
			}

			return $families[ $name ];
		};

		$get( 'up', 'Whether the health endpoint produced a report (always 1).' )->add( 1, $const_labels );
		$get( 'status', 'Overall status: 0 = ok, 1 = warn, 2 = critical.' )->add( Status::severity( $report->status() ), $const_labels );
		if ( array() !== $info ) {
			$get( 'info', 'Plugin and WordPress information (always 1).' )->add( 1, array_merge( $info, $const_labels ) );
		}
		$get( 'report_timestamp_seconds', 'Unix time the report was generated.' )->add( $report->generated_at, $const_labels );
		$get( 'report_duration_seconds', 'Time taken to run all checks in seconds.' )->add( $report->duration, $const_labels );
		$get( 'report_cached', 'Whether the report was served from the cache (1 = yes).' )->add( $report->cached ? 1 : 0, $const_labels );

		foreach ( $report->results() as $id => $result ) {
			$labels = array_merge( array( 'check' => (string) $id ), $const_labels );
			$get( 'check_skipped', 'Whether a check did not apply on this site (1 = skipped).' )->add( $result->skipped ? 1 : 0, $labels );
			if ( ! $result->skipped ) {
				$get( 'check_status', 'Check status: 0 = ok, 1 = warn, 2 = critical.' )->add( Status::severity( $result->status ), $labels );
			}
			$get( 'check_duration_seconds', 'Time taken by a check in seconds.' )->add( $result->duration, $labels );
		}

		// Samples from checks, including custom ones: anything that would make
		// the exposition invalid (bad names, a second type for a name, a
		// repeated label set) is dropped instead of breaking the scrape.
		foreach ( $report->results() as $result ) {
			foreach ( $result->samples as $sample ) {
				$name = self::PREFIX . $sample->name;
				if ( ! MetricFamily::is_valid_name( $name ) || ! in_array( $sample->type, MetricFamily::TYPES, true ) ) {
					continue;
				}
				if ( isset( $families[ $name ] ) && $families[ $name ]->type() !== $sample->type ) {
					continue;
				}
				try {
					$get( $sample->name, $sample->help, $sample->type )->add( $sample->value, array_merge( $sample->labels, $const_labels ) );
				} catch ( InvalidArgumentException $e ) {
					continue;
				}
			}
		}

		return array_values( $families );
	}

	/**
	 * Render a report.
	 *
	 * @param Report               $report Report.
	 * @param array<string,string> $info Info labels.
	 * @param array<string,string> $const_labels Labels for every sample.
	 */
	public static function render( Report $report, array $info = array(), array $const_labels = array() ): string {
		return Formatter::render( self::to_families( $report, $info, $const_labels ) );
	}
}
