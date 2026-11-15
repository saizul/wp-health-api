<?php
/**
 * Builds the configured checks.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi;

use InvalidArgumentException;
use Saizul\HealthApi\Check\ActionSchedulerCheck;
use Saizul\HealthApi\Check\Check;
use Saizul\HealthApi\Check\CronCheck;
use Saizul\HealthApi\Check\DatabaseCheck;
use Saizul\HealthApi\Check\DiskCheck;
use Saizul\HealthApi\Check\MaintenanceCheck;
use Saizul\HealthApi\Check\ObjectCacheCheck;
use Saizul\HealthApi\Check\PhpCheck;
use Saizul\HealthApi\Check\SslCheck;
use Saizul\HealthApi\Check\UpdatesCheck;
use Saizul\HealthApi\Check\WooCommerceCheck;
use Saizul\HealthApi\Settings\Config;
use Saizul\HealthApi\Support\Clock;
use Saizul\HealthApi\Support\Store;

/**
 * Creates check objects from the settings and a set of probes.
 *
 * Probes are given as factories (callables) so that only the probes of
 * enabled checks are created.
 */
final class CheckFactory {

	/**
	 * Config.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * Clock.
	 *
	 * @var Clock
	 */
	private $clock;

	/**
	 * Store (for the SSL cache).
	 *
	 * @var Store
	 */
	private $store;

	/**
	 * Probe factories keyed by check id.
	 *
	 * @var array<string,callable>
	 */
	private $probes;

	/**
	 * Home URL (for the SSL check).
	 *
	 * @var string
	 */
	private $home_url;

	/**
	 * Constructor.
	 *
	 * @param Config                 $config Config.
	 * @param Clock                  $clock Clock.
	 * @param Store                  $store Store.
	 * @param array<string,callable> $probes Probe factories keyed by check id.
	 * @param string                 $home_url Home URL.
	 */
	public function __construct( Config $config, Clock $clock, Store $store, array $probes, string $home_url ) {
		$this->config   = $config;
		$this->clock    = $clock;
		$this->store    = $store;
		$this->probes   = $probes;
		$this->home_url = $home_url;
	}

	/**
	 * Build the enabled checks, optionally limited to some ids.
	 *
	 * @param string[]|null $only Check ids to include, or null for all enabled.
	 * @return Check[]
	 */
	public function build( ?array $only = null ): array {
		$checks = array();
		foreach ( $this->config->enabled_checks() as $id ) {
			if ( null !== $only && ! in_array( $id, $only, true ) ) {
				continue;
			}
			if ( ! isset( $this->probes[ $id ] ) ) {
				continue;
			}
			$checks[] = $this->create( $id );
		}

		return $checks;
	}

	/**
	 * Build one check.
	 *
	 * @param string $id Check id.
	 * @throws InvalidArgumentException For unknown ids or missing probes.
	 */
	public function create( string $id ): Check {
		if ( ! isset( $this->probes[ $id ] ) ) {
			throw new InvalidArgumentException( sprintf( 'No probe for check "%s".', $id ) );
		}
		$probe = call_user_func( $this->probes[ $id ] );
		$c     = $this->config;

		switch ( $id ) {
			case 'database':
				return new DatabaseCheck( $probe, $c->threshold( 'database.latency_ms' ) );
			case 'object_cache':
				return new ObjectCacheCheck( $probe, $c->threshold( 'object_cache.latency_ms' ), $c->bool( 'require_persistent_cache' ) );
			case 'cron':
				return new CronCheck( $probe, $this->clock, $c->threshold( 'cron.overdue_events' ), $c->threshold( 'cron.last_run_age' ), $c->int( 'cron_grace' ) );
			case 'action_scheduler':
				return new ActionSchedulerCheck( $probe, $this->clock, $c->threshold( 'action_scheduler.past_due' ), $c->threshold( 'action_scheduler.failed_24h' ), $c->int( 'cron_grace' ) );
			case 'disk':
				return new DiskCheck( $probe, $c->threshold( 'disk.free_percent' ), $c->threshold( 'disk.free_mb' ) );
			case 'php':
				return new PhpCheck(
					$probe,
					$c->threshold( 'php.memory_limit_mb' ),
					$c->threshold( 'php.opcache_hit_percent' ),
					$c->threshold( 'php.opcache_free_memory_percent' ),
					array(
						'warn_below'      => $c->string( 'php_warn_below' ),
						'critical_below'  => $c->string( 'php_critical_below' ),
						'require_opcache' => $c->bool( 'require_opcache' ),
					)
				);
			case 'updates':
				return new UpdatesCheck( $probe, $c->threshold( 'updates.core' ), $c->threshold( 'updates.plugins' ), $c->threshold( 'updates.themes' ) );
			case 'ssl':
				return new SslCheck( $probe, $this->store, $this->clock, $c->threshold( 'ssl.days_left' ), $this->home_url, $c->int( 'ssl_cache_hours' ) * 3600 );
			case 'maintenance':
				return new MaintenanceCheck( $probe, $this->clock, $c->string( 'maintenance_status' ) );
			case 'woocommerce':
				return new WooCommerceCheck( $probe, $this->clock, $c->threshold( 'woocommerce.orders_last_hour' ), $c->threshold( 'woocommerce.failed_orders_last_hour' ) );
		}

		throw new InvalidArgumentException( sprintf( 'Unknown check "%s".', $id ) );
	}
}
