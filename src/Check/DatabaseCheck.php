<?php
/**
 * Database check.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Check;

use Saizul\HealthApi\CheckResult;
use Saizul\HealthApi\Probe\DatabaseProbe;
use Saizul\HealthApi\Sample;
use Saizul\HealthApi\Status;
use Saizul\HealthApi\Threshold;

/**
 * Database connectivity and query latency.
 */
final class DatabaseCheck implements Check {

	/**
	 * Probe.
	 *
	 * @var DatabaseProbe
	 */
	private $probe;

	/**
	 * Latency threshold in milliseconds.
	 *
	 * @var Threshold
	 */
	private $latency;

	/**
	 * Constructor.
	 *
	 * @param DatabaseProbe $probe Probe.
	 * @param Threshold     $latency Latency threshold in ms.
	 */
	public function __construct( DatabaseProbe $probe, Threshold $latency ) {
		$this->probe   = $probe;
		$this->latency = $latency;
	}

	/**
	 * Id.
	 */
	public function id(): string {
		return 'database';
	}

	/**
	 * Run.
	 */
	public function run(): CheckResult {
		$ping    = $this->probe->ping();
		$up      = ! empty( $ping['connected'] );
		$latency = isset( $ping['latency'] ) ? (float) $ping['latency'] : null;
		$samples = array( Sample::gauge( 'database_up', $up, 'Whether the database answered a test query (1 = yes).' ) );
		if ( ! $up ) {
			return new CheckResult(
				$this->id(),
				Status::CRITICAL,
				'Database query failed' . ( '' !== (string) ( $ping['error'] ?? '' ) ? ': ' . $ping['error'] : '.' ),
				array( 'connected' => false ),
				$samples
			);
		}
		$ms      = null === $latency ? null : $latency * 1000;
		$verdict = new Verdict();
		$verdict->threshold( 'latency_ms', $this->latency, $ms, 'Query took %s ms (limit %s ms)' );
		if ( null !== $latency ) {
			$samples[] = Sample::gauge( 'database_latency_seconds', $latency, 'Time taken by the database test query in seconds.' );
		}

		return new CheckResult(
			$this->id(),
			$verdict->status(),
			$verdict->message( null === $ms ? 'Database is reachable.' : sprintf( 'Database is reachable (%s ms).', Verdict::number( round( $ms, 2 ) ) ) ),
			array(
				'connected'  => true,
				'latency_ms' => null === $ms ? null : round( $ms, 3 ),
				'server'     => (string) ( $ping['server'] ?? '' ),
				'breaches'   => $verdict->breaches(),
			),
			$samples
		);
	}
}
