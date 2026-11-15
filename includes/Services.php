<?php
/**
 * Service container.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\WP;

use Saizul\HealthApi\CachedRunner;
use Saizul\HealthApi\Check\Check;
use Saizul\HealthApi\CheckFactory;
use Saizul\HealthApi\RateLimit\RateLimiter;
use Saizul\HealthApi\Report;
use Saizul\HealthApi\Runner;
use Saizul\HealthApi\Settings\Config;
use Saizul\HealthApi\Support\Clock;
use Saizul\HealthApi\Support\Store;
use Saizul\HealthApi\Support\SystemClock;
use Saizul\HealthApi\WP\Probe\StreamSslProbe;
use Saizul\HealthApi\WP\Probe\WcOrdersProbe;
use Saizul\HealthApi\WP\Probe\WpActionSchedulerProbe;
use Saizul\HealthApi\WP\Probe\WpCronProbe;
use Saizul\HealthApi\WP\Probe\WpdbProbe;
use Saizul\HealthApi\WP\Probe\WpDiskProbe;
use Saizul\HealthApi\WP\Probe\WpMaintenanceProbe;
use Saizul\HealthApi\WP\Probe\WpObjectCacheProbe;
use Saizul\HealthApi\WP\Probe\WpPhpProbe;
use Saizul\HealthApi\WP\Probe\WpUpdatesProbe;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and shares the plugin's objects.
 */
final class Services {

	/**
	 * Config.
	 *
	 * @var Config|null
	 */
	private $config;

	/**
	 * Clock.
	 *
	 * @var Clock
	 */
	private $clock;

	/**
	 * Store.
	 *
	 * @var Store
	 */
	private $store;

	/**
	 * Probe factories override.
	 *
	 * @var array<string,callable>|null
	 */
	private $probes;

	/**
	 * Reports built during this request, keyed by variant.
	 *
	 * @var array<string,Report>
	 */
	private $reports = array();

	/**
	 * Token manager.
	 *
	 * @var TokenManager
	 */
	private $tokens;

	/**
	 * Constructor.
	 *
	 * @param Config|null                 $config Config (read from the option when null).
	 * @param Clock|null                  $clock Clock.
	 * @param Store|null                  $store Store.
	 * @param array<string,callable>|null $probes Probe factories (the WordPress probes when null).
	 * @param TokenManager|null           $tokens Token manager.
	 */
	public function __construct( ?Config $config = null, ?Clock $clock = null, ?Store $store = null, ?array $probes = null, ?TokenManager $tokens = null ) {
		$this->config = $config;
		$this->clock  = $clock ?? new SystemClock();
		$this->store  = $store ?? new TransientStore();
		$this->probes = $probes;
		$this->tokens = $tokens ?? new TokenManager();
	}

	/**
	 * Settings.
	 */
	public function config(): Config {
		if ( null === $this->config ) {
			$stored = get_option( Options::SETTINGS, array() );

			/**
			 * Filters the stored settings before they are validated. Useful to
			 * manage thresholds from code or per environment.
			 *
			 * @param array<string,mixed> $settings Settings.
			 */
			$settings     = apply_filters( 'wp_health_api_settings', is_array( $stored ) ? $stored : array() );
			$this->config = new Config( is_array( $settings ) ? $settings : array() );
		}

		return $this->config;
	}

	/**
	 * Clock.
	 */
	public function clock(): Clock {
		return $this->clock;
	}

	/**
	 * Store.
	 */
	public function store(): Store {
		return $this->store;
	}

	/**
	 * Token manager.
	 */
	public function tokens(): TokenManager {
		return $this->tokens;
	}

	/**
	 * Rate limiter.
	 */
	public function limiter(): RateLimiter {
		return new RateLimiter( $this->store, $this->clock );
	}

	/**
	 * Probe factories keyed by check id.
	 *
	 * @return array<string,callable>
	 */
	public function probes(): array {
		if ( null !== $this->probes ) {
			return $this->probes;
		}

		$probes = array(
			'database'         => static function () {
				return new WpdbProbe();
			},
			'object_cache'     => static function () {
				return new WpObjectCacheProbe();
			},
			'cron'             => static function () {
				return new WpCronProbe();
			},
			'action_scheduler' => static function () {
				return new WpActionSchedulerProbe();
			},
			'disk'             => static function () {
				return new WpDiskProbe();
			},
			'php'              => static function () {
				return new WpPhpProbe();
			},
			'updates'          => static function () {
				return new WpUpdatesProbe();
			},
			'ssl'              => static function () {
				return new StreamSslProbe();
			},
			'maintenance'      => static function () {
				return new WpMaintenanceProbe();
			},
			'woocommerce'      => static function () {
				return new WcOrdersProbe();
			},
		);

		/**
		 * Filters the probe factories. Each entry is a callable returning the
		 * probe object for the check with the same id.
		 *
		 * @param array<string,callable> $probes Probe factories.
		 */
		$filtered     = apply_filters( 'wp_health_api_probes', $probes );
		$this->probes = is_array( $filtered ) ? $filtered : $probes;

		return $this->probes;
	}

	/**
	 * URL whose certificate the SSL check reads.
	 */
	public function ssl_url(): string {
		/**
		 * Filters the URL whose TLS certificate is checked.
		 *
		 * @param string $url Home URL.
		 */
		return (string) apply_filters( 'wp_health_api_ssl_url', home_url( '/' ) );
	}

	/**
	 * Enabled checks, plus custom checks added by the filter.
	 *
	 * @param string[]|null $only Limit to these ids.
	 * @return Check[]
	 */
	public function checks( ?array $only = null ): array {
		$factory = new CheckFactory( $this->config(), $this->clock, $this->store, $this->probes(), $this->ssl_url() );
		$checks  = $factory->build( $only );

		/**
		 * Filters the checks that run. Add objects implementing
		 * Saizul\HealthApi\Check\Check to include your own checks.
		 *
		 * @param Check[]       $checks Checks.
		 * @param string[]|null $only   Requested ids, or null for all.
		 */
		$filtered = apply_filters( 'wp_health_api_checks', $checks, $only );
		$valid    = array();
		$seen     = array();
		foreach ( is_array( $filtered ) ? $filtered : $checks as $check ) {
			if ( ! $check instanceof Check || isset( $seen[ $check->id() ] ) ) {
				continue;
			}
			if ( null !== $only && ! in_array( $check->id(), $only, true ) ) {
				continue;
			}
			if ( ! preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $check->id() ) ) {
				continue;
			}
			$seen[ $check->id() ] = true;
			$valid[]              = $check;
		}

		return $valid;
	}

	/**
	 * Get a report, cached briefly.
	 *
	 * @param bool          $fresh Skip the cache.
	 * @param string[]|null $only Limit to these check ids.
	 */
	public function report( bool $fresh = false, ?array $only = null ): Report {
		$variant = null === $only ? '' : substr( md5( implode( ',', $only ) ), 0, 12 );
		if ( ! $fresh && isset( $this->reports[ $variant ] ) ) {
			return $this->reports[ $variant ];
		}
		$runner = new CachedRunner( new Runner( $this->clock ), $this->store, $this->config()->int( 'cache_ttl' ) );
		$report = $runner->report( $this->checks( $only ), $variant, $fresh );

		/**
		 * Fires after a report was produced (fresh or from the cache).
		 *
		 * @param Report $report Report.
		 */
		do_action( 'wp_health_api_report', $report );

		$this->reports[ $variant ] = $report;

		return $report;
	}

	/**
	 * Forget cached reports.
	 */
	public function flush(): void {
		$this->reports = array();
		( new CachedRunner( new Runner( $this->clock ), $this->store, 1 ) )->flush();
	}

	/**
	 * Labels for the wp_health_info metric.
	 *
	 * @return array<string,string>
	 */
	public function info(): array {
		return array(
			'plugin_version'    => Plugin::VERSION,
			'wordpress_version' => (string) get_bloginfo( 'version' ),
		);
	}
}
