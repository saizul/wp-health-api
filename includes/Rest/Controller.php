<?php
/**
 * REST endpoints.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\WP\Rest;

use Saizul\HealthApi\Auth\HeaderToken;
use Saizul\HealthApi\Prometheus\Formatter;
use Saizul\HealthApi\Prometheus\ReportExporter;
use Saizul\HealthApi\RateLimit\ClientIp;
use Saizul\HealthApi\RateLimit\RateLimitResult;
use Saizul\HealthApi\Status;
use Saizul\HealthApi\WP\Plugin;
use Saizul\HealthApi\WP\Services;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Registers /health/v1/status (public), /health/v1/details and
 * /health/v1/metrics (token or admin session).
 */
final class Controller {

	const NAMESPACE_V1     = 'health/v1';
	const AUTH_FAIL_WINDOW = 900;

	/**
	 * Services.
	 *
	 * @var Services
	 */
	private $services;

	/**
	 * Rate limit result of the current request.
	 *
	 * @var RateLimitResult|null
	 */
	private $rate = null;

	/**
	 * Constructor.
	 *
	 * @param Services $services Services.
	 */
	public function __construct( Services $services ) {
		$this->services = $services;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( $this, 'add_headers' ), 10, 3 );
		add_filter( 'rest_pre_serve_request', array( $this, 'serve_metrics' ), 10, 4 );
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => '__return_true', // Public by design; rate limited in the callback.
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/details',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'details' ),
				'permission_callback' => array( $this, 'authorize' ),
				'args'                => $this->report_args(),
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/metrics',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'metrics' ),
				'permission_callback' => array( $this, 'authorize' ),
				'args'                => $this->report_args(),
			)
		);
	}

	/**
	 * Query arguments of the report endpoints.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function report_args(): array {
		return array(
			'checks' => array(
				'description'       => __( 'Comma-separated check ids to run (default: all enabled checks).', 'wp-health-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => array( self::class, 'sanitize_check_list' ),
			),
			'fresh'  => array(
				'description' => __( 'Run the checks now instead of using the short cache.', 'wp-health-api' ),
				'type'        => 'boolean',
				'required'    => false,
				'default'     => false,
			),
		);
	}

	/**
	 * Normalise "database, cron" into "cron,database".
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitize_check_list( $value ): string {
		$ids = array();
		foreach ( explode( ',', is_string( $value ) ? $value : '' ) as $id ) {
			$id = strtolower( trim( $id ) );
			if ( preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $id ) ) {
				$ids[ $id ] = true;
			}
		}
		$ids = array_keys( $ids );
		sort( $ids );

		return implode( ',', $ids );
	}

	/**
	 * Hashed client identity for rate limiting.
	 */
	private function client(): string {
		$server = array();
		foreach ( array( 'REMOTE_ADDR', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP' ) as $key ) {
			if ( isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) ) {
				$server[ $key ] = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
			}
		}
		$ip = ClientIp::resolve( $server, $this->services->config()->string( 'trusted_proxy_header' ) );

		return ClientIp::bucket( $ip, wp_salt( 'nonce' ) );
	}

	/**
	 * Error response helper.
	 *
	 * @param string $code Code.
	 * @param string $message Message.
	 * @param int    $status HTTP status.
	 */
	private function error( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/**
	 * Capability that grants access without a token (cookie-authenticated
	 * REST requests with a nonce).
	 */
	private function capability(): string {
		/**
		 * Filters the capability that may read the detailed endpoints without
		 * a token.
		 *
		 * @param string $capability Capability.
		 */
		return (string) apply_filters( 'wp_health_api_capability', 'manage_options' );
	}

	/**
	 * Permission callback of the detailed endpoints.
	 *
	 * Order: lock-out after repeated bad tokens, rate limit, then the token
	 * check, so a flood of guesses is stopped before any comparison.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function authorize( WP_REST_Request $request ) {
		$config  = $this->services->config();
		$limiter = $this->services->limiter();
		$client  = $this->client();

		if ( $limiter->is_blocked( 'auth_fail:' . $client, $config->int( 'auth_failures_limit' ), self::AUTH_FAIL_WINDOW ) ) {
			$this->rate = new RateLimitResult( false, $config->int( 'auth_failures_limit' ), 0, self::AUTH_FAIL_WINDOW );
			return $this->error( 'wp_health_api_locked', __( 'Too many failed attempts. Try again later.', 'wp-health-api' ), 429 );
		}

		$this->rate = $limiter->hit( 'private:' . $client, $config->int( 'rate_limit_private' ), $config->int( 'rate_limit_window' ) );
		if ( ! $this->rate->allowed ) {
			return $this->error( 'wp_health_api_rate_limited', __( 'Too many requests.', 'wp-health-api' ), 429 );
		}

		if ( is_user_logged_in() && current_user_can( $this->capability() ) ) {
			return true;
		}

		$verifier = $this->services->tokens()->verifier();
		if ( ! $verifier->is_configured() ) {
			return $this->error( 'wp_health_api_no_token', __( 'No access token is configured. Generate one under Settings > Health API or with "wp health token rotate".', 'wp-health-api' ), 401 );
		}

		$token = HeaderToken::extract( $request->get_headers() );
		if ( ! $verifier->verify( $token ) ) {
			if ( null !== $token ) {
				$limiter->hit( 'auth_fail:' . $client, $config->int( 'auth_failures_limit' ), self::AUTH_FAIL_WINDOW );
			}
			return $this->error( 'wp_health_api_unauthorized', __( 'A valid access token is required.', 'wp-health-api' ), 401 );
		}

		return true;
	}

	/**
	 * Public status.
	 *
	 * In "alive" mode the answer only says WordPress and its database
	 * answered (WordPress cannot boot without them). In "overall" mode the
	 * status of the cached report is returned, still without details.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function status( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- REST callback signature.
		$config     = $this->services->config();
		$this->rate = $this->services->limiter()->hit( 'public:' . $this->client(), $config->int( 'rate_limit_public' ), $config->int( 'rate_limit_window' ) );
		if ( ! $this->rate->allowed ) {
			return $this->error( 'wp_health_api_rate_limited', __( 'Too many requests.', 'wp-health-api' ), 429 );
		}

		$status = Status::OK;
		if ( 'overall' === $config->string( 'public_status' ) ) {
			$status = $this->services->report()->status();
		}

		return new WP_REST_Response( array( 'status' => $status ), $config->status_code( $status ) );
	}

	/**
	 * Requested check ids.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string[]|null
	 */
	private function only( WP_REST_Request $request ): ?array {
		$checks = self::sanitize_check_list( $request->get_param( 'checks' ) );

		return '' === $checks ? null : explode( ',', $checks );
	}

	/**
	 * Detailed JSON report.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function details( WP_REST_Request $request ): WP_REST_Response {
		$report = $this->services->report( (bool) $request->get_param( 'fresh' ), $this->only( $request ) );
		$data   = array_merge(
			array(
				'site'    => home_url( '/' ),
				'version' => Plugin::VERSION,
			),
			$report->to_array()
		);

		return new WP_REST_Response( $data, $this->services->config()->status_code( $report->status() ) );
	}

	/**
	 * Prometheus metrics. The body is sent as text by serve_metrics().
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function metrics( WP_REST_Request $request ): WP_REST_Response {
		$report = $this->services->report( (bool) $request->get_param( 'fresh' ), $this->only( $request ) );

		/**
		 * Filters labels added to every metric sample, e.g. array( 'site' => 'shop' ).
		 *
		 * @param array<string,string> $labels Labels.
		 */
		$labels   = apply_filters( 'wp_health_api_metric_labels', array() );
		$text     = ReportExporter::render( $report, $this->services->info(), is_array( $labels ) ? $labels : array() );
		$response = new WP_REST_Response( $text, 200 );
		$response->header( 'Content-Type', Formatter::CONTENT_TYPE );

		return $response;
	}

	/**
	 * Add cache, rate limit and authentication headers to our responses.
	 *
	 * @param mixed           $response Response.
	 * @param mixed           $server Server.
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function add_headers( $response, $server, $request ) {
		if ( ! $response instanceof WP_REST_Response || ! $request instanceof WP_REST_Request || ! self::is_ours( $request ) ) {
			return $response;
		}
		$response->header( 'Cache-Control', 'no-store, max-age=0' );
		$response->header( 'X-Robots-Tag', 'noindex' );
		if ( null !== $this->rate ) {
			foreach ( $this->rate->headers() as $name => $value ) {
				$response->header( $name, $value );
			}
		}
		if ( 401 === $response->get_status() ) {
			$response->header( 'WWW-Authenticate', 'Bearer realm="wp-health-api"' );
		}

		return $response;
	}

	/**
	 * Send the metrics body as plain text instead of JSON.
	 *
	 * @param bool                  $served Whether the request was served.
	 * @param mixed                 $result Response.
	 * @param WP_REST_Request|mixed $request Request.
	 * @param mixed                 $server Server.
	 */
	public function serve_metrics( $served, $result, $request, $server ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Filter signature.
		if ( $served || ! $request instanceof WP_REST_Request || '/' . self::NAMESPACE_V1 . '/metrics' !== $request->get_route() ) {
			return (bool) $served;
		}
		if ( ! $result instanceof WP_REST_Response || 200 !== $result->get_status() || ! is_string( $result->get_data() ) ) {
			return false;
		}
		if ( 'HEAD' !== $request->get_method() ) {
			echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/plain Prometheus exposition, escaped by the formatter.
		}

		return true;
	}

	/**
	 * Whether a request targets this plugin's namespace.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	private static function is_ours( WP_REST_Request $request ): bool {
		return 0 === strpos( $request->get_route(), '/' . self::NAMESPACE_V1 . '/' );
	}
}
