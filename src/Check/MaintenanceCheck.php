<?php
/**
 * Maintenance mode check.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Check;

use Saizul\HealthApi\CheckResult;
use Saizul\HealthApi\Maintenance\MaintenanceFile;
use Saizul\HealthApi\Probe\MaintenanceProbe;
use Saizul\HealthApi\Sample;
use Saizul\HealthApi\Status;
use Saizul\HealthApi\Support\Clock;
use Saizul\HealthApi\Support\Duration;

/**
 * Whether the site is in maintenance mode.
 */
final class MaintenanceCheck implements Check {

	/**
	 * Probe.
	 *
	 * @var MaintenanceProbe
	 */
	private $probe;

	/**
	 * Clock.
	 *
	 * @var Clock
	 */
	private $clock;

	/**
	 * Status reported while maintenance mode is active.
	 *
	 * @var string
	 */
	private $active_status;

	/**
	 * Constructor.
	 *
	 * @param MaintenanceProbe $probe Probe.
	 * @param Clock            $clock Clock.
	 * @param string           $active_status Status while active.
	 */
	public function __construct( MaintenanceProbe $probe, Clock $clock, string $active_status = Status::WARN ) {
		Status::severity( $active_status );
		$this->probe         = $probe;
		$this->clock         = $clock;
		$this->active_status = $active_status;
	}

	/**
	 * Id.
	 */
	public function id(): string {
		return 'maintenance';
	}

	/**
	 * Run.
	 */
	public function run(): CheckResult {
		$contents = $this->probe->file_contents();
		$state    = MaintenanceFile::state( $contents, $this->clock->now() );
		$core     = $this->probe->core_state();
		$active   = null === $core ? $state['active'] : $core;

		if ( $active ) {
			$status  = $this->active_status;
			$message = null !== $state['age'] && $state['active']
				? sprintf( 'Maintenance mode is active (started %s ago).', Duration::format( $state['age'] ) )
				: 'Maintenance mode is active.';
		} elseif ( $state['stale'] ) {
			$status  = Status::WARN;
			$message = sprintf( 'A stale .maintenance file from %s ago is left over from an interrupted update.', Duration::format( (int) $state['age'] ) );
		} else {
			$status  = Status::OK;
			$message = 'The site is not in maintenance mode.';
		}

		return new CheckResult(
			$this->id(),
			$status,
			$message,
			array(
				'active'     => $active,
				'file'       => null !== $contents,
				'stale_file' => $state['stale'],
				'started'    => $state['started'],
				'core_state' => $core,
			),
			array( Sample::gauge( 'maintenance_mode', $active, 'Whether the site is in maintenance mode (1 = yes).' ) )
		);
	}
}
