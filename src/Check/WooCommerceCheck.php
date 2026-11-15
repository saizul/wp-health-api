<?php
/**
 * WooCommerce order volume check.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Check;

use Saizul\HealthApi\CheckResult;
use Saizul\HealthApi\Probe\WooCommerceProbe;
use Saizul\HealthApi\Sample;
use Saizul\HealthApi\Support\Clock;
use Saizul\HealthApi\Threshold;

/**
 * Orders created in the last hour. A sudden drop to zero on a busy shop
 * usually means checkout is broken; a spike in failed orders points at the
 * payment gateway.
 */
final class WooCommerceCheck implements Check {

	/**
	 * Probe.
	 *
	 * @var WooCommerceProbe
	 */
	private $probe;

	/**
	 * Clock.
	 *
	 * @var Clock
	 */
	private $clock;

	/**
	 * Orders in the last hour (below).
	 *
	 * @var Threshold
	 */
	private $orders;

	/**
	 * Failed orders in the last hour (above).
	 *
	 * @var Threshold
	 */
	private $failed;

	/**
	 * Constructor.
	 *
	 * @param WooCommerceProbe $probe Probe.
	 * @param Clock            $clock Clock.
	 * @param Threshold        $orders Orders in the last hour.
	 * @param Threshold        $failed Failed orders in the last hour.
	 */
	public function __construct( WooCommerceProbe $probe, Clock $clock, Threshold $orders, Threshold $failed ) {
		$this->probe  = $probe;
		$this->clock  = $clock;
		$this->orders = $orders;
		$this->failed = $failed;
	}

	/**
	 * Id.
	 */
	public function id(): string {
		return 'woocommerce';
	}

	/**
	 * Run.
	 */
	public function run(): CheckResult {
		if ( ! $this->probe->is_available() ) {
			return CheckResult::skipped( $this->id(), 'WooCommerce is not active.' );
		}
		$by_status = array();
		foreach ( $this->probe->orders_since( $this->clock->now() - 3600 ) as $status => $count ) {
			$status               = (string) preg_replace( '/^wc-/', '', (string) $status );
			$by_status[ $status ] = ( $by_status[ $status ] ?? 0 ) + max( 0, (int) $count );
		}
		ksort( $by_status );
		$total   = array_sum( $by_status );
		$failed  = $by_status['failed'] ?? 0;
		$verdict = new Verdict();

		$verdict->threshold( 'orders_last_hour', $this->orders, (float) $total, '%s orders in the last hour (limit %s)' );
		$verdict->threshold( 'failed_orders_last_hour', $this->failed, (float) $failed, '%s failed orders in the last hour (limit %s)' );

		$samples = array( Sample::gauge( 'woocommerce_orders_last_hour_total', $total, 'WooCommerce orders created in the last hour.' ) );
		foreach ( $by_status as $status => $count ) {
			$samples[] = Sample::gauge( 'woocommerce_orders_last_hour', $count, 'WooCommerce orders created in the last hour, by status.', array( 'status' => $status ) );
		}

		return new CheckResult(
			$this->id(),
			$verdict->status(),
			$verdict->message( sprintf( '%d orders in the last hour, %d failed.', $total, $failed ) ),
			array(
				'orders_last_hour'        => $total,
				'failed_orders_last_hour' => $failed,
				'by_status'               => $by_status,
				'breaches'                => $verdict->breaches(),
			),
			$samples
		);
	}
}
