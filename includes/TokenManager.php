<?php
/**
 * Access token storage.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\WP;

use Saizul\HealthApi\Auth\TokenGenerator;
use Saizul\HealthApi\Auth\TokenHasher;
use Saizul\HealthApi\Auth\TokenVerifier;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the token from the WP_HEALTH_API_TOKEN constant or the stored
 * hash, and creates new tokens. A generated token is shown once and only
 * its SHA-256 hash is kept.
 */
final class TokenManager {

	/**
	 * Reads the constant; replaceable in tests.
	 *
	 * @var callable
	 */
	private $read_constant;

	/**
	 * Constructor.
	 *
	 * @param callable|null $read_constant Returns the constant's value or null.
	 */
	public function __construct( ?callable $read_constant = null ) {
		$this->read_constant = $read_constant ?? static function (): ?string {
			if ( ! defined( Options::TOKEN_CONSTANT ) ) {
				return null;
			}
			$value = constant( Options::TOKEN_CONSTANT );

			return is_string( $value ) ? $value : null;
		};
	}

	/**
	 * Verifier for the current configuration.
	 */
	public function verifier(): TokenVerifier {
		$hash = get_option( Options::TOKEN_HASH, '' );

		return new TokenVerifier( $this->constant(), is_string( $hash ) ? $hash : null );
	}

	/**
	 * Token from the constant, if defined.
	 */
	public function constant(): ?string {
		$value = call_user_func( $this->read_constant );

		return is_string( $value ) ? $value : null;
	}

	/**
	 * Create a new token, store its hash and return it.
	 */
	public function rotate(): string {
		$token = TokenGenerator::generate();
		update_option( Options::TOKEN_HASH, TokenHasher::hash( $token ), false );

		/**
		 * Fires after a new access token was generated. The token itself is
		 * not passed on.
		 */
		do_action( 'wp_health_api_token_rotated' );

		return $token;
	}

	/**
	 * Delete the stored hash.
	 */
	public function revoke(): void {
		delete_option( Options::TOKEN_HASH );
		do_action( 'wp_health_api_token_revoked' );
	}
}
