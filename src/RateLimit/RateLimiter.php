<?php
/**
 * Fixed-window rate limiter.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\RateLimit;

use Saizul\HealthApi\Support\Clock;
use Saizul\HealthApi\Support\Store;

/**
 * Counts hits per bucket in fixed time windows.
 *
 * With transients or a non-atomic object cache, concurrent requests may
 * occasionally both pass at the edge of the limit; the limiter is meant to
 * stop floods, not to meter exactly.
 */
final class RateLimiter {

	/**
	 * Store.
	 *
	 * @var Store
	 */
	private $store;

	/**
	 * Clock.
	 *
	 * @var Clock
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param Store $store Store.
	 * @param Clock $clock Clock.
	 */
	public function __construct( Store $store, Clock $clock ) {
		$this->store = $store;
		$this->clock = $clock;
	}

	/**
	 * Count a hit and tell whether it is allowed.
	 *
	 * @param string $bucket Bucket, e.g. "status:<ip hash>".
	 * @param int    $limit Hits allowed per window; 0 = unlimited.
	 * @param int    $window Window length in seconds.
	 */
	public function hit( string $bucket, int $limit, int $window ): RateLimitResult {
		if ( $limit <= 0 ) {
			return new RateLimitResult( true, 0, 0, 0 );
		}
		$window = max( 1, $window );
		$now    = $this->clock->now();
		$start  = $now - ( $now % $window );
		$reset  = $start + $window;
		$key    = 'rl_' . md5( $bucket ) . '_' . $start;
		$count  = (int) $this->store->get( $key ) + 1;
		if ( $count > $limit ) {
			return new RateLimitResult( false, $limit, 0, max( 1, $reset - $now ) );
		}
		$this->store->set( $key, $count, max( 1, $reset - $now ) );

		return new RateLimitResult( true, $limit, $limit - $count, max( 1, $reset - $now ) );
	}

	/**
	 * Whether a bucket is already over its limit, without counting a hit.
	 *
	 * @param string $bucket Bucket.
	 * @param int    $limit Limit; 0 = unlimited.
	 * @param int    $window Window in seconds.
	 */
	public function is_blocked( string $bucket, int $limit, int $window ): bool {
		if ( $limit <= 0 ) {
			return false;
		}
		$window = max( 1, $window );
		$now    = $this->clock->now();
		$key    = 'rl_' . md5( $bucket ) . '_' . ( $now - ( $now % $window ) );

		return (int) $this->store->get( $key ) >= $limit;
	}
}
