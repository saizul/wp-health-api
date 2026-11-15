<?php
/**
 * Store backed by transients.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\WP;

use Saizul\HealthApi\Support\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Transients use the persistent object cache when one is installed and
 * the options table otherwise.
 */
final class TransientStore implements Store {

	/**
	 * Read a value.
	 *
	 * @param string $key Key.
	 * @return mixed|null
	 */
	public function get( string $key ) {
		$value = get_transient( Options::TRANSIENT . $key );

		return false === $value ? null : $value;
	}

	/**
	 * Write a value.
	 *
	 * @param string $key Key.
	 * @param mixed  $value Value.
	 * @param int    $ttl Seconds.
	 */
	public function set( string $key, $value, int $ttl ): void {
		set_transient( Options::TRANSIENT . $key, $value, max( 1, $ttl ) );
	}

	/**
	 * Remove a value.
	 *
	 * @param string $key Key.
	 */
	public function delete( string $key ): void {
		delete_transient( Options::TRANSIENT . $key );
	}
}
