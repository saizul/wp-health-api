<?php
/**
 * Parser for the WordPress .maintenance file.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Maintenance;

/**
 * Interprets the .maintenance file without executing it.
 *
 * WordPress writes `<?php $upgrading = 1731600000; ?>` while it installs
 * updates. Core includes the file and treats the site as in maintenance
 * while `$upgrading` is less than ten minutes old; an older file is
 * ignored (a stale file left behind by an interrupted update).
 */
final class MaintenanceFile {

	/**
	 * Seconds after which WordPress ignores the file.
	 */
	const WINDOW = 600;

	/**
	 * Read the $upgrading timestamp.
	 *
	 * @param string $contents File contents.
	 * @return int|null Timestamp, or null when the file does not define one.
	 */
	public static function upgrading( string $contents ): ?int {
		if ( preg_match( '/\$upgrading\s*=\s*(\d+)\s*;/', $contents, $m ) ) {
			return (int) $m[1];
		}

		return null;
	}

	/**
	 * State of maintenance mode.
	 *
	 * Without a readable timestamp the file is treated like WordPress does
	 * when `$upgrading` is undefined: it uses the current time, so the
	 * site is in maintenance.
	 *
	 * @param string|null $contents File contents or null when there is no file.
	 * @param int         $now Current time.
	 * @return array{active:bool,stale:bool,started:int|null,age:int|null}
	 */
	public static function state( ?string $contents, int $now ): array {
		if ( null === $contents ) {
			return array(
				'active'  => false,
				'stale'   => false,
				'started' => null,
				'age'     => null,
			);
		}
		$started = self::upgrading( $contents );
		$age     = null === $started ? 0 : max( 0, $now - $started );

		return array(
			'active'  => $age < self::WINDOW,
			'stale'   => $age >= self::WINDOW,
			'started' => $started,
			'age'     => null === $started ? null : $age,
		);
	}
}
