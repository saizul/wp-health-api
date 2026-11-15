<?php
/**
 * Updates probe contract.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Probe;

/**
 * Reads the update transients WordPress stores after its own update checks.
 * Never triggers a remote request.
 */
interface UpdatesProbe {

	/**
	 * The update_core site transient (object, array or false).
	 *
	 * @return mixed
	 */
	public function core();

	/**
	 * The update_plugins site transient.
	 *
	 * @return mixed
	 */
	public function plugins();

	/**
	 * The update_themes site transient.
	 *
	 * @return mixed
	 */
	public function themes();

	/**
	 * Installed WordPress version.
	 */
	public function wordpress_version(): string;
}
