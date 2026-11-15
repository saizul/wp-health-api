<?php
/**
 * Updates probe.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\WP\Probe;

use Saizul\HealthApi\Probe\UpdatesProbe;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the update site transients. Never calls wp_update_plugins() or
 * similar functions, so no request leaves the server.
 */
final class WpUpdatesProbe implements UpdatesProbe {

	/**
	 * Core transient.
	 *
	 * @return mixed
	 */
	public function core() {
		return get_site_transient( 'update_core' );
	}

	/**
	 * Plugins transient.
	 *
	 * @return mixed
	 */
	public function plugins() {
		return get_site_transient( 'update_plugins' );
	}

	/**
	 * Themes transient.
	 *
	 * @return mixed
	 */
	public function themes() {
		return get_site_transient( 'update_themes' );
	}

	/**
	 * Installed WordPress version.
	 */
	public function wordpress_version(): string {
		return (string) get_bloginfo( 'version' );
	}
}
