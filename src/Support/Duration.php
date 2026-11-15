<?php
/**
 * Duration formatting.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Support;

/**
 * Formats a number of seconds as a short human string.
 */
final class Duration {

	/**
	 * Format seconds as "45s", "12m", "3h 5m" or "2d 4h".
	 *
	 * @param int $seconds Seconds (negative values are treated as 0).
	 */
	public static function format( int $seconds ): string {
		$seconds = max( 0, $seconds );
		if ( $seconds < 60 ) {
			return $seconds . 's';
		}
		if ( $seconds < 3600 ) {
			return intdiv( $seconds, 60 ) . 'm';
		}
		if ( $seconds < 86400 ) {
			$minutes = intdiv( $seconds % 3600, 60 );
			return intdiv( $seconds, 3600 ) . 'h' . ( $minutes ? ' ' . $minutes . 'm' : '' );
		}
		$hours = intdiv( $seconds % 86400, 3600 );

		return intdiv( $seconds, 86400 ) . 'd' . ( $hours ? ' ' . $hours . 'h' : '' );
	}
}
