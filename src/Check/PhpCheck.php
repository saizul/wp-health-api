<?php
/**
 * PHP runtime check.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Check;

use Saizul\HealthApi\CheckResult;
use Saizul\HealthApi\Probe\PhpProbe;
use Saizul\HealthApi\Sample;
use Saizul\HealthApi\Status;
use Saizul\HealthApi\Support\Bytes;
use Saizul\HealthApi\Threshold;

/**
 * PHP version, memory limit and OPcache.
 */
final class PhpCheck implements Check {

	/**
	 * Hits plus misses needed before the hit rate is judged; right after a
	 * restart the rate is low for a while.
	 */
	const MIN_OPCACHE_REQUESTS = 1000;

	/**
	 * Probe.
	 *
	 * @var PhpProbe
	 */
	private $probe;

	/**
	 * Options.
	 *
	 * @var array{warn_below:string,critical_below:string,require_opcache:bool}
	 */
	private $options;

	/**
	 * Memory limit threshold in MiB.
	 *
	 * @var Threshold
	 */
	private $memory;

	/**
	 * OPcache hit percent threshold.
	 *
	 * @var Threshold
	 */
	private $hit_rate;

	/**
	 * OPcache free memory percent threshold.
	 *
	 * @var Threshold
	 */
	private $free_memory;

	/**
	 * Constructor.
	 *
	 * @param PhpProbe                                                               $probe Probe.
	 * @param Threshold                                                              $memory Memory limit in MiB.
	 * @param Threshold                                                              $hit_rate OPcache hit rate in percent.
	 * @param Threshold                                                              $free_memory OPcache free memory in percent.
	 * @param array{warn_below?:string,critical_below?:string,require_opcache?:bool} $options Options.
	 */
	public function __construct( PhpProbe $probe, Threshold $memory, Threshold $hit_rate, Threshold $free_memory, array $options = array() ) {
		$this->probe       = $probe;
		$this->memory      = $memory;
		$this->hit_rate    = $hit_rate;
		$this->free_memory = $free_memory;
		$this->options     = array(
			'warn_below'      => (string) ( $options['warn_below'] ?? '' ),
			'critical_below'  => (string) ( $options['critical_below'] ?? '' ),
			'require_opcache' => (bool) ( $options['require_opcache'] ?? true ),
		);
	}

	/**
	 * Id.
	 */
	public function id(): string {
		return 'php';
	}

	/**
	 * Run.
	 */
	public function run(): CheckResult {
		$version = $this->probe->version();
		$raw     = $this->probe->memory_limit();
		$limit   = Bytes::parse_ini( $raw );
		$peak    = $this->probe->memory_peak();
		$opcache = $this->probe->opcache();
		$verdict = new Verdict();

		if ( '' !== $this->options['critical_below'] && version_compare( $version, $this->options['critical_below'], '<' ) ) {
			$verdict->add( Status::CRITICAL, sprintf( 'PHP %s is older than %s', $version, $this->options['critical_below'] ) );
		} elseif ( '' !== $this->options['warn_below'] && version_compare( $version, $this->options['warn_below'], '<' ) ) {
			$verdict->add( Status::WARN, sprintf( 'PHP %s is older than %s', $version, $this->options['warn_below'] ) );
		}

		$unlimited = '-1' === trim( $raw );
		if ( null !== $limit ) {
			$verdict->threshold( 'memory_limit_mb', $this->memory, $limit / 1048576, 'memory_limit is %s MiB (limit %s MiB)' );
		}

		$samples = array(
			Sample::gauge( 'php_info', 1, 'PHP version information (always 1).', array( 'version' => $version ) ),
			Sample::gauge( 'php_memory_limit_bytes', $unlimited ? -1 : (float) ( $limit ?? 0 ), 'PHP memory_limit in bytes (-1 = unlimited).' ),
			Sample::gauge( 'php_memory_peak_bytes', $peak, 'Peak memory used by the health request in bytes.' ),
			Sample::gauge( 'opcache_enabled', null !== $opcache && $opcache['enabled'], 'Whether OPcache is enabled (1 = yes).' ),
		);

		$details = array(
			'version'            => $version,
			'memory_limit'       => $raw,
			'memory_limit_bytes' => $unlimited ? -1 : $limit,
			'memory_peak_bytes'  => $peak,
			'opcache'            => null,
		);

		if ( null === $opcache || ! $opcache['enabled'] ) {
			if ( $this->options['require_opcache'] ) {
				$verdict->add( Status::WARN, 'OPcache is not enabled' );
			}
		} else {
			$requests = $opcache['hits'] + $opcache['misses'];
			$hit_rate = $requests > 0 ? $opcache['hits'] / $requests : null;
			$memory   = $opcache['used_memory'] + $opcache['free_memory'] + $opcache['wasted_memory'];
			$free     = $memory > 0 ? $opcache['free_memory'] / $memory : null;
			if ( null !== $hit_rate && $requests >= self::MIN_OPCACHE_REQUESTS ) {
				$verdict->threshold( 'opcache_hit_percent', $this->hit_rate, $hit_rate * 100, 'OPcache hit rate is %s%% (limit %s%%)' );
			}
			if ( null !== $free ) {
				$verdict->threshold( 'opcache_free_memory_percent', $this->free_memory, $free * 100, 'OPcache has %s%% memory free (limit %s%%)' );
			}
			if ( $opcache['cache_full'] ) {
				$verdict->add( Status::WARN, 'OPcache is full' );
			}
			if ( null !== $hit_rate ) {
				$samples[] = Sample::gauge( 'opcache_hit_ratio', $hit_rate, 'OPcache hits divided by hits plus misses (0 to 1).' );
			}
			$samples[]          = Sample::gauge( 'opcache_used_memory_bytes', $opcache['used_memory'], 'Memory used by OPcache in bytes.' );
			$samples[]          = Sample::gauge( 'opcache_free_memory_bytes', $opcache['free_memory'], 'Free OPcache memory in bytes.' );
			$samples[]          = Sample::gauge( 'opcache_wasted_memory_bytes', $opcache['wasted_memory'], 'Wasted OPcache memory in bytes.' );
			$samples[]          = Sample::gauge( 'opcache_cached_scripts', $opcache['cached_scripts'], 'Number of scripts cached by OPcache.' );
			$samples[]          = Sample::gauge( 'opcache_cache_full', $opcache['cache_full'], 'Whether OPcache is full (1 = yes).' );
			$details['opcache'] = array(
				'hit_percent'         => null === $hit_rate ? null : round( $hit_rate * 100, 2 ),
				'free_memory_percent' => null === $free ? null : round( $free * 100, 2 ),
				'cached_scripts'      => $opcache['cached_scripts'],
				'cache_full'          => $opcache['cache_full'],
				'restricted'          => $opcache['restricted'],
			);
		}
		$details['breaches'] = $verdict->breaches();

		return new CheckResult(
			$this->id(),
			$verdict->status(),
			$verdict->message( sprintf( 'PHP %s, memory_limit %s, OPcache %s.', $version, $raw, null !== $opcache && $opcache['enabled'] ? 'on' : 'off' ) ),
			$details,
			$samples
		);
	}
}
