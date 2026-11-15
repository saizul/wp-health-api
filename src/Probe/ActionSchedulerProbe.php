<?php
/**
 * Action Scheduler probe contract.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Probe;

/**
 * Counts Action Scheduler actions.
 */
interface ActionSchedulerProbe {

	/**
	 * Whether Action Scheduler is loaded.
	 */
	public function is_available(): bool;

	/**
	 * Pending actions scheduled before the given time.
	 *
	 * @param int $before Unix time.
	 */
	public function past_due( int $before ): int;

	/**
	 * Actions that failed since the given time.
	 *
	 * @param int $since Unix time.
	 */
	public function failed_since( int $since ): int;

	/**
	 * Unix time of the oldest past-due pending action, or null.
	 *
	 * @param int $before Unix time.
	 */
	public function oldest_past_due( int $before ): ?int;
}
