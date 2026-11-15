<?php
/**
 * WooCommerce probe contract.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Probe;

/**
 * Counts recent WooCommerce orders.
 */
interface WooCommerceProbe {

	/**
	 * Whether WooCommerce is active.
	 */
	public function is_available(): bool;

	/**
	 * Orders created since the given time, keyed by status without the
	 * "wc-" prefix, e.g. ["processing" => 4, "failed" => 1].
	 *
	 * @param int $since Unix time.
	 * @return array<string,int>
	 */
	public function orders_since( int $since ): array;
}
