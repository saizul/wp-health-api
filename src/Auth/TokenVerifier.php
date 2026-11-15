<?php
/**
 * Token verification.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\Auth;

/**
 * Verifies a presented token against the configured one.
 *
 * The token comes from a constant in wp-config.php (stored in clear,
 * outside the database) or from the hash stored by "token rotate". The
 * constant wins when both exist. Comparison is constant-time: both sides
 * are hashed to fixed-length digests and compared with hash_equals(), so
 * neither the content nor the length of the secret leaks through timing.
 */
final class TokenVerifier {

	const MIN_LENGTH = 16;

	const SOURCE_CONSTANT = 'constant';
	const SOURCE_OPTION   = 'option';
	const SOURCE_NONE     = 'none';

	/**
	 * Token from the constant.
	 *
	 * @var string|null
	 */
	private $constant;

	/**
	 * Stored hash.
	 *
	 * @var string|null
	 */
	private $hash;

	/**
	 * Constructor.
	 *
	 * @param string|null $constant Token from the constant, if defined.
	 * @param string|null $hash Stored hash, if any.
	 */
	public function __construct( ?string $constant, ?string $hash ) {
		$this->constant = null !== $constant && '' !== trim( $constant ) ? trim( $constant ) : null;
		$this->hash     = null !== $hash && TokenHasher::is_hash( $hash ) ? $hash : null;
	}

	/**
	 * Where the token comes from.
	 */
	public function source(): string {
		if ( null !== $this->constant ) {
			return self::SOURCE_CONSTANT;
		}

		return null !== $this->hash ? self::SOURCE_OPTION : self::SOURCE_NONE;
	}

	/**
	 * Whether the constant is set but too short to be accepted.
	 */
	public function is_weak(): bool {
		return null !== $this->constant && strlen( $this->constant ) < self::MIN_LENGTH;
	}

	/**
	 * Whether a usable token is configured.
	 */
	public function is_configured(): bool {
		$source = $this->source();

		return self::SOURCE_OPTION === $source || ( self::SOURCE_CONSTANT === $source && ! $this->is_weak() );
	}

	/**
	 * Verify a presented token.
	 *
	 * @param string|null $presented Token from the request.
	 */
	public function verify( ?string $presented ): bool {
		if ( null === $presented || '' === $presented || ! $this->is_configured() ) {
			return false;
		}
		if ( null !== $this->constant ) {
			return hash_equals( TokenHasher::hash( $this->constant ), TokenHasher::hash( $presented ) );
		}

		return hash_equals( (string) $this->hash, TokenHasher::hash( $presented ) );
	}
}
