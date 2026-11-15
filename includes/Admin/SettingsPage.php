<?php
/**
 * Settings screen.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\WP\Admin;

use Saizul\HealthApi\Settings\Defaults;
use Saizul\HealthApi\Settings\Sanitizer;
use Saizul\HealthApi\Status;
use Saizul\HealthApi\Threshold;
use Saizul\HealthApi\WP\Labels;
use Saizul\HealthApi\WP\Options;
use Saizul\HealthApi\WP\Services;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Settings > Health API.
 */
final class SettingsPage {

	const SLUG       = 'wp-health-api';
	const GROUP      = 'wp_health_api';
	const ROTATE     = 'wp_health_api_rotate';
	const REVOKE     = 'wp_health_api_revoke';
	const NEW_TOKEN  = 'wp_health_api_new_token_';
	const CAPABILITY = 'manage_options';

	/**
	 * Services.
	 *
	 * @var Services
	 */
	private $services;

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
	 *
	 * @param string $plugin_file Main plugin file.
	 */
	public function register( string $plugin_file ): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_action( 'admin_post_' . self::ROTATE, array( $this, 'handle_rotate' ) );
		add_action( 'admin_post_' . self::REVOKE, array( $this, 'handle_revoke' ) );
		add_action( 'update_option_' . Options::SETTINGS, array( $this, 'flush' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( $plugin_file ), array( $this, 'action_links' ) );
	}

	/**
	 * Add the menu entry.
	 */
	public function add_page(): void {
		add_options_page(
			__( 'Health API', 'wp-health-api' ),
			__( 'Health API', 'wp-health-api' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Register the option.
	 */
	public function register_setting(): void {
		register_setting(
			self::GROUP,
			Options::SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Sanitise submitted settings and report rejected values.
	 *
	 * @param mixed $input Input.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ): array {
		$errors = array();
		$output = Sanitizer::sanitize( is_array( $input ) ? wp_unslash( $input ) : array(), $errors );
		foreach ( $errors as $error ) {
			add_settings_error(
				Options::SETTINGS,
				'wp_health_api_invalid',
				/* translators: %s: technical description of the rejected value. */
				sprintf( __( 'Not saved: %s', 'wp-health-api' ), $error )
			);
		}

		return $output;
	}

	/**
	 * Drop cached reports after the settings changed.
	 */
	public function flush(): void {
		$this->services->flush();
	}

	/**
	 * Settings link on the plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'options-general.php?page=' . self::SLUG ) ), esc_html__( 'Settings', 'wp-health-api' ) )
		);

		return $links;
	}

	/**
	 * Generate a new token.
	 */
	public function handle_rotate(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wp-health-api' ), 403 );
		}
		check_admin_referer( self::ROTATE );
		$token = $this->services->tokens()->rotate();
		set_transient( self::NEW_TOKEN . get_current_user_id(), $token, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::SLUG . '&token=new' ) );
		exit;
	}

	/**
	 * Delete the stored token.
	 */
	public function handle_revoke(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wp-health-api' ), 403 );
		}
		check_admin_referer( self::REVOKE );
		$this->services->tokens()->revoke();
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::SLUG . '&token=revoked' ) );
		exit;
	}

	/**
	 * Take the token generated a moment ago, once.
	 */
	private function pull_new_token(): ?string {
		$key   = self::NEW_TOKEN . get_current_user_id();
		$token = get_transient( $key );
		delete_transient( $key );

		return is_string( $token ) && '' !== $token ? $token : null;
	}

	/**
	 * Render the page.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$config   = $this->services->config();
		$settings = $config->to_array();
		$name     = Options::SETTINGS;

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Health API', 'wp-health-api' ) . '</h1>';
		settings_errors( Options::SETTINGS );

		$this->render_endpoints();
		$this->render_token();
		$this->render_report();

		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
		settings_fields( self::GROUP );

		// Checks and thresholds.
		echo '<h2>' . esc_html__( 'Checks and thresholds', 'wp-health-api' ) . '</h2>';
		echo '<p>' . esc_html__( 'Leave a limit empty to disable that level. "Above" limits fire when the value is at or above the limit, "below" limits when it is at or below.', 'wp-health-api' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Check', 'wp-health-api' ) . '</th><th>' . esc_html__( 'Measure', 'wp-health-api' ) . '</th><th>' . esc_html__( 'Warning', 'wp-health-api' ) . '</th><th>' . esc_html__( 'Critical', 'wp-health-api' ) . '</th>';
		echo '</tr></thead><tbody>';
		$definitions = Defaults::thresholds();
		$labels      = Labels::thresholds();
		foreach ( array_keys( Defaults::checks() ) as $check ) {
			$keys = array_values(
				array_filter(
					array_keys( $definitions ),
					static function ( string $key ) use ( $check ): bool {
						return 0 === strpos( $key, $check . '.' );
					}
				)
			);
			$rows = max( 1, count( $keys ) );
			echo '<tr><td rowspan="' . esc_attr( (string) $rows ) . '">';
			printf( '<input type="hidden" name="%s[checks][%s]" value="0">', esc_attr( $name ), esc_attr( $check ) );
			printf(
				'<label><input type="checkbox" name="%1$s[checks][%2$s]" value="1"%3$s> <strong>%4$s</strong></label>',
				esc_attr( $name ),
				esc_attr( $check ),
				checked( ! empty( $settings['checks'][ $check ] ), true, false ),
				esc_html( Labels::check( $check ) )
			);
			echo '</td>';
			if ( array() === $keys ) {
				echo '<td colspan="3">' . esc_html__( 'No thresholds; see the options below.', 'wp-health-api' ) . '</td></tr>';
				continue;
			}
			foreach ( $keys as $index => $key ) {
				if ( $index > 0 ) {
					echo '<tr>';
				}
				$direction = Threshold::ABOVE === $definitions[ $key ][2] ? __( 'above', 'wp-health-api' ) : __( 'below', 'wp-health-api' );
				printf( '<td>%s <span class="description">(%s, %s)</span></td>', esc_html( $labels[ $key ] ?? $key ), esc_html( $definitions[ $key ][3] ), esc_html( $direction ) );
				foreach ( array( 'warn', 'critical' ) as $level ) {
					$value = $settings['thresholds'][ $key ][ $level ];
					printf(
						'<td><input type="number" step="any" class="small-text" name="%1$s[thresholds][%2$s][%3$s]" value="%4$s"></td>',
						esc_attr( $name ),
						esc_attr( $key ),
						esc_attr( $level ),
						esc_attr( null === $value ? '' : (string) $value )
					);
				}
				echo '</tr>';
			}
		}
		echo '</tbody></table>';

		// Responses.
		echo '<h2>' . esc_html__( 'Responses', 'wp-health-api' ) . '</h2><table class="form-table" role="presentation">';
		echo '<tr><th scope="row">' . esc_html__( 'HTTP status per result', 'wp-health-api' ) . '</th><td>';
		foreach ( Status::all() as $status ) {
			printf(
				'<label>%1$s <input type="number" min="200" max="599" class="small-text" name="%2$s[status_codes][%3$s]" value="%4$d"></label> ',
				esc_html( Labels::status( $status ) ),
				esc_attr( $name ),
				esc_attr( $status ),
				(int) $settings['status_codes'][ $status ]
			);
		}
		echo '<p class="description">' . esc_html__( 'Used by /status (in "overall" mode) and /details. Simple uptime monitors only look at the status code. /metrics always answers 200.', 'wp-health-api' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Public /status endpoint', 'wp-health-api' ) . '</th><td>';
		foreach ( array(
			'alive'   => __( 'Alive: "ok" whenever WordPress and its database answer', 'wp-health-api' ),
			'overall' => __( 'Overall: the overall result of all checks, without details', 'wp-health-api' ),
		) as $mode => $label ) {
			printf(
				'<label><input type="radio" name="%1$s[public_status]" value="%2$s"%3$s> %4$s</label><br>',
				esc_attr( $name ),
				esc_attr( $mode ),
				checked( $settings['public_status'], $mode, false ),
				esc_html( $label )
			);
		}
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Maintenance mode result', 'wp-health-api' ) . '</th><td><select name="' . esc_attr( $name ) . '[maintenance_status]">';
		foreach ( Status::all() as $status ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $status ), selected( $settings['maintenance_status'], $status, false ), esc_html( Labels::status( $status ) ) );
		}
		echo '</select></td></tr></table>';

		// Load protection.
		echo '<h2>' . esc_html__( 'Caching and rate limits', 'wp-health-api' ) . '</h2><table class="form-table" role="presentation">';
		$numbers = array(
			'cache_ttl'           => __( 'Cache reports for (seconds, 0 = off)', 'wp-health-api' ),
			'rate_limit_public'   => __( 'Public requests per window and IP (0 = unlimited)', 'wp-health-api' ),
			'rate_limit_private'  => __( 'Authenticated requests per window and IP (0 = unlimited)', 'wp-health-api' ),
			'rate_limit_window'   => __( 'Rate limit window (seconds)', 'wp-health-api' ),
			'auth_failures_limit' => __( 'Failed tokens per IP before a 15 minute lock-out (0 = never)', 'wp-health-api' ),
		);
		foreach ( $numbers as $key => $label ) {
			$this->number_row( $key, $label, (int) $settings[ $key ] );
		}
		echo '<tr><th scope="row"><label for="wp-health-api-proxy">' . esc_html__( 'Client IP header', 'wp-health-api' ) . '</label></th><td><select id="wp-health-api-proxy" name="' . esc_attr( $name ) . '[trusted_proxy_header]">';
		foreach ( Sanitizer::PROXY_HEADERS as $header ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $header ), selected( $settings['trusted_proxy_header'], $header, false ), esc_html( '' === $header ? __( 'REMOTE_ADDR (no proxy)', 'wp-health-api' ) : $header ) );
		}
		echo '</select><p class="description">' . esc_html__( 'Only choose a proxy header when your proxy or CDN always sets it; otherwise clients can forge it.', 'wp-health-api' ) . '</p></td></tr></table>';

		// Check options.
		echo '<h2>' . esc_html__( 'Check options', 'wp-health-api' ) . '</h2><table class="form-table" role="presentation">';
		$this->number_row( 'cron_grace', __( 'Seconds a cron event or action may be late before it is overdue', 'wp-health-api' ), (int) $settings['cron_grace'] );
		$this->number_row( 'ssl_cache_hours', __( 'Cache the certificate for (hours)', 'wp-health-api' ), (int) $settings['ssl_cache_hours'] );
		foreach ( array(
			'php_warn_below'     => __( 'Warn below PHP version', 'wp-health-api' ),
			'php_critical_below' => __( 'Critical below PHP version', 'wp-health-api' ),
		) as $key => $label ) {
			printf(
				'<tr><th scope="row"><label for="wp-health-api-%1$s">%2$s</label></th><td><input id="wp-health-api-%1$s" type="text" class="small-text" name="%3$s[%1$s]" value="%4$s"></td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( $name ),
				esc_attr( (string) $settings[ $key ] )
			);
		}
		foreach ( array(
			'require_opcache'          => __( 'Warn when OPcache is off', 'wp-health-api' ),
			'require_persistent_cache' => __( 'Warn when no persistent object cache is installed', 'wp-health-api' ),
		) as $key => $label ) {
			printf(
				'<tr><th scope="row">%1$s</th><td><input type="hidden" name="%2$s[%3$s]" value="0"><label><input type="checkbox" name="%2$s[%3$s]" value="1"%4$s> %5$s</label></td></tr>',
				esc_html( $label ),
				esc_attr( $name ),
				esc_attr( $key ),
				checked( ! empty( $settings[ $key ] ), true, false ),
				esc_html__( 'Enabled', 'wp-health-api' )
			);
		}
		echo '</table>';

		submit_button();
		echo '</form></div>';
	}

	/**
	 * Number input row.
	 *
	 * @param string $key Key.
	 * @param string $label Label.
	 * @param int    $value Value.
	 */
	private function number_row( string $key, string $label, int $value ): void {
		$range = Sanitizer::RANGES[ $key ] ?? array( 0, PHP_INT_MAX );
		printf(
			'<tr><th scope="row"><label for="wp-health-api-%1$s">%2$s</label></th><td><input id="wp-health-api-%1$s" type="number" min="%3$d" max="%4$d" class="small-text" name="%5$s[%1$s]" value="%6$d"></td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			(int) $range[0],
			(int) $range[1],
			esc_attr( Options::SETTINGS ),
			(int) $value
		);
	}

	/**
	 * Endpoint URLs and examples.
	 */
	private function render_endpoints(): void {
		echo '<h2>' . esc_html__( 'Endpoints', 'wp-health-api' ) . '</h2><table class="widefat striped"><tbody>';
		foreach ( array(
			'status'  => __( 'Public, no details. For simple uptime monitors.', 'wp-health-api' ),
			'details' => __( 'JSON report with every check. Needs the token.', 'wp-health-api' ),
			'metrics' => __( 'Prometheus text format. Needs the token.', 'wp-health-api' ),
		) as $route => $description ) {
			printf( '<tr><td><code>%1$s</code></td><td>%2$s</td></tr>', esc_html( rest_url( 'health/v1/' . $route ) ), esc_html( $description ) );
		}
		echo '</tbody></table>';
		printf(
			'<p>%1$s</p><pre><code>curl -H "Authorization: Bearer $HEALTH_TOKEN" %2$s</code></pre>',
			esc_html__( 'Send the token in the Authorization header (or X-Health-Token if your server strips Authorization):', 'wp-health-api' ),
			esc_html( rest_url( 'health/v1/details' ) )
		);
	}

	/**
	 * Token box.
	 */
	private function render_token(): void {
		$verifier = $this->services->tokens()->verifier();
		echo '<h2>' . esc_html__( 'Access token', 'wp-health-api' ) . '</h2>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display flag only.
		$flag  = isset( $_GET['token'] ) ? sanitize_key( wp_unslash( $_GET['token'] ) ) : '';
		$token = 'new' === $flag ? $this->pull_new_token() : null;
		if ( null !== $token ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Copy the new token now. It is shown only once; only its hash is stored.', 'wp-health-api' ) . '</p>';
			echo '<p><input type="text" readonly class="large-text code" value="' . esc_attr( $token ) . '" onfocus="this.select()"></p></div>';
		} elseif ( 'revoked' === $flag ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'The stored token was deleted.', 'wp-health-api' ) . '</p></div>';
		}

		switch ( $verifier->source() ) {
			case 'constant':
				echo '<p>' . ( $verifier->is_weak()
					? esc_html__( 'The WP_HEALTH_API_TOKEN constant is set but shorter than 16 characters, so it is not accepted.', 'wp-health-api' )
					: esc_html__( 'The token is defined by the WP_HEALTH_API_TOKEN constant in wp-config.php. A generated token is ignored while the constant exists.', 'wp-health-api' ) ) . '</p>';
				break;
			case 'option':
				echo '<p>' . esc_html__( 'A generated token is active. Generating a new one invalidates it immediately.', 'wp-health-api' ) . '</p>';
				break;
			default:
				echo '<p>' . esc_html__( 'No token is configured: /details and /metrics answer 401. Generate a token or define WP_HEALTH_API_TOKEN in wp-config.php.', 'wp-health-api' ) . '</p>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ROTATE ) . '">';
		wp_nonce_field( self::ROTATE );
		submit_button( __( 'Generate new token', 'wp-health-api' ), 'secondary', 'submit', false );
		echo '</form>';
		if ( 'option' === $verifier->source() ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::REVOKE ) . '">';
			wp_nonce_field( self::REVOKE );
			submit_button( __( 'Delete token', 'wp-health-api' ), 'delete', 'submit', false );
			echo '</form>';
		}
	}

	/**
	 * Current results.
	 */
	private function render_report(): void {
		echo '<h2>' . esc_html__( 'Current results', 'wp-health-api' ) . '</h2>';
		try {
			$report = $this->services->report( false );
		} catch ( Throwable $e ) {
			echo '<p>' . esc_html( $e->getMessage() ) . '</p>';
			return;
		}
		printf(
			/* translators: 1: overall status, 2: time the report was generated. */
			'<p>' . esc_html__( 'Overall: %1$s (generated %2$s UTC).', 'wp-health-api' ) . '</p>',
			'<strong>' . esc_html( Labels::status( $report->status() ) ) . '</strong>',
			esc_html( gmdate( 'Y-m-d H:i:s', $report->generated_at ) )
		);
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Check', 'wp-health-api' ) . '</th><th>' . esc_html__( 'Result', 'wp-health-api' ) . '</th><th>' . esc_html__( 'Message', 'wp-health-api' ) . '</th></tr></thead><tbody>';
		foreach ( $report->results() as $id => $result ) {
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td></tr>',
				esc_html( Labels::check( $id ) ),
				esc_html( $result->skipped ? __( 'Skipped', 'wp-health-api' ) : Labels::status( $result->status ) ),
				esc_html( $result->message )
			);
		}
		echo '</tbody></table>';
	}
}
