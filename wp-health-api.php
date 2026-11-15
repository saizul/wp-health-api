<?php
/**
 * Plugin Name:       Health API
 * Plugin URI:        https://github.com/saizul/wp-health-api
 * Description:       Health check and Prometheus metrics endpoints for uptime monitors: database, object cache, WP-Cron, Action Scheduler, disk space, PHP, pending updates, TLS expiry, maintenance mode and WooCommerce orders.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Saizul Amin
 * Author URI:        https://saizul.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-health-api
 * Domain Path:       /languages
 *
 * @package Saizul\HealthApi
 */

defined( 'ABSPATH' ) || exit;

if ( PHP_VERSION_ID < 70400 ) {
	add_action(
		'admin_notices',
		static function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Health API requires PHP 7.4 or newer.', 'wp-health-api' ) . '</p></div>';
			}
		}
	);
	return;
}

define( 'WP_HEALTH_API_FILE', __FILE__ );
define( 'WP_HEALTH_API_VERSION', '0.1.0' );

if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
} else {
	// PSR-4 autoloader for installs without Composer (the release zip).
	spl_autoload_register(
		static function ( $class_name ) {
			$prefixes = array(
				'Saizul\\HealthApi\\WP\\' => __DIR__ . '/includes/',
				'Saizul\\HealthApi\\'     => __DIR__ . '/src/',
			);
			foreach ( $prefixes as $prefix => $dir ) {
				if ( 0 !== strncmp( $class_name, $prefix, strlen( $prefix ) ) ) {
					continue;
				}
				$file = $dir . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
				if ( is_readable( $file ) ) {
					require_once $file;
				}
				return;
			}
		}
	);
}

\Saizul\HealthApi\WP\Plugin::boot( __FILE__ );
