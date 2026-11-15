<?php
/**
 * Certificate host name matching.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Ssl;

/**
 * Checks whether a certificate covers a host name (RFC 6125 rules for
 * DNS names: case-insensitive, a wildcard only as the whole left-most
 * label, covering exactly one label).
 */
final class HostnameMatcher {

	/**
	 * Whether the certificate is valid for the host.
	 *
	 * The subject CN is only used when the certificate has no DNS names,
	 * as browsers do.
	 *
	 * @param Certificate $certificate Certificate.
	 * @param string      $host Host name.
	 */
	public static function covers( Certificate $certificate, string $host ): bool {
		$names = $certificate->names;
		if ( array() === $names && '' !== $certificate->subject ) {
			$names = array( $certificate->subject );
		}
		foreach ( $names as $name ) {
			if ( self::matches( $name, $host ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether one certificate name matches the host.
	 *
	 * @param string $pattern Name from the certificate, may start with "*.".
	 * @param string $host Host name.
	 */
	public static function matches( string $pattern, string $host ): bool {
		$pattern = rtrim( strtolower( trim( $pattern ) ), '.' );
		$host    = rtrim( strtolower( trim( $host ) ), '.' );
		if ( '' === $pattern || '' === $host ) {
			return false;
		}
		if ( 0 !== strpos( $pattern, '*.' ) ) {
			return $pattern === $host;
		}
		$suffix = substr( $pattern, 1 ); // ".example.com".
		if ( false !== strpos( $suffix, '*' ) || substr_count( $suffix, '.' ) < 2 ) {
			return false; // No nested wildcards, no "*.com".
		}
		if ( strlen( $host ) <= strlen( $suffix ) || substr( $host, -strlen( $suffix ) ) !== $suffix ) {
			return false;
		}
		$label = substr( $host, 0, -strlen( $suffix ) );

		return '' !== $label && false === strpos( $label, '.' );
	}
}
