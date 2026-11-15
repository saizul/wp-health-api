<?php
/**
 * WP-Cron check.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Check;

use Saizul\HealthApi\CheckResult;
use Saizul\HealthApi\Cron\CronAnalyzer;
use Saizul\HealthApi\Probe\CronProbe;
use Saizul\HealthApi\Sample;
use Saizul\HealthApi\Support\Clock;
use Saizul\HealthApi\Support\Duration;
use Saizul\HealthApi\Threshold;

/**
 * Overdue WP-Cron events and the time since cron last ran.
 */
final class CronCheck implements Check {

	/**
	 * Probe.
	 *
	 * @var CronProbe
	 */
	private $probe;

	/**
	 * Clock.
	 *
	 * @var Clock
	 */
	private $clock;

	/**
	 * Overdue events threshold.
	 *
	 * @var Threshold
	 */
	private $overdue;

	/**
	 * Seconds since last run threshold.
	 *
	 * @var Threshold
	 */
	private $last_run;

	/**
	 * Grace period in seconds.
	 *
	 * @var int
	 */
	private $grace;

	/**
	 * Constructor.
	 *
	 * @param CronProbe $probe Probe.
	 * @param Clock     $clock Clock.
	 * @param Threshold $overdue Overdue events threshold.
	 * @param Threshold $last_run Seconds since the last run threshold.
	 * @param int       $grace Seconds an event may be late.
	 */
	public function __construct( CronProbe $probe, Clock $clock, Threshold $overdue, Threshold $last_run, int $grace = 600 ) {
		$this->probe    = $probe;
		$this->clock    = $clock;
		$this->overdue  = $overdue;
		$this->last_run = $last_run;
		$this->grace    = $grace;
	}

	/**
	 * Id.
	 */
	public function id(): string {
		return 'cron';
	}

	/**
	 * Run.
	 */
	public function run(): CheckResult {
		$now      = $this->clock->now();
		$analysis = CronAnalyzer::analyse( $this->probe->events(), $now, $this->grace );
		$last_run = $this->probe->last_run();
		$disabled = $this->probe->is_disabled();
		$age      = null === $last_run ? null : max( 0, $now - $last_run );
		$verdict  = new Verdict();

		$verdict->threshold( 'overdue_events', $this->overdue, (float) $analysis['overdue'], '%s overdue cron events (limit %s)' );
		$verdict->threshold(
			'last_run_age',
			$this->last_run,
			null === $age ? null : (float) $age,
			'Cron last ran %s ago (limit %s)',
			static function ( float $seconds ): string {
				return Duration::format( (int) $seconds );
			}
		);

		$samples = array(
			Sample::gauge( 'cron_events', $analysis['events'], 'Number of scheduled WP-Cron events.' ),
			Sample::gauge( 'cron_overdue_events', $analysis['overdue'], 'Number of WP-Cron events past their scheduled time plus the grace period.' ),
			Sample::gauge( 'cron_oldest_overdue_seconds', (float) ( $analysis['oldest_overdue_age'] ?? 0 ), 'Age in seconds of the oldest overdue WP-Cron event (0 when none).' ),
			Sample::gauge( 'cron_disabled', $disabled, 'Whether DISABLE_WP_CRON is set (1 = yes).' ),
		);
		if ( null !== $last_run ) {
			$samples[] = Sample::gauge( 'cron_last_run_timestamp_seconds', $last_run, 'Unix time of the last recorded WP-Cron run.' );
		}

		$ok = sprintf( '%d events scheduled, none overdue.', $analysis['events'] );
		if ( $analysis['overdue'] > 0 ) {
			$ok = sprintf( '%d of %d events overdue.', $analysis['overdue'], $analysis['events'] );
		}

		return new CheckResult(
			$this->id(),
			$verdict->status(),
			$verdict->message( $ok ),
			array(
				'events'                     => $analysis['events'],
				'overdue'                    => $analysis['overdue'],
				'oldest_overdue_age_seconds' => $analysis['oldest_overdue_age'],
				'overdue_hooks'              => array_slice( $analysis['overdue_hooks'], 0, 10, true ),
				'next_run'                   => $analysis['next_run'],
				'last_run'                   => $last_run,
				'last_run_age_seconds'       => $age,
				'grace_seconds'              => $this->grace,
				'disabled'                   => $disabled,
				'breaches'                   => $verdict->breaches(),
			),
			$samples
		);
	}
}
