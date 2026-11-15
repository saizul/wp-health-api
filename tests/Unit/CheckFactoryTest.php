<?php
declare(strict_types=1);

namespace Saizul\HealthApi\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Saizul\HealthApi\CheckFactory;
use Saizul\HealthApi\Runner;
use Saizul\HealthApi\Settings\Config;
use Saizul\HealthApi\Settings\Defaults;
use Saizul\HealthApi\Status;
use Saizul\HealthApi\Support\ArrayStore;
use Saizul\HealthApi\Support\FrozenClock;
use Saizul\HealthApi\Tests\Fakes;

final class CheckFactoryTest extends TestCase {

	/** @var array<string,int> */
	private $created = array();

	/**
	 * @return array<string,callable>
	 */
	private function probes(): array {
		$made    = &$this->created;
		$factory = static function ( string $id, $probe ) use ( &$made ): callable {
			return static function () use ( $id, $probe, &$made ) {
				$made[ $id ] = ( $made[ $id ] ?? 0 ) + 1;
				return $probe;
			};
		};

		return array(
			'database'         => $factory( 'database', new Fakes\FakeDatabase() ),
			'object_cache'     => $factory( 'object_cache', new Fakes\FakeObjectCache() ),
			'cron'             => $factory( 'cron', new Fakes\FakeCron() ),
			'action_scheduler' => $factory( 'action_scheduler', new Fakes\FakeActionScheduler() ),
			'disk'             => $factory( 'disk', new Fakes\FakeDisk() ),
			'php'              => $factory( 'php', new Fakes\FakePhp() ),
			'updates'          => $factory( 'updates', new Fakes\FakeUpdates() ),
			'ssl'              => $factory( 'ssl', new Fakes\FakeSsl() ),
			'maintenance'      => $factory( 'maintenance', new Fakes\FakeMaintenance() ),
			'woocommerce'      => $factory( 'woocommerce', new Fakes\FakeWooCommerce() ),
		);
	}

	private function factory( array $settings = array() ): CheckFactory {
		$clock = new FrozenClock( 1800000000 );

		return new CheckFactory( new Config( $settings ), $clock, new ArrayStore( $clock ), $this->probes(), 'https://shop.example.com' );
	}

	public function test_default_checks_are_built_lazily(): void {
		$checks = $this->factory()->build();
		$ids    = array_map(
			static function ( $check ) {
				return $check->id();
			},
			$checks
		);
		$this->assertSame( array( 'database', 'object_cache', 'cron', 'action_scheduler', 'disk', 'php', 'updates', 'maintenance' ), $ids );
		$this->assertArrayNotHasKey( 'ssl', $this->created );
		$this->assertArrayNotHasKey( 'woocommerce', $this->created );
	}

	public function test_every_check_can_be_built_and_run(): void {
		$all     = array_fill_keys( array_keys( Defaults::checks() ), true );
		$checks  = $this->factory( array( 'checks' => $all ) )->build();
		$this->assertCount( count( $all ), $checks );
		$report = ( new Runner( new FrozenClock( 1800000000 ) ) )->run( $checks );
		foreach ( $report->results() as $id => $result ) {
			$this->assertStringNotContainsString( 'The check failed', $result->message, $id );
		}
		$this->assertSame( Status::WARN, $report->status(), 'Only the SSL fake (no certificate) warns' );
	}

	public function test_subset(): void {
		$checks = $this->factory()->build( array( 'php', 'ssl' ) );
		$this->assertCount( 1, $checks, 'SSL is disabled, so only PHP' );
		$this->assertSame( 'php', $checks[0]->id() );
	}

	public function test_thresholds_from_settings_are_used(): void {
		$check  = $this->factory( array( 'thresholds' => array( 'database.latency_ms' => array( 'warn' => '1', 'critical' => '' ) ) ) )->create( 'database' );
		$this->assertSame( Status::WARN, $check->run()->status, '1.5 ms is over 1 ms' );
	}

	public function test_unknown_check(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->factory()->create( 'nope' );
	}
}
