<?php
/**
 * Token hashing.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Auth;

/**
 * Hashes access tokens for storage.
 *
 * Tokens are 256-bit random values, so a fast hash is enough: there is
 * nothing to brute-force. Only the hash is stored in the database.
 */
final class TokenHasher {

	const PREFIX = 'sha256:';

	/**
	 * Hash a token.
	 *
	 * @param string $token Token.
	 */
	public static function hash( string $token ): string {
		return self::PREFIX . hash( 'sha256', $token );
	}

	/**
	 * Whether a stored value looks like a hash produced by hash().
	 *
	 * @param string $hash Stored value.
	 */
	public static function is_hash( string $hash ): bool {
		return 1 === preg_match( '/^sha256:[0-9a-f]{64}$/', $hash );
	}
}
