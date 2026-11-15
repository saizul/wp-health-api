<?php
/**
 * Client IP resolution.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\RateLimit;

/**
 * Finds the client IP for rate limiting.
 *
 * Proxy headers can be forged by anyone, so they are only read when the
 * site owner names one in the settings (because the site sits behind a
 * proxy or CDN that sets it). Otherwise REMOTE_ADDR is used.
 */
final class ClientIp {

	/**
	 * Resolve the client IP.
	 *
	 * @param array<string,mixed> $server $_SERVER.
	 * @param string              $trusted_header $_SERVER key of the trusted proxy header, or ''.
	 * @return string IP address, or '' when unknown.
	 */
	public static function resolve( array $server, string $trusted_header = '' ): string {
		if ( '' !== $trusted_header && isset( $server[ $trusted_header ] ) && is_string( $server[ $trusted_header ] ) ) {
			foreach ( explode( ',', $server[ $trusted_header ] ) as $candidate ) {
				$ip = self::valid( $candidate );
				if ( '' !== $ip ) {
					return $ip;
				}
			}
		}

		return self::valid( isset( $server['REMOTE_ADDR'] ) && is_string( $server['REMOTE_ADDR'] ) ? $server['REMOTE_ADDR'] : '' );
	}

	/**
	 * Validated IP or ''.
	 *
	 * @param string $candidate Candidate.
	 */
	private static function valid( string $candidate ): string {
		$candidate = trim( $candidate );
		// Strip the port from bracketed IPv6 and from IPv4 addresses.
		if ( preg_match( '/^\[([0-9a-fA-F:.]+)\](?::\d+)?$/', $candidate, $m ) ) {
			$candidate = $m[1];
		} elseif ( preg_match( '/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $candidate, $m ) ) {
			$candidate = $m[1];
		}

		return false !== filter_var( $candidate, FILTER_VALIDATE_IP ) ? strtolower( $candidate ) : '';
	}

	/**
	 * Bucket key for an IP: a keyed hash, so IPs are never stored in clear.
	 *
	 * @param string $ip IP.
	 * @param string $secret Site secret.
	 */
	public static function bucket( string $ip, string $secret ): string {
		return substr( hash_hmac( 'sha256', '' === $ip ? 'unknown' : $ip, $secret ), 0, 24 );
	}
}
