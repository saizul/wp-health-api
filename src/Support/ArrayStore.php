<?php
/**
 * In-memory store.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Support;

/**
 * Store kept in a PHP array, honouring expiry through a clock.
 */
final class ArrayStore implements Store {

	/**
	 * Entries: key => [value, expires_at].
	 *
	 * @var array<string,array{0:mixed,1:int}>
	 */
	private $items = array();

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
	 * Read a value.
	 *
	 * @param string $key Key.
	 * @return mixed|null
	 */
	public function get( string $key ) {
		if ( ! isset( $this->items[ $key ] ) ) {
			return null;
		}
		if ( $this->items[ $key ][1] <= $this->clock->now() ) {
			unset( $this->items[ $key ] );
			return null;
		}

		return $this->items[ $key ][0];
	}

	/**
	 * Write a value.
	 *
	 * @param string $key Key.
	 * @param mixed  $value Value.
	 * @param int    $ttl Lifetime in seconds.
	 */
	public function set( string $key, $value, int $ttl ): void {
		$this->items[ $key ] = array( $value, $this->clock->now() + max( 1, $ttl ) );
	}

	/**
	 * Remove a value.
	 *
	 * @param string $key Key.
	 */
	public function delete( string $key ): void {
		unset( $this->items[ $key ] );
	}

	/**
	 * Number of live entries (for tests).
	 */
	public function count(): int {
		$now = $this->clock->now();

		return count(
			array_filter(
				$this->items,
				static function ( array $item ) use ( $now ): bool {
					return $item[1] > $now;
				}
			)
		);
	}
}
