<?php
/**
 * Settings sanitiser.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Settings;

use InvalidArgumentException;
use Saizul\HealthApi\Status;
use Saizul\HealthApi\Threshold;

/**
 * Turns submitted or stored settings into a complete, valid settings array.
 *
 * Unknown keys are dropped, missing keys get their defaults, numbers are
 * clamped to sane ranges and invalid threshold pairs fall back to the
 * defaults. Problems are collected so the settings screen can show them.
 */
final class Sanitizer {

	/**
	 * Allowed ranges for integer settings: key => [min, max].
	 *
	 * @var array<string,array{0:int,1:int}>
	 */
	const RANGES = array(
		'cache_ttl'           => array( 0, 300 ),
		'rate_limit_public'   => array( 0, 10000 ),
		'rate_limit_private'  => array( 0, 10000 ),
		'rate_limit_window'   => array( 1, 3600 ),
		'auth_failures_limit' => array( 0, 1000 ),
		'cron_grace'          => array( 0, 86400 ),
		'ssl_cache_hours'     => array( 1, 168 ),
	);

	/**
	 * Header names accepted as the client IP source behind a proxy.
	 *
	 * @var string[]
	 */
	const PROXY_HEADERS = array( '', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP' );

	/**
	 * Sanitise settings.
	 *
	 * @param array<string,mixed> $input Input.
	 * @param string[]            $errors Receives messages about rejected values.
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $input, array &$errors = array() ): array {
		$defaults = Defaults::settings();
		$output   = $defaults;

		// Checks.
		$checks = is_array( $input['checks'] ?? null ) ? $input['checks'] : array();
		foreach ( array_keys( $defaults['checks'] ) as $check ) {
			if ( array_key_exists( $check, $checks ) ) {
				$output['checks'][ $check ] = self::to_bool( $checks[ $check ] );
			}
		}

		// Thresholds.
		$thresholds  = is_array( $input['thresholds'] ?? null ) ? $input['thresholds'] : array();
		$definitions = Defaults::thresholds();
		foreach ( $definitions as $key => $definition ) {
			if ( ! isset( $thresholds[ $key ] ) || ! is_array( $thresholds[ $key ] ) ) {
				continue;
			}
			$limits = $thresholds[ $key ];
			try {
				$threshold                    = Threshold::from_array(
					array(
						'warn'     => self::number_or_empty( $limits['warn'] ?? null ),
						'critical' => self::number_or_empty( $limits['critical'] ?? null ),
					),
					$definition[2]
				);
				$output['thresholds'][ $key ] = array(
					'warn'     => $threshold->warn(),
					'critical' => $threshold->critical(),
				);
			} catch ( InvalidArgumentException $e ) {
				$errors[] = sprintf( '%s: %s', $key, $e->getMessage() );
			}
		}

		// Status codes.
		$codes = is_array( $input['status_codes'] ?? null ) ? $input['status_codes'] : array();
		foreach ( Status::all() as $status ) {
			if ( ! isset( $codes[ $status ] ) || '' === $codes[ $status ] ) {
				continue;
			}
			$code = is_numeric( $codes[ $status ] ) ? (int) $codes[ $status ] : 0;
			if ( $code >= 200 && $code <= 599 ) {
				$output['status_codes'][ $status ] = $code;
			} else {
				$errors[] = sprintf( 'status_codes.%s: must be between 200 and 599.', $status );
			}
		}

		// Integers.
		foreach ( self::RANGES as $key => $range ) {
			if ( isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ) {
				$output[ $key ] = max( $range[0], min( $range[1], (int) $input[ $key ] ) );
			}
		}

		// Booleans.
		foreach ( array( 'require_opcache', 'require_persistent_cache' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$output[ $key ] = self::to_bool( $input[ $key ] );
			}
		}

		// PHP versions.
		foreach ( array( 'php_warn_below', 'php_critical_below' ) as $key ) {
			if ( ! isset( $input[ $key ] ) ) {
				continue;
			}
			$version = trim( (string) $input[ $key ] );
			if ( '' === $version || preg_match( '/^\d+(\.\d+){0,2}$/', $version ) ) {
				$output[ $key ] = $version;
			} else {
				$errors[] = sprintf( '%s: "%s" is not a version number.', $key, $version );
			}
		}
		if ( '' !== $output['php_warn_below'] && '' !== $output['php_critical_below'] && version_compare( $output['php_warn_below'], $output['php_critical_below'], '<' ) ) {
			$errors[]                     = 'php_warn_below: must not be lower than php_critical_below.';
			$output['php_warn_below']     = $defaults['php_warn_below'];
			$output['php_critical_below'] = $defaults['php_critical_below'];
		}

		// Maintenance status.
		if ( isset( $input['maintenance_status'] ) ) {
			$status = (string) $input['maintenance_status'];
			if ( Status::is_valid( $status ) ) {
				$output['maintenance_status'] = $status;
			}
		}

		// Public status mode.
		if ( isset( $input['public_status'] ) && in_array( $input['public_status'], array( 'alive', 'overall' ), true ) ) {
			$output['public_status'] = $input['public_status'];
		}

		// Proxy header.
		if ( isset( $input['trusted_proxy_header'] ) ) {
			$header = strtoupper( trim( (string) $input['trusted_proxy_header'] ) );
			if ( in_array( $header, self::PROXY_HEADERS, true ) ) {
				$output['trusted_proxy_header'] = $header;
			} else {
				$errors[] = 'trusted_proxy_header: unsupported header.';
			}
		}

		return $output;
	}

	/**
	 * Boolean from form and stored values ("1", "yes", "on", true).
	 *
	 * @param mixed $value Value.
	 */
	private static function to_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'yes', 'on', 'true' ), true );
	}

	/**
	 * Keep numbers and empty values; anything else is passed through so the
	 * threshold rejects it.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function number_or_empty( $value ) {
		if ( is_string( $value ) ) {
			$value = trim( $value );
		}

		return $value;
	}
}
