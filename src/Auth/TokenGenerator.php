<?php
/**
 * Token generation.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Auth;

/**
 * Creates random access tokens.
 */
final class TokenGenerator {

	/**
	 * Generate a token: 32 random bytes as 64 hex characters.
	 *
	 * @param int $bytes Random bytes, at least 16.
	 */
	public static function generate( int $bytes = 32 ): string {
		return bin2hex( random_bytes( max( 16, $bytes ) ) );
	}
}
