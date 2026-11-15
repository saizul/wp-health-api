<?php
/**
 * A full health report.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi;

/**
 * Results of all checks plus the overall status.
 */
final class Report {

	/**
	 * Results keyed by check id.
	 *
	 * @var array<string,CheckResult>
	 */
	private $results = array();

	/**
	 * Unix time the report was generated.
	 *
	 * @var int
	 */
	public $generated_at;

	/**
	 * Total run time in seconds.
	 *
	 * @var float
	 */
	public $duration = 0.0;

	/**
	 * Whether the report was served from the cache.
	 *
	 * @var bool
	 */
	public $cached = false;

	/**
	 * Constructor.
	 *
	 * @param CheckResult[] $results Results.
	 * @param int           $generated_at Unix time.
	 */
	public function __construct( array $results, int $generated_at ) {
		foreach ( $results as $result ) {
			$this->results[ $result->id ] = $result;
		}
		$this->generated_at = $generated_at;
	}

	/**
	 * Results keyed by check id.
	 *
	 * @return array<string,CheckResult>
	 */
	public function results(): array {
		return $this->results;
	}

	/**
	 * One result.
	 *
	 * @param string $id Check id.
	 */
	public function result( string $id ): ?CheckResult {
		return $this->results[ $id ] ?? null;
	}

	/**
	 * Worst status of all checks that were not skipped.
	 */
	public function status(): string {
		$statuses = array();
		foreach ( $this->results as $result ) {
			if ( ! $result->skipped ) {
				$statuses[] = $result->status;
			}
		}

		return Status::worst( $statuses );
	}

	/**
	 * Number of checks per status (skipped checks counted separately).
	 *
	 * @return array<string,int>
	 */
	public function counts(): array {
		$counts            = array_fill_keys( Status::all(), 0 );
		$counts['skipped'] = 0;
		foreach ( $this->results as $result ) {
			if ( $result->skipped ) {
				++$counts['skipped'];
			} else {
				++$counts[ $result->status ];
			}
		}

		return $counts;
	}

	/**
	 * Array form for the detailed JSON endpoint.
	 *
	 * @param bool $with_samples Include metric samples (used for caching).
	 * @return array<string,mixed>
	 */
	public function to_array( bool $with_samples = false ): array {
		$checks = array();
		foreach ( $this->results as $id => $result ) {
			$checks[ $id ] = $result->to_array( $with_samples );
		}

		return array(
			'status'       => $this->status(),
			'generated_at' => gmdate( 'c', $this->generated_at ),
			'timestamp'    => $this->generated_at,
			'duration_ms'  => round( $this->duration * 1000, 2 ),
			'cached'       => $this->cached,
			'counts'       => $this->counts(),
			'checks'       => $checks,
		);
	}

	/**
	 * Rebuild from to_array( true ) output.
	 *
	 * @param array<string,mixed> $data Data.
	 */
	public static function from_array( array $data ): self {
		$results = array();
		foreach ( is_array( $data['checks'] ?? null ) ? $data['checks'] : array() as $id => $check ) {
			$results[] = CheckResult::from_array( (string) $id, is_array( $check ) ? $check : array() );
		}
		$report           = new self( $results, (int) ( $data['timestamp'] ?? 0 ) );
		$report->duration = (float) ( $data['duration_ms'] ?? 0 ) / 1000;

		return $report;
	}
}
