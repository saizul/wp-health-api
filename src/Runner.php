<?php
/**
 * Runs checks.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi;

use Saizul\HealthApi\Check\Check;
use Saizul\HealthApi\Support\Clock;
use Throwable;

/**
 * Runs a list of checks, times them and isolates failures: a check that
 * throws becomes a critical result instead of breaking the report.
 */
final class Runner {

	/**
	 * Clock.
	 *
	 * @var Clock
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param Clock $clock Clock.
	 */
	public function __construct( Clock $clock ) {
		$this->clock = $clock;
	}

	/**
	 * Run checks.
	 *
	 * @param Check[] $checks Checks.
	 */
	public function run( array $checks ): Report {
		$started = $this->clock->microtime();
		$results = array();
		foreach ( $checks as $check ) {
			$results[] = $this->run_one( $check );
		}
		$report           = new Report( $results, $this->clock->now() );
		$report->duration = max( 0.0, $this->clock->microtime() - $started );

		return $report;
	}

	/**
	 * Run one check.
	 *
	 * @param Check $check Check.
	 */
	public function run_one( Check $check ): CheckResult {
		$started = $this->clock->microtime();
		try {
			$result = $check->run();
		} catch ( Throwable $e ) {
			$result = new CheckResult(
				$check->id(),
				Status::CRITICAL,
				sprintf( 'The check failed: %s', $e->getMessage() ),
				array( 'exception' => get_class( $e ) )
			);
		}
		$result->id       = $check->id();
		$result->duration = max( 0.0, $this->clock->microtime() - $started );

		return $result;
	}
}
