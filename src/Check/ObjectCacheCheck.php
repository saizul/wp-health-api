<?php
/**
 * Object cache check.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Check;

use Saizul\HealthApi\CheckResult;
use Saizul\HealthApi\Probe\ObjectCacheProbe;
use Saizul\HealthApi\Sample;
use Saizul\HealthApi\Status;
use Saizul\HealthApi\Threshold;

/**
 * Object cache backend and a write/read round trip.
 *
 * Without a persistent cache the round trip only exercises WordPress's
 * in-request array cache, so it is reported but cannot fail meaningfully.
 */
final class ObjectCacheCheck implements Check {

	/**
	 * Probe.
	 *
	 * @var ObjectCacheProbe
	 */
	private $probe;

	/**
	 * Latency threshold in ms.
	 *
	 * @var Threshold
	 */
	private $latency;

	/**
	 * Whether a missing persistent cache is a warning.
	 *
	 * @var bool
	 */
	private $require_persistent;

	/**
	 * Constructor.
	 *
	 * @param ObjectCacheProbe $probe Probe.
	 * @param Threshold        $latency Round trip latency threshold in ms.
	 * @param bool             $require_persistent Warn without a persistent cache.
	 */
	public function __construct( ObjectCacheProbe $probe, Threshold $latency, bool $require_persistent = false ) {
		$this->probe              = $probe;
		$this->latency            = $latency;
		$this->require_persistent = $require_persistent;
	}

	/**
	 * Id.
	 */
	public function id(): string {
		return 'object_cache';
	}

	/**
	 * Run.
	 */
	public function run(): CheckResult {
		$backend    = $this->probe->backend();
		$persistent = $this->probe->is_persistent();
		$trip       = $this->probe->round_trip();
		$ok         = ! empty( $trip['ok'] );
		$latency    = isset( $trip['latency'] ) ? (float) $trip['latency'] : null;
		$verdict    = new Verdict();

		if ( ! $persistent && $this->require_persistent ) {
			$verdict->add( Status::WARN, 'No persistent object cache is installed' );
		}
		if ( ! $ok ) {
			$verdict->add(
				$persistent ? Status::CRITICAL : Status::WARN,
				'Object cache round trip failed' . ( '' !== (string) ( $trip['error'] ?? '' ) ? ': ' . $trip['error'] : '' )
			);
		} elseif ( $persistent && null !== $latency ) {
			$verdict->threshold( 'latency_ms', $this->latency, $latency * 1000, 'Object cache round trip took %s ms (limit %s ms)' );
		}

		$samples = array(
			Sample::gauge( 'object_cache_persistent', $persistent, 'Whether a persistent object cache drop-in is active (1 = yes).', array( 'backend' => $backend ) ),
			Sample::gauge( 'object_cache_hit_test', $ok, 'Whether a value written to the object cache could be read back (1 = yes).' ),
		);
		if ( null !== $latency ) {
			$samples[] = Sample::gauge( 'object_cache_latency_seconds', $latency, 'Time taken by the object cache write/read/delete test in seconds.' );
		}

		return new CheckResult(
			$this->id(),
			$verdict->status(),
			$verdict->message( $persistent ? sprintf( 'Persistent object cache (%s) is working.', $backend ) : 'No persistent object cache; using the in-request cache.' ),
			array(
				'backend'    => $backend,
				'persistent' => $persistent,
				'hit_test'   => $ok,
				'latency_ms' => null === $latency ? null : round( $latency * 1000, 3 ),
				'breaches'   => $verdict->breaches(),
			),
			$samples
		);
	}
}
