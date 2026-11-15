<?php
/**
 * Rate limiter answer.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\RateLimit;

/**
 * Whether a hit is allowed, plus the values for RateLimit headers.
 */
final class RateLimitResult {

	/**
	 * Allowed.
	 *
	 * @var bool
	 */
	public $allowed;

	/**
	 * Limit per window (0 = unlimited).
	 *
	 * @var int
	 */
	public $limit;

	/**
	 * Hits left in this window.
	 *
	 * @var int
	 */
	public $remaining;

	/**
	 * Seconds until the window resets.
	 *
	 * @var int
	 */
	public $retry_after;

	/**
	 * Constructor.
	 *
	 * @param bool $allowed Allowed.
	 * @param int  $limit Limit.
	 * @param int  $remaining Remaining.
	 * @param int  $retry_after Seconds until reset.
	 */
	public function __construct( bool $allowed, int $limit, int $remaining, int $retry_after ) {
		$this->allowed     = $allowed;
		$this->limit       = $limit;
		$this->remaining   = $remaining;
		$this->retry_after = $retry_after;
	}

	/**
	 * Response headers.
	 *
	 * @return array<string,string>
	 */
	public function headers(): array {
		if ( 0 === $this->limit ) {
			return array();
		}
		$headers = array(
			'X-RateLimit-Limit'     => (string) $this->limit,
			'X-RateLimit-Remaining' => (string) $this->remaining,
			'X-RateLimit-Reset'     => (string) $this->retry_after,
		);
		if ( ! $this->allowed ) {
			$headers['Retry-After'] = (string) $this->retry_after;
		}

		return $headers;
	}
}
