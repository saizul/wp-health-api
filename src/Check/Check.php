<?php
/**
 * Check contract.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Check;

use Saizul\HealthApi\CheckResult;

/**
 * A single health check.
 *
 * Checks read their data through a probe (the only part that touches
 * WordPress) and turn it into a status with thresholds. A check may throw;
 * the runner turns exceptions into critical results.
 */
interface Check {

	/**
	 * Stable id, used in URLs, settings and metric labels.
	 */
	public function id(): string;

	/**
	 * Run the check.
	 */
	public function run(): CheckResult;
}
