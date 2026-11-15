<?php
/**
 * Option, transient and hook names.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\WP;

defined( 'ABSPATH' ) || exit;

/**
 * Names stored in the database.
 */
final class Options {

	const SETTINGS       = 'wp_health_api_settings';
	const TOKEN_HASH     = 'wp_health_api_token_hash';
	const LAST_CRON      = 'wp_health_api_last_cron';
	const TRANSIENT      = 'wp_health_api_';
	const TOKEN_CONSTANT = 'WP_HEALTH_API_TOKEN';
	const HEARTBEAT      = 'wp_health_api_heartbeat';
	const SCHEDULE       = 'wp_health_api_five_minutes';
}
