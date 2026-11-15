<?php
/**
 * WooCommerce probe.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\WP\Probe;

use Saizul\HealthApi\Probe\WooCommerceProbe;

defined( 'ABSPATH' ) || exit;

/**
 * Counts recent orders with wc_get_orders(), so it works with both the
 * posts table and High-Performance Order Storage.
 */
final class WcOrdersProbe implements WooCommerceProbe {

	/**
	 * Whether WooCommerce is active.
	 */
	public function is_available(): bool {
		return function_exists( 'wc_get_orders' ) && function_exists( 'wc_get_order_statuses' );
	}

	/**
	 * Orders since a time, per status.
	 *
	 * @param int $since Unix time.
	 * @return array<string,int>
	 */
	public function orders_since( int $since ): array {
		$counts = array();
		foreach ( array_keys( wc_get_order_statuses() ) as $status ) {
			$result = wc_get_orders(
				array(
					'type'         => 'shop_order',
					'status'       => array( $status ),
					'date_created' => '>' . $since,
					'limit'        => 1,
					'return'       => 'ids',
					'paginate'     => true,
				)
			);
			$total  = is_object( $result ) && isset( $result->total ) ? (int) $result->total : 0;
			if ( $total > 0 ) {
				$counts[ (string) preg_replace( '/^wc-/', '', (string) $status ) ] = $total;
			}
		}

		return $counts;
	}
}
