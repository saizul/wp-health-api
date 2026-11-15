<?php
/**
 * Configurable probes for tests.
 *
 * @package Saizul\HealthApi
 */

declare(strict_types=1);

namespace Saizul\HealthApi\Tests\Fakes;

use RuntimeException;
use Saizul\HealthApi\Probe\ActionSchedulerProbe;
use Saizul\HealthApi\Probe\CronProbe;
use Saizul\HealthApi\Probe\DatabaseProbe;
use Saizul\HealthApi\Probe\DiskProbe;
use Saizul\HealthApi\Probe\MaintenanceProbe;
use Saizul\HealthApi\Probe\ObjectCacheProbe;
use Saizul\HealthApi\Probe\PhpProbe;
use Saizul\HealthApi\Probe\SslProbe;
use Saizul\HealthApi\Probe\UpdatesProbe;
use Saizul\HealthApi\Probe\WooCommerceProbe;
use Saizul\HealthApi\Ssl\Certificate;

final class FakeDatabase implements DatabaseProbe {
	/** @var array<string,mixed> */
	public $ping = array( 'connected' => true, 'latency' => 0.0015, 'error' => '', 'server' => '10.11.6-MariaDB' );
	/** @var int */
	public $calls = 0;

	public function ping(): array {
		++$this->calls;
		return $this->ping;
	}
}

final class FakeObjectCache implements ObjectCacheProbe {
	/** @var string */
	public $backend = 'redis';
	/** @var bool */
	public $persistent = true;
	/** @var array<string,mixed> */
	public $trip = array( 'ok' => true, 'latency' => 0.0004, 'error' => '' );

	public function backend(): string {
		return $this->backend;
	}

	public function is_persistent(): bool {
		return $this->persistent;
	}

	public function round_trip(): array {
		return $this->trip;
	}
}

final class FakeCron implements CronProbe {
	/** @var array<int|string,mixed> */
	public $events = array();
	/** @var int|null */
	public $last_run = null;
	/** @var bool */
	public $disabled = false;

	public function events(): array {
		return $this->events;
	}

	public function last_run(): ?int {
		return $this->last_run;
	}

	public function is_disabled(): bool {
		return $this->disabled;
	}
}

final class FakeActionScheduler implements ActionSchedulerProbe {
	/** @var bool */
	public $available = true;
	/** @var int */
	public $past_due = 0;
	/** @var int */
	public $failed = 0;
	/** @var int|null */
	public $oldest = null;
	/** @var array<int,array{0:string,1:int}> */
	public $queries = array();

	public function is_available(): bool {
		return $this->available;
	}

	public function past_due( int $before ): int {
		$this->queries[] = array( 'past_due', $before );
		return $this->past_due;
	}

	public function failed_since( int $since ): int {
		$this->queries[] = array( 'failed', $since );
		return $this->failed;
	}

	public function oldest_past_due( int $before ): ?int {
		return $this->oldest;
	}
}

final class FakeDisk implements DiskProbe {
	/** @var float|null */
	public $free = 50.0 * 1073741824;
	/** @var float|null */
	public $total = 100.0 * 1073741824;
	/** @var bool */
	public $writable = true;

	public function path(): string {
		return '/var/www/wp-content/uploads';
	}

	public function free_bytes(): ?float {
		return $this->free;
	}

	public function total_bytes(): ?float {
		return $this->total;
	}

	public function is_writable(): bool {
		return $this->writable;
	}
}

final class FakePhp implements PhpProbe {
	/** @var string */
	public $version = '8.3.12';
	/** @var string */
	public $memory_limit = '256M';
	/** @var int */
	public $peak = 12582912;
	/** @var array<string,mixed>|null */
	public $opcache = array(
		'enabled'        => true,
		'hits'           => 99000,
		'misses'         => 1000,
		'used_memory'    => 60000000,
		'free_memory'    => 60000000,
		'wasted_memory'  => 0,
		'cached_scripts' => 2100,
		'cache_full'     => false,
		'restricted'     => false,
	);

	public function version(): string {
		return $this->version;
	}

	public function memory_limit(): string {
		return $this->memory_limit;
	}

	public function memory_peak(): int {
		return $this->peak;
	}

	public function opcache(): ?array {
		return $this->opcache;
	}
}

final class FakeUpdates implements UpdatesProbe {
	/** @var mixed */
	public $core = false;
	/** @var mixed */
	public $plugins = false;
	/** @var mixed */
	public $themes = false;
	/** @var string */
	public $wp = '6.7.1';

	public function core() {
		return $this->core;
	}

	public function plugins() {
		return $this->plugins;
	}

	public function themes() {
		return $this->themes;
	}

	public function wordpress_version(): string {
		return $this->wp;
	}
}

final class FakeSsl implements SslProbe {
	/** @var Certificate|null */
	public $certificate;
	/** @var string */
	public $error = 'Connection refused';
	/** @var array<int,array{0:string,1:int}> */
	public $calls = array();

	public function certificate( string $host, int $port ): Certificate {
		$this->calls[] = array( $host, $port );
		if ( null === $this->certificate ) {
			throw new RuntimeException( $this->error );
		}
		return $this->certificate;
	}
}

final class FakeMaintenance implements MaintenanceProbe {
	/** @var string|null */
	public $contents = null;
	/** @var bool|null */
	public $forced = null;

	public function file_contents(): ?string {
		return $this->contents;
	}

	public function core_state(): ?bool {
		return $this->forced;
	}
}

final class FakeWooCommerce implements WooCommerceProbe {
	/** @var bool */
	public $available = true;
	/** @var array<string,int> */
	public $orders = array();
	/** @var int|null */
	public $since = null;

	public function is_available(): bool {
		return $this->available;
	}

	public function orders_since( int $since ): array {
		$this->since = $since;
		return $this->orders;
	}
}
