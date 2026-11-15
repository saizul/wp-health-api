<?php
/**
 * Action Scheduler probe.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\WP\Probe;

use ActionScheduler;
use ActionScheduler_Store;
use Saizul\HealthApi\Probe\ActionSchedulerProbe;

defined( 'ABSPATH' ) || exit;

/**
 * Counts actions through the Action Scheduler store API (works with the
 * custom tables store and the older post store).
 */
final class WpActionSchedulerProbe implements ActionSchedulerProbe {

	/**
	 * Whether Action Scheduler is loaded and initialised.
	 */
	public function is_available(): bool {
		return class_exists( 'ActionScheduler' ) && class_exists( 'ActionScheduler_Store' ) && did_action( 'action_scheduler_init' ) > 0;
	}

	/**
	 * Pending actions scheduled before a time.
	 *
	 * @param int $before Unix time.
	 */
	public function past_due( int $before ): int {
		return $this->count(
			array(
				'status'       => ActionScheduler_Store::STATUS_PENDING,
				'date'         => $before,
				'date_compare' => '<=',
			)
		);
	}

	/**
	 * Failed actions since a time.
	 *
	 * @param int $since Unix time.
	 */
	public function failed_since( int $since ): int {
		return $this->count(
			array(
				'status'           => ActionScheduler_Store::STATUS_FAILED,
				'modified'         => $since,
				'modified_compare' => '>=',
			)
		);
	}

	/**
	 * Time of the oldest past-due action.
	 *
	 * @param int $before Unix time.
	 */
	public function oldest_past_due( int $before ): ?int {
		$store = ActionScheduler::store();
		$ids   = $store->query_actions(
			array(
				'status'       => ActionScheduler_Store::STATUS_PENDING,
				'date'         => $before,
				'date_compare' => '<=',
				'orderby'      => 'date',
				'order'        => 'ASC',
				'per_page'     => 1,
			)
		);
		if ( ! is_array( $ids ) || array() === $ids ) {
			return null;
		}
		$date = $store->get_date( (int) reset( $ids ) );

		return $date instanceof \DateTimeInterface ? $date->getTimestamp() : null;
	}

	/**
	 * Count actions matching a query.
	 *
	 * @param array<string,mixed> $query Query.
	 */
	private function count( array $query ): int {
		return (int) ActionScheduler::store()->query_actions( $query, 'count' );
	}
}
