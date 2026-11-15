<?php
/**
 * WP-Cron probe.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\WP\Probe;

use Saizul\HealthApi\Probe\CronProbe;
use Saizul\HealthApi\WP\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the cron option and the last run recorded by CronRecorder.
 */
final class WpCronProbe implements CronProbe {

	/**
	 * Cron array.
	 *
	 * @return array<int|string,mixed>
	 */
	public function events(): array {
		$cron = function_exists( '_get_cron_array' ) ? _get_cron_array() : get_option( 'cron', array() );

		return is_array( $cron ) ? $cron : array();
	}

	/**
	 * Last recorded run.
	 */
	public function last_run(): ?int {
		$last = (int) get_option( Options::LAST_CRON, 0 );

		return $last > 0 ? $last : null;
	}

	/**
	 * Whether DISABLE_WP_CRON is set.
	 */
	public function is_disabled(): bool {
		return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	}
}
