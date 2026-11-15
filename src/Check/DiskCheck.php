<?php
/**
 * Disk space check.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Check;

use Saizul\HealthApi\CheckResult;
use Saizul\HealthApi\Probe\DiskProbe;
use Saizul\HealthApi\Sample;
use Saizul\HealthApi\Status;
use Saizul\HealthApi\Support\Bytes;
use Saizul\HealthApi\Threshold;

/**
 * Free space on the file system holding the uploads directory.
 */
final class DiskCheck implements Check {

	/**
	 * Probe.
	 *
	 * @var DiskProbe
	 */
	private $probe;

	/**
	 * Free percent threshold.
	 *
	 * @var Threshold
	 */
	private $percent;

	/**
	 * Free MiB threshold.
	 *
	 * @var Threshold
	 */
	private $megabytes;

	/**
	 * Constructor.
	 *
	 * @param DiskProbe $probe Probe.
	 * @param Threshold $percent Free space in percent.
	 * @param Threshold $megabytes Free space in MiB.
	 */
	public function __construct( DiskProbe $probe, Threshold $percent, Threshold $megabytes ) {
		$this->probe     = $probe;
		$this->percent   = $percent;
		$this->megabytes = $megabytes;
	}

	/**
	 * Id.
	 */
	public function id(): string {
		return 'disk';
	}

	/**
	 * Run.
	 */
	public function run(): CheckResult {
		$free     = $this->probe->free_bytes();
		$total    = $this->probe->total_bytes();
		$writable = $this->probe->is_writable();
		$verdict  = new Verdict();
		$samples  = array( Sample::gauge( 'uploads_writable', $writable, 'Whether the uploads directory is writable (1 = yes).' ) );

		if ( ! $writable ) {
			$verdict->add( Status::CRITICAL, 'The uploads directory is not writable' );
		}
		if ( null === $free ) {
			return new CheckResult(
				$this->id(),
				$verdict->status(),
				$verdict->message( 'Free disk space cannot be read on this host.' ),
				array(
					'path'     => $this->probe->path(),
					'writable' => $writable,
				),
				$samples
			);
		}

		$ratio = null !== $total && $total > 0 ? $free / $total : null;
		$verdict->threshold( 'free_percent', $this->percent, null === $ratio ? null : $ratio * 100, '%s%% disk space free (limit %s%%)' );
		$verdict->threshold(
			'free_mb',
			$this->megabytes,
			$free / 1048576,
			'%s free (limit %s)',
			static function ( float $mib ): string {
				return Bytes::format( $mib * 1048576 );
			}
		);

		$samples[] = Sample::gauge( 'disk_free_bytes', $free, 'Free bytes on the file system holding the uploads directory.' );
		if ( null !== $total ) {
			$samples[] = Sample::gauge( 'disk_total_bytes', $total, 'Size in bytes of the file system holding the uploads directory.' );
		}
		if ( null !== $ratio ) {
			$samples[] = Sample::gauge( 'disk_free_ratio', $ratio, 'Free space on the uploads file system as a ratio (0 to 1).' );
		}

		return new CheckResult(
			$this->id(),
			$verdict->status(),
			$verdict->message(
				null === $ratio
					? sprintf( '%s free.', Bytes::format( $free ) )
					: sprintf( '%s free of %s (%s%%).', Bytes::format( $free ), Bytes::format( (float) $total ), Verdict::number( round( $ratio * 100, 1 ) ) )
			),
			array(
				'path'         => $this->probe->path(),
				'writable'     => $writable,
				'free_bytes'   => $free,
				'total_bytes'  => $total,
				'free_percent' => null === $ratio ? null : round( $ratio * 100, 2 ),
				'breaches'     => $verdict->breaches(),
			),
			$samples
		);
	}
}
