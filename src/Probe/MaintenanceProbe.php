<?php
/**
 * Maintenance mode probe contract.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Probe;

/**
 * Reads the .maintenance file WordPress writes during updates.
 */
interface MaintenanceProbe {

	/**
	 * Contents of ABSPATH/.maintenance, or null when there is no such file.
	 */
	public function file_contents(): ?string;

	/**
	 * WordPress's own answer from wp_is_maintenance_mode() (file age plus
	 * the enable_maintenance_mode filter), or null where it is unavailable.
	 */
	public function core_state(): ?bool;
}
