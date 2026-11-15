<?php
/**
 * Check status values.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi;

use InvalidArgumentException;

/**
 * The three states a check (and the whole report) can be in.
 *
 * Statuses are ordered by severity, so the overall status of a report is
 * the worst status of its checks.
 */
final class Status {

	const OK       = 'ok';
	const WARN     = 'warn';
	const CRITICAL = 'critical';

	/**
	 * Severity of each status.
	 *
	 * @var array<string,int>
	 */
	const SEVERITY = array(
		self::OK       => 0,
		self::WARN     => 1,
		self::CRITICAL => 2,
	);

	/**
	 * All statuses, from best to worst.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array_keys( self::SEVERITY );
	}

	/**
	 * Whether a value is a known status.
	 *
	 * @param string $status Status.
	 */
	public static function is_valid( string $status ): bool {
		return isset( self::SEVERITY[ $status ] );
	}

	/**
	 * Numeric severity (0 = ok, 1 = warn, 2 = critical).
	 *
	 * @param string $status Status.
	 * @throws InvalidArgumentException For unknown statuses.
	 */
	public static function severity( string $status ): int {
		if ( ! self::is_valid( $status ) ) {
			throw new InvalidArgumentException( sprintf( 'Unknown status "%s".', $status ) );
		}

		return self::SEVERITY[ $status ];
	}

	/**
	 * The worst of the given statuses; OK for an empty list.
	 *
	 * @param string[] $statuses Statuses.
	 */
	public static function worst( array $statuses ): string {
		$worst = self::OK;
		foreach ( $statuses as $status ) {
			if ( self::severity( $status ) > self::SEVERITY[ $worst ] ) {
				$worst = $status;
			}
		}

		return $worst;
	}
}
