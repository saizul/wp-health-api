<?php
/**
 * Pending updates check.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Check;

use Saizul\HealthApi\CheckResult;
use Saizul\HealthApi\Probe\UpdatesProbe;
use Saizul\HealthApi\Sample;
use Saizul\HealthApi\Threshold;
use Saizul\HealthApi\Updates\UpdateCounter;

/**
 * Pending core, plugin and theme updates, read from the update transients.
 */
final class UpdatesCheck implements Check {

	/**
	 * Probe.
	 *
	 * @var UpdatesProbe
	 */
	private $probe;

	/**
	 * Thresholds keyed by core, plugins and themes.
	 *
	 * @var array<string,Threshold>
	 */
	private $thresholds;

	/**
	 * Constructor.
	 *
	 * @param UpdatesProbe $probe Probe.
	 * @param Threshold    $core Core updates threshold (0 or 1).
	 * @param Threshold    $plugins Plugin updates threshold.
	 * @param Threshold    $themes Theme updates threshold.
	 */
	public function __construct( UpdatesProbe $probe, Threshold $core, Threshold $plugins, Threshold $themes ) {
		$this->probe      = $probe;
		$this->thresholds = array(
			'core'    => $core,
			'plugins' => $plugins,
			'themes'  => $themes,
		);
	}

	/**
	 * Id.
	 */
	public function id(): string {
		return 'updates';
	}

	/**
	 * Run.
	 */
	public function run(): CheckResult {
		$core    = UpdateCounter::core( $this->probe->core(), $this->probe->wordpress_version() );
		$plugins = UpdateCounter::extensions( $this->probe->plugins() );
		$themes  = UpdateCounter::extensions( $this->probe->themes() );
		$counts  = array(
			'core'        => $core['available'] ? 1 : 0,
			'plugin'      => $plugins['count'],
			'theme'       => $themes['count'],
			'translation' => $plugins['translations'] + $themes['translations'],
		);
		$verdict = new Verdict();

		if ( $core['available'] ) {
			$verdict->add( $this->thresholds['core']->evaluate( 1.0 ), sprintf( 'WordPress %s is available', (string) $core['version'] ) );
		}
		$verdict->threshold( 'plugins', $this->thresholds['plugins'], (float) $plugins['count'], '%s plugin updates pending (limit %s)' );
		$verdict->threshold( 'themes', $this->thresholds['themes'], (float) $themes['count'], '%s theme updates pending (limit %s)' );

		$samples = array();
		foreach ( $counts as $type => $count ) {
			$samples[] = Sample::gauge( 'updates_pending', $count, 'Pending updates by type, from the WordPress update transients.', array( 'type' => $type ) );
		}
		foreach ( array(
			'core'   => $core['last_checked'],
			'plugin' => $plugins['last_checked'],
			'theme'  => $themes['last_checked'],
		) as $type => $checked ) {
			if ( null !== $checked ) {
				$samples[] = Sample::gauge( 'updates_last_checked_timestamp_seconds', $checked, 'Unix time WordPress last checked for updates, by type.', array( 'type' => $type ) );
			}
		}

		$total = $counts['core'] + $counts['plugin'] + $counts['theme'];

		return new CheckResult(
			$this->id(),
			$verdict->status(),
			$verdict->message(
				0 === $total
					? 'Everything is up to date.'
					: sprintf( '%d updates pending (core %d, plugins %d, themes %d).', $total, $counts['core'], $counts['plugin'], $counts['theme'] )
			),
			array(
				'core'         => $core,
				'plugins'      => $plugins['items'],
				'themes'       => $themes['items'],
				'translations' => $counts['translation'],
				'last_checked' => array(
					'core'    => $core['last_checked'],
					'plugins' => $plugins['last_checked'],
					'themes'  => $themes['last_checked'],
				),
				'breaches'     => $verdict->breaches(),
			),
			$samples
		);
	}
}
