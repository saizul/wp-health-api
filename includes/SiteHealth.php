<?php
/**
 * Site Health integration.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\WP;

use Saizul\HealthApi\CheckResult;
use Saizul\HealthApi\Status;
use Saizul\HealthApi\WP\Admin\SettingsPage;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Adds one Site Health test per enabled check and a section to the
 * Site Health "Info" tab. All tests share one (cached) report.
 */
final class SiteHealth {

	const PREFIX = 'wp_health_api_';

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
	 */
	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'add_tests' ) );
		add_filter( 'debug_information', array( $this, 'debug_information' ) );
	}

	/**
	 * Add direct tests.
	 *
	 * @param array<string,mixed> $tests Tests.
	 * @return array<string,mixed>
	 */
	public function add_tests( $tests ): array {
		$tests = is_array( $tests ) ? $tests : array();
		foreach ( $this->services->config()->enabled_checks() as $id ) {
			$tests['direct'][ self::PREFIX . $id ] = array(
				/* translators: %s: check name, e.g. "Database". */
				'label' => sprintf( __( 'Health API: %s', 'wp-health-api' ), Labels::check( $id ) ),
				'test'  => function () use ( $id ): array {
					return $this->run_test( $id );
				},
			);
		}

		return $tests;
	}

	/**
	 * Result of one test in the Site Health format.
	 *
	 * @param string $id Check id.
	 * @return array<string,mixed>
	 */
	public function run_test( string $id ): array {
		try {
			$result = $this->services->report( false )->result( $id );
		} catch ( Throwable $e ) {
			$result = new CheckResult( $id, Status::CRITICAL, $e->getMessage() );
		}

		return self::format( $id, $result );
	}

	/**
	 * Convert a check result.
	 *
	 * @param string           $id Check id.
	 * @param CheckResult|null $result Result.
	 * @return array<string,mixed>
	 */
	public static function format( string $id, ?CheckResult $result ): array {
		$name = Labels::check( $id );
		if ( null === $result || $result->skipped ) {
			$status = 'good';
			/* translators: %s: check name. */
			$label = sprintf( __( '%s: not applicable on this site', 'wp-health-api' ), $name );
		} elseif ( Status::CRITICAL === $result->status ) {
			$status = 'critical';
			/* translators: %s: check name. */
			$label = sprintf( __( '%s needs attention', 'wp-health-api' ), $name );
		} elseif ( Status::WARN === $result->status ) {
			$status = 'recommended';
			/* translators: %s: check name. */
			$label = sprintf( __( '%s is close to its limits', 'wp-health-api' ), $name );
		} else {
			$status = 'good';
			/* translators: %s: check name. */
			$label = sprintf( __( '%s is healthy', 'wp-health-api' ), $name );
		}

		$description = '<p>' . esc_html(
			sprintf(
				/* translators: %s: result message in English, as reported by the API. */
				__( 'Health API reports: %s', 'wp-health-api' ),
				null === $result ? __( 'no result', 'wp-health-api' ) : $result->message
			)
		) . '</p>';

		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Monitoring', 'wp-health-api' ),
				'color' => 'critical' === $status ? 'red' : ( 'recommended' === $status ? 'orange' : 'blue' ),
			),
			'description' => $description,
			'actions'     => sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( admin_url( 'options-general.php?page=' . SettingsPage::SLUG ) ),
				esc_html__( 'Adjust thresholds', 'wp-health-api' )
			),
			'test'        => self::PREFIX . $id,
		);
	}

	/**
	 * Add a section to Site Health > Info.
	 *
	 * @param array<string,mixed> $info Info.
	 * @return array<string,mixed>
	 */
	public function debug_information( $info ): array {
		$info     = is_array( $info ) ? $info : array();
		$config   = $this->services->config();
		$verifier = $this->services->tokens()->verifier();
		$sources  = array(
			'constant' => __( 'WP_HEALTH_API_TOKEN constant', 'wp-health-api' ),
			'option'   => __( 'Generated token (hash stored)', 'wp-health-api' ),
			'none'     => __( 'Not configured', 'wp-health-api' ),
		);

		$info['wp-health-api'] = array(
			'label'  => __( 'Health API', 'wp-health-api' ),
			'fields' => array(
				'version'        => array(
					'label' => __( 'Version', 'wp-health-api' ),
					'value' => Plugin::VERSION,
				),
				'status_url'     => array(
					'label' => __( 'Public status URL', 'wp-health-api' ),
					'value' => rest_url( 'health/v1/status' ),
				),
				'metrics_url'    => array(
					'label' => __( 'Metrics URL', 'wp-health-api' ),
					'value' => rest_url( 'health/v1/metrics' ),
				),
				'token'          => array(
					'label' => __( 'Access token', 'wp-health-api' ),
					'value' => $sources[ $verifier->source() ] ?? $verifier->source(),
					'debug' => $verifier->source(),
				),
				'enabled_checks' => array(
					'label' => __( 'Enabled checks', 'wp-health-api' ),
					'value' => implode( ', ', $config->enabled_checks() ),
				),
				'cache_ttl'      => array(
					'label' => __( 'Report cache (seconds)', 'wp-health-api' ),
					'value' => (string) $config->int( 'cache_ttl' ),
				),
			),
		);

		return $info;
	}
}
