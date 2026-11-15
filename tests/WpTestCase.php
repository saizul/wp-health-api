<?php
declare(strict_types=1);

namespace Saizul\HealthApi\Tests;

use PHPUnit\Framework\TestCase;
use Saizul\HealthApi\Settings\Config;
use Saizul\HealthApi\Support\ArrayStore;
use Saizul\HealthApi\Support\FrozenClock;
use Saizul\HealthApi\Tests\Fakes;
use Saizul\HealthApi\WP\Options;
use Saizul\HealthApi\WP\Services;
use Saizul\HealthApi\WP\TokenManager;
use WP_CLI;
use WpStubs;

/**
 * Base class for tests of the WordPress layer (running on stubs).
 */
abstract class WpTestCase extends TestCase {

	/** @var FrozenClock */
	protected $clock;

	/** @var ArrayStore */
	protected $store;

	/** @var array<string,object> */
	protected $probes = array();

	/** @var string|null Value of the WP_HEALTH_API_TOKEN constant in tests. */
	protected $constant = null;

	protected function setUp(): void {
		WpStubs::reset();
		WP_CLI::reset();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
		$this->clock  = new FrozenClock( 1800000000 );
		$this->store  = new ArrayStore( $this->clock );
		$this->constant = null;
		$this->probes   = array(
			'database'         => new Fakes\FakeDatabase(),
			'object_cache'     => new Fakes\FakeObjectCache(),
			'cron'             => new Fakes\FakeCron(),
			'action_scheduler' => new Fakes\FakeActionScheduler(),
			'disk'             => new Fakes\FakeDisk(),
			'php'              => new Fakes\FakePhp(),
			'updates'          => new Fakes\FakeUpdates(),
			'ssl'              => new Fakes\FakeSsl(),
			'maintenance'      => new Fakes\FakeMaintenance(),
			'woocommerce'      => new Fakes\FakeWooCommerce(),
		);
	}

	/**
	 * Services wired to the fakes. Settings come from the stored option
	 * unless given.
	 *
	 * @param array<string,mixed>|null $settings Settings.
	 */
	protected function services( ?array $settings = null ): Services {
		$factories = array();
		foreach ( $this->probes as $id => $probe ) {
			$factories[ $id ] = static function () use ( $probe ) {
				return $probe;
			};
		}

		return new Services( null === $settings ? null : new Config( $settings ), $this->clock, $this->store, $factories, $this->tokens() );
	}

	protected function tokens(): TokenManager {
		$constant = &$this->constant;

		return new TokenManager(
			static function () use ( &$constant ): ?string {
				return $constant;
			}
		);
	}

	/**
	 * Store a generated token and return it.
	 */
	protected function token(): string {
		$token = 'test-token-0123456789abcdef';
		WpStubs::$options[ Options::TOKEN_HASH ] = \Saizul\HealthApi\Auth\TokenHasher::hash( $token );

		return $token;
	}
}
