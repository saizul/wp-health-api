<?php
/**
 * Records when WP-Cron last ran.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\WP;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress does not store when cron last ran, so the plugin records it:
 * on every wp-cron.php request, and from a five-minute heartbeat event
 * that also catches system crons and `wp cron event run`.
 */
final class CronRecorder {

	/**
	 * Minimum seconds between two writes.
	 */
	const MIN_INTERVAL = 60;

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_filter( 'cron_schedules', array( $this, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- Five minutes is the intended heartbeat.
		add_action( Options::HEARTBEAT, array( $this, 'record' ) );
		add_action( 'init', array( $this, 'on_init' ) );
	}

	/**
	 * Add the five-minute schedule.
	 *
	 * @param array<string,array<string,mixed>> $schedules Schedules.
	 * @return array<string,array<string,mixed>>
	 */
	public function add_schedule( $schedules ): array {
		$schedules                      = is_array( $schedules ) ? $schedules : array();
		$schedules[ Options::SCHEDULE ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes (Health API heartbeat)', 'wp-health-api' ),
		);

		return $schedules;
	}

	/**
	 * Record cron runs and make sure the heartbeat is scheduled.
	 */
	public function on_init(): void {
		if ( wp_doing_cron() ) {
			$this->record();
		}
		$this->schedule();
	}

	/**
	 * Schedule the heartbeat if it is missing.
	 */
	public function schedule(): void {
		if ( ! wp_next_scheduled( Options::HEARTBEAT ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, Options::SCHEDULE, Options::HEARTBEAT );
		}
	}

	/**
	 * Remove the heartbeat.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( Options::HEARTBEAT );
	}

	/**
	 * Store the current time, at most once a minute.
	 */
	public function record(): void {
		$now  = time();
		$last = (int) get_option( Options::LAST_CRON, 0 );
		if ( $now - $last >= self::MIN_INTERVAL ) {
			update_option( Options::LAST_CRON, $now, false );
		}
	}
}
