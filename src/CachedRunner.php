<?php
/**
 * Report cache.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi;

use Saizul\HealthApi\Check\Check;
use Saizul\HealthApi\Support\Store;

/**
 * Serves a recent report from a store instead of re-running every check,
 * so frequent probes from several monitors do not add load.
 *
 * A short lock prevents a stampede: while one request runs the checks,
 * concurrent requests get the previous report if one exists.
 */
final class CachedRunner {

	const KEY      = 'report';
	const LOCK     = 'report_lock';
	const STALE    = 'report_stale';
	const LOCK_TTL = 30;

	/**
	 * Runner.
	 *
	 * @var Runner
	 */
	private $runner;

	/**
	 * Store.
	 *
	 * @var Store
	 */
	private $store;

	/**
	 * Lifetime in seconds; 0 disables caching.
	 *
	 * @var int
	 */
	private $ttl;

	/**
	 * Constructor.
	 *
	 * @param Runner $runner Runner.
	 * @param Store  $store Store.
	 * @param int    $ttl Lifetime in seconds.
	 */
	public function __construct( Runner $runner, Store $store, int $ttl ) {
		$this->runner = $runner;
		$this->store  = $store;
		$this->ttl    = max( 0, $ttl );
	}

	/**
	 * Get a report.
	 *
	 * @param Check[] $checks Checks to run on a cache miss.
	 * @param string  $variant Cache variant (e.g. a hash of the enabled checks).
	 * @param bool    $fresh Skip the cache.
	 */
	public function report( array $checks, string $variant = '', bool $fresh = false ): Report {
		if ( 0 === $this->ttl ) {
			return $this->runner->run( $checks );
		}
		$key = self::KEY . ( '' === $variant ? '' : '_' . $variant );
		if ( ! $fresh ) {
			$cached = $this->load( $key );
			if ( null !== $cached ) {
				return $cached;
			}
			if ( null !== $this->store->get( self::LOCK . $variant ) ) {
				$stale = $this->load( self::STALE . $variant );
				if ( null !== $stale ) {
					return $stale;
				}
			}
		}

		$this->store->set( self::LOCK . $variant, 1, self::LOCK_TTL );
		$report = $this->runner->run( $checks );
		$data   = $report->to_array( true );
		$this->store->set( $key, $data, $this->ttl );
		$this->store->set( self::STALE . $variant, $data, max( 300, $this->ttl * 10 ) );
		$this->store->delete( self::LOCK . $variant );

		return $report;
	}

	/**
	 * Load a cached report.
	 *
	 * @param string $key Key.
	 */
	private function load( string $key ): ?Report {
		$data = $this->store->get( $key );
		if ( ! is_array( $data ) || ! isset( $data['checks'] ) ) {
			return null;
		}
		$report         = Report::from_array( $data );
		$report->cached = true;

		return $report;
	}

	/**
	 * Drop the cached reports.
	 *
	 * @param string $variant Variant.
	 */
	public function flush( string $variant = '' ): void {
		$this->store->delete( self::KEY . ( '' === $variant ? '' : '_' . $variant ) );
		$this->store->delete( self::STALE . $variant );
	}
}
