<?php
/**
 * Maintenance probe.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\WP\Probe;

use Saizul\HealthApi\Probe\MaintenanceProbe;

defined( 'ABSPATH' ) || exit;

/**
 * Reads ABSPATH/.maintenance and asks WordPress whether it is in
 * maintenance mode.
 */
final class WpMaintenanceProbe implements MaintenanceProbe {

	/**
	 * File contents.
	 */
	public function file_contents(): ?string {
		$file = ABSPATH . '.maintenance';
		if ( ! is_readable( $file ) ) {
			return null;
		}
		$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.

		return false === $contents ? null : $contents;
	}

	/**
	 * WordPress's own answer.
	 */
	public function core_state(): ?bool {
		return function_exists( 'wp_is_maintenance_mode' ) ? (bool) wp_is_maintenance_mode() : null;
	}
}
