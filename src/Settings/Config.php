<?php
/**
 * Validated settings.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Settings;

use InvalidArgumentException;
use Saizul\HealthApi\Status;
use Saizul\HealthApi\Threshold;

/**
 * Read access to the sanitised settings.
 */
final class Config {

	/**
	 * Settings, already passed through Sanitizer::sanitize().
	 *
	 * @var array<string,mixed>
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $settings Raw stored settings (sanitised again here).
	 */
	public function __construct( array $settings = array() ) {
		$this->settings = Sanitizer::sanitize( $settings );
	}

	/**
	 * Whether a check is enabled.
	 *
	 * @param string $check Check id.
	 */
	public function is_enabled( string $check ): bool {
		return ! empty( $this->settings['checks'][ $check ] );
	}

	/**
	 * Ids of the enabled checks, in report order.
	 *
	 * @return string[]
	 */
	public function enabled_checks(): array {
		return array_keys( array_filter( $this->settings['checks'] ) );
	}

	/**
	 * Threshold for a key such as "database.latency_ms".
	 *
	 * @param string $key Threshold key.
	 * @throws InvalidArgumentException For unknown keys.
	 */
	public function threshold( string $key ): Threshold {
		$definitions = Defaults::thresholds();
		if ( ! isset( $definitions[ $key ] ) ) {
			throw new InvalidArgumentException( sprintf( 'Unknown threshold "%s".', $key ) );
		}
		$limits = $this->settings['thresholds'][ $key ];

		return new Threshold( $limits['warn'], $limits['critical'], $definitions[ $key ][2] );
	}

	/**
	 * HTTP status code for an overall status.
	 *
	 * @param string $status Status.
	 */
	public function status_code( string $status ): int {
		Status::severity( $status );

		return (int) $this->settings['status_codes'][ $status ];
	}

	/**
	 * Integer setting.
	 *
	 * @param string $key Key.
	 */
	public function int( string $key ): int {
		return (int) ( $this->settings[ $key ] ?? 0 );
	}

	/**
	 * Boolean setting.
	 *
	 * @param string $key Key.
	 */
	public function bool( string $key ): bool {
		return ! empty( $this->settings[ $key ] );
	}

	/**
	 * String setting.
	 *
	 * @param string $key Key.
	 */
	public function string( string $key ): string {
		return (string) ( $this->settings[ $key ] ?? '' );
	}

	/**
	 * All settings.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return $this->settings;
	}
}
