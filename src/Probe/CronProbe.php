<?php
/**
 * WP-Cron probe contract.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Probe;

/**
 * Reads the WP-Cron schedule.
 */
interface CronProbe {

	/**
	 * The raw cron array as returned by _get_cron_array().
	 *
	 * @return array<int|string,mixed>
	 */
	public function events(): array;

	/**
	 * Unix time of the last recorded cron run, or null if never seen.
	 */
	public function last_run(): ?int;

	/**
	 * Whether DISABLE_WP_CRON is set (cron is then triggered by a system cron).
	 */
	public function is_disabled(): bool;
}
