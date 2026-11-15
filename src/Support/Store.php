<?php
/**
 * Key/value store with expiry.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Support;

/**
 * Small cache abstraction: transients in WordPress, an array in tests.
 */
interface Store {

	/**
	 * Read a value.
	 *
	 * @param string $key Key.
	 * @return mixed|null Null when missing or expired.
	 */
	public function get( string $key );

	/**
	 * Write a value.
	 *
	 * @param string $key Key.
	 * @param mixed  $value Value (must be serialisable).
	 * @param int    $ttl Lifetime in seconds, at least 1.
	 */
	public function set( string $key, $value, int $ttl ): void;

	/**
	 * Remove a value.
	 *
	 * @param string $key Key.
	 */
	public function delete( string $key ): void;
}
