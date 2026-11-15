<?php
/**
 * Database probe using $wpdb.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\WP\Probe;

use Saizul\HealthApi\Probe\DatabaseProbe;

defined( 'ABSPATH' ) || exit;

/**
 * Times a `SELECT 1` through $wpdb.
 */
final class WpdbProbe implements DatabaseProbe {

	/**
	 * Ping the database.
	 *
	 * @return array{connected:bool,latency:float|null,error:string,server:string}
	 */
	public function ping(): array {
		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return array(
				'connected' => false,
				'latency'   => null,
				'error'     => 'No database connection object.',
				'server'    => '',
			);
		}

		$suppress = $wpdb->suppress_errors( true );
		$started  = microtime( true );
		$value    = $wpdb->get_var( 'SELECT 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The point is to reach the database.
		$latency  = microtime( true ) - $started;
		$error    = (string) $wpdb->last_error;
		$wpdb->suppress_errors( $suppress );

		$connected = '1' === (string) $value && '' === $error;
		$server    = '';
		if ( $connected && method_exists( $wpdb, 'db_server_info' ) ) {
			$server = (string) $wpdb->db_server_info();
		}

		return array(
			'connected' => $connected,
			'latency'   => $connected ? $latency : null,
			'error'     => $connected ? '' : ( '' !== $error ? $error : 'Unexpected answer to SELECT 1.' ),
			'server'    => $server,
		);
	}
}
