<?php
/**
 * Action Scheduler check.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Check;

use Saizul\HealthApi\CheckResult;
use Saizul\HealthApi\Probe\ActionSchedulerProbe;
use Saizul\HealthApi\Sample;
use Saizul\HealthApi\Support\Clock;
use Saizul\HealthApi\Threshold;

/**
 * Past-due and recently failed Action Scheduler actions.
 */
final class ActionSchedulerCheck implements Check {

	/**
	 * Probe.
	 *
	 * @var ActionSchedulerProbe
	 */
	private $probe;

	/**
	 * Clock.
	 *
	 * @var Clock
	 */
	private $clock;

	/**
	 * Past-due threshold.
	 *
	 * @var Threshold
	 */
	private $past_due;

	/**
	 * Failed in the last 24 hours threshold.
	 *
	 * @var Threshold
	 */
	private $failed;

	/**
	 * Grace period in seconds.
	 *
	 * @var int
	 */
	private $grace;

	/**
	 * Constructor.
	 *
	 * @param ActionSchedulerProbe $probe Probe.
	 * @param Clock                $clock Clock.
	 * @param Threshold            $past_due Past-due threshold.
	 * @param Threshold            $failed Failed (24 h) threshold.
	 * @param int                  $grace Seconds an action may be late.
	 */
	public function __construct( ActionSchedulerProbe $probe, Clock $clock, Threshold $past_due, Threshold $failed, int $grace = 600 ) {
		$this->probe    = $probe;
		$this->clock    = $clock;
		$this->past_due = $past_due;
		$this->failed   = $failed;
		$this->grace    = $grace;
	}

	/**
	 * Id.
	 */
	public function id(): string {
		return 'action_scheduler';
	}

	/**
	 * Run.
	 */
	public function run(): CheckResult {
		if ( ! $this->probe->is_available() ) {
			return CheckResult::skipped( $this->id(), 'Action Scheduler is not loaded.' );
		}
		$now      = $this->clock->now();
		$cutoff   = $now - max( 0, $this->grace );
		$past_due = $this->probe->past_due( $cutoff );
		$failed   = $this->probe->failed_since( $now - 86400 );
		$oldest   = $past_due > 0 ? $this->probe->oldest_past_due( $cutoff ) : null;
		$verdict  = new Verdict();

		$verdict->threshold( 'past_due', $this->past_due, (float) $past_due, '%s past-due actions (limit %s)' );
		$verdict->threshold( 'failed_24h', $this->failed, (float) $failed, '%s actions failed in the last 24 hours (limit %s)' );

		return new CheckResult(
			$this->id(),
			$verdict->status(),
			$verdict->message( sprintf( '%d past-due, %d failed in the last 24 hours.', $past_due, $failed ) ),
			array(
				'past_due'            => $past_due,
				'failed_24h'          => $failed,
				'oldest_past_due'     => $oldest,
				'oldest_past_due_age' => null === $oldest ? null : max( 0, $now - $oldest ),
				'grace_seconds'       => $this->grace,
				'breaches'            => $verdict->breaches(),
			),
			array(
				Sample::gauge( 'action_scheduler_past_due_actions', $past_due, 'Pending Action Scheduler actions past their scheduled time plus the grace period.' ),
				Sample::gauge( 'action_scheduler_failed_actions_24h', $failed, 'Action Scheduler actions that failed in the last 24 hours.' ),
			)
		);
	}
}
