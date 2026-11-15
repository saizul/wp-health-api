<?php
/**
 * WP-CLI: wp health token.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\WP\Cli;

use Saizul\HealthApi\WP\TokenManager;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the access token of the detailed endpoints.
 */
final class TokenCommand {

	/**
	 * Token manager.
	 *
	 * @var TokenManager
	 */
	private $tokens;

	/**
	 * Constructor.
	 *
	 * @param TokenManager $tokens Token manager.
	 */
	public function __construct( TokenManager $tokens ) {
		$this->tokens = $tokens;
	}

	/**
	 * Generates a new token and prints it once.
	 *
	 * The previous generated token stops working immediately. Only a hash
	 * of the new token is stored.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Do not ask for confirmation when a token already exists.
	 *
	 * [--porcelain]
	 * : Print only the token.
	 *
	 * ## EXAMPLES
	 *
	 *     wp health token rotate --yes
	 *     wp health token rotate --porcelain --yes > /run/secrets/wp-health-token
	 *
	 * @param string[]             $args Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 */
	public function rotate( $args, $assoc_args ): void {
		if ( 'option' === $this->tokens->verifier()->source() ) {
			WP_CLI::confirm( 'This replaces the current token. Monitors using it will get 401 until updated. Continue?', $assoc_args );
		}
		$token = $this->tokens->rotate();
		if ( isset( $assoc_args['porcelain'] ) ) {
			WP_CLI::line( $token );
			return;
		}
		WP_CLI::success( 'New token generated. It is shown only once:' );
		WP_CLI::line( $token );
		if ( null !== $this->tokens->constant() ) {
			WP_CLI::warning( 'WP_HEALTH_API_TOKEN is defined in wp-config.php and takes precedence over the generated token.' );
		}
	}

	/**
	 * Shows where the token comes from.
	 *
	 * @param string[]             $args Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 */
	public function status( $args, $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- WP-CLI signature.
		$verifier = $this->tokens->verifier();
		switch ( $verifier->source() ) {
			case 'constant':
				if ( $verifier->is_weak() ) {
					WP_CLI::warning( 'WP_HEALTH_API_TOKEN is shorter than 16 characters and is not accepted.' );
					return;
				}
				WP_CLI::line( 'Token source: WP_HEALTH_API_TOKEN constant.' );
				return;
			case 'option':
				WP_CLI::line( 'Token source: generated token (hash stored in the database).' );
				return;
		}
		WP_CLI::warning( 'No token is configured. Run "wp health token rotate".' );
	}

	/**
	 * Deletes the generated token.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @param string[]             $args Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 */
	public function revoke( $args, $assoc_args ): void {
		WP_CLI::confirm( 'Delete the generated token?', $assoc_args );
		$this->tokens->revoke();
		WP_CLI::success( 'Token deleted.' );
	}
}
