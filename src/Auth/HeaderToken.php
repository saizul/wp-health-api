<?php
/**
 * Token extraction from request headers.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Auth;

/**
 * Reads the token from `Authorization: Bearer <token>` (what Prometheus
 * sends with `authorization: credentials`) or from `X-Health-Token`.
 * Tokens are never read from the query string, so they do not end up in
 * access logs.
 */
final class HeaderToken {

	const HEADER     = 'x-health-token';
	const MAX_LENGTH = 512;

	/**
	 * Extract the token.
	 *
	 * @param array<string,string|string[]> $headers Headers (any key case; "-" or "_" separators).
	 * @return string|null Token, or null when none or malformed.
	 */
	public static function extract( array $headers ): ?string {
		$normalised = array();
		foreach ( $headers as $name => $value ) {
			$name                = strtolower( str_replace( '_', '-', (string) $name ) );
			$normalised[ $name ] = is_array( $value ) ? (string) reset( $value ) : (string) $value;
		}

		$authorization = trim( $normalised['authorization'] ?? '' );
		if ( '' !== $authorization && preg_match( '/^Bearer\s+(\S+)$/i', $authorization, $m ) ) {
			return self::clean( $m[1] );
		}
		if ( isset( $normalised[ self::HEADER ] ) ) {
			return self::clean( trim( $normalised[ self::HEADER ] ) );
		}

		return null;
	}

	/**
	 * Accept printable ASCII tokens of reasonable length only.
	 *
	 * @param string $token Token.
	 */
	private static function clean( string $token ): ?string {
		if ( '' === $token || strlen( $token ) > self::MAX_LENGTH || ! preg_match( '/^[\x21-\x7e]+$/', $token ) ) {
			return null;
		}

		return $token;
	}
}
