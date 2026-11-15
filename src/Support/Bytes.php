<?php
/**
 * Byte size helpers.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Support;

/**
 * Parses php.ini shorthand sizes and formats byte counts.
 */
final class Bytes {

	/**
	 * Parse a php.ini size such as "256M", "1G", "512k" or "-1".
	 *
	 * PHP reads the leading number and applies the last character as a
	 * multiplier (K, M, G), so "128MB" is not valid and means 128 bytes
	 * in PHP. This parser accepts the forms PHP accepts and returns null
	 * for "unlimited" (-1) or values that cannot be read.
	 *
	 * @param string $value Raw ini value.
	 * @return int|null Bytes, or null for unlimited/unknown.
	 */
	public static function parse_ini( string $value ): ?int {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}
		if ( ! preg_match( '/^(-?\d+)\s*([kmgKMG]?)$/', $value, $m ) ) {
			return null;
		}
		$number = (int) $m[1];
		if ( $number < 0 ) {
			return null;
		}
		switch ( strtolower( $m[2] ) ) {
			case 'g':
				$number *= 1024;
				// Fall through.
			case 'm':
				$number *= 1024;
				// Fall through.
			case 'k':
				$number *= 1024;
		}

		return $number;
	}

	/**
	 * Human readable size, binary units.
	 *
	 * @param float $bytes Bytes.
	 * @param int   $precision Decimals.
	 */
	public static function format( float $bytes, int $precision = 1 ): string {
		$units = array( 'B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB' );
		$i     = 0;
		$abs   = abs( $bytes );
		$last  = count( $units ) - 1;
		while ( $abs >= 1024 && $i < $last ) {
			$abs   /= 1024;
			$bytes /= 1024;
			++$i;
		}

		return 0 === $i ? sprintf( '%d B', (int) $bytes ) : sprintf( '%.' . $precision . 'f %s', $bytes, $units[ $i ] );
	}
}
